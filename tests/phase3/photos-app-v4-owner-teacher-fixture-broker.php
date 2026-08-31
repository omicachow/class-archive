<?php

declare(strict_types=1);

/**
 * Local-private, CLI-only Teacher fixture broker for V4 role acceptance.
 *
 * The historical FQA aggregate intentionally has no Teacher seat.  This
 * broker creates (or resumes) one test-namespaced TEACHER aggregate through
 * the normal AdminService -> Claim -> ProvisioningService lifecycle, then
 * freezes it before it may be leased for a bounded Chrome run.  It never
 * exposes an HTTP/API surface, never uses SQL to create fixture identities,
 * and never persists a browser password.  A narrow 0600 recovery ledger
 * contains only topology plus password-verifier digests so interrupted work
 * can be quarantined or closed without retaining a reusable credential.
 *
 * Required explicit gates (all default to disabled):
 *   CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1
 *   CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1
 *   CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=PRIVATE_REAL_FULL_OWNER
 *   CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=LEASED_TEACHER_FIXTURE_V1
 *   CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=<24 lowercase hex>
 *   ..._ENSURE=1 xor ..._LEASE=1 xor ..._RECOVERY=1
 *
 * ENSURE mode is deliberately separate: it completes the normal formal Claim
 * and immediately leaves the FQA-T identity frozen, so a later pre-migration
 * snapshot captures its terminal fixture state.  Lease mode therefore never
 * creates, claims, or first-freezes an identity after that snapshot.  It
 * accepts exactly one `EXPORT <run>` line and one `STOP <run>`
 * line on stdin.  The export is a base64url JSON browser document and must be
 * consumed only by the owning parent process; callers must never log it.
 */

const V4_TEACHER_BROKER_ROOT = '/var/www/html/piwigo';
// The test harness is deliberately mounted read-only outside the writable
// Piwigo document root. Keep its trust boundary explicit instead of assuming
// it is mirrored under the application tree.
const V4_TEACHER_BROKER_WORKSPACE_ROOT = '/workspace';
const V4_TEACHER_BROKER_RECOVERY_ROOT = '/var/lib/class-archive-private-e2e';
const V4_TEACHER_BROKER_LOCK = 'class_archive_v4_owner_teacher_fixture_broker_v1';
const V4_TEACHER_BROKER_OWNER = 'v4-owner-teacher-fixture-broker';
// A single persistent, opaque test namespace is intentionally reused. The
// private snapshot comparator recognizes only its closed, FROZEN terminal
// state; arbitrary FQA-T runs are rejected rather than silently accumulating
// exempt identities in the Owner database.
const V4_TEACHER_BROKER_PERSISTENT_RUN = '3e2f1a94b0c74d81952e6f0a';
const V4_TEACHER_BROKER_TARGET = 'PRIVATE_REAL_FULL_OWNER';
const V4_TEACHER_BROKER_LEDGER_VERSION = 1;
const V4_TEACHER_BROKER_LEDGER_ENVIRONMENT = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_FIXTURE';
const V4_TEACHER_BROKER_BROWSER_ENVIRONMENT = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_BROWSER_EXPORT';
const V4_TEACHER_BROKER_MAX_LEDGER_BYTES = 65536;

final class V4TeacherBrokerFailure extends RuntimeException
{
}

function v4teacherFail(string $code): never
{
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'teacher_broker_failure_invalid';
    }
    throw new V4TeacherBrokerFailure($code);
}

function v4teacherSafeThrowableCode(\Throwable $error): string
{
    // A broker failure record is intentionally a closed diagnostic surface.
    // Only bounded Class Archive implementation codes can leave the process;
    // database text, paths, user data and every unknown exception remain
    // opaque so a failed local acceptance run cannot become a secret sink.
    $message = $error->getMessage();
    if (preg_match('/\A(?:class_identity|teacher_broker)_[a-z0-9_]{1,80}\z/D', $message) === 1) {
        return $message;
    }
    if ($error instanceof \mysqli_sql_exception) {
        return 'teacher_broker_runtime_sql';
    }
    if ($error instanceof \TypeError) {
        return 'teacher_broker_runtime_type';
    }
    return 'unexpected';
}

function v4teacherRun(string $run): string
{
    if (preg_match('/\A[a-f0-9]{24}\z/D', $run) !== 1) {
        v4teacherFail('teacher_broker_run_invalid');
    }
    return $run;
}

function v4teacherPersistentRun(string $run): string
{
    $run = v4teacherRun($run);
    if (!hash_equals(V4_TEACHER_BROKER_PERSISTENT_RUN, $run)) {
        v4teacherFail('teacher_broker_persistent_run_required');
    }
    return $run;
}

function v4teacherSecret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

function v4teacherBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @param list<mixed> $params
 * @return list<array<string,mixed>>
 */
function v4teacherRows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        v4teacherFail('teacher_broker_query_prepare_failed');
    }
    try {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            v4teacherFail('teacher_broker_query_execute_failed');
        }
        $result = $stmt->get_result();
        if (!$result instanceof mysqli_result) {
            v4teacherFail('teacher_broker_query_result_failed');
        }
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $stmt->close();
    }
}

function v4teacherScalar(mysqli $db, string $sql): int
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        v4teacherFail('teacher_broker_scalar_query_failed');
    }
    try {
        $row = $result->fetch_row();
    } finally {
        $result->free();
    }
    if (!is_array($row) || count($row) !== 1) {
        v4teacherFail('teacher_broker_scalar_invalid');
    }
    return (int) $row[0];
}

function v4teacherRequirePrivateCli(bool $recoveryMode = false): void
{
    if (PHP_SAPI !== 'cli'
        || getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE') !== '1'
        || !hash_equals(
            V4_TEACHER_BROKER_TARGET,
            (string) getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET'),
        )
        || !hash_equals(
            'LEASED_TEACHER_FIXTURE_V1',
            (string) getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK'),
        )
        || ($recoveryMode && getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY') !== '1')
        || (!$recoveryMode && getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1')
    ) {
        v4teacherFail('teacher_broker_disabled');
    }
}

/**
 * A browser password is emitted only as one framed record to a supervising
 * process.  A terminal is not a credential sink: it is too easy to retain the
 * line in scrollback, a transcript, or a screen recording.  STATUS frames may
 * use the same pipe, but a lease is never acquired when stdout is a TTY.
 */
function v4teacherRequirePipeStdout(): void
{
    $stdoutIsTty = (function_exists('stream_isatty') && @stream_isatty(STDOUT))
        || (function_exists('posix_isatty') && @posix_isatty(STDOUT));
    $stdout = @fstat(STDOUT);
    $stdin = @fstat(STDIN);
    // The broker runs in the Linux Piwigo container. Accept only a FIFO or
    // socket for both protocol streams; regular-file redirection is a durable
    // transcript and must never receive a browser password.
    $pipeTypes = [0010000, 0140000];
    $stdoutType = is_array($stdout) ? ((int) ($stdout['mode'] ?? 0) & 0170000) : 0;
    $stdinType = is_array($stdin) ? ((int) ($stdin['mode'] ?? 0) & 0170000) : 0;
    if ($stdoutIsTty || !in_array($stdoutType, $pipeTypes, true) || !in_array($stdinType, $pipeTypes, true)) {
        v4teacherFail('teacher_broker_stdout_pipe_required');
    }
}

/**
 * The lease service deliberately blocks all acquisition while normal private
 * E2E is disabled. A recovery process is not a new acquisition: after the
 * broker has proven the fixed private target, explicit recovery mode may
 * temporarily satisfy that internal service guard in its own short-lived CLI
 * process. The caller never has to re-enable test acquisition globally.
 */
function v4teacherWithRecoveryLeasePermit(callable $callback): mixed
{
    $previous = getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
    if ($previous === '1') {
        return $callback();
    }
    if (!putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1')) {
        v4teacherFail('teacher_broker_recovery_permit_failed');
    }
    try {
        return $callback();
    } finally {
        if ($previous === false) {
            putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED');
        } else {
            putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=' . $previous);
        }
    }
}

function v4teacherAssertPath(string $actual, string $expected, string $code): void
{
    $actual = realpath($actual) ?: '';
    $expected = realpath($expected) ?: '';
    if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
        v4teacherFail($code);
    }
}

function v4teacherEnsureRecoveryRoot(): void
{
    if (!is_dir(V4_TEACHER_BROKER_RECOVERY_ROOT)
        && !mkdir(V4_TEACHER_BROKER_RECOVERY_ROOT, 0700, true)
        && !is_dir(V4_TEACHER_BROKER_RECOVERY_ROOT)
    ) {
        v4teacherFail('teacher_broker_recovery_root_create_failed');
    }
    clearstatcache(true, V4_TEACHER_BROKER_RECOVERY_ROOT);
    $stat = @lstat(V4_TEACHER_BROKER_RECOVERY_ROOT);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0040000
        || (($stat['mode'] ?? 0) & 0077) !== 0
        || is_link(V4_TEACHER_BROKER_RECOVERY_ROOT)
    ) {
        v4teacherFail('teacher_broker_recovery_root_invalid');
    }
}

