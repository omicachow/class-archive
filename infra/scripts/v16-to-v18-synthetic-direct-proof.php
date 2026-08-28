<?php

declare(strict_types=1);

/**
 * Isolated synthetic proof for the current V18 source migrating an exact V16
 * ClassIdentity ledger directly to V18.
 *
 * This is deliberately separate from v18-synthetic-migration-proof.php.  It
 * never stages, includes, or installs a historical V17 Schema implementation:
 * one current checked-out Schema::migrate() call must apply migrations 17 and
 * 18 in ledger order.  It is safe only in a restored synthetic V16 lab; it
 * neither reads nor writes media, private runtime state, or an Owner database.
 */

const V16_TO_V18_DIRECT_PROOF_ROOT = '/var/www/html/piwigo';
const V16_TO_V18_DIRECT_CURRENT_SCHEMA = '/workspace/plugins/ClassIdentity/src/Schema.php';

/** @var list<string> */
const V16_TO_V18_DIRECT_NEW_TABLE_SUFFIXES = [
    'collection_snapshot',
    'collection_snapshot_item',
    'collection_snapshot_pointer',
    'collection_pin',
    'collection_feedback',
    'collection_maintenance_state',
    'spotlight_rotation_state',
];

/** @var array<int, array{name:string,signature:string}> */
const V16_TO_V18_DIRECT_EXPECTED_MIGRATIONS = [
    17 => [
        'name' => '0017_photos_app_v4_collection_snapshots',
        'signature' => 'v1:versioned-role-scoped-collection-snapshots:atomic-active-pointer:retained-superseded-history:principal-pins-feedback:maintenance-watermark:innodb:utf8mb4',
    ],
    18 => [
        'name' => '0018_photos_app_v4_spotlight_rotation_state',
        'signature' => 'v1:bounded-policy-scoped-hero:opaque-spotlight-fk:candidate-digest:deterministic-next-after-previous:monotonic-display-count:revisioned-schedule:innodb:utf8mb4',
    ],
];

function v16ToV18DirectFail(string $code): never
{
    fwrite(STDERR, 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=FAIL code='
        . preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) . "\n");
    exit(1);
}

function v16ToV18DirectRequireScope(): void
{
    if (PHP_SAPI !== 'cli'
        || getenv('CLASS_ARCHIVE_V16_TO_V18_DIRECT_PROOF') !== '1'
        || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION') {
        throw new RuntimeException('scope_confirmation_missing');
    }
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        throw new RuntimeException('posix_required');
    }
    $account = posix_getpwuid(posix_geteuid());
    if (posix_geteuid() === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        throw new RuntimeException('nginx_user_required');
    }
}

function v16ToV18DirectIdentifier(string $value): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $value) !== 1) {
        throw new RuntimeException('identifier_invalid');
    }
    return '`' . $value . '`';
}

/** @return array{0:mysqli,1:string} */
function v16ToV18DirectDatabase(): array
{
    if (realpath(V16_TO_V18_DIRECT_PROOF_ROOT) !== V16_TO_V18_DIRECT_PROOF_ROOT
        || is_link(V16_TO_V18_DIRECT_PROOF_ROOT)) {
        throw new RuntimeException('piwigo_root_untrusted');
    }
    $config = V16_TO_V18_DIRECT_PROOF_ROOT . '/local/config/database.inc.php';
    if (!is_file($config) || is_link($config)) {
        throw new RuntimeException('piwigo_database_config_untrusted');
    }
    $conf = [];
    $prefixeTable = null;
    require $config;
    if (!is_array($conf) || $prefixeTable !== 'piwigo_') {
        throw new RuntimeException('synthetic_database_prefix_invalid');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if (!$db->set_charset('utf8mb4')) {
        throw new RuntimeException('database_utf8mb4_required');
    }
    return [$db, $prefixeTable];
}

function v16ToV18DirectLoadCurrentSchema(): void
{
    if (!defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', V16_TO_V18_DIRECT_PROOF_ROOT . '/');
    }
    if (!is_file(V16_TO_V18_DIRECT_CURRENT_SCHEMA) || is_link(V16_TO_V18_DIRECT_CURRENT_SCHEMA)) {
        throw new RuntimeException('current_schema_missing');
    }
    require V16_TO_V18_DIRECT_CURRENT_SCHEMA;
    if (!class_exists('ClassIdentity\\Schema') || \ClassIdentity\Schema::CURRENT_VERSION !== 18) {
        throw new RuntimeException('current_schema_version_invalid');
    }
}

/** @return list<array<string,string>> */
function v16ToV18DirectLedgerRows(mysqli $db, string $ledger): array
{
    $result = $db->query(
        'SELECT `version`,`migration_name`,HEX(`checksum`) AS `checksum`,`plugin_version`,'
        . "DATE_FORMAT(`applied_at`,'%Y-%m-%d %H:%i:%s.%f') AS `applied_at` "
        . 'FROM ' . v16ToV18DirectIdentifier($ledger) . ' ORDER BY `version`'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn(mixed $value): string => (string) $value, $row);
    }
    $result->free();
    return $rows;
}

