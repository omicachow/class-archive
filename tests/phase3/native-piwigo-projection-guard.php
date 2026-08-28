<?php

declare(strict_types=1);

function nativeGuardFail(string $message): never
{
    throw new RuntimeException($message);
}

function nativeGuardIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        nativeGuardFail('native_guard_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function nativeGuardExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        nativeGuardFail('native_guard_query_failed_' . $db->errno . '_' . $db->error);
    }
}

/** @return array<string,array{state:string,generation:string,reason:?string}> */
function nativeGuardProjectionState(mysqli $db, string $table): array
{
    $result = $db->query(
        'SELECT `projection_key`,`state`,HEX(`generation`) AS `generation`,`invalidated_reason` '
            . 'FROM ' . nativeGuardIdentifier($table) . ' ORDER BY `projection_key`',
    );
    if (!$result instanceof mysqli_result) {
        nativeGuardFail('native_guard_projection_state_unavailable');
    }
    try {
        $state = [];
        while ($row = $result->fetch_assoc()) {
            $state[(string) $row['projection_key']] = [
                'state' => (string) $row['state'],
                'generation' => (string) $row['generation'],
                'reason' => is_string($row['invalidated_reason'] ?? null)
                    ? $row['invalidated_reason']
                    : null,
            ];
        }
        return $state;
    } finally {
        $result->free();
    }
}

function nativeGuardActivate(mysqli $db, string $table, string $epochTable): void
{
    $quoted = nativeGuardIdentifier($table);
    $epoch = nativeGuardIdentifier($epochTable);
    nativeGuardExecute(
        $db,
        "UPDATE {$quoted} SET `state`='ACTIVE',`source_revision`=RANDOM_BYTES(32),"
            . '`generation`=RANDOM_BYTES(16),`item_count`=0,'
            . "`native_source_generation`=CASE WHEN `projection_key`='PHOTO_CATALOG' "
            . "THEN (SELECT `generation` FROM {$epoch} WHERE `source_key`='PIWIGO_NATIVE') ELSE NULL END,"
            . "`payload_json`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE JSON_OBJECT() END,"
            . "`payload_digest`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE RANDOM_BYTES(32) END,"
            . "`dependency_revision`=CASE WHEN `projection_key`='PHOTO_CATALOG' THEN NULL ELSE RANDOM_BYTES(32) END,"
            . '`invalidated_reason`=NULL,`built_at`=UTC_TIMESTAMP(6),`invalidated_at`=NULL,'
            . "`updated_at`=UTC_TIMESTAMP(6) WHERE `projection_key` IN "
            . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES')",
    );
    if ($db->affected_rows !== 5) {
        nativeGuardFail('native_guard_activate_count_' . $db->affected_rows);
    }
}