function v4teacherLedgerPath(string $run): string
{
    return V4_TEACHER_BROKER_RECOVERY_ROOT . '/teacher-fixture-' . v4teacherRun($run) . '.json';
}

/** @param array<string,mixed> $ledger */
function v4teacherWriteLedger(string $path, array $ledger): void
{
    $json = json_encode($ledger, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > V4_TEACHER_BROKER_MAX_LEDGER_BYTES) {
        v4teacherFail('teacher_broker_ledger_too_large');
    }
    if ((file_exists($path) || is_link($path))) {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current)
            || (($current['mode'] ?? 0) & 0170000) !== 0100000
            || (($current['mode'] ?? 0) & 0777) !== 0600
            || (int) ($current['nlink'] ?? 0) !== 1
            || is_link($path)
        ) {
            v4teacherFail('teacher_broker_ledger_target_invalid');
        }
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $previousUmask = umask(0077);
    try {
        $handle = fopen($temporary, 'x');
    } finally {
        umask($previousUmask);
    }
    if (!is_resource($handle)) {
        v4teacherFail('teacher_broker_ledger_create_failed');
    }
    try {
        if (!function_exists('fsync')
            || !chmod($temporary, 0600)
            || fwrite($handle, $json) !== strlen($json)
            || !fflush($handle)
            || !fsync($handle)
        ) {
            v4teacherFail('teacher_broker_ledger_write_failed');
        }
    } finally {
        fclose($handle);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        v4teacherFail('teacher_broker_ledger_replace_failed');
    }
    $directory = @fopen(dirname($path), 'r');
    if (!is_resource($directory)) {
        v4teacherFail('teacher_broker_ledger_directory_open_failed');
    }
    try {
        if (!fsync($directory)) {
            v4teacherFail('teacher_broker_ledger_directory_fsync_failed');
        }
    } finally {
        fclose($directory);
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || is_link($path)
    ) {
        v4teacherFail('teacher_broker_ledger_written_invalid');
    }
}

function v4teacherRemoveLedger(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || is_link($path)
        || !unlink($path)
    ) {
        v4teacherFail('teacher_broker_ledger_cleanup_failed');
    }
}

/** @return array<string,mixed> */
function v4teacherLedger(string $run, ?int $identityId = null, ?int $seatId = null, string $stage = 'CREATING', ?array $recovery = null): array
{
    if (!in_array($stage, ['CREATING', 'IDENTITY_CREATED', 'CLAIM_ISSUED', 'PROVISIONED', 'FROZEN', 'LEASE_ACQUIRING', 'LEASE_ACQUIRED', 'LEASE_PREPARED', 'LEASE_OPEN', 'CONFLICT'], true)) {
        v4teacherFail('teacher_broker_ledger_stage_invalid');
    }
    return [
        'version' => V4_TEACHER_BROKER_LEDGER_VERSION,
        'environment' => V4_TEACHER_BROKER_LEDGER_ENVIRONMENT,
        'run' => v4teacherRun($run),
        'roster' => privateE2ETeacherFixtureRoster($run),
        'username' => privateE2ETeacherFixtureUsername($run),
        'stage' => $stage,
        'identity_id' => $identityId,
        'seat_id' => $seatId,
        'recovery' => $recovery,
    ];
}

/** @return array<string,mixed> */
function v4teacherReadLedger(string $path, string $run): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || is_link($path)
        || (int) ($stat['size'] ?? 0) < 64
        || (int) ($stat['size'] ?? 0) > V4_TEACHER_BROKER_MAX_LEDGER_BYTES
    ) {
        v4teacherFail('teacher_broker_ledger_invalid');
    }
    $raw = file_get_contents($path);
    try {
        $document = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable) {
        $document = null;
    }
    $expected = ['environment', 'identity_id', 'recovery', 'roster', 'run', 'seat_id', 'stage', 'username', 'version'];
    $actual = is_array($document) ? array_keys($document) : [];
    sort($actual, SORT_STRING);
    if (!is_array($document)
        || $actual !== $expected
        || ($document['version'] ?? null) !== V4_TEACHER_BROKER_LEDGER_VERSION
        || ($document['environment'] ?? null) !== V4_TEACHER_BROKER_LEDGER_ENVIRONMENT
        || ($document['run'] ?? null) !== v4teacherRun($run)
        || ($document['roster'] ?? null) !== privateE2ETeacherFixtureRoster($run)
        || ($document['username'] ?? null) !== privateE2ETeacherFixtureUsername($run)
        || !in_array($document['stage'] ?? null, ['CREATING', 'IDENTITY_CREATED', 'CLAIM_ISSUED', 'PROVISIONED', 'FROZEN', 'LEASE_ACQUIRING', 'LEASE_ACQUIRED', 'LEASE_PREPARED', 'LEASE_OPEN', 'CONFLICT'], true)
        || !($document['identity_id'] === null || (is_int($document['identity_id']) && $document['identity_id'] > 0))
        || !($document['seat_id'] === null || (is_int($document['seat_id']) && $document['seat_id'] > 0))
        || !($document['recovery'] === null || is_array($document['recovery']))
    ) {
        v4teacherFail('teacher_broker_ledger_contract_invalid');
    }
    return $document;
}

/** @return array{user_id:int,principal_id:int} */
function v4teacherSystemAdmin(mysqli $db, string $prefix): array
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
        v4teacherFail('teacher_broker_table_prefix_invalid');
    }
    $ci = $prefix . 'class_identity_';
    $rows = v4teacherRows($db, <<<SQL
SELECT p.id AS principal_id,p.piwigo_user_id AS user_id
  FROM `{$ci}principal` p
  JOIN `{$prefix}user_infos` ui ON ui.user_id=p.piwigo_user_id
 WHERE p.principal_type='SYSTEM_ACCOUNT' AND p.system_role='SYSTEM_ADMIN'
   AND p.account_id IS NULL AND p.state='ACTIVE' AND ui.status IN ('admin','webmaster')
 ORDER BY p.id
SQL);
    if (count($rows) !== 1 || (int) ($rows[0]['user_id'] ?? 0) <= 0 || (int) ($rows[0]['principal_id'] ?? 0) <= 0) {
        v4teacherFail('teacher_broker_system_admin_invalid');
    }
    return ['user_id' => (int) $rows[0]['user_id'], 'principal_id' => (int) $rows[0]['principal_id']];
}

/** @return array{identity_id:int,state:string,lock_version:int,current_accounts:int,seat_id:int|null}|null */
function v4teacherIdentityEnvelope(mysqli $db, string $prefix, string $run): ?array
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
        v4teacherFail('teacher_broker_table_prefix_invalid');
    }
    $ci = $prefix . 'class_identity_';
    $roster = privateE2ETeacherFixtureRoster($run);
    $rows = v4teacherRows($db, <<<SQL
SELECT i.id,i.state,i.lock_version,i.identity_type,
       (SELECT COUNT(*) FROM `{$ci}account` a
          JOIN `{$ci}seat` s ON s.id=a.seat_id
         WHERE s.identity_id=i.id AND a.current_marker=1) AS current_accounts,
       (SELECT s.id FROM `{$ci}seat` s WHERE s.identity_id=i.id AND s.seat_type='TEACHER' LIMIT 2) AS seat_id
  FROM `{$ci}identity` i
 WHERE i.roster_code=?
 LIMIT 2
SQL, 's', [$roster]);
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1
        || ($rows[0]['identity_type'] ?? null) !== 'TEACHER'
        || !in_array($rows[0]['state'] ?? null, ['ACTIVE', 'FROZEN'], true)
        || (int) ($rows[0]['id'] ?? 0) <= 0
        || (int) ($rows[0]['lock_version'] ?? -1) < 0
        || !($rows[0]['seat_id'] === null || (int) $rows[0]['seat_id'] > 0)
    ) {
        v4teacherFail('teacher_broker_identity_envelope_invalid');
    }
    return [
        'identity_id' => (int) $rows[0]['id'],
        'state' => (string) $rows[0]['state'],
        'lock_version' => (int) $rows[0]['lock_version'],
        'current_accounts' => (int) $rows[0]['current_accounts'],
        'seat_id' => $rows[0]['seat_id'] === null ? null : (int) $rows[0]['seat_id'],
    ];
}

