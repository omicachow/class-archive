<?php

declare(strict_types=1);

/**
 * Narrow, synthetic-only V17 -> V18 schema proof.
 *
 * This helper deliberately never loads both Schema implementations in the
 * same PHP process.  `--bootstrap-v17` loads one exact historical source file
 * staged by the fixed attempt8 runner; later modes load only the checked-out
 * V18 source.  The database contains the public 72-photo synthetic fixture
 * only, has no mounted media, and is never an Owner/private runtime.
 */

const V18_SYNTHETIC_PROOF_ROOT = '/var/www/html/piwigo';
const V18_SYNTHETIC_V17_SCHEMA = '/workspace/v18-synthetic-v17/Schema.php';
const V18_SYNTHETIC_V17_COMMIT = '52ff3a7ba91155efc7bed1572e2b1740973e484c';
const V18_SYNTHETIC_V17_SCHEMA_SHA256 = 'aee8ced818747a8f81c816ef5aef112005af280b694ef3bdf8f7ac453e6f7413';

/** @var list<string> */
const V18_SYNTHETIC_V17_TABLE_SUFFIXES = [
    'collection_snapshot',
    'collection_snapshot_item',
    'collection_snapshot_pointer',
    'collection_pin',
    'collection_feedback',
    'collection_maintenance_state',
];

function v18SyntheticProofFail(string $code): never
{
    fwrite(STDERR, 'V18_SYNTHETIC_PROOF=FAIL code='
        . preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) . "\n");
    exit(1);
}

function v18SyntheticProofRequireScope(): void
{
    if (PHP_SAPI !== 'cli' || getenv('CLASS_ARCHIVE_V18_SYNTHETIC_PROOF') !== '1'
        || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION') {
        v18SyntheticProofFail('scope_confirmation_missing');
    }
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        v18SyntheticProofFail('posix_required');
    }
    $account = posix_getpwuid(posix_geteuid());
    if (posix_geteuid() === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        v18SyntheticProofFail('nginx_user_required');
    }
}

function v18SyntheticProofIdentifier(string $value): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $value) !== 1) {
        throw new RuntimeException('identifier_invalid');
    }
    return '`' . $value . '`';
}

/** @return array{0: mysqli, 1: string} */
function v18SyntheticProofDatabase(): array
{
    $configuredHost = getenv('V18_SYNTHETIC_DB_HOST');
    if (is_string($configuredHost) && $configuredHost !== '') {
        if ($configuredHost !== 'v18-synthetic-recovery-db') {
            throw new RuntimeException('recovery_db_host_invalid');
        }
        $name = (string) getenv('V18_SYNTHETIC_DB_NAME');
        $user = (string) getenv('V18_SYNTHETIC_DB_USER');
        $password = (string) getenv('V18_SYNTHETIC_DB_PASSWORD');
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1
            || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $user) !== 1
            || strlen($password) < 32) {
            throw new RuntimeException('recovery_db_configuration_invalid');
        }
        $db = @new mysqli($configuredHost, $user, $password, $name);
        if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
            throw new RuntimeException('recovery_database_unavailable');
        }
        return [$db, 'piwigo_'];
    }

    if (realpath(V18_SYNTHETIC_PROOF_ROOT) !== V18_SYNTHETIC_PROOF_ROOT
        || is_link(V18_SYNTHETIC_PROOF_ROOT)) {
        throw new RuntimeException('piwigo_root_untrusted');
    }
    $config = V18_SYNTHETIC_PROOF_ROOT . '/local/config/database.inc.php';
    if (!is_file($config) || is_link($config)) {
        throw new RuntimeException('piwigo_database_config_untrusted');
    }
    $conf = [];
    $prefixeTable = null;
    require $config;
    if (!is_array($conf) || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('piwigo_database_config_invalid');
    }
    $db = @new mysqli((string) ($conf['db_host'] ?? ''), (string) ($conf['db_user'] ?? ''), (string) ($conf['db_password'] ?? ''), (string) ($conf['db_base'] ?? ''));
    if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
        throw new RuntimeException('database_unavailable');
    }
    return [$db, $prefixeTable];
}

