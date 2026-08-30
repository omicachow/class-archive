<?php

declare(strict_types=1);

/**
 * Disposable MariaDB runtime proof for the local-private fixture lease.
 *
 * It uses a random table prefix in the synthetic database and drops every
 * table in finally. No persistent Class Archive row, media byte or private
 * Owner runtime is read or changed.
 */

function fixtureLeaseRuntimeFail(string $code): never
{
    throw new RuntimeException($code);
}

function fixtureLeaseRuntimeExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) !== true) {
        fixtureLeaseRuntimeFail('fixture_lease_runtime_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed> */
function fixtureLeaseRuntimeOne(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        fixtureLeaseRuntimeFail('fixture_lease_runtime_query_failed_' . $db->errno);
    }
    try {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }
    if (count($rows) !== 1) {
        fixtureLeaseRuntimeFail('fixture_lease_runtime_row_count_invalid');
    }
    return $rows[0];
}

function fixtureLeaseRuntimeRejected(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) {
            return;
        }
        throw $error;
    }
    fixtureLeaseRuntimeFail('fixture_lease_runtime_expected_rejection_missing_' . $expected);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "PRIVATE_E2E_FIXTURE_LEASE_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "PRIVATE_E2E_FIXTURE_LEASE_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) $conf['db_host'],
    (string) $conf['db_user'],
    (string) $conf['db_password'],
    (string) $conf['db_base'],
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "PRIVATE_E2E_FIXTURE_LEASE_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/PrivateE2EFixtureLeaseService.php';

$run = strtolower(bin2hex(random_bytes(6)));
$prefix = 'ci_fql_' . $run . '_';
$identity = $prefix . 'class_identity_identity';
$lease = $prefix . 'class_identity_private_e2e_fixture_lease';
$seat = $prefix . 'class_identity_seat';
$account = $prefix . 'class_identity_account';
$principal = $prefix . 'class_identity_principal';
$coreUser = $prefix . 'users';
$malformedPrefix = 'ci_fql_bad_' . $run . '_';
$malformedIdentity = $malformedPrefix . 'class_identity_identity';
$malformedLease = $malformedPrefix . 'class_identity_private_e2e_fixture_lease';
$testRunA = str_repeat('a', 24);
$testRunB = str_repeat('b', 24);
$owner = 'synthetic-lease-runtime';
$assertions = 0;
$exit = 0;

$assert = static function (bool $condition, string $code) use (&$assertions): void {
    if (!$condition) {
        fixtureLeaseRuntimeFail($code);
    }
    ++$assertions;
};

