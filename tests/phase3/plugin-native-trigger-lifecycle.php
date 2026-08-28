<?php

declare(strict_types=1);

/**
 * Real MariaDB lifecycle gate for plugin-owned triggers on native Piwigo
 * tables. All objects use a disposable random prefix and contain synthetic
 * rows only; the live Piwigo tables are never written.
 */

function triggerLifecycleFail(string $message): never
{
    throw new RuntimeException($message);
}

function triggerLifecycleIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        triggerLifecycleFail('trigger_lifecycle_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function triggerLifecycleExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        triggerLifecycleFail('trigger_lifecycle_query_failed_' . $db->errno . '_' . $db->error);
    }
}

function triggerLifecycleCount(mysqli $db, string $prefix): int
{
    $tables = array_map(
        static fn(string $suffix): string => "'" . $db->real_escape_string($prefix . $suffix) . "'",
        ['images', 'image_category', 'categories'],
    );
    $result = $db->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() "
            . 'AND EVENT_OBJECT_TABLE IN (' . implode(',', $tables) . ')',
    );
    if (!$result instanceof mysqli_result) {
        triggerLifecycleFail('trigger_lifecycle_count_unavailable');
    }
    try {
        return (int) ($result->fetch_row()[0] ?? -1);
    } finally {
        $result->free();
    }
}

/** @return array<string,array{state:string,reason:?string,generation:string}> */
function triggerLifecycleProjectionState(mysqli $db, string $table): array
{
    $result = $db->query(
        'SELECT `projection_key`,`state`,`invalidated_reason`,HEX(`generation`) AS `generation` '
            . 'FROM ' . triggerLifecycleIdentifier($table) . ' ORDER BY `projection_key`',
    );
    if (!$result instanceof mysqli_result) {
        triggerLifecycleFail('trigger_lifecycle_projection_unavailable');
    }
    try {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(string) $row['projection_key']] = [
                'state' => (string) $row['state'],
                'reason' => is_string($row['invalidated_reason'] ?? null) ? $row['invalidated_reason'] : null,
                'generation' => (string) $row['generation'],
            ];
        }
        return $rows;
    } finally {
        $result->free();
    }
}

function triggerLifecycleEpoch(mysqli $db, string $table): string
{
    $result = $db->query(
        'SELECT HEX(`generation`) FROM ' . triggerLifecycleIdentifier($table)
            . " WHERE `source_key`='PIWIGO_NATIVE'",
    );
    if (!$result instanceof mysqli_result) {
        triggerLifecycleFail('trigger_lifecycle_epoch_unavailable');
    }
    try {
        return (string) ($result->fetch_row()[0] ?? '');
    } finally {
        $result->free();
    }
}

