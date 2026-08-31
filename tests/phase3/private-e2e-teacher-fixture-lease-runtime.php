<?php

declare(strict_types=1);

/**
 * Disposable synthetic runtime proof for the generic Teacher fixture adapter.
 *
 * This uses a random MariaDB table prefix, creates no private Owner records,
 * opens no browser and drops every table in finally.
 */

function teacherFixtureRuntimeFail(string $code): never
{
    throw new RuntimeException($code);
}

function teacherFixtureRuntimeExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) !== true) {
        teacherFixtureRuntimeFail('teacher_fixture_runtime_query_failed_' . $db->errno);
    }
}

function teacherFixtureRuntimeRejected(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) {
            return;
        }
        throw $error;
    }
    teacherFixtureRuntimeFail('teacher_fixture_runtime_expected_rejection_missing_' . $expected);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "PRIVATE_E2E_TEACHER_FIXTURE_LEASE_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "PRIVATE_E2E_TEACHER_FIXTURE_LEASE_RUNTIME=FAIL reason=nginx_user_required\n");
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
    fwrite(STDERR, "PRIVATE_E2E_TEACHER_FIXTURE_LEASE_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/PrivateE2EFixtureLeaseService.php';
define('PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY', true);
require __DIR__ . '/private-e2e-teacher-fixture-lease.php';

$run = strtolower(bin2hex(random_bytes(12)));
$prefix = 'ci_tfl_' . substr($run, 0, 12) . '_';
$identity = $prefix . 'class_identity_identity';
$lease = $prefix . 'class_identity_private_e2e_fixture_lease';
$seat = $prefix . 'class_identity_seat';
$account = $prefix . 'class_identity_account';
$principal = $prefix . 'class_identity_principal';
$coreUser = $prefix . 'users';
$assertions = 0;
$exit = 0;

$assert = static function (bool $condition, string $code) use (&$assertions): void {
    if (!$condition) {
        teacherFixtureRuntimeFail($code);
    }
    ++$assertions;
};

try {
    teacherFixtureRuntimeExecute($db, <<<SQL
CREATE TABLE `{$identity}` (
  `id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    teacherFixtureRuntimeExecute($db, "INSERT INTO `{$identity}` (`id`,`state`,`lock_version`) VALUES (7,'FROZEN',0),(8,'FROZEN',0)");
    teacherFixtureRuntimeExecute($db, "CREATE TABLE `{$seat}` (`id` BIGINT UNSIGNED NOT NULL,`identity_id` BIGINT UNSIGNED NOT NULL,`state` VARCHAR(24) NOT NULL,`lock_version` INT UNSIGNED NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    teacherFixtureRuntimeExecute($db, "CREATE TABLE `{$account}` (`id` BIGINT UNSIGNED NOT NULL,`seat_id` BIGINT UNSIGNED NOT NULL,`state` VARCHAR(32) NOT NULL,`current_marker` TINYINT UNSIGNED NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    teacherFixtureRuntimeExecute($db, "CREATE TABLE `{$principal}` (`id` BIGINT UNSIGNED NOT NULL,`account_id` BIGINT UNSIGNED NULL,`piwigo_user_id` BIGINT UNSIGNED NOT NULL,`principal_type` VARCHAR(24) NOT NULL,`state` VARCHAR(16) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    teacherFixtureRuntimeExecute($db, "CREATE TABLE `{$coreUser}` (`id` INT UNSIGNED NOT NULL,`username` VARCHAR(100) NOT NULL,`password` VARCHAR(255) NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB");
    teacherFixtureRuntimeExecute($db, "INSERT INTO `{$seat}` VALUES (70,7,'ACTIVE',0),(80,8,'ACTIVE',0)");
    teacherFixtureRuntimeExecute($db, "INSERT INTO `{$account}` VALUES (700,70,'ACTIVE',1),(800,80,'ACTIVE',1)");
    teacherFixtureRuntimeExecute($db, "INSERT INTO `{$principal}` VALUES (7000,700,70000,'SEAT_ACCOUNT','ACTIVE'),(8000,800,80000,'SEAT_ACCOUNT','ACTIVE')");
    teacherFixtureRuntimeExecute($db, "INSERT INTO `{$coreUser}` VALUES (70000,'" . privateE2ETeacherFixtureUsername($run) . "','before-hash')");
    $service = new \ClassIdentity\PrivateE2EFixtureLeaseService($db, $prefix);
    $descriptor = [
        'identity' => ['id' => 7, 'roster_code' => privateE2ETeacherFixtureRoster($run), 'identity_type' => 'TEACHER', 'state' => 'FROZEN', 'lock_version' => 0],
        'seat' => ['id' => 70, 'identity_id' => 7, 'seat_type' => 'TEACHER', 'state' => 'ACTIVE', 'lock_version' => 0],
        'account' => ['id' => 700, 'seat_id' => 70, 'requested_username' => privateE2ETeacherFixtureUsername($run), 'state' => 'ACTIVE', 'current_marker' => 1],
        'principal' => ['id' => 7000, 'account_id' => 700, 'piwigo_user_id' => 70000, 'principal_type' => 'SEAT_ACCOUNT', 'state' => 'ACTIVE'],
    ];

    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK');
    teacherFixtureRuntimeRejected(
        static fn() => privateE2ETeacherFixtureAcquireLease($service, $descriptor, $run, 300),
        'teacher_fixture_disabled',
    );
    ++$assertions;

    $wrongType = $descriptor;
    $wrongType['identity']['identity_type'] = 'CLASSMATE';
    teacherFixtureRuntimeRejected(
        static fn() => privateE2ETeacherFixtureValidateDescriptor($wrongType, $run),
        'teacher_fixture_descriptor_invariant_failed',
    );
    ++$assertions;
    $fqaAggregate = $descriptor;
    $fqaAggregate['identity']['roster_code'] = 'FQA-C-99CA3B3B6AF1';
    teacherFixtureRuntimeRejected(
        static fn() => privateE2ETeacherFixtureValidateDescriptor($fqaAggregate, $run),
        'teacher_fixture_descriptor_invariant_failed',
    );
    ++$assertions;

    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' . PRIVATE_E2E_TEACHER_FIXTURE_ACK);
    $opened = privateE2ETeacherFixtureAcquireLease($service, $descriptor, $run, 300);
    $fixture = $opened['descriptor'];
    $context = $opened['lease'];
    $assert($context->targetId() === 7 && $context->owner() === PRIVATE_E2E_TEACHER_FIXTURE_OWNER, 'teacher_fixture_lease_binding_invalid');
    $service->assertIdentityHttpAuthorizationAllowed(7);
    ++$assertions;
    $service->heartbeat($context, 300);
    $assert($context->leaseRevision() === 2, 'teacher_fixture_heartbeat_not_advanced');

    $revoked = [];
    $audits = [];
    privateE2ETeacherFixtureInstallCredential(
        $service,
        $context,
        $fixture,
        'before-hash',
        'leased-hash',
        static function (int $userId) use (&$revoked): void { $revoked[] = $userId; },
        static function (array $record) use (&$audits): void { $audits[] = $record; },
    );
    $afterInstall = $db->query("SELECT `password` FROM `{$coreUser}` WHERE `id`=70000");
    $installRow = $afterInstall instanceof mysqli_result ? $afterInstall->fetch_row() : null;
    if ($afterInstall instanceof mysqli_result) { $afterInstall->free(); }
    $assert(($installRow[0] ?? null) === 'leased-hash' && $revoked === [70000], 'teacher_fixture_credential_install_invalid');
    $assert(($audits[0]['state'] ?? null) === 'LEASE_OPEN' && !array_key_exists('password', $audits[0]), 'teacher_fixture_open_audit_invalid');

    $closed = privateE2ETeacherFixtureCloseCredential(
        $service,
        $context,
        $fixture,
        'leased-hash',
        'closed-hash',
        static function (int $userId) use (&$revoked): void { $revoked[] = $userId; },
        static function (array $record) use (&$audits): void { $audits[] = $record; },
    );
    $afterClose = $db->query("SELECT `password` FROM `{$coreUser}` WHERE `id`=70000");
    $closeRow = $afterClose instanceof mysqli_result ? $afterClose->fetch_row() : null;
    if ($afterClose instanceof mysqli_result) { $afterClose->free(); }
    $assert($closed && ($closeRow[0] ?? null) === 'closed-hash' && $revoked === [70000, 70000], 'teacher_fixture_credential_cleanup_invalid');
    $assert(($audits[1]['state'] ?? null) === 'LEASE_CLOSED' && !array_key_exists('lease_password_hash', $audits[1]), 'teacher_fixture_close_audit_invalid');

    $recovery = privateE2ETeacherFixtureRecoveryDocument($fixture, $run, 'before-hash', 'leased-hash', 'closed-hash');
    $assert(
        ($recovery['environment'] ?? null) === PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_ENVIRONMENT
            && !array_key_exists('roles', $recovery)
            && !array_key_exists('before_password_hash', $recovery['recovery_plan'] ?? [])
            && ($recovery['recovery_plan']['lease_password_sha256'] ?? null) === hash('sha256', 'leased-hash'),
        'teacher_fixture_recovery_secret_boundary_invalid',
    );
    $browser = privateE2ETeacherFixtureBrowserCredentialDocument($fixture, $run, str_repeat('A', 64));
    $assert(
        array_keys($browser['roles'] ?? []) === ['teacher']
            && !array_key_exists('recovery_plan', $browser)
            && ($browser['lease']['role'] ?? null) === 'TEACHER',
        'teacher_fixture_browser_document_boundary_invalid',
    );

    // Cleanup survives a later feature-flag change, while acquisition never
    // does. A disabled gate must not strand an already-held durable lease.
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK');
    privateE2ETeacherFixtureReleaseLease($service, $context);
    $service->assertIdentityHttpAuthorizationAllowed(7);
    ++$assertions;

    $runConflict = strtolower(bin2hex(random_bytes(12)));
    $conflictDescriptor = [
        'identity' => ['id' => 8, 'roster_code' => privateE2ETeacherFixtureRoster($runConflict), 'identity_type' => 'TEACHER', 'state' => 'FROZEN', 'lock_version' => 0],
        'seat' => ['id' => 80, 'identity_id' => 8, 'seat_type' => 'TEACHER', 'state' => 'ACTIVE', 'lock_version' => 0],
        'account' => ['id' => 800, 'seat_id' => 80, 'requested_username' => privateE2ETeacherFixtureUsername($runConflict), 'state' => 'ACTIVE', 'current_marker' => 1],
        'principal' => ['id' => 8000, 'account_id' => 800, 'piwigo_user_id' => 80000, 'principal_type' => 'SEAT_ACCOUNT', 'state' => 'ACTIVE'],
    ];
    teacherFixtureRuntimeExecute($db, "INSERT INTO `{$coreUser}` VALUES (80000,'" . privateE2ETeacherFixtureUsername($runConflict) . "','before-conflict-hash')");
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' . PRIVATE_E2E_TEACHER_FIXTURE_ACK);
    $conflictOpened = privateE2ETeacherFixtureAcquireLease($service, $conflictDescriptor, $runConflict, 300);
    $conflictFixture = $conflictOpened['descriptor'];
    $conflictContext = $conflictOpened['lease'];
    privateE2ETeacherFixtureInstallCredential(
        $service,
        $conflictContext,
        $conflictFixture,
        'before-conflict-hash',
        'leased-conflict-hash',
        static function (int $userId) use (&$revoked): void { $revoked[] = $userId; },
        static function (array $record) use (&$audits): void { $audits[] = $record; },
    );
    teacherFixtureRuntimeExecute($db, "UPDATE `{$coreUser}` SET `password`='administrator-new-value' WHERE `id`=80000");
    $conflictClosed = privateE2ETeacherFixtureCloseCredential(
        $service,
        $conflictContext,
        $conflictFixture,
        'leased-conflict-hash',
        'closed-conflict-hash',
        static function (int $userId) use (&$revoked): void { $revoked[] = $userId; },
        static function (array $record) use (&$audits): void { $audits[] = $record; },
    );
    $afterConflict = $db->query("SELECT `password` FROM `{$coreUser}` WHERE `id`=80000");
    $conflictRow = $afterConflict instanceof mysqli_result ? $afterConflict->fetch_row() : null;
    if ($afterConflict instanceof mysqli_result) { $afterConflict->free(); }
    $assert(!$conflictClosed && ($conflictRow[0] ?? null) === 'administrator-new-value', 'teacher_fixture_conflict_overwrote_admin_password');
    $assert(($audits[3]['state'] ?? null) === 'LEASE_CONFLICT', 'teacher_fixture_conflict_audit_invalid');
    $service->markConflict($conflictContext);
    teacherFixtureRuntimeRejected(
        static fn() => $service->assertIdentityHttpAuthorizationAllowed(8),
        'class_identity_fixture_lease_http_authorization_conflict',
    );
    ++$assertions;
    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK');
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_E2E_TEACHER_FIXTURE_LEASE_RUNTIME=FAIL reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE');
    putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK');
    foreach ([$lease, $principal, $account, $seat, $coreUser, $identity] as $table) {
        @$db->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $db->close();
}

if ($exit === 0) {
    fwrite(STDOUT, "PRIVATE_E2E_TEACHER_FIXTURE_LEASE_RUNTIME=PASS assertions={$assertions} mode=DISABLED_BY_DEFAULT lease=CAS credential_cleanup=CAS scope=SYNTHETIC_RANDOM_PREFIX\n");
}
exit($exit);