function v18SyntheticProofDefinePiwigoRoot(): void
{
    if (!defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', V18_SYNTHETIC_PROOF_ROOT . '/');
    }
}

function v18SyntheticProofLoadHistoricalSchema(): void
{
    v18SyntheticProofDefinePiwigoRoot();
    if (!is_file(V18_SYNTHETIC_V17_SCHEMA) || is_link(V18_SYNTHETIC_V17_SCHEMA)) {
        throw new RuntimeException('historical_schema_missing');
    }
    $digest = hash_file('sha256', V18_SYNTHETIC_V17_SCHEMA);
    if (!is_string($digest) || !hash_equals(V18_SYNTHETIC_V17_SCHEMA_SHA256, $digest)) {
        throw new RuntimeException('historical_schema_hash_invalid');
    }
    require V18_SYNTHETIC_V17_SCHEMA;
    if (!class_exists('ClassIdentity\\Schema') || \ClassIdentity\Schema::CURRENT_VERSION !== 17) {
        throw new RuntimeException('historical_schema_version_invalid');
    }
}

function v18SyntheticProofLoadCurrentSchema(): void
{
    v18SyntheticProofDefinePiwigoRoot();
    $source = '/workspace/plugins/ClassIdentity/src/Schema.php';
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException('current_schema_missing');
    }
    require $source;
    if (!class_exists('ClassIdentity\\Schema') || \ClassIdentity\Schema::CURRENT_VERSION !== 18) {
        throw new RuntimeException('current_schema_version_invalid');
    }
}

function v18SyntheticProofSchemaVersion(mysqli $db, string $piwigoPrefix): int
{
    $ledger = v18SyntheticProofIdentifier($piwigoPrefix . 'class_identity_migration');
    $row = $db->query('SELECT COALESCE(MAX(`version`),0) AS `version` FROM ' . $ledger);
    $record = $row instanceof mysqli_result ? $row->fetch_assoc() : null;
    if ($row instanceof mysqli_result) {
        $row->free();
    }
    if (!is_array($record) || !isset($record['version']) || !is_numeric($record['version'])) {
        throw new RuntimeException('schema_version_unavailable');
    }
    return (int) $record['version'];
}

function v18SyntheticProofV17LedgerFingerprint(mysqli $db, \ClassIdentity\Schema $schema): string
{
    $ledger = v18SyntheticProofIdentifier($schema->table('migration'));
    $result = $db->query('SELECT `version`,`migration_name`,HEX(`checksum`) AS `checksum`,DATE_FORMAT(`applied_at`,\'%Y-%m-%d %H:%i:%s.%f\') AS `applied_at` FROM ' . $ledger . ' WHERE `version` <= 17 ORDER BY `version`');
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('v17_ledger_unavailable');
    }
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $result->free();
    if (count($records) !== 17 || (int) ($records[16]['version'] ?? 0) !== 17) {
        throw new RuntimeException('v17_ledger_incomplete');
    }
    return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
}

