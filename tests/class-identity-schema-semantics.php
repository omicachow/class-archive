<?php

declare(strict_types=1);

/**
 * Real MariaDB semantic-schema drift gate.
 *
 * The fixture clones only the tracked ClassIdentity DDL into a random, empty
 * table prefix in the same local development database. It never writes a
 * production ClassIdentity table. Every temporary table is dropped explicitly
 * in finally, including on an assertion failure.
 */

const CI_SCHEMA_SUFFIXES = [
    'migration',
    'identity',
    'seat',
    'account',
    'principal',
    'operation',
    'token',
    'audit_event',
    'role_group',
    'rate_limit_bucket',
    'submission',
    'archive_image',
    'photo',
];

function schemaTestFail(string $message): never
{
    throw new RuntimeException($message);
}

function schemaTestIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        schemaTestFail('schema_test_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function schemaTestExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        schemaTestFail('schema_test_query_failed_' . $db->errno);
    }
}

function schemaTestExpectDrift(ClassIdentity\Schema $schema, string $suffix): void
{
    $expected = 'class_identity_schema_semantic_drift_' . $suffix;
    try {
        $schema->verifyCurrent();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === $expected) {
            return;
        }
        throw $exception;
    }
    schemaTestFail('schema_test_drift_not_detected_' . $suffix);
}

/** @return array<string, array<string, string>> */
function schemaTestCloneForeignKeys(
    mysqli $db,
    string $sourcePrefix,
    string $temporaryPrefix,
    string $runId,
): array {
    $escapedLike = $db->real_escape_string($sourcePrefix) . '%';
    $sql = 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, '
        . 'k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, '
        . 'r.MATCH_OPTION, r.UPDATE_RULE, r.DELETE_RULE '
        . 'FROM information_schema.KEY_COLUMN_USAGE k '
        . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
        . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
        . 'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
        . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME LIKE '{$escapedLike}' "
        . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
        . 'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION';
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        schemaTestFail('schema_test_foreign_key_inventory_unavailable');
    }
    try {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }

    $groups = [];
    foreach ($rows as $row) {
        $sourceTable = (string) ($row['TABLE_NAME'] ?? '');
        $sourceReference = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');
        if (!str_starts_with($sourceTable, $sourcePrefix) || !str_starts_with($sourceReference, $sourcePrefix)) {
            schemaTestFail('schema_test_foreign_key_outside_source_prefix');
        }
        $tableSuffix = substr($sourceTable, strlen($sourcePrefix));
        $referenceSuffix = substr($sourceReference, strlen($sourcePrefix));
        if (!in_array($tableSuffix, CI_SCHEMA_SUFFIXES, true)
            || !in_array($referenceSuffix, CI_SCHEMA_SUFFIXES, true)
        ) {
            schemaTestFail('schema_test_foreign_key_suffix_invalid');
        }
        $originalName = (string) ($row['CONSTRAINT_NAME'] ?? '');
        $key = $tableSuffix . "\0" . $originalName;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'table' => $tableSuffix,
                'original_name' => $originalName,
                'reference' => $referenceSuffix,
                'match' => strtoupper((string) ($row['MATCH_OPTION'] ?? 'NONE')),
                'update' => strtoupper((string) ($row['UPDATE_RULE'] ?? 'RESTRICT')),
                'delete' => strtoupper((string) ($row['DELETE_RULE'] ?? 'RESTRICT')),
                'columns' => [],
                'referenced_columns' => [],
            ];
        }
        $position = (int) ($row['ORDINAL_POSITION'] ?? 0);
        $groups[$key]['columns'][$position] = (string) ($row['COLUMN_NAME'] ?? '');
        $groups[$key]['referenced_columns'][$position] = (string) ($row['REFERENCED_COLUMN_NAME'] ?? '');
    }

    $publishedNames = [];
    $counter = 0;
    foreach ($groups as $group) {
        ++$counter;
        foreach (['update', 'delete'] as $action) {
            if (!in_array($group[$action], ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'], true)) {
                schemaTestFail('schema_test_foreign_key_action_invalid');
            }
        }
        if (!in_array($group['match'], ['NONE', 'FULL', 'PARTIAL', 'SIMPLE'], true)) {
            schemaTestFail('schema_test_foreign_key_match_invalid');
        }
        ksort($group['columns'], SORT_NUMERIC);
        ksort($group['referenced_columns'], SORT_NUMERIC);
        $columns = implode(', ', array_map('schemaTestIdentifier', array_values($group['columns'])));
        $referencedColumns = implode(', ', array_map('schemaTestIdentifier', array_values($group['referenced_columns'])));
        $constraint = 'fk_cisem_' . $runId . '_' . $counter;
        $match = $group['match'] === 'NONE' ? '' : ' MATCH ' . $group['match'];
        schemaTestExecute(
            $db,
            'ALTER TABLE ' . schemaTestIdentifier($temporaryPrefix . $group['table'])
            . ' ADD CONSTRAINT ' . schemaTestIdentifier($constraint)
            . ' FOREIGN KEY (' . $columns . ') REFERENCES '
            . schemaTestIdentifier($temporaryPrefix . $group['reference'])
            . ' (' . $referencedColumns . ')' . $match
            . ' ON UPDATE ' . $group['update'] . ' ON DELETE ' . $group['delete'],
        );
        $publishedNames[$group['table']][$group['original_name']] = $constraint;
    }
    return $publishedNames;
}

