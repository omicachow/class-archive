<?php

declare(strict_types=1);

/**
 * Random-prefix runtime proof for the Owner FQA credential broker.
 *
 * The test imports the broker's exact credential-plan/CAS helpers but never
 * executes its private Owner entry point. It creates only disposable tables
 * in the synthetic MariaDB and removes them (plus every 0600 plan file) in
 * finally. No 8191 row, real filename, media byte or persistent account is
 * read or changed.
 */

function v4BrokerRuntimeFail(string $code): never
{
    throw new RuntimeException($code);
}

function v4BrokerRuntimeExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) !== true) {
        v4BrokerRuntimeFail('broker_runtime_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed> */
function v4BrokerRuntimeOne(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        v4BrokerRuntimeFail('broker_runtime_query_failed_' . $db->errno);
    }
    try {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }
    if (count($rows) !== 1) {
        v4BrokerRuntimeFail('broker_runtime_row_count_invalid');
    }
    return $rows[0];
}

function v4BrokerRuntimeRejected(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) {
            return;
        }
        throw $error;
    }
    v4BrokerRuntimeFail('broker_runtime_expected_rejection_missing_' . $expected);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "V4_OWNER_FQA_BROKER_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeUser = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeUser) || ($runtimeUser['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "V4_OWNER_FQA_BROKER_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