/** @return array{fingerprint: string, counts: array<string, int>} */
function v18SyntheticProofFingerprint(mysqli $db, \ClassIdentity\Schema $schema, bool $includeV18): array
{
    $suffixes = V18_SYNTHETIC_V17_TABLE_SUFFIXES;
    if ($includeV18) {
        $suffixes[] = 'spotlight_rotation_state';
    }
    $records = [];
    foreach ($suffixes as $suffix) {
        $table = $schema->table($suffix);
        $quoted = v18SyntheticProofIdentifier($table);
        $row = $db->query('SHOW CREATE TABLE ' . $quoted);
        if (!$row instanceof mysqli_result || !($create = $row->fetch_assoc()) || !isset($create['Create Table'])) {
            throw new RuntimeException('fingerprint_schema_unavailable_' . $suffix);
        }
        $row->free();
        $countRow = $db->query('SELECT COUNT(*) AS `count` FROM ' . $quoted);
        if (!$countRow instanceof mysqli_result || !($count = $countRow->fetch_assoc()) || !isset($count['count'])) {
            throw new RuntimeException('fingerprint_count_unavailable_' . $suffix);
        }
        $countRow->free();
        $checksumRow = $db->query('CHECKSUM TABLE ' . $quoted . ' EXTENDED');
        if (!$checksumRow instanceof mysqli_result || !($checksum = $checksumRow->fetch_assoc()) || !array_key_exists('Checksum', $checksum) || !is_numeric($checksum['Checksum'])) {
            throw new RuntimeException('fingerprint_checksum_unavailable_' . $suffix);
        }
        $checksumRow->free();
        $records[$suffix] = [
            'count' => (int) $count['count'],
            'schema' => hash('sha256', (string) $create['Create Table']),
            'checksum' => (string) $checksum['Checksum'],
        ];
    }
    ksort($records, SORT_STRING);
    return [
        'fingerprint' => hash('sha256', json_encode($records, JSON_THROW_ON_ERROR)),
        'counts' => array_map(static fn(array $record): int => $record['count'], $records),
    ];
}

/**
 * The V18 scheduler row is deliberately mutable operational state.  The
 * migration proof must establish that a fresh V17 -> V18 transition starts
 * with no scheduler rows, but a later read-only verification must remain
 * valid after the lifecycle runner has created bounded FULL/HERITAGE
 * checkpoints.  Keep the durable migration fingerprint independent from
 * those runtime values while still checking their shape fail-closed.
 */
function v18SyntheticProofV18MigrationFingerprint(mysqli $db, \ClassIdentity\Schema $schema): string
{
    $rotation = v18SyntheticProofIdentifier($schema->table('spotlight_rotation_state'));
    $createResult = $db->query('SHOW CREATE TABLE ' . $rotation);
    $create = $createResult instanceof mysqli_result ? $createResult->fetch_assoc() : null;
    if ($createResult instanceof mysqli_result) {
        $createResult->free();
    }
    $ledger = v18SyntheticProofIdentifier($schema->table('migration'));
    $ledgerResult = $db->query('SELECT `version`,`migration_name`,HEX(`checksum`) AS `checksum` FROM ' . $ledger . ' WHERE `version`=18');
    $ledgerRecord = $ledgerResult instanceof mysqli_result ? $ledgerResult->fetch_assoc() : null;
    if ($ledgerResult instanceof mysqli_result) {
        $ledgerResult->free();
    }
    if (!is_array($create) || !isset($create['Create Table']) || !is_array($ledgerRecord)
        || (int) ($ledgerRecord['version'] ?? 0) !== 18
        || !is_string($ledgerRecord['migration_name'] ?? null)
        || !preg_match('/\A[0-9A-F]{64}\z/D', (string) ($ledgerRecord['checksum'] ?? ''))) {
        throw new RuntimeException('v18_migration_fingerprint_unavailable');
    }
    return hash('sha256', json_encode([
        'rotation_schema' => hash('sha256', (string) $create['Create Table']),
        'migration' => $ledgerRecord,
    ], JSON_THROW_ON_ERROR));
}

/** @return array{count:int,state:string} */
function v18SyntheticProofRotationState(mysqli $db, \ClassIdentity\Schema $schema, bool $requireEmpty): array
{
    $rotation = v18SyntheticProofIdentifier($schema->table('spotlight_rotation_state'));
    $result = $db->query('SELECT `scope`,OCTET_LENGTH(`candidate_digest`) AS `candidate_bytes`,OCTET_LENGTH(`revision`) AS `revision_bytes`,`display_count`,`last_rotated_at`,`next_rotation_at` FROM ' . $rotation . ' ORDER BY `scope`');
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('v18_rotation_state_unavailable');
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    if ($requireEmpty && $rows !== []) {
        throw new RuntimeException('v18_rotation_state_not_empty');
    }
    if (count($rows) > 2) {
        throw new RuntimeException('v18_rotation_state_row_count_invalid');
    }
    $seen = [];
    foreach ($rows as $row) {
        $scope = (string) ($row['scope'] ?? '');
        if (!in_array($scope, ['FULL', 'HERITAGE'], true) || isset($seen[$scope])
            || (int) ($row['candidate_bytes'] ?? 0) !== 32
            || (int) ($row['revision_bytes'] ?? 0) !== 32
            || !is_numeric($row['display_count'] ?? null)
            || (int) $row['display_count'] < 0
            || !is_string($row['next_rotation_at'] ?? null) || $row['next_rotation_at'] === '') {
            throw new RuntimeException('v18_rotation_state_invalid');
        }
        $seen[$scope] = true;
    }
    return ['count' => count($rows), 'state' => $rows === [] ? 'EMPTY' : 'OPERATIONAL'];
}