/** @param list<array<string,string>> $rows */
function v16ToV18DirectAssertExactLedger(array $rows, int $expectedVersion): void
{
    if (count($rows) !== $expectedVersion) {
        throw new RuntimeException('ledger_count_invalid');
    }
    foreach ($rows as $offset => $row) {
        if ((int) ($row['version'] ?? 0) !== $offset + 1
            || !preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['checksum'] ?? ''))
            || (string) ($row['migration_name'] ?? '') === '') {
            throw new RuntimeException('ledger_shape_invalid');
        }
    }
}

function v16ToV18DirectExpectedChecksum(int $version): string
{
    $migration = V16_TO_V18_DIRECT_EXPECTED_MIGRATIONS[$version] ?? null;
    if (!is_array($migration)) {
        throw new RuntimeException('expected_migration_missing');
    }
    return strtoupper(hash('sha256', $version . "\0" . $migration['name'] . "\0" . $migration['signature']));
}

/** @param list<array<string,string>> $rows */
function v16ToV18DirectAssertTargetLedger(array $rows, array $sourceRows): void
{
    v16ToV18DirectAssertExactLedger($rows, 18);
    if (array_slice($rows, 0, 16) !== $sourceRows) {
        throw new RuntimeException('source_ledger_not_preserved');
    }
    foreach (V16_TO_V18_DIRECT_EXPECTED_MIGRATIONS as $version => $expected) {
        $row = $rows[$version - 1] ?? null;
        if (!is_array($row)
            || (int) ($row['version'] ?? 0) !== $version
            || ($row['migration_name'] ?? '') !== $expected['name']
            || !hash_equals(v16ToV18DirectExpectedChecksum($version), (string) ($row['checksum'] ?? ''))) {
            throw new RuntimeException('target_ledger_migration_invalid_' . $version);
        }
    }
}

/** @return list<string> */
function v16ToV18DirectPrefixedBaseTables(mysqli $db, string $prefix): array
{
    $result = $db->query('SHOW FULL TABLES');
    $tables = [];
    while ($row = $result->fetch_assoc()) {
        $values = array_values($row);
        $name = is_string($values[0] ?? null) ? $values[0] : '';
        $type = is_string($values[1] ?? null) ? strtoupper($values[1]) : '';
        if ($type === 'BASE TABLE' && str_starts_with($name, $prefix)
            && preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) === 1) {
            $tables[] = $name;
        }
    }
    $result->free();
    sort($tables, SORT_STRING);
    if ($tables === []) {
        throw new RuntimeException('prefixed_tables_missing');
    }
    return $tables;
}

/** @return array{fingerprint:string,tables:list<string>} */
function v16ToV18DirectLegacyFingerprint(mysqli $db, array $tables, string $ledger): array
{
    $records = [];
    foreach ($tables as $table) {
        if ($table === $ledger) {
            continue;
        }
        $quoted = v16ToV18DirectIdentifier($table);
        $createResult = $db->query('SHOW CREATE TABLE ' . $quoted);
        $createRow = $createResult->fetch_assoc();
        $createResult->free();
        $createValues = is_array($createRow) ? array_values($createRow) : [];
        $create = is_string($createValues[1] ?? null) ? $createValues[1] : '';
        if ($create === '') {
            throw new RuntimeException('legacy_schema_unavailable');
        }
        $countResult = $db->query('SELECT COUNT(*) AS `count` FROM ' . $quoted);
        $countRow = $countResult->fetch_assoc();
        $countResult->free();
        $checksumResult = $db->query('CHECKSUM TABLE ' . $quoted . ' EXTENDED');
        $checksumRow = $checksumResult->fetch_assoc();
        $checksumResult->free();
        if (!is_array($countRow) || !is_numeric($countRow['count'] ?? null)
            || !is_array($checksumRow) || !is_numeric($checksumRow['Checksum'] ?? null)) {
            throw new RuntimeException('legacy_table_fingerprint_unavailable');
        }
        $records[$table] = [
            'schema' => hash('sha256', $create),
            'count' => (int) $countRow['count'],
            'checksum' => (string) $checksumRow['Checksum'],
        ];
    }
    ksort($records, SORT_STRING);
    return [
        'fingerprint' => hash('sha256', json_encode($records, JSON_THROW_ON_ERROR)),
        'tables' => array_keys($records),
    ];
}