/** @return array{fixture:array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string},password_hash:string}|null */
function v4teacherDescriptor(mysqli $db, string $prefix, string $run): ?array
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
        v4teacherFail('teacher_broker_table_prefix_invalid');
    }
    $ci = $prefix . 'class_identity_';
    $rows = v4teacherRows($db, <<<SQL
SELECT i.id AS identity_id,i.roster_code,i.identity_type,i.state AS identity_state,i.lock_version AS identity_lock_version,
       s.id AS seat_id,s.identity_id AS seat_identity_id,s.lock_version AS seat_lock_version,s.seat_type,s.state AS seat_state,
       a.id AS account_id,a.seat_id AS account_seat_id,a.requested_username,a.state AS account_state,a.current_marker,
       p.id AS principal_id,p.account_id AS principal_account_id,p.piwigo_user_id,p.principal_type,p.state AS principal_state,
       u.password AS core_password_hash
  FROM `{$ci}identity` i
  JOIN `{$ci}seat` s ON s.identity_id=i.id AND s.seat_type='TEACHER'
  JOIN `{$ci}account` a ON a.seat_id=s.id AND a.current_marker=1
  JOIN `{$ci}principal` p ON p.account_id=a.id AND p.principal_type='SEAT_ACCOUNT'
  JOIN `{$prefix}users` u ON u.id=p.piwigo_user_id
 WHERE i.roster_code=?
 LIMIT 2
SQL, 's', [privateE2ETeacherFixtureRoster($run)]);
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1 || !is_string($rows[0]['core_password_hash'] ?? null) || $rows[0]['core_password_hash'] === '') {
        v4teacherFail('teacher_broker_descriptor_query_invalid');
    }
    $row = $rows[0];
    $descriptor = [
        'identity' => [
            'id' => (int) $row['identity_id'], 'identity_type' => (string) $row['identity_type'],
            'lock_version' => (int) $row['identity_lock_version'], 'roster_code' => (string) $row['roster_code'],
            'state' => (string) $row['identity_state'],
        ],
        'seat' => [
            'id' => (int) $row['seat_id'], 'identity_id' => (int) $row['seat_identity_id'],
            'lock_version' => (int) $row['seat_lock_version'], 'seat_type' => (string) $row['seat_type'],
            'state' => (string) $row['seat_state'],
        ],
        'account' => [
            'current_marker' => (int) $row['current_marker'], 'id' => (int) $row['account_id'],
            'requested_username' => (string) $row['requested_username'], 'seat_id' => (int) $row['account_seat_id'],
            'state' => (string) $row['account_state'],
        ],
        'principal' => [
            'account_id' => (int) $row['principal_account_id'], 'id' => (int) $row['principal_id'],
            'piwigo_user_id' => (int) $row['piwigo_user_id'], 'principal_type' => (string) $row['principal_type'],
            'state' => (string) $row['principal_state'],
        ],
    ];
    return [
        'fixture' => privateE2ETeacherFixtureValidateDescriptor($descriptor, $run),
        'password_hash' => (string) $row['core_password_hash'],
    ];
}

/**
 * The frozen descriptor is intentionally unsuitable after unfreeze.  Re-read
 * the aggregate revision and resolve the ordinary authorization graph instead
 * of assuming that a successful mutation created a usable Teacher principal.
 * The service resolver is also where managed-group and active-lease policy are
 * enforced, so UNKNOWN remains DENY before READY or credential export.
 *
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture
 */
function v4teacherAssertLeasedTeacherAuthorization(
    mysqli $db,
    string $prefix,
    array $fixture,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $lease,
    string $run,
): void {
    $run = v4teacherPersistentRun($run);
    if ($lease->identityId() !== $fixture['identity_id']
        || !hash_equals(V4_TEACHER_BROKER_OWNER, $lease->fixtureOwner())
        || !hash_equals($run, $lease->testRunId())
    ) {
        v4teacherFail('teacher_broker_lease_context_invalid');
    }
    $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
    if ($envelope === null
        || $envelope['identity_id'] !== $fixture['identity_id']
        || $envelope['state'] !== 'ACTIVE'
        || $envelope['current_accounts'] !== 1
        || $envelope['seat_id'] !== $fixture['seat_id']
        || $envelope['lock_version'] !== $lease->expectedLockVersion()
    ) {
        v4teacherFail('teacher_broker_leased_identity_state_invalid');
    }
    $context = \ClassIdentity\Access::resolveAuthorizationContext($fixture['user_id']);
    if (!is_array($context)
        || ($context['role'] ?? null) !== \ClassIdentity\Access::ROLE_TEACHER
        || (int) ($context['identity_id'] ?? 0) !== $fixture['identity_id']
        || (int) ($context['seat_id'] ?? 0) !== $fixture['seat_id']
        || (int) ($context['account_id'] ?? 0) !== $fixture['account_id']
        || (int) ($context['principal_id'] ?? 0) !== $fixture['principal_id']
        || (int) ($context['piwigo_user_id'] ?? 0) !== $fixture['user_id']
    ) {
        v4teacherFail('teacher_broker_teacher_authorization_unresolved');
    }
}

function v4teacherPasswordHash(string $password): string
{
    global $conf;
    if (!isset($conf['password_hash']) || !is_callable($conf['password_hash'])) {
        v4teacherFail('teacher_broker_password_hasher_unavailable');
    }
    $hash = $conf['password_hash']($password);
    if (!is_string($hash) || $hash === '') {
        v4teacherFail('teacher_broker_password_hash_failed');
    }
    return $hash;
}

/** @param array{user_id:int,principal_id:int} $admin
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture
 */
function v4teacherAppendCredentialAudit(array $admin, array $fixture, array $newValue): void
{
    \ClassIdentity\Audit::fromPiwigo()->append([
        'actor_principal_id' => $admin['principal_id'],
        'actor_user_id' => $admin['user_id'],
        'actor_kind' => 'SYSTEM_ADMIN',
        'action' => 'PRINCIPAL_SECURITY_CHANGE',
        'target_type' => 'PRINCIPAL',
        'target_id' => (string) $fixture['principal_id'],
        'target_identity_id' => $fixture['identity_id'],
        'target_principal_id' => $fixture['principal_id'],
        'new_value' => $newValue,
        'reason' => '本地 V4 教师浏览器验收临时凭据租约',
        'result' => ($newValue['state'] ?? null) === 'LEASE_CONFLICT' ? 'FAILED' : 'SUCCESS',
    ]);
}

/**
 * Recovery itself is security-sensitive: it can close a credential lease or
 * leave an identity deliberately quarantined. Record both outcomes before a
 * ledger is removed. Failures are intentionally propagated so the caller
 * retains the ledger in CONFLICT for manual reconciliation.
 *
 * @param array{user_id:int,principal_id:int} $admin
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string}|null $fixture
 */
function v4teacherAppendRecoveryAudit(array $admin, ?array $fixture, ?int $identityId, string $state): void
{
    if (!in_array($state, ['LEASE_CLOSED', 'LEASE_CONFLICT', 'LEASE_CONFLICT_RESOLVED', 'TERMINAL_FROZEN'], true)) {
        v4teacherFail('teacher_broker_recovery_audit_input_invalid');
    }
    $identityId = is_int($identityId) && $identityId > 0 ? $identityId : null;
    $principalId = is_array($fixture) ? (int) $fixture['principal_id'] : null;
    \ClassIdentity\Audit::fromPiwigo()->append([
        'actor_principal_id' => $admin['principal_id'],
        'actor_user_id' => $admin['user_id'],
        'actor_kind' => 'SYSTEM_ADMIN',
        'action' => 'PRINCIPAL_SECURITY_CHANGE',
        'target_type' => $principalId !== null && $principalId > 0
            ? 'PRINCIPAL'
            : ($identityId !== null ? 'IDENTITY' : 'TEACHER_FIXTURE'),
        'target_id' => $principalId !== null && $principalId > 0
            ? (string) $principalId
            : ($identityId !== null ? (string) $identityId : privateE2ETeacherFixtureRoster(V4_TEACHER_BROKER_PERSISTENT_RUN)),
        'target_identity_id' => $identityId,
        'target_principal_id' => $principalId,
        'new_value' => [
            'state' => $state,
            'role_code' => 'TEACHER',
            // Keep structured audit text deliberately short. Audit values run
            // through the same credential-shaped-string defense as operator
            // supplied prose; a long underscore-delimited implementation
            // label would correctly be rejected as secret-like noise.
            'reason_code' => 'TEACHER_FIXTURE',
        ],
        'reason' => $state === 'LEASE_CONFLICT'
            ? '本地 V4 教师测试租约恢复检测到冲突，已隔离待核对'
            : ($state === 'LEASE_CONFLICT_RESOLVED'
                ? '本地 V4 教师测试租约冲突已在终态核验后安全解除'
                : '本地 V4 教师测试租约恢复已完成安全关闭'),
        'result' => $state === 'LEASE_CONFLICT' ? 'FAILED' : 'SUCCESS',
    ]);
}

/**
 * Resolve only the broker's own previously quarantined lease. A conflict is
 * never treated as harmless: first prove the exact frozen descriptor and the
 * password verifier are already terminal, then atomically audit and release
 * the CONFLICT row through the lease service's explicit reconciliation API.
 *
 * @param array<string,mixed> $ledger
 * @param array{user_id:int,principal_id:int} $admin
 */