/**
 * Snapshot publication is operationally mutable: the seeded V17 snapshots
 * can correctly become SUPERSEDED once a lifecycle run atomically publishes
 * a newer bundle.  Anchor the migration proof to immutable seed identity,
 * input/payload digest, and item content instead of mutable state, pointer,
 * timestamp, or maintenance watermark rows.
 */
function v18SyntheticProofV17SeedAnchorFingerprint(mysqli $db, \ClassIdentity\Schema $schema): string
{
    $snapshot = v18SyntheticProofIdentifier($schema->table('collection_snapshot'));
    $item = v18SyntheticProofIdentifier($schema->table('collection_snapshot_item'));
    $records = [];
    foreach (['FULL', 'HERITAGE'] as $scope) {
        foreach (['HOME', 'MEMORY', 'SPOTLIGHT', 'SEARCH_SUGGESTION'] as $kind) {
            $seed = 'CLASS_ARCHIVE_V18_SYNTHETIC_V17_SEED:' . $scope . ':' . $kind;
            $snapshotId = substr(hash('sha256', $seed), 0, 32);
            $inputRevision = strtoupper(hash('sha256', $seed . ':revision'));
            $payload = json_encode(['synthetic' => true, 'scope' => $scope, 'kind' => $kind], JSON_THROW_ON_ERROR);
            $payloadDigest = strtoupper(hash('sha256', $payload));
            $statement = $db->prepare('SELECT HEX(`snapshot_id`) AS `snapshot_id`,`scope`,`projection_kind`,HEX(`input_revision`) AS `input_revision`,HEX(`payload_digest`) AS `payload_digest`,`item_count` FROM ' . $snapshot . ' WHERE `snapshot_id`=UNHEX(?)');
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('v17_seed_snapshot_prepare_failed');
            }
            $statement->bind_param('s', $snapshotId);
            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException('v17_seed_snapshot_execute_failed');
            }
            $result = $statement->get_result();
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            $statement->close();
            if (!is_array($row) || strtoupper((string) ($row['snapshot_id'] ?? '')) !== strtoupper($snapshotId)
                || (string) ($row['scope'] ?? '') !== $scope || (string) ($row['projection_kind'] ?? '') !== $kind
                || strtoupper((string) ($row['input_revision'] ?? '')) !== $inputRevision
                || strtoupper((string) ($row['payload_digest'] ?? '')) !== $payloadDigest
                || (int) ($row['item_count'] ?? -1) !== 1) {
                throw new RuntimeException('v17_seed_snapshot_anchor_invalid');
            }
            $statement = $db->prepare('SELECT `ordinal`,`item_kind`,`item_key`,HEX(`cover_class_photo_id`) AS `cover_photo_id`,`photo_ids_json`,HEX(`payload_digest`) AS `payload_digest` FROM ' . $item . ' WHERE `snapshot_id`=UNHEX(?) ORDER BY `ordinal`');
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('v17_seed_item_prepare_failed');
            }
            $statement->bind_param('s', $snapshotId);
            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException('v17_seed_item_execute_failed');
            }
            $result = $statement->get_result();
            $items = [];
            if ($result instanceof mysqli_result) {
                while ($itemRow = $result->fetch_assoc()) {
                    $items[] = $itemRow;
                }
                $result->free();
            }
            $statement->close();
            $itemRow = $items[0] ?? null;
            $photoIds = is_array($itemRow) ? json_decode((string) ($itemRow['photo_ids_json'] ?? ''), true, 8, JSON_THROW_ON_ERROR) : null;
            $cover = strtoupper((string) ($itemRow['cover_photo_id'] ?? ''));
            if (count($items) !== 1 || !is_array($itemRow) || (int) ($itemRow['ordinal'] ?? -1) !== 0
                || (string) ($itemRow['item_kind'] ?? '') !== 'PHOTO'
                || (string) ($itemRow['item_key'] ?? '') !== strtolower($scope . '-' . $kind)
                || strtoupper((string) ($itemRow['payload_digest'] ?? '')) !== $payloadDigest
                || !is_array($photoIds) || count($photoIds) !== 1
                || !is_string($photoIds[0] ?? null) || !preg_match('/\A[0-9A-F]{32}\z/D', strtoupper($photoIds[0]))
                || $cover !== strtoupper($photoIds[0])) {
                throw new RuntimeException('v17_seed_item_anchor_invalid');
            }
            $records[] = [
                'snapshot_id' => strtoupper($snapshotId),
                'scope' => $scope,
                'kind' => $kind,
                'input_revision' => $inputRevision,
                'payload_digest' => $payloadDigest,
                'cover_photo_id' => $cover,
            ];
        }
    }
    return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
}