function triggerLifecycleActivate(mysqli $db, string $projection, string $epoch): void
{
    triggerLifecycleExecute(
        $db,
        'UPDATE ' . triggerLifecycleIdentifier($projection)
            . " SET `state`='ACTIVE',`source_revision`=RANDOM_BYTES(32),`generation`=RANDOM_BYTES(16),"
            . "`native_source_generation`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN "
            . '(SELECT `generation` FROM ' . triggerLifecycleIdentifier($epoch)
            . " WHERE `source_key`='PIWIGO_NATIVE') ELSE NULL END,"
            . "`item_count`=0,`payload_json`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE JSON_OBJECT() END,"
            . "`payload_digest`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE RANDOM_BYTES(32) END,"
            . "`dependency_revision`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE RANDOM_BYTES(32) END,"
            . "`invalidated_reason`=NULL,`built_at`=UTC_TIMESTAMP(6),`invalidated_at`=NULL,"
            . '`updated_at`=UTC_TIMESTAMP(6)',
    );
    if ($db->affected_rows !== 6) {
        triggerLifecycleFail('trigger_lifecycle_activate_count_' . $db->affected_rows);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "PLUGIN_NATIVE_TRIGGER_LIFECYCLE=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "PLUGIN_NATIVE_TRIGGER_LIFECYCLE=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
define('PHPWG_VERSION', '16.4.0');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "PLUGIN_NATIVE_TRIGGER_LIFECYCLE=FAIL reason=piwigo_prefix_invalid\n");
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
    fwrite(STDERR, "PLUGIN_NATIVE_TRIGGER_LIFECYCLE=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_tl_' . $run . '_';
$classPrefix = $basePrefix . 'class_identity_';
$createdNative = [];
$assertions = 0;
$exit = 0;

try {
    ClassIdentity\Schema::assertLockedPiwigoRuntime('16.4.0');
    ++$assertions;
    foreach (['16.4.1', '16.5.0', ''] as $unsupported) {
        try {
            ClassIdentity\Schema::assertLockedPiwigoRuntime($unsupported);
            triggerLifecycleFail('trigger_lifecycle_unsupported_version_allowed');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'class_identity_unsupported_piwigo_runtime') {
                throw $error;
            }
        }
        ++$assertions;
    }

    $createdNative = classIdentityCreateNativeProjectionFixture($db, $prefixeTable, $basePrefix);
    $schema = new ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    foreach ([
        'migrationGatewayReadProjection',
        'migrationGatewayAggregateProjection',
        'migrationNativePiwigoProjectionGuard',
        'migrationDurableNativeSourceEpoch',
    ] as $method) {
        (new ReflectionMethod(ClassIdentity\Schema::class, $method))->invoke($schema);
    }

    $projection = $classPrefix . 'read_projection';
    $epoch = $classPrefix . 'native_source_epoch';
    $images = $basePrefix . 'images';
    if (triggerLifecycleCount($db, $basePrefix) !== 18) {
        triggerLifecycleFail('trigger_lifecycle_initial_count');
    }
    ++$assertions;

    triggerLifecycleActivate($db, $projection, $epoch);
    $epochBeforeRetire = triggerLifecycleEpoch($db, $epoch);
    $schema->retireNativeMutationProtection();
    if (triggerLifecycleCount($db, $basePrefix) !== 0) {
        triggerLifecycleFail('trigger_lifecycle_retire_count');
    }
    ++$assertions;
    $retired = triggerLifecycleProjectionState($db, $projection);
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'] as $kind) {
        if (($retired[$kind]['state'] ?? null) !== 'STALE'
            || ($retired[$kind]['reason'] ?? null) !== 'PLUGIN_LIFECYCLE_RETIRED'
        ) {
            triggerLifecycleFail('trigger_lifecycle_retired_projection_' . strtolower($kind));
        }
        ++$assertions;
    }
    $epochAfterRetire = triggerLifecycleEpoch($db, $epoch);
    if ($epochBeforeRetire === '' || $epochAfterRetire === '' || hash_equals($epochBeforeRetire, $epochAfterRetire)) {
        triggerLifecycleFail('trigger_lifecycle_retired_epoch_not_rotated');
    }
    ++$assertions;

    // Native writes while inactive are allowed, but all old read projections
    // remain unusable until activation rebuilds them.
    triggerLifecycleExecute(
        $db,
        'INSERT INTO ' . triggerLifecycleIdentifier($images)
            . " (`id`,`file`,`date_available`,`name`,`path`) VALUES "
            . "(1,'retired-fixture.jpg','2026-01-01 00:00:00','停用期间写入','upload/retired-fixture.jpg')",
    );
    ++$assertions;

    $epochBeforeActivation = triggerLifecycleEpoch($db, $epoch);
    $schema->prepareNativeMutationProtectionForActivation();
    if (triggerLifecycleCount($db, $basePrefix) !== 18) {
        triggerLifecycleFail('trigger_lifecycle_reinstall_count');
    }
    ++$assertions;
    $activated = triggerLifecycleProjectionState($db, $projection);
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'] as $kind) {
        if (($activated[$kind]['state'] ?? null) !== 'STALE'
            || ($activated[$kind]['reason'] ?? null) !== 'PLUGIN_LIFECYCLE_ACTIVATION'
        ) {
            triggerLifecycleFail('trigger_lifecycle_activation_projection_' . strtolower($kind));
        }
        ++$assertions;
    }
    $epochAfterActivation = triggerLifecycleEpoch($db, $epoch);
    if ($epochBeforeActivation === '' || $epochAfterActivation === ''
        || hash_equals($epochBeforeActivation, $epochAfterActivation)
    ) {
        triggerLifecycleFail('trigger_lifecycle_activation_epoch_not_rotated');
    }
    ++$assertions;

    triggerLifecycleActivate($db, $projection, $epoch);
    $epochBeforeMutation = triggerLifecycleEpoch($db, $epoch);
    triggerLifecycleExecute(
        $db,
        'UPDATE ' . triggerLifecycleIdentifier($images) . " SET `name`='重新激活后写入' WHERE `id`=1",
    );
    $mutated = triggerLifecycleProjectionState($db, $projection);
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES'] as $kind) {
        if (($mutated[$kind]['state'] ?? null) !== 'STALE'
            || ($mutated[$kind]['reason'] ?? null) !== 'NATIVE_PIWIGO_MUTATION'
        ) {
            triggerLifecycleFail('trigger_lifecycle_reinstalled_guard_' . strtolower($kind));
        }
        ++$assertions;
    }
    if (($mutated['SPOTLIGHT']['state'] ?? null) !== 'ACTIVE') {
        triggerLifecycleFail('trigger_lifecycle_spotlight_unexpected_invalidation');
    }
    ++$assertions;
    $epochAfterMutation = triggerLifecycleEpoch($db, $epoch);
    if ($epochBeforeMutation === '' || $epochAfterMutation === ''
        || hash_equals($epochBeforeMutation, $epochAfterMutation)
    ) {
        triggerLifecycleFail('trigger_lifecycle_reinstalled_epoch_guard');
    }
    ++$assertions;

    $schema->retireNativeMutationProtection();
    $schema->retireNativeMutationProtection();
    if (triggerLifecycleCount($db, $basePrefix) !== 0) {
        triggerLifecycleFail('trigger_lifecycle_idempotent_retire_count');
    }
    ++$assertions;
    $schema->prepareNativeMutationProtectionForActivation();
    if (triggerLifecycleCount($db, $basePrefix) !== 18) {
        triggerLifecycleFail('trigger_lifecycle_second_reinstall_count');
    }
    ++$assertions;

    // An interrupted installation can have incomplete singleton/projection
    // rows. Retirement must still remove every plugin-owned trigger rather
    // than leave native Piwigo writes permanently blocked.
    triggerLifecycleExecute(
        $db,
        'DELETE FROM ' . triggerLifecycleIdentifier($projection) . " WHERE `projection_key`='SPOTLIGHT'",
    );
    triggerLifecycleExecute(
        $db,
        'DELETE FROM ' . triggerLifecycleIdentifier($epoch) . " WHERE `source_key`='PIWIGO_NATIVE'",
    );
    $schema->retireNativeMutationProtection();
    if (triggerLifecycleCount($db, $basePrefix) !== 0) {
        triggerLifecycleFail('trigger_lifecycle_partial_retire_count');
    }
    ++$assertions;
    foreach (triggerLifecycleProjectionState($db, $projection) as $state) {
        if ($state['state'] !== 'STALE' || $state['reason'] !== 'PLUGIN_LIFECYCLE_RETIRED') {
            triggerLifecycleFail('trigger_lifecycle_partial_retire_projection');
        }
    }
    ++$assertions;

    $maintain = file_get_contents($root . '/plugins/ClassIdentity/maintain.class.php');
    if (!is_string($maintain)
        || !str_contains($maintain, 'prepareNativeMutationProtectionForActivation')
        || substr_count($maintain, 'retireNativeMutationProtection();') < 3
        || !str_contains($maintain, 'fromPiwigoForRetirement')
    ) {
        triggerLifecycleFail('trigger_lifecycle_maintain_hooks_missing');
    }
    ++$assertions;

    fwrite(STDOUT, 'PLUGIN_NATIVE_TRIGGER_LIFECYCLE=PASS assertions=' . $assertions
        . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'PLUGIN_NATIVE_TRIGGER_LIFECYCLE=FAIL run=' . $run
        . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach (['read_photo', 'read_projection', 'native_source_epoch'] as $suffix) {
        $db->query('DROP TABLE IF EXISTS ' . triggerLifecycleIdentifier($classPrefix . $suffix));
    }
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'PLUGIN_NATIVE_TRIGGER_LIFECYCLE_CLEANUP=FAIL run=' . $run
                . ' reason=' . $cleanupError->getMessage() . "\n");
            $exit = 1;
        }
    }
    $like = $db->real_escape_string($basePrefix) . '%';
    $remaining = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME LIKE '{$like}'",
    );
    $remainingCount = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0 || triggerLifecycleCount($db, $basePrefix) !== 0) {
        fwrite(STDERR, 'PLUGIN_NATIVE_TRIGGER_LIFECYCLE_CLEANUP=FAIL run=' . $run
            . " remaining={$remainingCount}\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