function v4teacherResolveConflictLedger(
    mysqli $db,
    string $prefix,
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    array $ledger,
    array $admin,
    string $run,
    string $ledgerPath,
    int $identityId,
): bool {
    $stage = 'plan';
    try {
        $plan = v4teacherRecoveryPlan($ledger, $run);
        $stage = 'descriptor';
        $descriptor = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
        if ($descriptor === null
            || !v4teacherRecoveryPlanMatchesFixture($plan, $descriptor['fixture'])
            || $descriptor['fixture']['identity_id'] !== $identityId
        ) {
            v4teacherFail('teacher_broker_conflict_descriptor_invalid');
        }
        $stage = 'password';
        $current = v4teacherRows(
            $db,
            "SELECT password FROM `{$prefix}users` WHERE id=? AND BINARY username=BINARY ? LIMIT 2",
            'is',
            [(int) $plan['user_id'], (string) $plan['username']],
        );
        $hash = count($current) === 1 && is_string($current[0]['password'] ?? null)
            ? (string) $current[0]['password']
            : '';
        $digest = $hash === '' ? '' : hash('sha256', $hash);
        if (!hash_equals((string) $plan['before_password_sha256'], $digest)
            && !hash_equals((string) $plan['closed_password_sha256'], $digest)
        ) {
            v4teacherFail('teacher_broker_conflict_password_not_terminal');
        }
        $stage = 'terminal';
        v4teacherAssertTerminalCredentialState($db, $descriptor['fixture']);
        $stage = 'release';
        v4teacherWithRecoveryLeasePermit(static function () use (
            $leaseService, $descriptor, $run, $admin, $identityId,
        ): void {
            $leaseService->resolveConflictIdentityLease(
                $identityId,
                $run,
                V4_TEACHER_BROKER_OWNER,
                $descriptor['fixture']['lock_version'],
                static function () use ($admin, $descriptor, $identityId): void {
                    v4teacherAppendRecoveryAudit(
                        $admin,
                        $descriptor['fixture'],
                        $identityId,
                        'LEASE_CONFLICT_RESOLVED',
                    );
                },
            );
        });
        $stage = 'ledger_remove';
        v4teacherRemoveLedger($ledgerPath);
        return true;
    } catch (V4TeacherBrokerFailure $error) {
        throw $error;
    } catch (\RuntimeException $error) {
        // The lease service intentionally reports bounded, non-secret
        // transition codes. Preserve only a closed diagnostic category here:
        // it lets the local recovery procedure distinguish a stale CAS from
        // an audit/descriptor failure without ever reflecting row contents,
        // identifiers, or credential material into the terminal.
        $category = match ($error->getMessage()) {
            'class_identity_fixture_lease_conflict_resolution_version_conflict' => 'version',
            'class_identity_fixture_lease_conflict_resolution_required' => 'required',
            'class_identity_fixture_lease_conflict_resolution_conflict' => 'cas',
            'class_identity_fixture_lease_conflict_resolution_audit' => 'audit',
            'class_identity_fixture_lease_conflict_resolution_release' => 'release',
            'class_identity_fixture_lease_conflict_resolution_commit' => 'commit',
            default => 'service',
        };
        v4teacherFail('teacher_broker_conflict_reconciliation_' . $category . '_failed');
    } catch (\Throwable) {
        v4teacherFail('teacher_broker_conflict_reconciliation_' . $stage . '_failed');
    }
}

/** @return array{state:string,live:bool,run:string,owner:string}|null */
function v4teacherUnresolvedLease(mysqli $db, string $prefix, int $identityId): ?array
{
    if ($identityId <= 0 || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
        v4teacherFail('teacher_broker_lease_lookup_input_invalid');
    }
    $table = $prefix . 'class_identity_private_e2e_fixture_lease';
    // The table may legitimately be absent before the first ever lease. This
    // projection is read-only; it never creates storage while recovery is
    // deciding whether a FROZEN ledger can be safely removed.
    // MariaDB does not accept a parameter marker in SHOW TABLES LIKE through
    // mysqli prepared statements. Use the same schema-scoped metadata query
    // as the production services so recovery stays usable after a failed
    // browser lease rather than stranding its durable ledger.
    $exists = v4teacherRows(
        $db,
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1',
        's',
        [$table],
    );
    if ($exists === []) {
        return null;
    }
    $rows = v4teacherRows($db, <<<SQL
SELECT state,(expires_at>UTC_TIMESTAMP(6)) AS lease_live,test_run_id,fixture_owner
  FROM `{$table}`
 WHERE resource_type='IDENTITY' AND resource_id=? AND state IN ('ACTIVE','CONFLICT')
 ORDER BY acquired_at,lease_id
 LIMIT 2
SQL, 'i', [$identityId]);
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1
        || !in_array($rows[0]['state'] ?? null, ['ACTIVE', 'CONFLICT'], true)
        || !is_string($rows[0]['test_run_id'] ?? null)
        || !is_string($rows[0]['fixture_owner'] ?? null)
    ) {
        v4teacherFail('teacher_broker_unresolved_lease_ambiguous');
    }
    return [
        'state' => (string) $rows[0]['state'],
        'live' => (int) ($rows[0]['lease_live'] ?? 0) === 1,
        'run' => (string) $rows[0]['test_run_id'],
        'owner' => (string) $rows[0]['fixture_owner'],
    ];
}

/** @return array{fixture:array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string},password_hash:string}|null */
function v4teacherFrozenDescriptorOrNull(mysqli $db, string $prefix, string $run): ?array
{
    try {
        return v4teacherDescriptor($db, $prefix, $run);
    } catch (PrivateE2ETeacherFixtureLeaseFailure) {
        return null;
    }
}

/**
 * A terminal frozen fixture is not safe merely because its business rows say
 * FROZEN. Crash recovery can encounter a database state after a session was
 * created but before normal close completed. Revoke through the supported
 * Core path, then independently prove that neither a live Piwigo session nor
 * an unrevoked auth/API key remains before a ledger may be removed.
 *
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture
 */
function v4teacherAssertTerminalCredentialState(mysqli $db, array $fixture): void
{
    $userId = (int) ($fixture['user_id'] ?? 0);
    if ($userId <= 0
        || !defined('SESSIONS_TABLE')
        || !defined('USER_AUTH_KEYS_TABLE')
        || preg_match('/\A[A-Za-z0-9_]+\z/D', (string) SESSIONS_TABLE) !== 1
        || preg_match('/\A[A-Za-z0-9_]+\z/D', (string) USER_AUTH_KEYS_TABLE) !== 1
    ) {
        v4teacherFail('teacher_broker_terminal_credential_surface_invalid');
    }

    \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
    $sessionNeedle = '%pwg_uid|i:' . $userId . ';%';
    $sessions = v4teacherRows(
        $db,
        'SELECT 1 FROM `' . SESSIONS_TABLE . '` WHERE `data` LIKE ? LIMIT 1',
        's',
        [$sessionNeedle],
    );
    $keys = v4teacherRows(
        $db,
        'SELECT 1 FROM `' . USER_AUTH_KEYS_TABLE . '` WHERE `user_id`=? AND `revoked_on` IS NULL LIMIT 1',
        'i',
        [$userId],
    );
    if ($sessions !== [] || $keys !== []) {
        v4teacherFail('teacher_broker_terminal_credentials_live');
    }
}

/**
 * A pre-provision ledger can be closed only after an exact proof: either the
 * formal Teacher descriptor exists in its FROZEN terminal state, or no account
 * or principal exists and no issued/reserved claim remains. A generic freeze
 * alone is not enough evidence to delete a durable ledger.
 *
 * @return array{kind:'TERMINAL'|'PRE_PROVISION',identity_id:int,fixture:array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string}|null}|null
 */
function v4teacherPreProvisionRecoveryProof(mysqli $db, string $prefix, string $run): ?array
{
    $terminal = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
    if ($terminal !== null) {
        return ['kind' => 'TERMINAL', 'identity_id' => $terminal['fixture']['identity_id'], 'fixture' => $terminal['fixture']];
    }
    $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
    if ($envelope === null) {
        // No Identity means the create transaction never committed. There can
        // be no ClassIdentity account/token without that aggregate, and an
        // orphaned Core username would make a later formal Claim ambiguous, so
        // require its absence too before removing a creation ledger.
        $orphanedUser = v4teacherRows(
            $db,
            "SELECT COUNT(*) AS count FROM `{$prefix}users` WHERE BINARY username=BINARY ?",
            's',
            [privateE2ETeacherFixtureUsername($run)],
        );
        if ((int) ($orphanedUser[0]['count'] ?? -1) !== 0) {
            return null;
        }
        return ['kind' => 'PRE_PROVISION', 'identity_id' => 0, 'fixture' => null];
    }
    if ($envelope['state'] !== 'FROZEN' || $envelope['seat_id'] === null) {
        return null;
    }
    $ci = $prefix . 'class_identity_';
    $accounts = v4teacherRows($db, <<<SQL
SELECT COUNT(*) AS count
  FROM `{$ci}account` a JOIN `{$ci}seat` s ON s.id=a.seat_id
 WHERE s.identity_id=?
SQL, 'i', [$envelope['identity_id']]);
    $principals = v4teacherRows($db, <<<SQL
SELECT COUNT(*) AS count
  FROM `{$ci}principal` p JOIN `{$ci}account` a ON a.id=p.account_id
  JOIN `{$ci}seat` s ON s.id=a.seat_id
 WHERE s.identity_id=?
SQL, 'i', [$envelope['identity_id']]);
    $claims = v4teacherRows($db, "SELECT COUNT(*) AS count FROM `{$ci}token` WHERE seat_id=? AND purpose='CLAIM' AND state IN ('ISSUED','RESERVED')", 'i', [$envelope['seat_id']]);
    if ((int) ($accounts[0]['count'] ?? -1) !== 0
        || (int) ($principals[0]['count'] ?? -1) !== 0
        || (int) ($claims[0]['count'] ?? -1) !== 0
    ) {
        return null;
    }
    return ['kind' => 'PRE_PROVISION', 'identity_id' => $envelope['identity_id'], 'fixture' => null];
}