function v18SyntheticProofAssertV17Seed(mysqli $db, \ClassIdentity\Schema $schema): void
{
    $photo = v18SyntheticProofIdentifier($schema->table('photo'));
    $active = $db->query("SELECT HEX(`class_photo_id`) AS `photo_id` FROM {$photo} WHERE `state`='ACTIVE' ORDER BY `class_photo_id` LIMIT 1");
    if (!$active instanceof mysqli_result || !($photoRow = $active->fetch_assoc()) || !preg_match('/\A[0-9A-F]{32}\z/D', (string) ($photoRow['photo_id'] ?? ''))) {
        throw new RuntimeException('synthetic_active_photo_missing');
    }
    $active->free();
    $photoId = (string) $photoRow['photo_id'];
    $snapshot = v18SyntheticProofIdentifier($schema->table('collection_snapshot'));
    $item = v18SyntheticProofIdentifier($schema->table('collection_snapshot_item'));
    $pointer = v18SyntheticProofIdentifier($schema->table('collection_snapshot_pointer'));
    $maintenance = v18SyntheticProofIdentifier($schema->table('collection_maintenance_state'));

    $scopes = ['FULL', 'HERITAGE'];
    $kinds = ['HOME', 'MEMORY', 'SPOTLIGHT', 'SEARCH_SUGGESTION'];
    $db->begin_transaction();
    try {
        foreach ($scopes as $scope) {
            foreach ($kinds as $ordinal => $kind) {
                $seed = 'CLASS_ARCHIVE_V18_SYNTHETIC_V17_SEED:' . $scope . ':' . $kind;
                $snapshotId = substr(hash('sha256', $seed), 0, 32);
                $revision = hash('sha256', $seed . ':revision');
                $payload = json_encode(['synthetic' => true, 'scope' => $scope, 'kind' => $kind], JSON_THROW_ON_ERROR);
                $payloadDigest = hash('sha256', $payload);
                $statement = $db->prepare("INSERT INTO {$snapshot} (`snapshot_id`,`scope`,`projection_kind`,`state`,`input_revision`,`payload_digest`,`item_count`,`published_at`) VALUES (UNHEX(?),?,'{$kind}','ACTIVE',UNHEX(?),UNHEX(?),1,UTC_TIMESTAMP(6))");
                if (!$statement instanceof mysqli_stmt) {
                    throw new RuntimeException('seed_snapshot_prepare_failed');
                }
                $statement->bind_param('ssss', $snapshotId, $scope, $revision, $payloadDigest);
                if (!$statement->execute()) {
                    throw new RuntimeException('seed_snapshot_insert_failed');
                }
                $statement->close();

                $itemKey = strtolower($scope . '-' . $kind);
                $photoIds = json_encode([$photoId], JSON_THROW_ON_ERROR);
                $statement = $db->prepare("INSERT INTO {$item} (`snapshot_id`,`ordinal`,`item_kind`,`item_key`,`cover_class_photo_id`,`photo_ids_json`,`payload_json`,`payload_digest`) VALUES (UNHEX(?),0,'PHOTO',?,UNHEX(?),?,?,UNHEX(?))");
                if (!$statement instanceof mysqli_stmt) {
                    throw new RuntimeException('seed_item_prepare_failed');
                }
                $statement->bind_param('ssssss', $snapshotId, $itemKey, $photoId, $photoIds, $payload, $payloadDigest);
                if (!$statement->execute()) {
                    throw new RuntimeException('seed_item_insert_failed');
                }
                $statement->close();

                $statement = $db->prepare("INSERT INTO {$pointer} (`scope`,`projection_kind`,`active_snapshot_id`,`active_revision`) VALUES (?,'{$kind}',UNHEX(?),UNHEX(?))");
                if (!$statement instanceof mysqli_stmt) {
                    throw new RuntimeException('seed_pointer_prepare_failed');
                }
                $statement->bind_param('sss', $scope, $snapshotId, $revision);
                if (!$statement->execute()) {
                    throw new RuntimeException('seed_pointer_insert_failed');
                }
                $statement->close();
            }
            $maintenanceKey = 'COLLECTION_SNAPSHOTS_' . $scope;
            $lastSnapshot = substr(hash('sha256', 'CLASS_ARCHIVE_V18_SYNTHETIC_V17_SEED:' . $scope . ':HOME'), 0, 32);
            $revision = hash('sha256', 'CLASS_ARCHIVE_V18_SYNTHETIC_V17_SEED:' . $scope . ':HOME:revision');
            $statement = $db->prepare("INSERT INTO {$maintenance} (`maintenance_key`,`state`,`last_input_revision`,`last_snapshot_id`,`started_at`,`completed_at`) VALUES (?,'COMPLETE',UNHEX(?),UNHEX(?),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
            if (!$statement instanceof mysqli_stmt) {
                throw new RuntimeException('seed_maintenance_prepare_failed');
            }
            $statement->bind_param('sss', $maintenanceKey, $revision, $lastSnapshot);
            if (!$statement->execute()) {
                throw new RuntimeException('seed_maintenance_insert_failed');
            }
            $statement->close();
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function v18SyntheticProofAssertControlledV17(mysqli $db, \ClassIdentity\Schema $schema): array
{
    $schema->verifyCurrent();
    $fingerprint = v18SyntheticProofFingerprint($db, $schema, false);
    if (($fingerprint['counts']['collection_snapshot'] ?? 0) !== 8
        || ($fingerprint['counts']['collection_snapshot_item'] ?? 0) !== 8
        || ($fingerprint['counts']['collection_snapshot_pointer'] ?? 0) !== 8
        || ($fingerprint['counts']['collection_maintenance_state'] ?? 0) !== 2) {
        throw new RuntimeException('v17_seed_counts_invalid');
    }
    $fingerprint['ledger_fingerprint'] = v18SyntheticProofV17LedgerFingerprint($db, $schema);
    $fingerprint['seed_fingerprint'] = v18SyntheticProofV17SeedAnchorFingerprint($db, $schema);
    return $fingerprint;
}

function v18SyntheticProofAssertV18State(mysqli $db, \ClassIdentity\Schema $schema, bool $requireEmptyRotation = false): array
{
    $schema->verifyCurrent();
    $fingerprint = v18SyntheticProofFingerprint($db, $schema, false);
    $rotationState = v18SyntheticProofRotationState($db, $schema, $requireEmptyRotation);
    $rotation = v18SyntheticProofIdentifier($schema->table('spotlight_rotation_state'));
    // The failed CHECK exercise proves the actual V18 table, rather than only
    // a source-level fixture, rejects an unrecognized policy scope.
    $invalidInsertAccepted = false;
    try {
        $invalidInsertAccepted = $db->query("INSERT INTO {$rotation} (`scope`,`candidate_digest`,`next_rotation_at`,`revision`) VALUES ('INVALID',UNHEX(SHA2('invalid',256)),UTC_TIMESTAMP(6),UNHEX(SHA2('invalid',256)))") !== false;
    } catch (mysqli_sql_exception) {
        // mysqli strict mode throws for the expected CHECK rejection.
        $invalidInsertAccepted = false;
    }
    if ($invalidInsertAccepted) {
        throw new RuntimeException('v18_scope_constraint_not_enforced');
    }
    $fingerprint['ledger_fingerprint'] = v18SyntheticProofV17LedgerFingerprint($db, $schema);
    $fingerprint['seed_fingerprint'] = v18SyntheticProofV17SeedAnchorFingerprint($db, $schema);
    $fingerprint['fingerprint'] = v18SyntheticProofV18MigrationFingerprint($db, $schema);
    $fingerprint['rotation_rows'] = $rotationState['count'];
    $fingerprint['rotation_state'] = $rotationState['state'];
    return $fingerprint;
}

function v18SyntheticProofFailClosed(mysqli $db, string $piwigoPrefix): void
{
    $scratchPrefix = $piwigoPrefix . 'v18fc_' . bin2hex(random_bytes(4)) . '_';
    $ledger = v18SyntheticProofIdentifier($scratchPrefix . 'class_identity_migration');
    try {
        if ($db->query("CREATE TABLE {$ledger} (`version` SMALLINT UNSIGNED NOT NULL,`migration_name` VARCHAR(128) NOT NULL,`checksum` BINARY(32) NOT NULL,`applied_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (`version`)) ENGINE=InnoDB") === false) {
            throw new RuntimeException('fail_closed_fixture_create_failed');
        }
        if ($db->query("INSERT INTO {$ledger} (`version`,`migration_name`,`checksum`) VALUES (18,'unknown',UNHEX('" . str_repeat('0', 64) . "'))") === false) {
            throw new RuntimeException('fail_closed_fixture_insert_failed');
        }
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
    v18SyntheticProofRequireScope();
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['--bootstrap-v17', '--verify-v17', '--migrate-v18', '--verify-v18', '--fail-closed'], true)) {
        throw new RuntimeException('mode_invalid');
    }
    [$db, $prefix] = v18SyntheticProofDatabase();
    try {
        if ($mode === '--bootstrap-v17') {
            v18SyntheticProofLoadHistoricalSchema();
            $schema = new \ClassIdentity\Schema($db, $prefix, '0.1.0');
            $sourceVersion = v18SyntheticProofSchemaVersion($db, $prefix);
            if ($sourceVersion === 16) {
                $schema->migrate();
                v18SyntheticProofAssertV17Seed($db, $schema);
                $bootstrapMode = 'MIGRATED';
            } elseif ($sourceVersion === 17) {
                // A runner interruption after a completed one-shot helper must
                // not reinsert synthetic rows. Reopen only the exact checked
                // historical V17 state and verify its controlled contents.
                $bootstrapMode = 'RESUMED_CONTROLLED';
            } else {
                throw new RuntimeException('historical_schema_source_not_v16_or_v17');
            }
            if (\ClassIdentity\Schema::CURRENT_VERSION !== 17) {
                throw new RuntimeException('historical_schema_target_invalid');
            }
            $fingerprint = v18SyntheticProofAssertControlledV17($db, $schema);
            fwrite(STDOUT, 'V18_SYNTHETIC_PROOF=PASS stage=bootstrap_v17 schema=17 v17_fingerprint='
                . $fingerprint['fingerprint'] . ' v17_ledger_fingerprint=' . $fingerprint['ledger_fingerprint'] . ' v17_seed_fingerprint=' . $fingerprint['seed_fingerprint'] . ' snapshots=8 pointers=8 items=8 maintenance=2 historical_commit=' . V18_SYNTHETIC_V17_COMMIT . ' bootstrap_mode=' . $bootstrapMode . "\n");
        } elseif ($mode === '--verify-v17') {
            v18SyntheticProofLoadHistoricalSchema();
            if (v18SyntheticProofSchemaVersion($db, $prefix) !== 17) {
                throw new RuntimeException('verify_v17_schema_not_17');
            }
            $schema = new \ClassIdentity\Schema($db, $prefix, '0.1.0');
            $fingerprint = v18SyntheticProofAssertControlledV17($db, $schema);
            fwrite(STDOUT, 'V18_SYNTHETIC_PROOF=PASS stage=verify_v17 schema=17 v17_fingerprint='
                . $fingerprint['fingerprint'] . ' v17_ledger_fingerprint=' . $fingerprint['ledger_fingerprint'] . ' v17_seed_fingerprint=' . $fingerprint['seed_fingerprint'] . ' snapshots=8 pointers=8 items=8 maintenance=2 historical_commit=' . V18_SYNTHETIC_V17_COMMIT . "\n");
        } elseif ($mode === '--migrate-v18') {
            v18SyntheticProofLoadCurrentSchema();
            $schema = new \ClassIdentity\Schema($db, $prefix, '0.1.0');
            $before = $db->query('SELECT COALESCE(MAX(`version`),0) AS `version` FROM ' . v18SyntheticProofIdentifier($schema->table('migration')));
            $beforeRow = $before instanceof mysqli_result ? $before->fetch_assoc() : null;
            if ($before instanceof mysqli_result) {
                $before->free();
            }
            if (!is_array($beforeRow) || !isset($beforeRow['version']) || !in_array((int) $beforeRow['version'], [17, 18], true)) {
                throw new RuntimeException('migration_source_not_v17');
            }
            $sourceVersion = (int) $beforeRow['version'];
            $v17 = v18SyntheticProofFingerprint($db, $schema, false);
            $schema->migrate();
            $v18 = v18SyntheticProofAssertV18State($db, $schema, $sourceVersion === 17);
            fwrite(STDOUT, 'V18_SYNTHETIC_PROOF=PASS stage=migrate_v18 schema_from=' . $sourceVersion
                . ' schema_to=18 replay=' . ($sourceVersion === 18 ? 'PASS' : 'NOT_APPLICABLE')
                . ' v17_fingerprint=' . $v17['fingerprint'] . ' v17_ledger_fingerprint=' . $v18['ledger_fingerprint'] . ' v17_seed_fingerprint=' . $v18['seed_fingerprint'] . ' v18_fingerprint=' . $v18['fingerprint'] . "\n");
        } elseif ($mode === '--verify-v18') {
            v18SyntheticProofLoadCurrentSchema();
            $schema = new \ClassIdentity\Schema($db, $prefix, '0.1.0');
            $v17 = v18SyntheticProofFingerprint($db, $schema, false);
            $v18 = v18SyntheticProofAssertV18State($db, $schema);
            fwrite(STDOUT, 'V18_SYNTHETIC_PROOF=PASS stage=verify_v18 schema=18 v17_fingerprint='
                . $v17['fingerprint'] . ' v17_ledger_fingerprint=' . $v18['ledger_fingerprint'] . ' v17_seed_fingerprint=' . $v18['seed_fingerprint'] . ' v18_fingerprint=' . $v18['fingerprint']
                . ' rotation_rows=' . $v18['rotation_rows'] . ' rotation_state=' . $v18['rotation_state'] . "\n");
        } else {
            v18SyntheticProofLoadCurrentSchema();
            v18SyntheticProofFailClosed($db, $prefix);
            fwrite(STDOUT, "V18_SYNTHETIC_PROOF=PASS stage=fail_closed unknown_schema=DENY scratch=DISPOSED\n");
        }
    } finally {
        $db->close();
    }
} catch (Throwable $error) {
    v18SyntheticProofFail($error->getMessage());
}