/** @return list<string> */
function v16ToV18DirectNewTableNames(\ClassIdentity\Schema $schema): array
{
    $tables = [];
    foreach (V16_TO_V18_DIRECT_NEW_TABLE_SUFFIXES as $suffix) {
        $tables[] = $schema->table($suffix);
    }
    sort($tables, SORT_STRING);
    return $tables;
}

/** @param list<string> $tables */
function v16ToV18DirectAssertTablesAbsent(mysqli $db, string $prefix, array $tables): void
{
    $existing = v16ToV18DirectPrefixedBaseTables($db, $prefix);
    foreach ($tables as $table) {
        if (in_array($table, $existing, true)) {
            throw new RuntimeException('target_table_present_before_migration');
        }
    }
}

/** @param list<string> $tables */
function v16ToV18DirectAssertNewTablesEmpty(mysqli $db, array $tables): void
{
    foreach ($tables as $table) {
        $result = $db->query('SELECT COUNT(*) AS `count` FROM ' . v16ToV18DirectIdentifier($table));
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row) || (int) ($row['count'] ?? -1) !== 0) {
            throw new RuntimeException('new_table_not_empty');
        }
    }
}

function v16ToV18DirectAssertV18Constraint(mysqli $db, \ClassIdentity\Schema $schema): void
{
    $rotation = v16ToV18DirectIdentifier($schema->table('spotlight_rotation_state'));
    $accepted = false;
    try {
        $accepted = $db->query(
            "INSERT INTO {$rotation} (`scope`,`candidate_digest`,`next_rotation_at`,`revision`) "
            . "VALUES ('INVALID',UNHEX(SHA2('invalid',256)),UTC_TIMESTAMP(6),UNHEX(SHA2('invalid',256)))"
        ) === true;
    } catch (mysqli_sql_exception) {
        $accepted = false;
    }
    if ($accepted) {
        $db->query("DELETE FROM {$rotation} WHERE `scope`='INVALID'");
        throw new RuntimeException('v18_scope_constraint_not_enforced');
    }
}

/** @param list<array<string,string>> $sourceLedger */
function v16ToV18DirectMigrate(mysqli $db, \ClassIdentity\Schema $schema, string $prefix, array $sourceLedger): array
{
    $ledger = $schema->table('migration');
    $beforeTables = v16ToV18DirectPrefixedBaseTables($db, $prefix);
    $legacyBefore = v16ToV18DirectLegacyFingerprint($db, $beforeTables, $ledger);
    $newTables = v16ToV18DirectNewTableNames($schema);
    v16ToV18DirectAssertTablesAbsent($db, $prefix, $newTables);

    $schema->migrate();
    $schema->verifyCurrent();

    $afterLedger = v16ToV18DirectLedgerRows($db, $ledger);
    v16ToV18DirectAssertTargetLedger($afterLedger, $sourceLedger);
    $afterTables = v16ToV18DirectPrefixedBaseTables($db, $prefix);
    $expectedTables = array_values(array_unique(array_merge($beforeTables, $newTables)));
    sort($expectedTables, SORT_STRING);
    if ($afterTables !== $expectedTables) {
        throw new RuntimeException('unexpected_table_set_after_migration');
    }
    // Compare only the pre-existing V16 table set.  The seven V17/V18 tables
    // are intentionally new and are asserted separately below.
    $legacyAfter = v16ToV18DirectLegacyFingerprint($db, $beforeTables, $ledger);
    if (!hash_equals($legacyBefore['fingerprint'], $legacyAfter['fingerprint'])
        || $legacyBefore['tables'] !== $legacyAfter['tables']) {
        throw new RuntimeException('legacy_tables_not_preserved');
    }
    v16ToV18DirectAssertNewTablesEmpty($db, $newTables);
    v16ToV18DirectAssertV18Constraint($db, $schema);
    return [
        'legacy_fingerprint' => $legacyAfter['fingerprint'],
        'new_table_count' => count($newTables),
    ];
}