/** @param array<string,mixed> $ledger
 * @param array{user_id:int,principal_id:int} $admin
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string}|null $fixture
 */
function v4teacherRecoveryConflict(
    string $ledgerPath,
    string $run,
    array $ledger,
    array $admin,
    int $identityId,
    ?array $fixture,
    ?\ClassIdentity\PrivateE2EFixtureLeaseService $leaseService = null,
    ?\ClassIdentity\PrivateE2EFixtureLeaseContext $lease = null,
): never {
    $recovery = is_array($ledger['recovery'] ?? null) ? $ledger['recovery'] : null;
    $seatId = is_array($fixture) ? $fixture['seat_id'] : (($ledger['seat_id'] ?? null) === null ? null : (int) $ledger['seat_id']);
    v4teacherWriteLedger($ledgerPath, v4teacherLedger($run, $identityId > 0 ? $identityId : null, $seatId, 'CONFLICT', $recovery));
    if ($leaseService instanceof \ClassIdentity\PrivateE2EFixtureLeaseService
        && $lease instanceof \ClassIdentity\PrivateE2EFixtureLeaseContext
    ) {
        try {
            $leaseService->markConflict($lease);
        } catch (Throwable) {
        }
    }
    // If this audit throws, the already-persisted CONFLICT ledger remains and
    // the caller exits nonzero. Never suppress an unaudited recovery decision.
    v4teacherAppendRecoveryAudit($admin, $fixture, $identityId, 'LEASE_CONFLICT');
    v4teacherFail('teacher_broker_recovery_reconciliation_required');
}

/**
 * A recovery ledger is removed only after its terminal/closed audit event has
 * committed. If audit is unavailable, converting the ledger to CONFLICT is
 * safer than silently leaving an unaudited "successful" recovery behind.
 *
 * @param array<string,mixed> $ledger
 * @param array{user_id:int,principal_id:int} $admin
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string}|null $fixture
 */
function v4teacherRecoveryClosedOrConflict(
    string $ledgerPath,
    string $run,
    array $ledger,
    array $admin,
    ?int $identityId,
    ?array $fixture,
    string $state,
): void {
    try {
        v4teacherAppendRecoveryAudit($admin, $fixture, $identityId, $state);
    } catch (Throwable) {
        v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, (int) $identityId, $fixture);
    }
}

/** @param array<string,mixed> $ledger
 * @return array{fixture:array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string},password_hash:string}
 */
function v4teacherEnsureProvisionedFixture(
    mysqli $db,
    string $prefix,
    ClassIdentityAdminService $adminService,
    \ClassIdentity\ProvisioningService $provisioningService,
    array $admin,
    string $run,
    string $ledgerPath,
    array &$ledger,
): array {
    $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
    if ($envelope === null) {
        $ledger = v4teacherLedger($run, null, null, 'CREATING');
        v4teacherWriteLedger($ledgerPath, $ledger);
        $identityId = $adminService->createIdentity(
            'TEACHER',
            privateE2ETeacherFixtureRoster($run),
            'FQA 临时教师',
            '本地 V4 教师浏览器验收临时身份创建',
            $admin['user_id'],
        );
        $ledger = v4teacherLedger($run, $identityId, null, 'IDENTITY_CREATED');
        v4teacherWriteLedger($ledgerPath, $ledger);
        $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
    }
    if ($envelope === null || ($ledger['identity_id'] ?? null) !== null && (int) $ledger['identity_id'] !== $envelope['identity_id']) {
        v4teacherFail('teacher_broker_identity_recovery_drift');
    }
    $ledger = v4teacherLedger($run, $envelope['identity_id'], $envelope['seat_id'], (string) ($ledger['stage'] ?? 'IDENTITY_CREATED'));
    v4teacherWriteLedger($ledgerPath, $ledger);

    $descriptor = null;
    if ($envelope['current_accounts'] === 1) {
        if ($envelope['state'] === 'ACTIVE') {
            $adminService->setIdentityFrozen(
                $envelope['identity_id'], true, '本地 V4 教师验收身份预检冻结', $admin['user_id'], null, $envelope['lock_version'],
            );
        }
        $descriptor = v4teacherDescriptor($db, $prefix, $run);
        if ($descriptor === null) {
            v4teacherFail('teacher_broker_existing_account_topology_invalid');
        }
        $ledger = v4teacherLedger($run, $descriptor['fixture']['identity_id'], $descriptor['fixture']['seat_id'], 'FROZEN');
        v4teacherWriteLedger($ledgerPath, $ledger);
        return $descriptor;
    }
    if ($envelope['current_accounts'] !== 0 || $envelope['seat_id'] === null) {
        v4teacherFail('teacher_broker_existing_identity_topology_invalid');
    }
    if ($envelope['state'] === 'FROZEN') {
        // A partial create/claim may have been frozen by terminal recovery.
        // With no account there is no usable principal; unfreeze only long
        // enough for the ordinary Claim service to complete a fresh issue.
        $adminService->setIdentityFrozen(
            $envelope['identity_id'], false, '本地 V4 教师验收继续受控认领', $admin['user_id'], null, $envelope['lock_version'],
        );
        $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
        if ($envelope === null || $envelope['state'] !== 'ACTIVE') {
            v4teacherFail('teacher_broker_identity_unfreeze_failed');
        }
    }
    $issued = $adminService->issueClaim(
        $envelope['identity_id'], '本地 V4 教师浏览器验收临时 Claim', $admin['user_id'],
    );
    $ledger = v4teacherLedger($run, $envelope['identity_id'], (int) $issued['seat_id'], 'CLAIM_ISSUED');
    v4teacherWriteLedger($ledgerPath, $ledger);

    $initialPassword = v4teacherSecret();
    try {
        $claimed = $provisioningService->claimFormal(
            privateE2ETeacherFixtureRoster($run),
            (string) $issued['code'],
            privateE2ETeacherFixtureUsername($run),
            'fqa-t-' . substr($run, 0, 16) . '@example.invalid',
            $initialPassword,
        );
    } finally {
        $initialPassword = str_repeat("\0", strlen($initialPassword));
        $issued['code'] = str_repeat("\0", strlen((string) $issued['code']));
    }
    if (($claimed['role'] ?? null) !== 'TEACHER'
        || (int) ($claimed['identity_id'] ?? 0) !== $envelope['identity_id']
        || (int) ($claimed['seat_id'] ?? 0) !== (int) $issued['seat_id']
    ) {
        v4teacherFail('teacher_broker_claim_result_invalid');
    }
    $ledger = v4teacherLedger($run, $envelope['identity_id'], (int) $issued['seat_id'], 'PROVISIONED');
    v4teacherWriteLedger($ledgerPath, $ledger);
    $fresh = v4teacherIdentityEnvelope($db, $prefix, $run);
    if ($fresh === null || $fresh['state'] !== 'ACTIVE') {
        v4teacherFail('teacher_broker_claim_activation_invalid');
    }
    // Claiming creates a real account. Freeze before any lease or credential
    // export, and let AdminService revoke sessions/keys as part of that state.
    $adminService->setIdentityFrozen(
        $fresh['identity_id'], true, '本地 V4 教师浏览器验收认领后立即冻结', $admin['user_id'], null, $fresh['lock_version'],
    );
    $descriptor = v4teacherDescriptor($db, $prefix, $run);
    if ($descriptor === null) {
        v4teacherFail('teacher_broker_claimed_descriptor_invalid');
    }
    $ledger = v4teacherLedger($run, $descriptor['fixture']['identity_id'], $descriptor['fixture']['seat_id'], 'FROZEN');
    v4teacherWriteLedger($ledgerPath, $ledger);
    return $descriptor;
}

/** @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture
 * @param array{user_id:int,principal_id:int} $admin
 */
function v4teacherCloseLease(
    mysqli $db,
    ClassIdentityAdminService $adminService,
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $lease,
    array $fixture,
    array $admin,
    string $leasePasswordHash,
    string $closedPasswordHash,
): bool {
    $safe = false;
    try {
        $adminService->setIdentityFrozen(
            $fixture['identity_id'], true, '本地 V4 教师浏览器验收临时访问租约结束',
            $admin['user_id'], $lease, $lease->expectedLockVersion(),
        );
        $closed = privateE2ETeacherFixtureCloseCredential(
            $leaseService,
            $lease,
            $fixture,
            $leasePasswordHash,
            $closedPasswordHash,
            static function (int $userId): void {
                \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
            },
            static function (array $value) use ($admin, $fixture): void {
                v4teacherAppendCredentialAudit($admin, $fixture, $value);
            },
        );
        if (!$closed) {
            throw new RuntimeException('teacher_broker_credential_cas_conflict');
        }
        v4teacherAssertTerminalCredentialState($db, $fixture);
        privateE2ETeacherFixtureReleaseLease($leaseService, $lease);
        $safe = true;
    } catch (Throwable) {
        try {
            $leaseService->markConflict($lease);
        } catch (Throwable) {
        }
    }
    return $safe;
}