if (PHP_SAPI !== 'cli'
    || !function_exists('posix_geteuid')
    || !function_exists('posix_getpwuid')
) {
    fwrite(STDERR, "CLASS_IDENTITY_SCHEMA_SEMANTICS=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_IDENTITY_SCHEMA_SEMANTICS=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "CLASS_IDENTITY_SCHEMA_SEMANTICS=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) ($conf['db_host'] ?? ''),
    (string) ($conf['db_user'] ?? ''),
    (string) ($conf['db_password'] ?? ''),
    (string) ($conf['db_base'] ?? ''),
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "CLASS_IDENTITY_SCHEMA_SEMANTICS=FAIL reason=database_unavailable\n");
    exit(1);
}

require dirname(__DIR__) . '/plugins/ClassIdentity/src/Schema.php';

$runId = strtolower(bin2hex(random_bytes(6)));
$sourcePrefix = $prefixeTable . 'class_identity_';
$temporaryBasePrefix = 'ci_sem_' . $runId . '_';
$temporaryPrefix = $temporaryBasePrefix . 'class_identity_';
$createdTables = [];
$assertions = 0;

try {
    $versionResult = $db->query('SELECT VERSION()');
    $version = $versionResult instanceof mysqli_result ? (string) ($versionResult->fetch_row()[0] ?? '') : '';
    if ($versionResult instanceof mysqli_result) {
        $versionResult->free();
    }
    if (!str_starts_with($version, '11.8.8-MariaDB')) {
        schemaTestFail('schema_test_locked_mariadb_required');
    }

    // Read-only precondition: never derive a trusted temporary baseline from a
    // production schema which already fails the locked semantic contract.
    $sourceSchema = new ClassIdentity\Schema($db, $prefixeTable, '0.1.0');
    $sourceSchema->verifyCurrent();
    ++$assertions;

    foreach (CI_SCHEMA_SUFFIXES as $suffix) {
        $source = $sourcePrefix . $suffix;
        $temporary = $temporaryPrefix . $suffix;
        schemaTestExecute(
            $db,
            'CREATE TABLE ' . schemaTestIdentifier($temporary)
            . ' LIKE ' . schemaTestIdentifier($source),
        );
        $createdTables[] = $temporary;
    }
    $foreignKeys = schemaTestCloneForeignKeys($db, $sourcePrefix, $temporaryPrefix, $runId);
    schemaTestExecute(
        $db,
        'INSERT INTO ' . schemaTestIdentifier($temporaryPrefix . 'migration')
        . ' SELECT * FROM ' . schemaTestIdentifier($sourcePrefix . 'migration'),
    );

    $schema = new ClassIdentity\Schema($db, $temporaryBasePrefix, '0.1.0');
    $schema->verifyCurrent();
    ++$assertions;

    $identity = schemaTestIdentifier($temporaryPrefix . 'identity');
    $seat = schemaTestIdentifier($temporaryPrefix . 'seat');
    $principal = schemaTestIdentifier($temporaryPrefix . 'principal');

    schemaTestExecute($db, "ALTER TABLE {$identity} MODIFY `state` VARCHAR(17) NULL DEFAULT 'BROKEN'");
    schemaTestExpectDrift($schema, 'identity');
    ++$assertions;
    schemaTestExecute($db, "ALTER TABLE {$identity} MODIFY `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE'");
    $schema->verifyCurrent();

    schemaTestExecute($db, "ALTER TABLE {$identity} MODIFY `id` BIGINT UNSIGNED NOT NULL");
    schemaTestExpectDrift($schema, 'identity');
    ++$assertions;
    schemaTestExecute($db, "ALTER TABLE {$identity} MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
    $schema->verifyCurrent();

    schemaTestExecute(
        $db,
        "ALTER TABLE {$seat} MODIFY `singleton_marker` VARCHAR(16) "
        . "GENERATED ALWAYS AS (CASE WHEN `seat_type` IN ('CLASSMATE','TEACHER') THEN `seat_type` ELSE NULL END) STORED",
    );
    schemaTestExpectDrift($schema, 'seat');
    ++$assertions;
    schemaTestExecute(
        $db,
        "ALTER TABLE {$seat} MODIFY `singleton_marker` VARCHAR(16) "
        . "GENERATED ALWAYS AS (CASE WHEN `seat_type` IN ('CLASSMATE','TEACHER','ANONYMOUS') THEN `seat_type` ELSE NULL END) STORED",
    );
    $schema->verifyCurrent();

    schemaTestExecute(
        $db,
        "ALTER TABLE {$identity} DROP INDEX `uq_ci_identity_roster`, "
        . 'ADD INDEX `uq_ci_identity_roster` (`identity_type`, `roster_code`)',
    );
    schemaTestExpectDrift($schema, 'identity');
    ++$assertions;
    schemaTestExecute(
        $db,
        "ALTER TABLE {$identity} DROP INDEX `uq_ci_identity_roster`, "
        . 'ADD UNIQUE INDEX `uq_ci_identity_roster` (`roster_code`)',
    );
    $schema->verifyCurrent();

    schemaTestExecute($db, "ALTER TABLE {$principal} DROP CONSTRAINT `chk_ci_principal_account_xor`");
    schemaTestExecute(
        $db,
        "ALTER TABLE {$principal} ADD CONSTRAINT `chk_ci_principal_account_xor` "
        . "CHECK (`principal_type` IN ('SEAT_ACCOUNT','SYSTEM_ACCOUNT'))",
    );
    schemaTestExpectDrift($schema, 'principal');
    ++$assertions;
    schemaTestExecute($db, "ALTER TABLE {$principal} DROP CONSTRAINT `chk_ci_principal_account_xor`");
    schemaTestExecute(
        $db,
        "ALTER TABLE {$principal} ADD CONSTRAINT `chk_ci_principal_account_xor` CHECK ("
        . "(`principal_type` = 'SEAT_ACCOUNT' AND `account_id` IS NOT NULL AND `system_role` IS NULL) OR "
        . "(`principal_type` = 'SYSTEM_ACCOUNT' AND `account_id` IS NULL AND `system_role` IS NOT NULL))",
    );
    $schema->verifyCurrent();

    $seatForeignKey = $foreignKeys['seat']['fk_ci_seat_identity'] ?? null;
    if (!is_string($seatForeignKey)) {
        schemaTestFail('schema_test_seat_foreign_key_missing');
    }
    schemaTestExecute($db, "ALTER TABLE {$seat} DROP FOREIGN KEY " . schemaTestIdentifier($seatForeignKey));
    schemaTestExecute(
        $db,
        "ALTER TABLE {$seat} ADD CONSTRAINT " . schemaTestIdentifier($seatForeignKey)
        . ' FOREIGN KEY (`identity_id`) REFERENCES ' . $identity . ' (`id`)'
        . ' ON UPDATE RESTRICT ON DELETE CASCADE',
    );
    schemaTestExpectDrift($schema, 'seat');
    ++$assertions;
    schemaTestExecute($db, "ALTER TABLE {$seat} DROP FOREIGN KEY " . schemaTestIdentifier($seatForeignKey));
    schemaTestExecute(
        $db,
        "ALTER TABLE {$seat} ADD CONSTRAINT " . schemaTestIdentifier($seatForeignKey)
        . ' FOREIGN KEY (`identity_id`) REFERENCES ' . $identity . ' (`id`)'
        . ' ON UPDATE RESTRICT ON DELETE RESTRICT',
    );
    $schema->verifyCurrent();
    ++$assertions;

    fwrite(STDOUT, 'CLASS_IDENTITY_SCHEMA_SEMANTICS=PASS assertions=' . $assertions . ' run=' . $runId . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'CLASS_IDENTITY_SCHEMA_SEMANTICS=FAIL run=' . $runId
        . ' reason=' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($createdTables !== []) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($createdTables) as $table) {
            $db->query('DROP TABLE IF EXISTS ' . schemaTestIdentifier($table));
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
    $like = $db->real_escape_string($temporaryPrefix) . '%';
    $remaining = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$like}'"
    );
    $remainingCount = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'CLASS_IDENTITY_SCHEMA_SEMANTICS_CLEANUP=FAIL run=' . $runId
            . ' remaining=' . $remainingCount . "\n");
        $exitCode = 1;
    }
    $db->close();
}

exit($exitCode ?? 0);