if (!class_exists('ClassIdentityAdminService', false)) {
    final class ClassIdentityAdminService
    {
        public function setIdentityFrozen(mixed ...$arguments): void
        {
            throw new RuntimeException('unexpected_identity_mutation');
        }
    }
}
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
    fwrite(STDERR, "V4_OWNER_FQA_BROKER_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/PrivateE2EFixtureLeaseService.php';
define('V4_FQA_LIBRARY_ONLY', true);
require $root . '/tests/phase3/photos-app-v4-owner-fqa-lease.php';

// The broker only requires an opaque Core-compatible verifier. A low-cost
// bcrypt fixture keeps the test realistic without exposing any credential.
$conf['password_hash'] = static fn(string $secret): string => password_hash(
    $secret,
    PASSWORD_BCRYPT,
    ['cost' => 4],
);

$run = strtolower(bin2hex(random_bytes(6)));
$prefix = 'ci_v4br_' . $run . '_';
$identityTable = $prefix . 'class_identity_identity';
$leaseTable = $prefix . 'class_identity_private_e2e_fixture_lease';
$seatTable = $prefix . 'class_identity_seat';
$accountTable = $prefix . 'class_identity_account';
$principalTable = $prefix . 'class_identity_principal';
$userTable = $prefix . 'users';
$owner = 'synthetic-v4-broker-runtime';
$files = [];
$assertions = 0;
$exit = 0;

$assert = static function (bool $condition, string $code) use (&$assertions): void {
    if (!$condition) {
        v4BrokerRuntimeFail($code);
    }
    ++$assertions;
};
$revoke = static function (int $userId) use (&$assertions): void {
    if ($userId <= 0) {
        v4BrokerRuntimeFail('broker_runtime_revoke_user_invalid');
    }
    ++$assertions;
};
$audit = static function (array $event) use (&$assertions): void {
    if (($event['action'] ?? null) !== 'PRINCIPAL_SECURITY_CHANGE'
        || ($event['new_value']['state'] ?? null) !== 'LEASE_OPEN'
    ) {
        v4BrokerRuntimeFail('broker_runtime_audit_event_invalid');
    }
    ++$assertions;
};

/** @return array{identity_id:int,lock_version:int,accounts:array<string,array<string,mixed>>} */
$addFixture = static function (int $identityId) use (
    $db,
    $run,
    $identityTable,
    $seatTable,
    $accountTable,
    $principalTable,
    $userTable,
): array {
    v4BrokerRuntimeExecute(
        $db,
        "INSERT INTO `{$identityTable}` (`id`,`state`,`lock_version`) VALUES ({$identityId},'FROZEN',0)",
    );
    $accounts = [];
    foreach (V4_FQA_ROLES as $offset => $role) {
        $seatId = ($identityId * 100) + $offset + 1;
        $accountId = ($identityId * 1000) + $offset + 1;
        $principalId = ($identityId * 10000) + $offset + 1;
        $userId = ($identityId * 100000) + $offset + 1;
        $username = 'syn_' . $run . '_' . strtolower($role) . '_' . $identityId;
        $before = 'before-' . $run . '-' . $identityId . '-' . $offset;
        v4BrokerRuntimeExecute(
            $db,
            "INSERT INTO `{$seatTable}` VALUES ({$seatId},{$identityId},'ACTIVE',0)",
        );
        v4BrokerRuntimeExecute(
            $db,
            "INSERT INTO `{$accountTable}` VALUES ({$accountId},{$seatId},'ACTIVE',1)",
        );
        v4BrokerRuntimeExecute(
            $db,
            "INSERT INTO `{$principalTable}` VALUES ({$principalId},{$accountId},{$userId},'SEAT_ACCOUNT','ACTIVE')",
        );
        v4BrokerRuntimeExecute(
            $db,
            "INSERT INTO `{$userTable}` VALUES ({$userId},'{$username}','{$before}')",
        );
        $accounts[$role] = [
            'seat_id' => $seatId,
            'seat_lock_version' => 0,
            'account_id' => $accountId,
            'principal_id' => $principalId,
            'user_id' => $userId,
            'username' => $username,
            'password_hash' => $before,
        ];
    }
    return ['identity_id' => $identityId, 'lock_version' => 0, 'accounts' => $accounts];
};

$credentialProjection = static function (array $plan): array {
    $credentials = [];
    foreach ($plan as $role => $item) {
        $credentials[strtolower((string) $role)] = [
            'username' => (string) $item['username'],
            'password' => (string) $item['browser_password'],
        ];
    }
    return $credentials;
};

$expireLease = static function (int $identityId) use ($db, $leaseTable): void {
    v4BrokerRuntimeExecute(
        $db,
        "UPDATE `{$leaseTable}` SET `heartbeat_at`=TIMESTAMPADD(SECOND,-601,UTC_TIMESTAMP(6)),"
        . "`expires_at`=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) "
        . "WHERE `resource_id`={$identityId} AND `state`='ACTIVE'",
    );
};

$passwordDigest = static function (int $userId) use ($db, $userTable): string {
    $row = v4BrokerRuntimeOne($db, "SELECT `password` FROM `{$userTable}` WHERE `id`={$userId}");
    return hash('sha256', (string) $row['password']);
};

try {
    v4BrokerRuntimeExecute($db, <<<SQL
CREATE TABLE `{$identityTable}` (
  `id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    v4BrokerRuntimeExecute($db, "CREATE TABLE `{$seatTable}` (`id` BIGINT UNSIGNED NOT NULL,`identity_id` BIGINT UNSIGNED NOT NULL,`state` VARCHAR(24) NOT NULL,`lock_version` INT UNSIGNED NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    v4BrokerRuntimeExecute($db, "CREATE TABLE `{$accountTable}` (`id` BIGINT UNSIGNED NOT NULL,`seat_id` BIGINT UNSIGNED NOT NULL,`state` VARCHAR(32) NOT NULL,`current_marker` TINYINT UNSIGNED NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    v4BrokerRuntimeExecute($db, "CREATE TABLE `{$principalTable}` (`id` BIGINT UNSIGNED NOT NULL,`account_id` BIGINT UNSIGNED NULL,`piwigo_user_id` BIGINT UNSIGNED NOT NULL,`principal_type` VARCHAR(24) NOT NULL,`state` VARCHAR(16) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    v4BrokerRuntimeExecute($db, "CREATE TABLE `{$userTable}` (`id` INT UNSIGNED NOT NULL,`username` VARCHAR(100) NOT NULL,`password` VARCHAR(255) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    $service = new \ClassIdentity\PrivateE2EFixtureLeaseService($db, $prefix);
    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1');

    // Normal open/close through the exact broker helpers.
    $normal = $addFixture(10);
    $normalRun = str_repeat('1', 24);
    $normalContext = $service->acquireIdentityLease(10, $normalRun, $owner, 300, 0);
    $normalPlan = v4fqaBuildCredentialPlan($normal['accounts']);
    $normalFile = '/tmp/class-archive-v4-broker-runtime-' . $run . '-normal.json';
    $files[] = $normalFile;
    v4fqaWriteCredentialFile($normalFile, $normalRun, $credentialProjection($normalPlan), $normalPlan);
    $persistedNormal = v4fqaReadCredentialPlan($normalFile, $normalRun);
    $assert(v4fqaCredentialPlanMatchesState($persistedNormal, $normal), 'normal_plan_topology_invalid');
    v4fqaInstallCredentialPlan($service, $normalContext, $normalPlan, 10, 900001, 900002, $revoke, $audit);
    $assert(v4fqaCloseCredentialPlan($db, $prefix, $service, $normalContext, $persistedNormal, $revoke), 'normal_close_failed');
    foreach ($persistedNormal as $item) {
        $assert(
            hash_equals((string) $item['closed_password_sha256'], $passwordDigest((int) $item['user_id'])),
            'normal_closed_hash_missing',
        );
    }
    $service->releaseIdentityLease($normalContext);
    v4fqaRemoveCredentialFile($normalFile);

    // Exact outer-finally behavior: a helper throws after the first CAS and
    // the same mutated in-memory plan (without re-reading the durable file)
    // must still close the installed verifier while recognizing untouched
    // verifiers by the digest created in v4fqaBuildCredentialPlan().
    $finallyState = $addFixture(15);
    $finallyRun = str_repeat('2', 24);
    $finallyContext = $service->acquireIdentityLease(15, $finallyRun, $owner, 300, 0);
    $finallyPlan = v4fqaBuildCredentialPlan($finallyState['accounts']);
    $finallyFile = '/tmp/class-archive-v4-broker-runtime-' . $run . '-finally.json';
    $files[] = $finallyFile;
    v4fqaWriteCredentialFile($finallyFile, $finallyRun, $credentialProjection($finallyPlan), $finallyPlan);
    $finallyClosed = false;
    try {
        v4fqaInstallCredentialPlan(
            $service,
            $finallyContext,
            $finallyPlan,
            15,
            900001,
            900002,
            $revoke,
            $audit,
            static function (string $role): void {
                if ($role === 'ANONYMOUS') {
                    throw new RuntimeException('simulated_helper_throw');
                }
            },
        );
        v4BrokerRuntimeFail('helper_throw_missing');
    } catch (Throwable $error) {
        if ($error->getMessage() !== 'simulated_helper_throw') {
            throw $error;
        }
        ++$assertions;
    } finally {
        $finallyClosed = v4fqaCloseCredentialPlan(
            $db,
            $prefix,
            $service,
            $finallyContext,
            $finallyPlan,
            $revoke,
        );
    }
    $assert($finallyClosed, 'helper_throw_same_plan_finally_close_failed');
    $position = 0;
    foreach ($finallyPlan as $item) {
        ++$position;
        $expectedDigest = $position === 1
            ? (string) $item['closed_password_sha256']
            : (string) $item['before_password_sha256'];
        $assert(
            hash_equals($expectedDigest, $passwordDigest((int) $item['user_id'])),
            'helper_throw_same_plan_password_state_invalid',
        );
    }
    $service->releaseIdentityLease($finallyContext);
    v4fqaRemoveCredentialFile($finallyFile);

    // Crash after the first and middle credential CAS. Because the recovery
    // plan was durable first, installed hashes are closed while untouched
    // original verifiers are recognized by digest and left byte-for-byte.
    foreach ([1 => 20, 2 => 30] as $crashAfter => $identityId) {
        $state = $addFixture($identityId);
        $scenarioRun = str_repeat((string) ($crashAfter + 2), 24);
        $context = $service->acquireIdentityLease($identityId, $scenarioRun, $owner, 300, 0);
        $plan = v4fqaBuildCredentialPlan($state['accounts']);
        $file = '/tmp/class-archive-v4-broker-runtime-' . $run . '-crash-' . $crashAfter . '.json';
        $files[] = $file;
        v4fqaWriteCredentialFile($file, $scenarioRun, $credentialProjection($plan), $plan);
        $installed = 0;
        v4BrokerRuntimeRejected(
            static function () use (
                $service,
                $context,
                &$plan,
                $identityId,
                $revoke,
                $audit,
                &$installed,
                $crashAfter,
            ): void {
                v4fqaInstallCredentialPlan(
                    $service,
                    $context,
                    $plan,
                    $identityId,
                    900001,
                    900002,
                    $revoke,
                    $audit,
                    static function () use (&$installed, $crashAfter): void {
                        ++$installed;
                        if ($installed === $crashAfter) {
                            throw new RuntimeException('simulated_broker_crash');
                        }
                    },
                );
            },
            'simulated_broker_crash',
        );
        $assert($installed === $crashAfter, 'crash_failpoint_position_invalid');
        $expireLease($identityId);
        v4BrokerRuntimeRejected(
            static fn() => $service->assertIdentityHttpAuthorizationAllowed($identityId),
            'class_identity_fixture_lease_http_authorization_expired',
        );
        ++$assertions;
        $persisted = v4fqaReadCredentialPlan($file, $scenarioRun);
        $assert(v4fqaCredentialPlanMatchesState($persisted, $state), 'crash_recovery_topology_invalid');
        if ($crashAfter === 1) {
            v4BrokerRuntimeRejected(
                static fn() => $service->recoverAbandonedIdentityLease(
                    $identityId,
                    str_repeat('f', 24),
                    $owner,
                    300,
                ),
                'class_identity_fixture_lease_recovery_owner_conflict',
            );
            ++$assertions;
            v4BrokerRuntimeRejected(
                static fn() => $service->recoverAbandonedIdentityLease(
                    $identityId,
                    $scenarioRun,
                    'synthetic-wrong-owner',
                    300,
                ),
                'class_identity_fixture_lease_recovery_owner_conflict',
            );
            ++$assertions;
        }
        $recovery = $service->recoverAbandonedIdentityLease($identityId, $scenarioRun, $owner, 300);
        $assert(v4fqaCloseCredentialPlan($db, $prefix, $service, $recovery, $persisted, $revoke), 'crash_recovery_close_failed');
        $position = 0;
        foreach ($persisted as $item) {
            ++$position;
            $expectedDigest = $position <= $crashAfter
                ? (string) $item['closed_password_sha256']
                : (string) $item['before_password_sha256'];
            $assert(
                hash_equals($expectedDigest, $passwordDigest((int) $item['user_id'])),
                'crash_recovery_password_state_invalid',
            );
        }
        $service->releaseIdentityLease($recovery);
        v4fqaRemoveCredentialFile($file);
    }

    // A concurrent administrator verifier wins. Other broker-owned hashes
    // are closed, the administrator byte string is preserved, and the lease
    // becomes durable CONFLICT so HTTP authorization fails closed.
    $conflictState = $addFixture(40);
    $conflictRun = str_repeat('5', 24);
    $conflictContext = $service->acquireIdentityLease(40, $conflictRun, $owner, 300, 0);
    $conflictPlan = v4fqaBuildCredentialPlan($conflictState['accounts']);
    $conflictFile = '/tmp/class-archive-v4-broker-runtime-' . $run . '-conflict.json';
    $files[] = $conflictFile;
    v4fqaWriteCredentialFile($conflictFile, $conflictRun, $credentialProjection($conflictPlan), $conflictPlan);
    $persistedConflict = v4fqaReadCredentialPlan($conflictFile, $conflictRun);
    v4fqaInstallCredentialPlan($service, $conflictContext, $conflictPlan, 40, 900001, 900002, $revoke, $audit);
    $administratorHash = 'administrator-wins-' . $run;
    $administratorUser = (int) $persistedConflict['CLASSMATE']['user_id'];
    v4BrokerRuntimeExecute(
        $db,
        "UPDATE `{$userTable}` SET `password`='{$administratorHash}' WHERE `id`={$administratorUser}",
    );
    $assert(
        !v4fqaCloseCredentialPlan($db, $prefix, $service, $conflictContext, $persistedConflict, $revoke),
        'administrator_conflict_reported_safe',
    );
    $assert(
        hash_equals(hash('sha256', $administratorHash), $passwordDigest($administratorUser)),
        'administrator_verifier_overwritten',
    );
    $service->markConflict($conflictContext);
    $conflictRow = v4BrokerRuntimeOne(
        $db,
        "SELECT `state` FROM `{$leaseTable}` WHERE `resource_id`=40 ORDER BY `acquired_at` DESC LIMIT 1",
    );
    $assert($conflictRow['state'] === 'CONFLICT', 'administrator_conflict_lease_state_invalid');
    v4BrokerRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(40),
        'class_identity_fixture_lease_http_authorization_conflict',
    );
    ++$assertions;

    // Missing plan: the broker cannot claim recovery. The expired lease stays
    // active-and-denied until explicit reconciliation.
    $missingState = $addFixture(50);
    $missingRun = str_repeat('6', 24);
    $missingContext = $service->acquireIdentityLease(50, $missingRun, $owner, 300, 0);
    $missingPlan = v4fqaBuildCredentialPlan($missingState['accounts']);
    $firstMissing = $missingPlan['ANONYMOUS'];
    $assert(
        $service->compareAndSetFixturePasswordHash(
            $missingContext,
            (int) $firstMissing['user_id'],
            (int) $firstMissing['principal_id'],
            (int) $firstMissing['account_id'],
            (int) $firstMissing['seat_id'],
            (int) $firstMissing['seat_lock_version'],
            (string) $firstMissing['username'],
            (string) $firstMissing['before_password_hash'],
            (string) $firstMissing['lease_password_hash'],
        ),
        'missing_plan_fixture_install_failed',
    );
    $expireLease(50);
    $missingFile = '/tmp/class-archive-v4-broker-runtime-' . $run . '-missing.json';
    v4BrokerRuntimeRejected(
        static fn() => v4fqaReadCredentialPlan($missingFile, $missingRun),
        'credential_recovery_plan_file_invalid',
    );
    ++$assertions;
    v4BrokerRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(50),
        'class_identity_fixture_lease_http_authorization_expired',
    );
    ++$assertions;
    $missingLease = v4BrokerRuntimeOne(
        $db,
        "SELECT `state`,(`expires_at`<=UTC_TIMESTAMP(6)) AS expired FROM `{$leaseTable}` WHERE `resource_id`=50 AND `state`='ACTIVE'",
    );
    $assert($missingLease['state'] === 'ACTIVE' && (int) $missingLease['expired'] === 1, 'missing_plan_false_recovery');

    // Topology drift: the durable plan no longer matches the Seat/Principal
    // topology. Recovery first removes only exact broker-owned verifiers, does
    // not overwrite a concurrent administrator verifier, keeps the Identity
    // frozen, and transitions the lease to durable CONFLICT (never RELEASED).
    $driftState = $addFixture(60);
    $driftRun = str_repeat('7', 24);
    $driftContext = $service->acquireIdentityLease(60, $driftRun, $owner, 300, 0);
    $driftPlan = v4fqaBuildCredentialPlan($driftState['accounts']);
    $driftFile = '/tmp/class-archive-v4-broker-runtime-' . $run . '-drift.json';
    $files[] = $driftFile;
    v4fqaWriteCredentialFile($driftFile, $driftRun, $credentialProjection($driftPlan), $driftPlan);
    $persistedDrift = v4fqaReadCredentialPlan($driftFile, $driftRun);
    v4fqaInstallCredentialPlan($service, $driftContext, $driftPlan, 60, 900001, 900002, $revoke, $audit);
    $driftedState = $driftState;
    $oldFamilyPrincipal = (int) $driftedState['accounts']['FAMILY']['principal_id'];
    $newFamilyPrincipal = $oldFamilyPrincipal + 500000;
    v4BrokerRuntimeExecute(
        $db,
        "UPDATE `{$principalTable}` SET `id`={$newFamilyPrincipal} WHERE `id`={$oldFamilyPrincipal}",
    );
    $driftedState['accounts']['FAMILY']['principal_id'] = $newFamilyPrincipal;
    $assert(!v4fqaCredentialPlanMatchesState($persistedDrift, $driftedState), 'topology_drift_accepted');
    $driftAdminHash = 'topology-administrator-wins-' . $run;
    $driftAdminUser = (int) $persistedDrift['CLASSMATE']['user_id'];
    v4BrokerRuntimeExecute(
        $db,
        "UPDATE `{$userTable}` SET `password`='{$driftAdminHash}' WHERE `id`={$driftAdminUser}",
    );
    $expireLease(60);
    v4BrokerRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(60),
        'class_identity_fixture_lease_http_authorization_expired',
    );
    ++$assertions;
    $driftRecovery = $service->recoverAbandonedIdentityLease(60, $driftRun, $owner, 300);
    $assert(
        v4fqaQuarantineTopologyConflict(
            $db,
            $prefix,
            new ClassIdentityAdminService(),
            $service,
            $driftRecovery,
            ['identity_state' => 'FROZEN'],
            $persistedDrift,
            $revoke,
        ),
        'topology_drift_quarantine_failed',
    );
    foreach ($persistedDrift as $role => $item) {
        $expectedDigest = $role === 'CLASSMATE'
            ? hash('sha256', $driftAdminHash)
            : (string) $item['closed_password_sha256'];
        $assert(
            hash_equals($expectedDigest, $passwordDigest((int) $item['user_id'])),
            'topology_drift_credential_cleanup_invalid',
        );
    }
    $identity = v4BrokerRuntimeOne($db, "SELECT `state` FROM `{$identityTable}` WHERE `id`=60");
    $assert($identity['state'] === 'FROZEN', 'topology_drift_identity_not_frozen');
    $driftLease = v4BrokerRuntimeOne(
        $db,
        "SELECT `state`,(`expires_at`<=UTC_TIMESTAMP(6)) AS expired FROM `{$leaseTable}` WHERE `resource_id`=60 ORDER BY `acquired_at` DESC LIMIT 1",
    );
    $assert($driftLease['state'] === 'CONFLICT', 'topology_drift_not_quarantined');

    // A cleanup helper/Core revoke exception must not leave an unexpired
    // ACTIVE lease authorized. Quarantine continues to the durable CONFLICT
    // state immediately; Identity state remains frozen and HTTP denies.
    $cleanupThrowState = $addFixture(70);
    $cleanupThrowRun = str_repeat('8', 24);
    $cleanupThrowContext = $service->acquireIdentityLease(70, $cleanupThrowRun, $owner, 300, 0);
    $cleanupThrowPlan = v4fqaBuildCredentialPlan($cleanupThrowState['accounts']);
    v4fqaInstallCredentialPlan(
        $service,
        $cleanupThrowContext,
        $cleanupThrowPlan,
        70,
        900001,
        900002,
        $revoke,
        $audit,
    );
    $cleanupThrowQuarantined = v4fqaQuarantineTopologyConflict(
        $db,
        $prefix,
        new ClassIdentityAdminService(),
        $service,
        $cleanupThrowContext,
        ['identity_state' => 'FROZEN'],
        $cleanupThrowPlan,
        static function (): void {
            throw new RuntimeException('simulated_cleanup_revoke_throw');
        },
    );
    $assert($cleanupThrowQuarantined, 'cleanup_throw_quarantine_failed');
    $cleanupThrowLease = v4BrokerRuntimeOne(
        $db,
        "SELECT `state`,(`expires_at`>UTC_TIMESTAMP(6)) AS live FROM `{$leaseTable}` WHERE `resource_id`=70 ORDER BY `acquired_at` DESC LIMIT 1",
    );
    $assert($cleanupThrowLease['state'] === 'CONFLICT' && (int) $cleanupThrowLease['live'] === 1,
        'cleanup_throw_lease_not_immediately_conflicted');
    v4BrokerRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(70),
        'class_identity_fixture_lease_http_authorization_conflict',
    );
    ++$assertions;
} catch (Throwable $error) {
    fwrite(STDERR, 'V4_OWNER_FQA_BROKER_RUNTIME=FAIL reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    foreach ($files as $file) {
        if (is_string($file) && (file_exists($file) || is_link($file))) {
            @unlink($file);
        }
    }
    foreach ([$leaseTable, $principalTable, $accountTable, $seatTable, $userTable, $identityTable] as $table) {
        @$db->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $db->close();
}

if ($exit === 0) {
    fwrite(
        STDOUT,
        "V4_OWNER_FQA_BROKER_RUNTIME=PASS assertions={$assertions} normal=CLOSED helper_throw_finally=CLOSED crash_first=RECOVERED crash_middle=RECOVERED admin_change=PRESERVED_CONFLICT missing_plan=DENIED topology_drift=CLEANED_CONFLICT cleanup_throw=DENIED_CONFLICT scope=SYNTHETIC_RANDOM_PREFIX\n",
    );
}
exit($exit);