/** @param array{user_id:int,principal_id:int} $admin */
function v4teacherFreezePartial(
    mysqli $db,
    string $prefix,
    ClassIdentityAdminService $adminService,
    array $admin,
    string $run,
): void {
    $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
    if ($envelope === null) {
        return;
    }
    if ($envelope['state'] === 'ACTIVE') {
        $adminService->setIdentityFrozen(
            $envelope['identity_id'], true, '本地 V4 教师验收异常后隔离', $admin['user_id'], null, $envelope['lock_version'],
        );
    }
    // A raw Claim is never persisted. If claim creation stopped before formal
    // provisioning, retire any outstanding issued token via AdminService;
    // read-only lookup is allowed solely to locate the service target.
    if ($envelope['current_accounts'] === 0 && $envelope['seat_id'] !== null) {
        $ci = $prefix . 'class_identity_';
        $rows = v4teacherRows($db, "SELECT id FROM `{$ci}token` WHERE seat_id=? AND purpose='CLAIM' AND state IN ('ISSUED','RESERVED') ORDER BY id LIMIT 2", 'i', [$envelope['seat_id']]);
        if (count($rows) > 1) {
            v4teacherFail('teacher_broker_partial_claim_ambiguous');
        }
        if (count($rows) === 1) {
            $adminService->revokeClaim((int) $rows[0]['id'], '本地 V4 教师验收异常 Claim 隔离', $admin['user_id']);
        }
    }
}

/** @param array<string,mixed> $ledger
 * @return array<string,mixed>
 */
function v4teacherRecoveryPlan(array $ledger, string $run): array
{
    $recovery = $ledger['recovery'] ?? null;
    $plan = is_array($recovery) ? ($recovery['recovery_plan'] ?? null) : null;
    if (!is_array($recovery)
        || ($recovery['version'] ?? null) !== PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_DOCUMENT_VERSION
        || ($recovery['environment'] ?? null) !== PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_ENVIRONMENT
        || ($recovery['run'] ?? null) !== $run
        || !is_array($recovery['fixture'] ?? null)
        || ($recovery['fixture']['role'] ?? null) !== \ClassIdentity\Access::ROLE_TEACHER
        || !is_string($recovery['fixture']['roster'] ?? null)
        || !hash_equals(privateE2ETeacherFixtureRoster($run), (string) $recovery['fixture']['roster'])
        || !is_array($plan)
        || (int) ($plan['identity_id'] ?? 0) <= 0
        || (int) ($plan['seat_id'] ?? 0) <= 0
        || (int) ($plan['account_id'] ?? 0) <= 0
        || (int) ($plan['principal_id'] ?? 0) <= 0
        || (int) ($plan['user_id'] ?? 0) <= 0
        || !is_string($plan['username'] ?? null)
        || !is_string($plan['lease_password_sha256'] ?? null)
        || !is_string($plan['closed_password_hash'] ?? null)
        || !is_string($plan['closed_password_sha256'] ?? null)
        || !is_string($plan['before_password_sha256'] ?? null)
    ) {
        v4teacherFail('teacher_broker_recovery_plan_invalid');
    }
    return $plan;
}

/** @param array<string,mixed> $plan
 * @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture
 */
function v4teacherRecoveryPlanMatchesFixture(array $plan, array $fixture): bool
{
    foreach (['identity_id', 'seat_id', 'account_id', 'principal_id', 'user_id', 'username'] as $field) {
        if ((string) ($plan[$field] ?? '') !== (string) ($fixture[$field] ?? '')) {
            return false;
        }
    }
    return true;
}

/** @param array<string,mixed> $ledger
 * @param array{user_id:int,principal_id:int} $admin
 */
function v4teacherRecoverLedger(
    mysqli $db,
    string $prefix,
    ClassIdentityAdminService $adminService,
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    array $admin,
    string $run,
    string $ledgerPath,
    array $ledger,
): bool {
    $stage = (string) ($ledger['stage'] ?? '');
    $envelope = v4teacherIdentityEnvelope($db, $prefix, $run);
    $identityId = $envelope['identity_id'] ?? (int) ($ledger['identity_id'] ?? 0);
    if ($identityId > 0 && ($ledger['identity_id'] ?? null) !== null && (int) $ledger['identity_id'] !== $identityId) {
        v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null);
    }

    // CONFLICT is deliberately terminal for automatic recovery. It may have
    // been written after an interrupted previous repair, and attempting to
    // infer a safe close from a changed aggregate would turn reconciliation
    // into an unsafe retry. Append an auditable failed recovery observation
    // and leave the ledger exactly where it is.
    if ($stage === 'CONFLICT') {
        if ($identityId <= 0
            || !v4teacherResolveConflictLedger(
                $db, $prefix, $leaseService, $ledger, $admin, $run, $ledgerPath, $identityId,
            )
        ) {
            v4teacherFail('teacher_broker_recovery_reconciliation_required');
        }
        return true;
    }

    $unresolved = $identityId > 0 ? v4teacherUnresolvedLease($db, $prefix, $identityId) : null;
    if ($unresolved !== null) {
        if (!hash_equals(V4_TEACHER_BROKER_OWNER, $unresolved['owner'])
            || !hash_equals($run, $unresolved['run'])
            || $unresolved['state'] === 'CONFLICT'
        ) {
            $fixture = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, $fixture['fixture'] ?? null);
        }
        if ($unresolved['live']) {
            // A still-live lease cannot be seized. Quarantine the durable
            // ledger and make the attempted recovery visible in audit rather
            // than deleting a FROZEN/ACQUIRING record on an assumption.
            $fixture = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, $fixture['fixture'] ?? null);
        }
    }

    $requiresCredentialPlan = in_array($stage, ['LEASE_PREPARED', 'LEASE_OPEN'], true);
    if ($unresolved === null) {
        if ($requiresCredentialPlan) {
            try {
                $plan = v4teacherRecoveryPlan($ledger, $run);
                $descriptor = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
            } catch (Throwable) {
                v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null);
            }
            if ($descriptor === null || !v4teacherRecoveryPlanMatchesFixture($plan, $descriptor['fixture'])) {
                v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, (int) $plan['identity_id'], null);
            }
            try {
                $current = v4teacherRows($db, "SELECT password FROM `{$prefix}users` WHERE id=? AND BINARY username=BINARY ? LIMIT 2", 'is', [(int) $plan['user_id'], (string) $plan['username']]);
            } catch (Throwable) {
                v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, (int) $plan['identity_id'], $descriptor['fixture']);
            }
            $hash = count($current) === 1 && is_string($current[0]['password'] ?? null) ? (string) $current[0]['password'] : '';
            $digest = $hash === '' ? '' : hash('sha256', $hash);
            if (!hash_equals((string) $plan['before_password_sha256'], $digest)
                && !hash_equals((string) $plan['closed_password_sha256'], $digest)
            ) {
                v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, (int) $plan['identity_id'], $descriptor['fixture']);
            }
            v4teacherAssertTerminalCredentialState($db, $descriptor['fixture']);
            v4teacherRecoveryClosedOrConflict(
                $ledgerPath,
                $run,
                $ledger,
                $admin,
                (int) $plan['identity_id'],
                $descriptor['fixture'],
                'LEASE_CLOSED',
            );
            v4teacherRemoveLedger($ledgerPath);
            return true;
        }
        // Do not remove a FROZEN/creation ledger on appearance alone. Freeze
        // and revoke only through AdminService, then prove a terminal Teacher
        // descriptor or a zero-account, zero-principal, revoked-claim state.
        try {
            v4teacherFreezePartial($db, $prefix, $adminService, $admin, $run);
            $proof = v4teacherPreProvisionRecoveryProof($db, $prefix, $run);
        } catch (Throwable) {
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null);
        }
        if ($proof === null) {
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null);
        }
        if (is_array($proof['fixture'])) {
            v4teacherAssertTerminalCredentialState($db, $proof['fixture']);
        }
        v4teacherRecoveryClosedOrConflict(
            $ledgerPath,
            $run,
            $ledger,
            $admin,
            $proof['identity_id'] > 0 ? $proof['identity_id'] : null,
            $proof['fixture'],
            'TERMINAL_FROZEN',
        );
        v4teacherRemoveLedger($ledgerPath);
        return true;
    }

    // An expired own ACTIVE lease exists. Recover it with a process-local
    // permit only; the operator did not have to turn on normal acquisition.
    return v4teacherWithRecoveryLeasePermit(function () use (
        $db, $prefix, $adminService, $leaseService, $admin, $run, $ledgerPath,
        $ledger, $identityId, $requiresCredentialPlan,
    ): bool {
        try {
            $lease = $leaseService->recoverAbandonedIdentityLease(
                $identityId, $run, V4_TEACHER_BROKER_OWNER, 300,
            );
        } catch (Throwable) {
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null);
        }
        try {
            $currentEnvelope = v4teacherIdentityEnvelope($db, $prefix, $run);
            if ($currentEnvelope === null || $currentEnvelope['identity_id'] !== $identityId) {
                v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null, $leaseService, $lease);
            }
            if ($currentEnvelope['state'] === 'ACTIVE') {
                $adminService->setIdentityFrozen(
                    $identityId, true, '本地 V4 教师验收遗留租约恢复冻结',
                    $admin['user_id'], $lease, $lease->expectedLockVersion(),
                );
            }
            $descriptor = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
            if ($descriptor === null) {
                v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null, $leaseService, $lease);
            }
            if ($requiresCredentialPlan) {
                $plan = v4teacherRecoveryPlan($ledger, $run);
                if (!v4teacherRecoveryPlanMatchesFixture($plan, $descriptor['fixture'])) {
                    v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, $descriptor['fixture'], $leaseService, $lease);
                }
                $current = v4teacherRows($db, "SELECT password FROM `{$prefix}users` WHERE id=? AND BINARY username=BINARY ? LIMIT 2", 'is', [(int) $plan['user_id'], (string) $plan['username']]);
                $hash = count($current) === 1 && is_string($current[0]['password'] ?? null) ? (string) $current[0]['password'] : '';
                $digest = $hash === '' ? '' : hash('sha256', $hash);
                $closed = false;
                if (hash_equals((string) $plan['lease_password_sha256'], $digest)) {
                    $closed = $leaseService->compareAndSetLeasedPasswordHash(
                        $lease, (int) $plan['user_id'], (string) $plan['username'], $hash, (string) $plan['closed_password_hash'],
                    );
                } elseif (hash_equals((string) $plan['closed_password_sha256'], $digest)
                    || hash_equals((string) $plan['before_password_sha256'], $digest)) {
                    $closed = true;
                }
                \ClassIdentity\CoreAdapter::revokeAllCredentials((int) $plan['user_id']);
                if (!$closed) {
                    v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, $descriptor['fixture'], $leaseService, $lease);
                }
            }
            v4teacherAssertTerminalCredentialState($db, $descriptor['fixture']);
            // Audit before lease release. If the append fails, the catch below
            // persists CONFLICT and never removes the recovery ledger.
            v4teacherAppendRecoveryAudit($admin, $descriptor['fixture'], $identityId, 'LEASE_CLOSED');
            privateE2ETeacherFixtureReleaseLease($leaseService, $lease);
            v4teacherRemoveLedger($ledgerPath);
            return true;
        } catch (V4TeacherBrokerFailure $error) {
            if ($error->getMessage() === 'teacher_broker_recovery_reconciliation_required') {
                throw $error;
            }
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null, $leaseService, $lease);
        } catch (Throwable) {
            v4teacherRecoveryConflict($ledgerPath, $run, $ledger, $admin, $identityId, null, $leaseService, $lease);
        }
    });
}