/** @param array<string,array{state:string,generation:string,reason:?string}> $state */
function nativeGuardAssertFiveState(array $state, string $expected): void
{
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES'] as $kind) {
        if (($state[$kind]['state'] ?? null) !== $expected) {
            nativeGuardFail('native_guard_projection_state_' . strtolower($kind));
        }
        if ($expected === 'STALE' && ($state[$kind]['reason'] ?? null) !== 'NATIVE_PIWIGO_MUTATION') {
            nativeGuardFail('native_guard_projection_reason_' . strtolower($kind));
        }
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "NATIVE_PIWIGO_PROJECTION_GUARD=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "NATIVE_PIWIGO_PROJECTION_GUARD=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "NATIVE_PIWIGO_PROJECTION_GUARD=FAIL reason=piwigo_prefix_invalid\n");
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
    fwrite(STDERR, "NATIVE_PIWIGO_PROJECTION_GUARD=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_native_guard_' . $run . '_';
$classPrefix = $basePrefix . 'class_identity_';
$createdNative = [];
$assertions = 0;
$exit = 0;

try {
    $createdNative = classIdentityCreateNativeProjectionFixture($db, $prefixeTable, $basePrefix);
    $schema = new ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    foreach (['migrationGatewayReadProjection', 'migrationGatewayAggregateProjection', 'migrationNativePiwigoProjectionGuard', 'migrationDurableNativeSourceEpoch'] as $method) {
        (new ReflectionMethod(ClassIdentity\Schema::class, $method))->invoke($schema);
    }

    $projection = $classPrefix . 'read_projection';
    $nativeEpoch = $classPrefix . 'native_source_epoch';
    $images = $basePrefix . 'images';
    $categories = $basePrefix . 'categories';
    $associations = $basePrefix . 'image_category';

    $triggerResult = $db->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() "
            . "AND EVENT_OBJECT_TABLE IN ('" . $db->real_escape_string($images) . "','"
            . $db->real_escape_string($categories) . "','" . $db->real_escape_string($associations) . "')",
    );
    $triggerCount = $triggerResult instanceof mysqli_result ? (int) ($triggerResult->fetch_row()[0] ?? -1) : -1;
    if ($triggerResult instanceof mysqli_result) {
        $triggerResult->free();
    }
    if ($triggerCount !== 18) {
        nativeGuardFail('native_guard_trigger_count_' . $triggerCount);
    }
    ++$assertions;

    // Initial rows are inserted while the projections are already STALE.
    // The per-call generation rotation keeps ROW_COUNT exactly five, even for
    // a multi-row native statement.
    nativeGuardExecute(
        $db,
        'INSERT INTO ' . nativeGuardIdentifier($images)
            . " (`id`,`file`,`date_available`,`name`,`path`) VALUES "
            . "(1,'fixture-a.jpg','2026-01-01 00:00:00','初始名称','upload/fixture-a.jpg'),"
            . "(2,'fixture-b.jpg','2026-01-01 00:00:00','第二张','upload/fixture-b.jpg')",
    );
    ++$assertions;
    nativeGuardExecute(
        $db,
        'INSERT INTO ' . nativeGuardIdentifier($categories)
            . " (`id`,`name`,`uppercats`) VALUES (101,'合成相册','101')",
    );
    nativeGuardExecute(
        $db,
        'INSERT INTO ' . nativeGuardIdentifier($associations)
            . ' (`image_id`,`category_id`,`rank`) VALUES (1,101,1)',
    );

    nativeGuardActivate($db, $projection, $nativeEpoch);
    $beforeHit = nativeGuardProjectionState($db, $projection);
    nativeGuardAssertFiveState($beforeHit, 'ACTIVE');
    nativeGuardExecute(
        $db,
        'UPDATE ' . nativeGuardIdentifier($images) . ' SET `hit`=`hit`+1 WHERE `id`=1',
    );
    $afterHit = nativeGuardProjectionState($db, $projection);
    nativeGuardAssertFiveState($afterHit, 'ACTIVE');
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES'] as $kind) {
        if (!hash_equals($beforeHit[$kind]['generation'], $afterHit[$kind]['generation'])) {
            nativeGuardFail('native_guard_hit_rotated_' . strtolower($kind));
        }
    }
    $assertions += 11;

    nativeGuardExecute(
        $db,
        'UPDATE ' . nativeGuardIdentifier($images) . " SET `name`='已修改名称' WHERE `id`=1",
    );
    nativeGuardAssertFiveState(nativeGuardProjectionState($db, $projection), 'STALE');
    ++$assertions;

    nativeGuardActivate($db, $projection, $nativeEpoch);
    nativeGuardExecute(
        $db,
        'UPDATE ' . nativeGuardIdentifier($associations) . ' SET `rank`=2 WHERE `image_id`=1 AND `category_id`=101',
    );
    nativeGuardAssertFiveState(nativeGuardProjectionState($db, $projection), 'STALE');
    ++$assertions;

    nativeGuardActivate($db, $projection, $nativeEpoch);
    nativeGuardExecute(
        $db,
        'UPDATE ' . nativeGuardIdentifier($categories) . " SET `name`='合成相册二' WHERE `id`=101",
    );
    nativeGuardAssertFiveState(nativeGuardProjectionState($db, $projection), 'STALE');
    ++$assertions;

    // Piwigo source tables are MyISAM while read_projection is InnoDB. An
    // explicit caller rollback cannot undo the source mutation. The separate
    // MyISAM epoch persists alongside it, so the rolled-back ACTIVE metadata
    // is treated as stale and every Gateway read fails closed.
    nativeGuardActivate($db, $projection, $nativeEpoch);
    $epochBeforeResult = $db->query(
        'SELECT HEX(`generation`) FROM ' . nativeGuardIdentifier($nativeEpoch)
            . " WHERE `source_key`='PIWIGO_NATIVE'",
    );
    $epochBefore = $epochBeforeResult instanceof mysqli_result
        ? (string) ($epochBeforeResult->fetch_row()[0] ?? '') : '';
    if ($epochBeforeResult instanceof mysqli_result) {
        $epochBeforeResult->free();
    }
    if (!$db->begin_transaction()) {
        nativeGuardFail('native_guard_cross_engine_begin_failed');
    }
    nativeGuardExecute(
        $db,
        'UPDATE ' . nativeGuardIdentifier($images) . " SET `name`='MyISAM回滚后仍存在' WHERE `id`=1",
    );
    if (!$db->rollback()) {
        nativeGuardFail('native_guard_cross_engine_rollback_failed');
    }
    nativeGuardAssertFiveState(nativeGuardProjectionState($db, $projection), 'ACTIVE');
    $epochAfterResult = $db->query(
        'SELECT HEX(`generation`) FROM ' . nativeGuardIdentifier($nativeEpoch)
            . " WHERE `source_key`='PIWIGO_NATIVE'",
    );
    $epochAfter = $epochAfterResult instanceof mysqli_result
        ? (string) ($epochAfterResult->fetch_row()[0] ?? '') : '';
    if ($epochAfterResult instanceof mysqli_result) {
        $epochAfterResult->free();
    }
    $nameAfterRollbackResult = $db->query(
        'SELECT `name` FROM ' . nativeGuardIdentifier($images) . ' WHERE `id`=1',
    );
    $nameAfterRollback = $nameAfterRollbackResult instanceof mysqli_result
        ? (string) ($nameAfterRollbackResult->fetch_row()[0] ?? '') : '';
    if ($nameAfterRollbackResult instanceof mysqli_result) {
        $nameAfterRollbackResult->free();
    }
    if ($epochBefore === '' || $epochAfter === '' || hash_equals($epochBefore, $epochAfter)
        || $nameAfterRollback !== 'MyISAM回滚后仍存在'
    ) {
        nativeGuardFail('native_guard_cross_engine_fixture_invalid');
    }
    $guardStore = new ClassIdentity\Gateway\ReadProjectionStore(
        new ClassIdentity\Repository($db, $basePrefix),
    );
    $effective = [];
    foreach ($guardStore->status() as $state) {
        $effective[$state['kind']] = $state;
    }
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES'] as $kind) {
        if (($effective[$kind]['state'] ?? null) !== 'STALE'
            || ($effective[$kind]['reason'] ?? null) !== 'NATIVE_SOURCE_EPOCH_MISMATCH'
        ) {
            nativeGuardFail('native_guard_cross_engine_not_closed_' . strtolower($kind));
        }
    }
    try {
        $guardStore->photos();
        nativeGuardFail('native_guard_cross_engine_read_allowed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_native_source_epoch_mismatch') {
            throw $error;
        }
    }
    $assertions += 8;

    // Failure injection: with one mandatory projection row missing, the
    // BEFORE trigger updates only four rows and SIGNAL aborts the MyISAM
    // mutation. The source value must remain byte-for-byte unchanged.
    nativeGuardActivate($db, $projection, $nativeEpoch);
    nativeGuardExecute(
        $db,
        'DELETE FROM ' . nativeGuardIdentifier($projection) . " WHERE `projection_key`='MEMORIES'",
    );
    $failed = $db->query(
        'UPDATE ' . nativeGuardIdentifier($images) . " SET `name`='绝不能写入' WHERE `id`=1",
    );
    if ($failed !== false || $db->errno !== 1644 || !str_contains($db->error, 'class_archive_projection_guard_failed')) {
        nativeGuardFail('native_guard_failure_injection_not_blocked_' . $db->errno);
    }
    $nameResult = $db->query(
        'SELECT `name` FROM ' . nativeGuardIdentifier($images) . ' WHERE `id`=1',
    );
    $name = $nameResult instanceof mysqli_result ? (string) ($nameResult->fetch_row()[0] ?? '') : '';
    if ($nameResult instanceof mysqli_result) {
        $nameResult->free();
    }
    if ($name !== 'MyISAM回滚后仍存在') {
        nativeGuardFail('native_guard_failure_injection_source_changed');
    }
    $remaining = nativeGuardProjectionState($db, $projection);
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE'] as $kind) {
        if (($remaining[$kind]['state'] ?? null) !== 'ACTIVE') {
            nativeGuardFail('native_guard_failure_injection_projection_changed_' . strtolower($kind));
        }
    }
    $assertions += 6;

    fwrite(STDOUT, 'NATIVE_PIWIGO_PROJECTION_GUARD=PASS assertions=' . $assertions . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'NATIVE_PIWIGO_PROJECTION_GUARD=FAIL run=' . $run
        . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    foreach (['read_photo', 'read_projection', 'native_source_epoch'] as $suffix) {
        $db->query('DROP TABLE IF EXISTS ' . nativeGuardIdentifier($classPrefix . $suffix));
    }
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'NATIVE_PIWIGO_PROJECTION_GUARD_CLEANUP=FAIL run=' . $run
                . ' reason=' . $cleanupError->getMessage() . "\n");
            $exit = 1;
        }
    }
    $like = $db->real_escape_string($basePrefix) . '%';
    $left = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'",
    );
    $leftCount = $left instanceof mysqli_result ? (int) ($left->fetch_row()[0] ?? -1) : -1;
    if ($left instanceof mysqli_result) {
        $left->free();
    }
    if ($leftCount !== 0) {
        fwrite(STDERR, 'NATIVE_PIWIGO_PROJECTION_GUARD_CLEANUP=FAIL run=' . $run
            . ' remaining=' . $leftCount . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