try {
    fixtureLeaseRuntimeExecute($db, <<<SQL
CREATE TABLE `{$identity}` (
  `id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    fixtureLeaseRuntimeExecute(
        $db,
        "INSERT INTO `{$identity}` (`id`,`state`,`lock_version`) VALUES (1,'FROZEN',0),(2,'FROZEN',0),(3,'FROZEN',0),(4,'FROZEN',0),(5,'FROZEN',0),(6,'FROZEN',0)",
    );
    fixtureLeaseRuntimeExecute($db, "CREATE TABLE `{$seat}` (`id` BIGINT UNSIGNED NOT NULL,`identity_id` BIGINT UNSIGNED NOT NULL,`state` VARCHAR(24) NOT NULL,`lock_version` INT UNSIGNED NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    fixtureLeaseRuntimeExecute($db, "CREATE TABLE `{$account}` (`id` BIGINT UNSIGNED NOT NULL,`seat_id` BIGINT UNSIGNED NOT NULL,`state` VARCHAR(32) NOT NULL,`current_marker` TINYINT UNSIGNED NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    fixtureLeaseRuntimeExecute($db, "CREATE TABLE `{$principal}` (`id` BIGINT UNSIGNED NOT NULL,`account_id` BIGINT UNSIGNED NULL,`piwigo_user_id` BIGINT UNSIGNED NOT NULL,`principal_type` VARCHAR(24) NOT NULL,`state` VARCHAR(16) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    fixtureLeaseRuntimeExecute($db, "CREATE TABLE `{$coreUser}` (`id` INT UNSIGNED NOT NULL,`username` VARCHAR(100) NOT NULL,`password` VARCHAR(255) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    fixtureLeaseRuntimeExecute($db, "INSERT INTO `{$seat}` VALUES (40,4,'ACTIVE',0)");
    fixtureLeaseRuntimeExecute($db, "INSERT INTO `{$account}` VALUES (400,40,'ACTIVE',1)");
    fixtureLeaseRuntimeExecute($db, "INSERT INTO `{$principal}` VALUES (4000,400,40000,'SEAT_ACCOUNT','ACTIVE')");
    fixtureLeaseRuntimeExecute($db, "INSERT INTO `{$coreUser}` VALUES (40000,'synthetic_fixture_user','before-hash')");
    $service = new \ClassIdentity\PrivateE2EFixtureLeaseService($db, $prefix);

    $service->assertIdentityHttpAuthorizationAllowed(1);
    ++$assertions;

    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    fixtureLeaseRuntimeRejected(
        static fn() => $service->acquireIdentityLease(1, $testRunA, $owner, 300, 0),
        'class_identity_private_e2e_disabled',
    );
    ++$assertions;

    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1');

    // A same-named non-transactional look-alike must be rejected after
    // CREATE TABLE IF NOT EXISTS. The service must never hand out a context
    // unless the durable lease schema has been structurally attested.
    fixtureLeaseRuntimeExecute($db, <<<SQL
CREATE TABLE `{$malformedIdentity}` (
  `id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    fixtureLeaseRuntimeExecute(
        $db,
        "INSERT INTO `{$malformedIdentity}` (`id`,`state`,`lock_version`) VALUES (1,'FROZEN',0)",
    );
    fixtureLeaseRuntimeExecute($db, <<<SQL
CREATE TABLE `{$malformedLease}` (
  `lease_id` BINARY(16) NOT NULL,
  PRIMARY KEY (`lease_id`)
) ENGINE=MyISAM
SQL);
    $malformedService = new \ClassIdentity\PrivateE2EFixtureLeaseService($db, $malformedPrefix);
    fixtureLeaseRuntimeRejected(
        static fn() => $malformedService->acquireIdentityLease(1, $testRunA, $owner, 300, 0),
        'class_identity_fixture_lease_storage_invalid',
    );
    ++$assertions;
    fixtureLeaseRuntimeRejected(
        static fn() => $malformedService->assertIdentityHttpAuthorizationAllowed(1),
        'class_identity_fixture_lease_storage_invalid',
    );
    ++$assertions;

    $context = $service->acquireIdentityLease(1, $testRunA, $owner, 300, 0);
    $service->assertIdentityHttpAuthorizationAllowed(1);
    ++$assertions;
    $active = fixtureLeaseRuntimeOne(
        $db,
        "SELECT `state`,`expected_lock_version`,`lease_revision`,(`expires_at`>`heartbeat_at`) AS `expiry_valid` "
        . "FROM `{$lease}` WHERE `resource_id`=1 AND `state`='ACTIVE'",
    );
    $assert($active['state'] === 'ACTIVE' && (int) $active['expected_lock_version'] === 0, 'lease_acquire_state_invalid');
    $assert((int) $active['lease_revision'] === 1 && (int) $active['expiry_valid'] === 1, 'lease_acquire_ttl_invalid');
    $metadata = $service->activeIdentityLeaseMetadata(1);
    $assert(
        is_array($metadata)
            && $metadata['target_kind'] === 'IDENTITY'
            && $metadata['target_id'] === 1
            && $metadata['test_run_id'] === $testRunA
            && $metadata['owner'] === $owner
            && $metadata['fixture_owner'] === $owner
            && $metadata['expected_lock_version'] === 0
            && $metadata['lease_revision'] === 1
            && $metadata['version_token'] === '0:1'
            && $metadata['live'] === true,
        'lease_registry_metadata_invalid',
    );
    $assert(
        !array_key_exists('token', $metadata) && !array_key_exists('token_digest', $metadata),
        'lease_registry_metadata_secret_exposed',
    );
    $assert(
        $context->targetKind() === 'IDENTITY'
            && $context->targetId() === 1
            && $context->owner() === $owner
            && $context->versionToken() === '0:1',
        'lease_context_registry_contract_invalid',
    );
    $assert($context->__debugInfo() === ['opaque' => true], 'lease_context_debug_secret_exposed');
    fixtureLeaseRuntimeRejected(
        static fn() => serialize($context),
        'class_identity_fixture_lease_context_not_serializable',
    );
    ++$assertions;

    fixtureLeaseRuntimeRejected(
        static fn() => $service->acquireIdentityLease(1, $testRunB, $owner, 300, 0),
        'class_identity_fixture_lease_conflict',
    );
    ++$assertions;
    fixtureLeaseRuntimeRejected(
        static fn() => $service->beginIdentityMutation(1, null),
        'class_identity_fixture_lease_conflict',
    );
    ++$assertions;

    $guard = $service->beginIdentityMutation(1, $context);
    $db->begin_transaction();
    try {
        fixtureLeaseRuntimeExecute(
            $db,
            "UPDATE `{$identity}` SET `state`='ACTIVE',`lock_version`=`lock_version`+1 "
            . "WHERE `id`=1 AND `state`='FROZEN' AND `lock_version`=0",
        );
        $assert($db->affected_rows === 1, 'lease_identity_cas_update_failed');
        $service->advanceIdentityMutation($context, 0, 1);
        $db->commit();
        $service->confirmIdentityMutationCommitted($context, 1);
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    } finally {
        $guard->release();
    }
    $assert($context->expectedLockVersion() === 1, 'lease_context_version_not_advanced');

    $service->heartbeat($context, 300);
    $heartbeat = fixtureLeaseRuntimeOne(
        $db,
        "SELECT `expected_lock_version`,`lease_revision`,(`expires_at`>`heartbeat_at`) AS `expiry_valid` "
        . "FROM `{$lease}` WHERE `resource_id`=1 AND `state`='ACTIVE'",
    );
    $assert((int) $heartbeat['expected_lock_version'] === 1, 'lease_heartbeat_version_drift');
    $assert((int) $heartbeat['lease_revision'] === 3 && (int) $heartbeat['expiry_valid'] === 1, 'lease_heartbeat_revision_invalid');

    // Simulate an actor that bypasses the application lease lock. Explicit
    // release must reject the drift and must not restore an older state.
    fixtureLeaseRuntimeExecute($db, "UPDATE `{$identity}` SET `lock_version`=2 WHERE `id`=1");
    fixtureLeaseRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(1),
        'class_identity_fixture_lease_http_authorization_version_conflict',
    );
    ++$assertions;
    fixtureLeaseRuntimeRejected(
        static fn() => $service->releaseIdentityLease($context),
        'class_identity_fixture_lease_release_version_conflict',
    );
    ++$assertions;
    $service->markConflict($context);
    $conflict = fixtureLeaseRuntimeOne(
        $db,
        "SELECT i.`state` AS identity_state,i.`lock_version`,l.`state` AS lease_state "
        . "FROM `{$identity}` i JOIN `{$lease}` l ON l.`resource_id`=i.`id` WHERE i.`id`=1",
    );
    $assert(
        $conflict['identity_state'] === 'ACTIVE'
            && (int) $conflict['lock_version'] === 2
            && $conflict['lease_state'] === 'CONFLICT',
        'lease_conflict_overwrote_business_state',
    );
    fixtureLeaseRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(1),
        'class_identity_fixture_lease_http_authorization_conflict',
    );
    ++$assertions;
    fixtureLeaseRuntimeRejected(
        static fn() => $service->acquireIdentityLease(1, $testRunB, $owner, 300, 2),
        'class_identity_fixture_lease_conflict_reconciliation_required',
    );
    ++$assertions;

    // Expiry remains fail-closed until explicit abandoned recovery. Recovery
    // is permitted only because identity 2 kept the exact expected revision.
    $expired = $service->acquireIdentityLease(2, $testRunA, $owner, 300, 0);
    fixtureLeaseRuntimeExecute(
        $db,
        "UPDATE `{$lease}` SET `heartbeat_at`=TIMESTAMPADD(SECOND,-601,UTC_TIMESTAMP(6)),"
        . "`expires_at`=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE `resource_id`=2 AND `state`='ACTIVE'",
    );
    fixtureLeaseRuntimeRejected(
        static fn() => $service->beginIdentityMutation(2, null),
        'class_identity_fixture_lease_expired_recovery_required',
    );
    ++$assertions;
    fixtureLeaseRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(2),
        'class_identity_fixture_lease_http_authorization_expired',
    );
    ++$assertions;
    fixtureLeaseRuntimeRejected(
        static fn() => $service->recoverAbandonedIdentityLease(2, $testRunB, $owner, 300),
        'class_identity_fixture_lease_recovery_owner_conflict',
    );
    ++$assertions;
    $recovery = $service->recoverAbandonedIdentityLease(2, $testRunA, $owner, 300);
    $service->assertIdentityHttpAuthorizationAllowed(2);
    ++$assertions;
    $service->heartbeat($recovery, 300);
    $service->releaseIdentityLease($recovery);
    $service->assertIdentityHttpAuthorizationAllowed(2);
    ++$assertions;
    $recoveredStates = fixtureLeaseRuntimeOne(
        $db,
        "SELECT SUM(`state`='ABANDONED') AS abandoned_count,SUM(`state`='RELEASED') AS released_count "
        . "FROM `{$lease}` WHERE `resource_id`=2",
    );
    $assert((int) $recoveredStates['abandoned_count'] === 1, 'lease_abandoned_state_missing');
    $assert((int) $recoveredStates['released_count'] === 1, 'lease_recovery_release_missing');
    $expired->clear();

    // A transaction rollback must not advance the opaque in-memory context.
    // The same context must remain usable for safe release after rollback.
    $rolledBack = $service->acquireIdentityLease(3, str_repeat('c', 24), $owner, 300, 0);
    $rollbackGuard = $service->beginIdentityMutation(3, $rolledBack);
    $db->begin_transaction();
    try {
        fixtureLeaseRuntimeExecute(
            $db,
            "UPDATE `{$identity}` SET `state`='ACTIVE',`lock_version`=1 WHERE `id`=3 AND `state`='FROZEN' AND `lock_version`=0",
        );
        $service->advanceIdentityMutation($rolledBack, 0, 1);
        $db->rollback();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    } finally {
        $rollbackGuard->release();
    }
    $assert($rolledBack->versionToken() === '0:1', 'lease_context_advanced_before_commit');
    $rollbackState = fixtureLeaseRuntimeOne(
        $db,
        "SELECT i.`state` AS identity_state,i.`lock_version`,l.`expected_lock_version`,l.`lease_revision` "
        . "FROM `{$identity}` i JOIN `{$lease}` l ON l.`resource_id`=i.`id` "
        . "WHERE i.`id`=3 AND l.`state`='ACTIVE'",
    );
    $assert(
        $rollbackState['identity_state'] === 'FROZEN'
            && (int) $rollbackState['lock_version'] === 0
            && (int) $rollbackState['expected_lock_version'] === 0
            && (int) $rollbackState['lease_revision'] === 1,
        'lease_rollback_state_drift',
    );
    $service->releaseIdentityLease($rolledBack);
    ++$assertions;

    // Password cleanup is a true single-statement CAS over the exact fixture
    // topology. A concurrent administrator value is preserved byte-for-byte.
    $credentialLease = $service->acquireIdentityLease(4, str_repeat('d', 24), $owner, 300, 0);
    $assert(
        $service->compareAndSetFixturePasswordHash(
            $credentialLease,
            40000,
            4000,
            400,
            40,
            0,
            'synthetic_fixture_user',
            'before-hash',
            'lease-hash',
        ),
        'fixture_credential_cas_apply_failed',
    );
    $assert(
        !$service->compareAndSetFixturePasswordHash(
            $credentialLease,
            40000,
            4000,
            400,
            40,
            0,
            'synthetic_fixture_user',
            'before-hash',
            'must-not-overwrite',
        ),
        'fixture_credential_stale_cas_allowed',
    );
    fixtureLeaseRuntimeExecute($db, "UPDATE `{$coreUser}` SET `password`='administrator-new-value' WHERE `id`=40000");
    $assert(
        !$service->compareAndSetFixturePasswordHash(
            $credentialLease,
            40000,
            4000,
            400,
            40,
            0,
            'synthetic_fixture_user',
            'lease-hash',
            'closed-hash',
        ),
        'fixture_credential_admin_change_overwritten',
    );
    $credentialState = fixtureLeaseRuntimeOne($db, "SELECT `password` FROM `{$coreUser}` WHERE `id`=40000");
    $assert($credentialState['password'] === 'administrator-new-value', 'fixture_credential_admin_value_not_preserved');
    $service->markConflict($credentialLease);

    fixtureLeaseRuntimeExecute($db, "UPDATE `{$coreUser}` SET `password`='lease-hash' WHERE `id`=40000");
    $cleanupFirst = $service->acquireIdentityLease(5, str_repeat('e', 24), $owner, 300, 0);
    $assert(
        !$service->compareAndSetLeasedPasswordHash($cleanupFirst, 40000, 'rebound-user', 'lease-hash', 'must-not-write'),
        'fixture_credential_cleanup_username_rebind_allowed',
    );
    $assert(
        $service->compareAndSetLeasedPasswordHash($cleanupFirst, 40000, 'synthetic_fixture_user', 'lease-hash', 'closed-hash'),
        'fixture_credential_cleanup_first_cas_failed',
    );
    fixtureLeaseRuntimeExecute($db, "UPDATE `{$coreUser}` SET `password`='administrator-after-cleanup' WHERE `id`=40000");
    $cleanupFirstState = fixtureLeaseRuntimeOne($db, "SELECT `password` FROM `{$coreUser}` WHERE `id`=40000");
    $assert($cleanupFirstState['password'] === 'administrator-after-cleanup', 'fixture_credential_cleanup_overwrote_later_admin');
    fixtureLeaseRuntimeExecute($db, "UPDATE `{$identity}` SET `state`='ACTIVE' WHERE `id`=5");
    fixtureLeaseRuntimeRejected(
        static fn() => $service->releaseIdentityLease($cleanupFirst),
        'class_identity_fixture_lease_release_version_conflict',
    );
    ++$assertions;
    fixtureLeaseRuntimeExecute($db, "UPDATE `{$identity}` SET `state`='FROZEN' WHERE `id`=5");
    $service->releaseIdentityLease($cleanupFirst);
    ++$assertions;

    // A missing/corrupt business aggregate must not make the LEFT JOIN hide
    // an unresolved lease and accidentally turn UNKNOWN into ALLOW.
    $orphaned = $service->acquireIdentityLease(6, str_repeat('f', 24), $owner, 300, 0);
    fixtureLeaseRuntimeExecute($db, "DELETE FROM `{$identity}` WHERE `id`=6");
    fixtureLeaseRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(6),
        'class_identity_fixture_lease_http_authorization_version_conflict',
    );
    ++$assertions;
    $orphaned->clear();

    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    fixtureLeaseRuntimeRejected(
        static fn() => $service->acquireIdentityLease(2, str_repeat('e', 24), $owner, 300, 0),
        'class_identity_private_e2e_disabled',
    );
    ++$assertions;
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_E2E_FIXTURE_LEASE_RUNTIME=FAIL reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    foreach ([$lease, $principal, $account, $seat, $coreUser, $identity, $malformedLease, $malformedIdentity] as $table) {
        @$db->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $db->close();
}

if ($exit === 0) {
    fwrite(
        STDOUT,
        "PRIVATE_E2E_FIXTURE_LEASE_RUNTIME=PASS assertions={$assertions} mode=DISABLED_BY_DEFAULT ttl=PASS heartbeat=PASS conflict=FAIL_CLOSED cleanup=CAS abandoned=RECOVERED scope=SYNTHETIC_RANDOM_PREFIX\n",
    );
}
exit($exit);