if (defined('V4_TEACHER_BROKER_LIBRARY_ONLY') && V4_TEACHER_BROKER_LIBRARY_ONLY === true) {
    return;
}

$run = (string) getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID');
$ensureMode = getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ENSURE') === '1';
$leaseMode = getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_LEASE') === '1';
$recoveryMode = getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY') === '1';
$ttlRaw = (string) getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TTL_SECONDS');
if (PHP_SAPI !== 'cli'
    || (($ensureMode ? 1 : 0) + ($leaseMode ? 1 : 0) + ($recoveryMode ? 1 : 0)) !== 1
    || !hash_equals(V4_TEACHER_BROKER_PERSISTENT_RUN, $run)
    || (($leaseMode && (!preg_match('/\A[0-9]{3,4}\z/D', $ttlRaw) || (int) $ttlRaw < 300 || (int) $ttlRaw > 1800))
        || (($ensureMode || $recoveryMode) && $ttlRaw !== ''))
    || ($argv[1] ?? null) !== $run
    || (function_exists('posix_geteuid') && posix_geteuid() === 0)
) {
    fwrite(STDOUT, "V4_OWNER_TEACHER_FIXTURE=FAIL stage=bootstrap code=runtime_gate_rejected\n");
    exit(2);
}

$ttl = $leaseMode ? (int) $ttlRaw : 0;
$ledgerPath = '';
$db = null;
$prefix = '';
$lockHeld = false;
$adminService = null;
$leaseService = null;
$admin = null;
$fixture = null;
$lease = null;
$leasePasswordHash = '';
$closedPasswordHash = '';
$browserPassword = '';
$cleanupSafe = false;
$ensureComplete = false;
$exitCode = 0;