function v16ToV18DirectAssertCurrent(mysqli $db, \ClassIdentity\Schema $schema, string $prefix): array
{
    $ledger = v16ToV18DirectLedgerRows($db, $schema->table('migration'));
    v16ToV18DirectAssertExactLedger($ledger, 18);
    $schema->verifyCurrent();
    $newTables = v16ToV18DirectNewTableNames($schema);
    $tables = v16ToV18DirectPrefixedBaseTables($db, $prefix);
    foreach ($newTables as $table) {
        if (!in_array($table, $tables, true)) {
            throw new RuntimeException('target_table_missing_after_migration');
        }
    }
    v16ToV18DirectAssertNewTablesEmpty($db, $newTables);
    v16ToV18DirectAssertV18Constraint($db, $schema);
    return v16ToV18DirectLegacyFingerprint(
        $db,
        array_values(array_diff($tables, $newTables)),
        $schema->table('migration'),
    );
}

function v16ToV18DirectFailClosed(mysqli $db, string $piwigoPrefix): void
{
    $scratchPrefix = $piwigoPrefix . 'v16v18fc_' . bin2hex(random_bytes(4)) . '_';
    $ledger = v16ToV18DirectIdentifier($scratchPrefix . 'class_identity_migration');
    try {
        $db->query(
            "CREATE TABLE {$ledger} (`version` SMALLINT UNSIGNED NOT NULL,`migration_name` VARCHAR(128) NOT NULL,"
            . "`checksum` BINARY(32) NOT NULL,`applied_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (`version`)) ENGINE=InnoDB"
        );
        $db->query("INSERT INTO {$ledger} (`version`,`migration_name`,`checksum`) VALUES (18,'unknown',UNHEX('" . str_repeat('0', 64) . "'))");
        $schema = new \ClassIdentity\Schema($db, $scratchPrefix, '0.1.0');
        try {
            $schema->verifyCurrent();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException('unknown_schema_not_fail_closed');
    } finally {
        $db->query('DROP TABLE IF EXISTS ' . $ledger);
    }
}

try {
    v16ToV18DirectRequireScope();
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['--migrate-current-source', '--verify-current-source', '--fail-closed'], true)) {
        throw new RuntimeException('mode_invalid');
    }
    v16ToV18DirectLoadCurrentSchema();
    [$db, $prefix] = v16ToV18DirectDatabase();
    try {
        $schema = new \ClassIdentity\Schema($db, $prefix, '0.1.0');
        $sourceVersion = v16ToV18DirectLedgerRows($db, $schema->table('migration'));
        $schemaVersion = count($sourceVersion);
        if ($mode === '--migrate-current-source') {
            if ($schemaVersion === 16) {
                v16ToV18DirectAssertExactLedger($sourceVersion, 16);
                $result = v16ToV18DirectMigrate($db, $schema, $prefix, $sourceVersion);
                fwrite(STDOUT, 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source'
                    . ' schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE'
                    . ' legacy_tables_preserved=PASS new_tables=EMPTY new_table_count=' . $result['new_table_count']
                    . ' legacy_fingerprint=' . $result['legacy_fingerprint'] . ' media=NOT_TOUCHED' . "\n");
            } elseif ($schemaVersion === 18) {
                $result = v16ToV18DirectAssertCurrent($db, $schema, $prefix);
                fwrite(STDOUT, 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source'
                    . ' schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS'
                    . ' new_tables=EMPTY legacy_fingerprint=' . $result['fingerprint'] . ' media=NOT_TOUCHED' . "\n");
            } else {
                throw new RuntimeException('migration_source_not_exact_v16_or_v18');
            }
        } elseif ($mode === '--verify-current-source') {
            if ($schemaVersion !== 18) {
                throw new RuntimeException('verify_source_not_v18');
            }
            $result = v16ToV18DirectAssertCurrent($db, $schema, $prefix);
            fwrite(STDOUT, 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=verify_current_source'
                . ' schema=18 ledger=18 new_tables=EMPTY legacy_fingerprint=' . $result['fingerprint']
                . ' media=NOT_TOUCHED' . "\n");
        } else {
            v16ToV18DirectFailClosed($db, $prefix);
            fwrite(STDOUT, "V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=fail_closed unknown_schema=DENY scratch=DISPOSED\n");
        }
    } finally {
        $db->close();
    }
} catch (Throwable $error) {
    v16ToV18DirectFail($error->getMessage());
}
