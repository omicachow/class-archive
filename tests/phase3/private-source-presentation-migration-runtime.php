<?php

declare(strict_types=1);

/**
 * Disposable v15 -> v16 migration gate for presentation-surrogate provenance.
 *
 * The owner runtime deliberately remains v15 while its isolated restore drill
 * is in progress. This test first attests that read-only v15 source, clones its
 * DDL and foreign keys under a random prefix, then runs the real public
 * Schema::migrate() path twice. No owner row or table is modified.
 */

function presentationMigrationFail(string $reason): never
{
    throw new RuntimeException($reason);
}

function presentationMigrationIdentifier(string $value): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $value) !== 1) {
        presentationMigrationFail('presentation_migration_identifier_invalid');
    }
    return '`' . $value . '`';
}

function presentationMigrationExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        presentationMigrationFail('presentation_migration_query_failed_' . $db->errno);
    }
}

/**
 * CREATE TABLE LIKE intentionally omits foreign keys. Recreate the exact FK
 * semantics with run-scoped names; Schema fingerprints do not trust names.
 *
 * @param list<string> $suffixes
 */
function presentationMigrationCloneForeignKeys(
    mysqli $db,
    string $sourcePrefix,
    string $targetPrefix,
    string $run,
    array $suffixes,
): void {
    $escaped = $db->real_escape_string($sourcePrefix) . '%';
    $result = $db->query(
        'SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,'
        . 'k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.MATCH_OPTION,r.UPDATE_RULE,r.DELETE_RULE '
        . 'FROM information_schema.KEY_COLUMN_USAGE k '
        . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
        . 'ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME '
        . 'AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
        . "WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME LIKE '{$escaped}' "
        . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
        . 'ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
    );
    if (!$result instanceof mysqli_result) {
        presentationMigrationFail('presentation_migration_fk_inventory_unavailable');
    }
    try {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }
    $allowed = array_fill_keys($suffixes, true);
    $groups = [];
    foreach ($rows as $row) {
        $sourceTable = (string) ($row['TABLE_NAME'] ?? '');
        $sourceReference = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');
        if (!str_starts_with($sourceTable, $sourcePrefix) || !str_starts_with($sourceReference, $sourcePrefix)) {
            presentationMigrationFail('presentation_migration_fk_outside_source');
        }
        $suffix = substr($sourceTable, strlen($sourcePrefix));
        $referenceSuffix = substr($sourceReference, strlen($sourcePrefix));
        if (!isset($allowed[$suffix], $allowed[$referenceSuffix])) {
            presentationMigrationFail('presentation_migration_fk_suffix_invalid');
        }
        $key = $suffix . "\0" . (string) ($row['CONSTRAINT_NAME'] ?? '');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'table' => $suffix,
                'reference' => $referenceSuffix,
                'match' => strtoupper((string) ($row['MATCH_OPTION'] ?? 'NONE')),
                'update' => strtoupper((string) ($row['UPDATE_RULE'] ?? 'RESTRICT')),
                'delete' => strtoupper((string) ($row['DELETE_RULE'] ?? 'RESTRICT')),
                'columns' => [],
                'references' => [],
            ];
        }
        $ordinal = (int) ($row['ORDINAL_POSITION'] ?? 0);
        $groups[$key]['columns'][$ordinal] = (string) ($row['COLUMN_NAME'] ?? '');
        $groups[$key]['references'][$ordinal] = (string) ($row['REFERENCED_COLUMN_NAME'] ?? '');
    }
    $counter = 0;
    foreach ($groups as $group) {
        ++$counter;
        if (!in_array($group['match'], ['NONE', 'FULL', 'PARTIAL', 'SIMPLE'], true)
            || !in_array($group['update'], ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'], true)
            || !in_array($group['delete'], ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'], true)
        ) {
            presentationMigrationFail('presentation_migration_fk_contract_invalid');
        }
        ksort($group['columns'], SORT_NUMERIC);
        ksort($group['references'], SORT_NUMERIC);
        $columns = implode(',', array_map('presentationMigrationIdentifier', array_values($group['columns'])));
        $references = implode(',', array_map('presentationMigrationIdentifier', array_values($group['references'])));
        $match = $group['match'] === 'NONE' ? '' : ' MATCH ' . $group['match'];
        presentationMigrationExecute(
            $db,
            'ALTER TABLE ' . presentationMigrationIdentifier($targetPrefix . $group['table'])
            . ' ADD CONSTRAINT ' . presentationMigrationIdentifier('fk_civ16_' . $run . '_' . $counter)
            . ' FOREIGN KEY (' . $columns . ') REFERENCES '
            . presentationMigrationIdentifier($targetPrefix . $group['reference'])
            . ' (' . $references . ')' . $match
            . ' ON UPDATE ' . $group['update'] . ' ON DELETE ' . $group['delete'],
        );
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    fwrite(STDERR, "PRIVATE_SOURCE_PRESENTATION_MIGRATION=FAIL reason=non_root_cli_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) ($conf['db_host'] ?? ''),
    (string) ($conf['db_user'] ?? ''),
    (string) ($conf['db_password'] ?? ''),
    (string) ($conf['db_base'] ?? ''),
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')
    || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1
) {
    fwrite(STDERR, "PRIVATE_SOURCE_PRESENTATION_MIGRATION=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

$run = strtolower(bin2hex(random_bytes(6)));
$sourcePrefix = $prefixeTable . 'class_identity_';
$temporaryBase = 'ci_v16_' . $run . '_';
$temporaryPrefix = $temporaryBase . 'class_identity_';
$assertions = 0;
$exitCode = 0;
$success = null;
$assert = static function (bool $condition, string $reason) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        presentationMigrationFail($reason);
    }
};

try {
    $versionResult = $db->query('SELECT VERSION()');
    $version = $versionResult instanceof mysqli_result ? (string) ($versionResult->fetch_row()[0] ?? '') : '';
    if ($versionResult instanceof mysqli_result) {
        $versionResult->free();
    }
    $assert(str_starts_with($version, '11.8.8-MariaDB'), 'presentation_migration_locked_mariadb_required');

    $source = new ClassIdentity\Schema($db, $prefixeTable, 'v15-read-only-attestation');
    $migrationMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'migrations');
    /** @var array<int,array{name:string,signature:string,method:string}> $migrations */
    $migrations = $migrationMethod->invoke($source);
    $assert(count($migrations) === 16 && isset($migrations[16]), 'presentation_migration_catalog_invalid');

    $ledgerResult = $db->query(
        'SELECT `version`,`migration_name`,`checksum` FROM '
        . presentationMigrationIdentifier($sourcePrefix . 'migration') . ' ORDER BY `version`',
    );
    if (!$ledgerResult instanceof mysqli_result) {
        presentationMigrationFail('presentation_migration_ledger_unavailable');
    }
    try {
        $ledger = $ledgerResult->fetch_all(MYSQLI_ASSOC);
    } finally {
        $ledgerResult->free();
    }
    $assert(count($ledger) === 15, 'presentation_migration_source_not_v15');
    foreach ($ledger as $index => $row) {
        $versionNumber = $index + 1;
        $expected = $migrations[$versionNumber] ?? null;
        $checksum = is_array($expected)
            ? hash('sha256', $versionNumber . "\0" . $expected['name'] . "\0" . $expected['signature'], true)
            : '';
        $assert((int) ($row['version'] ?? 0) === $versionNumber
            && is_array($expected)
            && (string) ($row['migration_name'] ?? '') === $expected['name']
            && is_string($row['checksum'] ?? null)
            && hash_equals($checksum, (string) $row['checksum']),
            'presentation_migration_source_ledger_drift_' . $versionNumber,
        );
    }

    $expectedMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'expectedSemanticDigests');
    /** @var array<string,string> $expectedDigests */
    $expectedDigests = $expectedMethod->invoke(null);
    $semanticMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'semanticDigest');
    $v15Suffixes = array_values(array_filter(
        array_keys($expectedDigests),
        static fn(string $suffix): bool => $suffix !== 'photo_source_presentation',
    ));
    foreach ($v15Suffixes as $suffix) {
        $actual = $semanticMethod->invoke($source, $suffix);
        $assert(is_string($actual) && hash_equals($expectedDigests[$suffix], $actual),
            'presentation_migration_source_semantic_drift_' . $suffix);
    }
    $assertProjectionEpochs = new ReflectionMethod(ClassIdentity\Schema::class, 'assertProjectionEpochsInitialized');
    $assertProjectionEpochs->invoke($source);
    $assertNativeTriggers = new ReflectionMethod(ClassIdentity\Schema::class, 'assertNativeProjectionTriggers');
    $assertNativeTriggers->invoke($source);
    $epochDefinitionsMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'nativeSourceEpochTriggerDefinitions');
    $assertNativeTriggers->invoke($source, $epochDefinitionsMethod->invoke($source));
    ++$assertions;

    $sourcePresentation = $sourcePrefix . 'photo_source_presentation';
    $sourcePresentationResult = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
        . $db->real_escape_string($sourcePresentation) . "'",
    );
    $sourcePresentationCount = $sourcePresentationResult instanceof mysqli_result
        ? (int) ($sourcePresentationResult->fetch_row()[0] ?? -1) : -1;
    if ($sourcePresentationResult instanceof mysqli_result) {
        $sourcePresentationResult->free();
    }
    $assert($sourcePresentationCount === 0, 'presentation_migration_source_v16_table_present');

    foreach ($v15Suffixes as $suffix) {
        presentationMigrationExecute(
            $db,
            'CREATE TABLE ' . presentationMigrationIdentifier($temporaryPrefix . $suffix)
            . ' LIKE ' . presentationMigrationIdentifier($sourcePrefix . $suffix),
        );
    }
    presentationMigrationCloneForeignKeys($db, $sourcePrefix, $temporaryPrefix, $run, $v15Suffixes);
    presentationMigrationExecute(
        $db,
        'INSERT INTO ' . presentationMigrationIdentifier($temporaryPrefix . 'migration')
        . ' SELECT * FROM ' . presentationMigrationIdentifier($sourcePrefix . 'migration'),
    );
    classIdentityCreateNativeProjectionFixture($db, $prefixeTable, $temporaryBase);

    $temporary = new ClassIdentity\Schema($db, $temporaryBase, 'v16-disposable-migration');
    (new ReflectionMethod(ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($temporary);
    (new ReflectionMethod(ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($temporary);
    $temporary->migrate();
    $temporary->verifyCurrent();
    ++$assertions;

    $v16 = $db->query(
        'SELECT `migration_name`,`checksum` FROM ' . presentationMigrationIdentifier($temporaryPrefix . 'migration')
        . ' WHERE `version`=16 LIMIT 1',
    );
    $v16Row = $v16 instanceof mysqli_result ? $v16->fetch_assoc() : null;
    if ($v16 instanceof mysqli_result) {
        $v16->free();
    }
    $expectedV16 = hash('sha256', "16\0" . $migrations[16]['name'] . "\0" . $migrations[16]['signature'], true);
    $assert(is_array($v16Row)
        && ($v16Row['migration_name'] ?? null) === $migrations[16]['name']
        && is_string($v16Row['checksum'] ?? null)
        && hash_equals($expectedV16, (string) $v16Row['checksum']),
        'presentation_migration_v16_attestation_invalid');
    $assert(hash_equals(
        $expectedDigests['photo_source_presentation'],
        (string) $semanticMethod->invoke($temporary, 'photo_source_presentation'),
    ), 'presentation_migration_v16_semantic_digest_invalid');

    $temporary->migrate();
    $temporary->verifyCurrent();
    $countResult = $db->query(
        'SELECT COUNT(*) FROM ' . presentationMigrationIdentifier($temporaryPrefix . 'migration'),
    );
    $count = $countResult instanceof mysqli_result ? (int) ($countResult->fetch_row()[0] ?? -1) : -1;
    if ($countResult instanceof mysqli_result) {
        $countResult->free();
    }
    $assert($count === 16, 'presentation_migration_not_idempotent');

    $ownerCountResult = $db->query(
        'SELECT COUNT(*),COALESCE(MAX(`version`),0) FROM ' . presentationMigrationIdentifier($sourcePrefix . 'migration'),
    );
    $ownerCount = $ownerCountResult instanceof mysqli_result ? $ownerCountResult->fetch_row() : null;
    if ($ownerCountResult instanceof mysqli_result) {
        $ownerCountResult->free();
    }
    $assert(is_array($ownerCount) && (int) ($ownerCount[0] ?? -1) === 15 && (int) ($ownerCount[1] ?? -1) === 15,
        'presentation_migration_owner_mutated');

    $success = 'PRIVATE_SOURCE_PRESENTATION_MIGRATION=PASS assertions=' . $assertions
        . ' source_schema=15 target_schema=16 owner_mutations=0';
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_SOURCE_PRESENTATION_MIGRATION=FAIL reason='
        . preg_replace('/[^a-z0-9_.-]/', '_', strtolower($error->getMessage()))
        . ' assertions=' . $assertions . "\n");
    $exitCode = 1;
} finally {
    // Query the complete random namespace so an implicit DDL commit or an
    // exception during fixture construction cannot hide an untracked table.
    // FK checks are disabled on this connection only while dropping the
    // verified disposable namespace; the v15 owner prefix can never match it.
    $like = $db->real_escape_string($temporaryBase) . '%';
    $inventory = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'"
    );
    $cleanupTables = $inventory instanceof mysqli_result ? $inventory->fetch_all(MYSQLI_NUM) : [];
    if ($inventory instanceof mysqli_result) {
        $inventory->free();
    }
    if (!$db->query('SET FOREIGN_KEY_CHECKS=0')) {
        $exitCode = 1;
    } else {
        try {
            foreach ($cleanupTables as $row) {
                $table = (string) ($row[0] ?? '');
                if (!str_starts_with($table, $temporaryBase)
                    || preg_match('/\Aci_v16_[0-9a-f]{12}_[A-Za-z0-9_]+\z/D', $table) !== 1
                    || !$db->query('DROP TABLE IF EXISTS ' . presentationMigrationIdentifier($table))
                ) {
                    $exitCode = 1;
                }
            }
        } finally {
            if (!$db->query('SET FOREIGN_KEY_CHECKS=1')) {
                $exitCode = 1;
            }
        }
    }
    $remaining = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'"
    );
    $remainingCount = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'PRIVATE_SOURCE_PRESENTATION_MIGRATION_CLEANUP=FAIL remaining=' . $remainingCount . "\n");
        $exitCode = 1;
    }
    $db->close();
}

if ($exitCode === 0 && is_string($success)) {
    fwrite(STDOUT, $success . " cleanup=PASS\n");
}
exit($exitCode);