try {
    v4teacherRequirePrivateCli($recoveryMode);
    $run = v4teacherPersistentRun($run);
    chdir(V4_TEACHER_BROKER_ROOT) || v4teacherFail('teacher_broker_piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    v4teacherAssertPath(CLASS_IDENTITY_PATH . 'src/AdminService.php', V4_TEACHER_BROKER_ROOT . '/plugins/ClassIdentity/src/AdminService.php', 'teacher_broker_admin_service_untrusted');
    v4teacherAssertPath(CLASS_IDENTITY_PATH . 'src/ProvisioningService.php', V4_TEACHER_BROKER_ROOT . '/plugins/ClassIdentity/src/ProvisioningService.php', 'teacher_broker_provisioning_service_untrusted');
    v4teacherAssertPath(V4_TEACHER_BROKER_WORKSPACE_ROOT . '/tests/phase3/private-e2e-teacher-fixture-lease.php', V4_TEACHER_BROKER_WORKSPACE_ROOT . '/tests/phase3/private-e2e-teacher-fixture-lease.php', 'teacher_broker_adapter_untrusted');
    require_once CLASS_IDENTITY_PATH . 'src/AdminService.php';
    require_once CLASS_IDENTITY_PATH . 'src/ProvisioningService.php';
    define('PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY', true);
    require_once V4_TEACHER_BROKER_WORKSPACE_ROOT . '/tests/phase3/private-e2e-teacher-fixture-lease.php';

    global $mysqli, $prefixeTable;
    if (!$mysqli instanceof mysqli
        || !is_string($prefixeTable)
        || !class_exists(ClassIdentityAdminService::class)
        || !class_exists(\ClassIdentity\ProvisioningService::class)
        || !class_exists(\ClassIdentity\PrivateE2EFixtureLeaseService::class)
        || !class_exists(\ClassIdentity\PrivateE2EFixtureLeaseContext::class)
        || !class_exists(\ClassIdentity\Schema::class)
        || !class_exists(\ClassIdentity\Access::class)
        || !\ClassIdentity\Access::isEnforcementEnabled()
    ) {
        v4teacherFail('teacher_broker_class_identity_runtime_unavailable');
    }
    \ClassIdentity\Schema::fromPiwigo((string) CLASS_IDENTITY_VERSION)->verifyCurrent();
    $db = $mysqli;
    $prefix = $prefixeTable;
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
        v4teacherFail('teacher_broker_table_prefix_invalid');
    }
    if (v4teacherScalar($db, "SELECT GET_LOCK('" . V4_TEACHER_BROKER_LOCK . "',0)") !== 1) {
        v4teacherFail('teacher_broker_database_lock_busy');
    }
    $lockHeld = true;
    v4teacherEnsureRecoveryRoot();
    $ledgerPath = v4teacherLedgerPath($run);
    $adminService = ClassIdentityAdminService::fromPiwigo();
    $leaseService = \ClassIdentity\PrivateE2EFixtureLeaseService::fromPiwigo();
    $admin = v4teacherSystemAdmin($db, $prefix);

    if ($recoveryMode) {
        // A previous recovery can commit the terminal lease release just
        // before process interruption removes the local ledger. Treat an
        // absent ledger as idempotent only after re-proving the exact frozen
        // Teacher descriptor, zero unresolved lease, and revoked credential
        // surfaces. Missing state alone is never a recovery success.
        if (!file_exists($ledgerPath) && !is_link($ledgerPath)) {
            $terminal = v4teacherFrozenDescriptorOrNull($db, $prefix, $run);
            if ($terminal === null
                || v4teacherUnresolvedLease($db, $prefix, (int) $terminal['fixture']['identity_id']) !== null
            ) {
                v4teacherFail('teacher_broker_recovery_terminal_proof_required');
            }
            v4teacherAssertTerminalCredentialState($db, $terminal['fixture']);
        } else {
            $ledger = v4teacherReadLedger($ledgerPath, $run);
            if (!v4teacherRecoverLedger($db, $prefix, $adminService, $leaseService, $admin, $run, $ledgerPath, $ledger)) {
                v4teacherFail('teacher_broker_recovery_conflict');
            }
        }
        $cleanupSafe = true;
    } elseif ($ensureMode) {
        if (file_exists($ledgerPath) || is_link($ledgerPath)) {
            v4teacherFail('teacher_broker_recovery_required');
        }
        $ledger = v4teacherLedger($run);
        v4teacherWriteLedger($ledgerPath, $ledger);
        $candidate = v4teacherEnsureProvisionedFixture(
            $db, $prefix, $adminService, \ClassIdentity\ProvisioningService::fromPiwigo(), $admin, $run, $ledgerPath, $ledger,
        );
        $fixture = $candidate['fixture'];
        // Terminal bootstrap state: a real TEACHER account exists but is
        // frozen with sessions/keys revoked. It is intentionally retained as
        // a test-only aggregate; the future lease opens it only after the
        // caller has captured its pre-migration snapshot.
        v4teacherAssertTerminalCredentialState($db, $fixture);
        v4teacherRemoveLedger($ledgerPath);
        $ensureComplete = true;
        $cleanupSafe = true;
    } else {
        if (file_exists($ledgerPath) || is_link($ledgerPath)) {
            v4teacherFail('teacher_broker_recovery_required');
        }
        // Lease mode has a hard precondition: no creation, Claim issuance or
        // provisioning is allowed here. A snapshot-aware orchestrator must
        // run ENSURE first and attest this frozen descriptor before opening a
        // browser credential lease.
        $candidate = v4teacherDescriptor($db, $prefix, $run);
        if ($candidate === null) {
            v4teacherFail('teacher_broker_ensure_required');
        }
        $fixture = $candidate['fixture'];
        // Browser credentials must never be printed to a terminal. Reject the
        // run before the durable acquisition marker and before any DB lease is
        // created; only an owning process with a pipe may control EXPORT.
        v4teacherRequirePipeStdout();
        // This marker is intentionally durable before acquireIdentityLease().
        // A power loss in the narrow DB-acquisition window is therefore
        // recoverable by reconciling the possible ACTIVE lease row.
        $ledger = v4teacherLedger($run, $fixture['identity_id'], $fixture['seat_id'], 'LEASE_ACQUIRING');
        v4teacherWriteLedger($ledgerPath, $ledger);
        $lease = privateE2ETeacherFixtureAcquireLease(
            $leaseService, [
                'identity' => ['id' => $fixture['identity_id'], 'identity_type' => 'TEACHER', 'lock_version' => $fixture['lock_version'], 'roster_code' => $fixture['roster_code'], 'state' => 'FROZEN'],
                'seat' => ['id' => $fixture['seat_id'], 'identity_id' => $fixture['identity_id'], 'lock_version' => $fixture['seat_lock_version'], 'seat_type' => 'TEACHER', 'state' => 'ACTIVE'],
                'account' => ['current_marker' => 1, 'id' => $fixture['account_id'], 'requested_username' => $fixture['username'], 'seat_id' => $fixture['seat_id'], 'state' => 'ACTIVE'],
                'principal' => ['account_id' => $fixture['account_id'], 'id' => $fixture['principal_id'], 'piwigo_user_id' => $fixture['user_id'], 'principal_type' => 'SEAT_ACCOUNT', 'state' => 'ACTIVE'],
            ], $run, $ttl,
        )['lease'];
        $ledger = v4teacherLedger($run, $fixture['identity_id'], $fixture['seat_id'], 'LEASE_ACQUIRED');
        v4teacherWriteLedger($ledgerPath, $ledger);
        $browserPassword = v4teacherSecret();
        $leasePasswordHash = v4teacherPasswordHash($browserPassword);
        $closedSecret = v4teacherSecret();
        $closedPasswordHash = v4teacherPasswordHash($closedSecret);
        $closedSecret = str_repeat("\0", strlen($closedSecret));
        $recovery = privateE2ETeacherFixtureRecoveryDocument(
            $fixture, $run, $candidate['password_hash'], $leasePasswordHash, $closedPasswordHash,
        );
        $ledger = v4teacherLedger($run, $fixture['identity_id'], $fixture['seat_id'], 'LEASE_PREPARED', $recovery);
        v4teacherWriteLedger($ledgerPath, $ledger);
        privateE2ETeacherFixtureInstallCredential(
            $leaseService,
            $lease,
            $fixture,
            $candidate['password_hash'],
            $leasePasswordHash,
            static function (int $userId): void {
                \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
            },
            static function (array $value) use ($admin, $fixture): void {
                v4teacherAppendCredentialAudit($admin, $fixture, $value);
            },
        );
        $adminService->setIdentityFrozen(
            $fixture['identity_id'], false, '本地 V4 教师浏览器验收临时访问租约开始',
            $admin['user_id'], $lease, $lease->expectedLockVersion(),
        );
        // The post-unfreeze assertion must resolve the actual authorization
        // graph and the mutation-advanced version, not a cached frozen
        // descriptor. UNKNOWN, wrong role, or any aggregate drift blocks
        // READY and credential export.
        v4teacherAssertLeasedTeacherAuthorization($db, $prefix, $fixture, $lease, $run);
        $ledger = v4teacherLedger($run, $fixture['identity_id'], $fixture['seat_id'], 'LEASE_OPEN', $recovery);
        v4teacherWriteLedger($ledgerPath, $ledger);
        v4teacherAssertLeasedTeacherAuthorization($db, $prefix, $fixture, $lease, $run);
        fwrite(STDOUT, "V4_OWNER_TEACHER_FIXTURE=READY roles=1 ttl={$ttl}\n");
        fflush(STDOUT);
        $exported = false;
        $deadline = time() + $ttl;
        $nextHeartbeat = time() + max(30, min(60, intdiv($ttl, 3)));
        stream_set_blocking(STDIN, false);
        while (time() < $deadline) {
            $read = [STDIN];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, 1);
            if ($ready === false) {
                v4teacherFail('teacher_broker_control_channel_failed');
            }
            if ($ready === 0) {
                if (time() >= $nextHeartbeat) {
                    $leaseService->heartbeat($lease, $ttl);
                    $nextHeartbeat = time() + max(30, min(60, intdiv($ttl, 3)));
                }
                continue;
            }
            $line = fgets(STDIN);
            if ($line === false) {
                break;
            }
            $control = rtrim($line, "\r\n");
            if (hash_equals('EXPORT ' . $run, $control)) {
                if ($exported) {
                    v4teacherFail('teacher_broker_credential_export_replayed');
                }
                v4teacherAssertLeasedTeacherAuthorization($db, $prefix, $fixture, $lease, $run);
                $document = privateE2ETeacherFixtureBrowserCredentialDocument($fixture, $run, $browserPassword);
                $frame = v4teacherBase64Url(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $record = "V4_OWNER_TEACHER_FIXTURE_CREDENTIAL={$frame}\n";
                if (fwrite(STDOUT, $record) !== strlen($record) || !fflush(STDOUT)) {
                    v4teacherFail('teacher_broker_credential_export_failed');
                }
                $browserPassword = str_repeat("\0", strlen($browserPassword));
                $frame = '';
                $record = '';
                $exported = true;
                continue;
            }
            if (hash_equals('STOP ' . $run, $control)) {
                break;
            }
            v4teacherFail('teacher_broker_control_message_invalid');
        }
    }
} catch (Throwable $error) {
    $code = $error instanceof V4TeacherBrokerFailure
        ? $error->getMessage()
        : v4teacherSafeThrowableCode($error);
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'unexpected';
    }
    fwrite(STDOUT, "V4_OWNER_TEACHER_FIXTURE=FAIL stage=runtime code={$code}\n");
    $exitCode = 2;
} finally {
    if ($browserPassword !== '') {
        $browserPassword = str_repeat("\0", strlen($browserPassword));
    }
    if ($lease instanceof \ClassIdentity\PrivateE2EFixtureLeaseContext
        && is_array($fixture)
        && $adminService instanceof ClassIdentityAdminService
        && $leaseService instanceof \ClassIdentity\PrivateE2EFixtureLeaseService
        && is_array($admin)
    ) {
        $cleanupSafe = v4teacherCloseLease(
            $db, $adminService, $leaseService, $lease, $fixture, $admin, $leasePasswordHash, $closedPasswordHash,
        );
        if ($cleanupSafe && $ledgerPath !== '') {
            try {
                v4teacherRemoveLedger($ledgerPath);
            } catch (Throwable) {
                $cleanupSafe = false;
                $exitCode = 2;
            }
        }
        if (!$cleanupSafe) {
            $exitCode = 2;
        }
    } elseif (!$ensureComplete
        && !$cleanupSafe
        && $ledgerPath !== ''
        && $db instanceof mysqli
        && $adminService instanceof ClassIdentityAdminService
        && is_array($admin)
    ) {
        // Creation/claim errors are not treated as harmless.  Refreeze and
        // revoke an unclaimed token through the business service; leave the
        // 0600 ledger for an explicit recovery invocation if that fails.
        try {
            v4teacherFreezePartial($db, $prefix, $adminService, $admin, $run);
        } catch (Throwable) {
            $exitCode = 2;
        }
    }
    if ($lockHeld && $db instanceof mysqli) {
        try {
            if (v4teacherScalar($db, "SELECT RELEASE_LOCK('" . V4_TEACHER_BROKER_LOCK . "')") !== 1) {
                $exitCode = 2;
            }
        } catch (Throwable) {
            $exitCode = 2;
        }
    }
}

if ($exitCode === 0 && $recoveryMode && $cleanupSafe) {
    fwrite(STDOUT, "V4_OWNER_TEACHER_FIXTURE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked\n");
} elseif ($exitCode === 0 && $ensureMode && $ensureComplete) {
    fwrite(STDOUT, "V4_OWNER_TEACHER_FIXTURE=ENSURED identity=FROZEN credentials=unknown sessions=revoked\n");
} elseif ($cleanupSafe && $leaseMode) {
    fwrite(STDOUT, "V4_OWNER_TEACHER_FIXTURE=CLOSED identity=FROZEN credentials=unknown sessions=revoked\n");
}
exit($exitCode);
