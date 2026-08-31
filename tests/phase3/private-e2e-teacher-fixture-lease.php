<?php

declare(strict_types=1);

/**
 * Disabled-by-default, local-private Teacher fixture lease adapter.
 *
 * The historical FQA aggregate deliberately contains only Classmate, Family
 * and Anonymous seats.  A Teacher must never be impersonated through that
 * aggregate.  This adapter instead binds one separately provisioned,
 * test-namespaced TEACHER identity to the existing generic Identity lease
 * service.  It has no HTTP route, no Compose enablement and no automatic
 * provisioning/deletion path.  An orchestrator must create the isolated
 * teacher through the ordinary identity/claim flow, freeze it, and then use
 * this adapter under both explicit local test gates.
 *
 * Browser credentials are intentionally not persisted here.  The recovery
 * document helper contains only verifier digests plus a closed verifier, so a
 * future broker can recover without retaining a reusable browser secret.
 */

const PRIVATE_E2E_TEACHER_FIXTURE_OWNER = 'v4-owner-teacher-fixture-broker';
const PRIVATE_E2E_TEACHER_FIXTURE_ROSTER_PREFIX = 'FQA-T-';
const PRIVATE_E2E_TEACHER_FIXTURE_ROLE = 'TEACHER';
const PRIVATE_E2E_TEACHER_FIXTURE_BROWSER_DOCUMENT_VERSION = 1;
const PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_DOCUMENT_VERSION = 1;
const PRIVATE_E2E_TEACHER_FIXTURE_BROWSER_ENVIRONMENT = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_BROWSER_EXPORT';
const PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_ENVIRONMENT = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_FIXTURE_LEASE';
const PRIVATE_E2E_TEACHER_FIXTURE_ACK = 'LEASED_TEACHER_FIXTURE_V1';

final class PrivateE2ETeacherFixtureLeaseFailure extends RuntimeException
{
}

function privateE2ETeacherFixtureFail(string $code): never
{
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'teacher_fixture_failure_invalid';
    }
    throw new PrivateE2ETeacherFixtureLeaseFailure($code);
}

function privateE2ETeacherFixtureRun(string $run): string
{
    if (preg_match('/\A[a-f0-9]{24}\z/D', $run) !== 1) {
        privateE2ETeacherFixtureFail('teacher_fixture_run_invalid');
    }
    return $run;
}

function privateE2ETeacherFixtureRoster(string $run): string
{
    return PRIVATE_E2E_TEACHER_FIXTURE_ROSTER_PREFIX . strtoupper(privateE2ETeacherFixtureRun($run));
}

function privateE2ETeacherFixtureUsername(string $run): string
{
    return 'fqa_t_' . privateE2ETeacherFixtureRun($run) . '_teacher';
}

/** @param mixed $value @param list<string> $expected */
function privateE2ETeacherFixtureExactKeys(mixed $value, array $expected): bool
{
    if (!is_array($value)) {
        return false;
    }
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    return $actual === $expected;
}

/** @param mixed $value */
function privateE2ETeacherFixturePositiveInt(mixed $value): bool
{
    return is_int($value) && $value > 0;
}

/** @param mixed $value */
function privateE2ETeacherFixtureVersion(mixed $value): bool
{
    return is_int($value) && $value >= 0;
}

/** @param array<string,mixed> $descriptor
 * @return array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string}
 */
function privateE2ETeacherFixtureValidateDescriptor(array $descriptor, string $run): array
{
    $run = privateE2ETeacherFixtureRun($run);
    if (!privateE2ETeacherFixtureExactKeys($descriptor, ['account', 'identity', 'principal', 'seat'])) {
        privateE2ETeacherFixtureFail('teacher_fixture_descriptor_shape_invalid');
    }
    $identity = $descriptor['identity'];
    $seat = $descriptor['seat'];
    $account = $descriptor['account'];
    $principal = $descriptor['principal'];
    if (!privateE2ETeacherFixtureExactKeys($identity, ['id', 'identity_type', 'lock_version', 'roster_code', 'state'])
        || !privateE2ETeacherFixtureExactKeys($seat, ['id', 'identity_id', 'lock_version', 'seat_type', 'state'])
        || !privateE2ETeacherFixtureExactKeys($account, ['current_marker', 'id', 'requested_username', 'seat_id', 'state'])
        || !privateE2ETeacherFixtureExactKeys($principal, ['account_id', 'id', 'piwigo_user_id', 'principal_type', 'state'])
    ) {
        privateE2ETeacherFixtureFail('teacher_fixture_descriptor_item_shape_invalid');
    }

    $identityId = $identity['id'] ?? null;
    $seatId = $seat['id'] ?? null;
    $accountId = $account['id'] ?? null;
    $principalId = $principal['id'] ?? null;
    $userId = $principal['piwigo_user_id'] ?? null;
    $lockVersion = $identity['lock_version'] ?? null;
    $seatLockVersion = $seat['lock_version'] ?? null;
    $roster = $identity['roster_code'] ?? null;
    $username = $account['requested_username'] ?? null;

    if (!privateE2ETeacherFixturePositiveInt($identityId)
        || !privateE2ETeacherFixturePositiveInt($seatId)
        || !privateE2ETeacherFixturePositiveInt($accountId)
        || !privateE2ETeacherFixturePositiveInt($principalId)
        || !privateE2ETeacherFixturePositiveInt($userId)
        || !privateE2ETeacherFixtureVersion($lockVersion)
        || !privateE2ETeacherFixtureVersion($seatLockVersion)
        || $identity['identity_type'] !== PRIVATE_E2E_TEACHER_FIXTURE_ROLE
        || $identity['state'] !== 'FROZEN'
        || $roster !== privateE2ETeacherFixtureRoster($run)
        || str_starts_with((string) $roster, 'FQA-C-')
        || $seat['identity_id'] !== $identityId
        || $seat['seat_type'] !== PRIVATE_E2E_TEACHER_FIXTURE_ROLE
        || $seat['state'] !== 'ACTIVE'
        || $account['seat_id'] !== $seatId
        || $account['state'] !== 'ACTIVE'
        || $account['current_marker'] !== 1
        || $username !== privateE2ETeacherFixtureUsername($run)
        || $principal['account_id'] !== $accountId
        || $principal['principal_type'] !== 'SEAT_ACCOUNT'
        || $principal['state'] !== 'ACTIVE'
    ) {
        privateE2ETeacherFixtureFail('teacher_fixture_descriptor_invariant_failed');
    }

    return [
        'identity_id' => $identityId,
        'lock_version' => $lockVersion,
        'roster_code' => $roster,
        'seat_id' => $seatId,
        'seat_lock_version' => $seatLockVersion,
        'account_id' => $accountId,
        'principal_id' => $principalId,
        'user_id' => $userId,
        'username' => $username,
    ];
}

/**
 * Explicit double gate. This remains disabled in ordinary CLI, all web
 * requests, tracked Compose files and public runtimes. The acknowledgement is
 * deliberately a capability label rather than a secret.
 */
function privateE2ETeacherFixtureRequirePrivateCli(): void
{
    if (PHP_SAPI !== 'cli'
        || getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1'
        || getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE') !== '1'
        || !hash_equals(PRIVATE_E2E_TEACHER_FIXTURE_ACK, (string) getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK'))
    ) {
        privateE2ETeacherFixtureFail('teacher_fixture_disabled');
    }
}

/** @param array<string,mixed> $descriptor
 * @return array{descriptor:array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string},lease:\ClassIdentity\PrivateE2EFixtureLeaseContext}
 */
function privateE2ETeacherFixtureAcquireLease(
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    array $descriptor,
    string $run,
    int $ttlSeconds,
): array {
    privateE2ETeacherFixtureRequirePrivateCli();
    $fixture = privateE2ETeacherFixtureValidateDescriptor($descriptor, $run);
    if ($ttlSeconds < 300 || $ttlSeconds > 1800) {
        privateE2ETeacherFixtureFail('teacher_fixture_ttl_invalid');
    }
    return [
        'descriptor' => $fixture,
        'lease' => $leaseService->acquireIdentityLease(
            $fixture['identity_id'],
            privateE2ETeacherFixtureRun($run),
            PRIVATE_E2E_TEACHER_FIXTURE_OWNER,
            $ttlSeconds,
            $fixture['lock_version'],
        ),
    ];
}

/**
 * Exact release only. Cleanup deliberately remains callable after the
 * enablement flag has been cleared: once a broker owns an opaque lease, a
 * feature-flag change must not strand that durable ACTIVE row. The underlying
 * service performs the identity-version and lease-revision CAS and fails
 * closed on drift; this adapter never retries with a newer revision.
 */
function privateE2ETeacherFixtureReleaseLease(
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $lease,
): void {
    $leaseService->releaseIdentityLease($lease);
    $lease->clear();
}

/** @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture */
function privateE2ETeacherFixtureCredentialAuditData(array $fixture, string $state): array
{
    if (!in_array($state, ['LEASE_OPEN', 'LEASE_CLOSED', 'LEASE_CONFLICT'], true)) {
        privateE2ETeacherFixtureFail('teacher_fixture_audit_state_invalid');
    }
    return [
        'state' => $state,
        'role_code' => PRIVATE_E2E_TEACHER_FIXTURE_ROLE,
        // Audit value strings are intentionally screened with the same
        // credential-shaped-text defense as free-form reasons. Keep this
        // enum-like marker short and digit-free; the lease state already
        // carries the detailed lifecycle meaning.
        'reason_code' => 'TEACHER_FIXTURE',
    ];
}

function privateE2ETeacherFixtureHash(string $hash, string $code): string
{
    if (strlen($hash) < 8 || strlen($hash) > 255 || str_contains($hash, "\0")) {
        privateE2ETeacherFixtureFail($code);
    }
    return $hash;
}

/** @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture */
function privateE2ETeacherFixtureInstallCredential(
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $lease,
    array $fixture,
    string $beforePasswordHash,
    string $leasePasswordHash,
    callable $revokeCredentials,
    callable $appendAudit,
): void {
    $beforePasswordHash = privateE2ETeacherFixtureHash($beforePasswordHash, 'teacher_fixture_before_hash_invalid');
    $leasePasswordHash = privateE2ETeacherFixtureHash($leasePasswordHash, 'teacher_fixture_lease_hash_invalid');
    if (!$leaseService->compareAndSetFixturePasswordHash(
        $lease,
        $fixture['user_id'],
        $fixture['principal_id'],
        $fixture['account_id'],
        $fixture['seat_id'],
        $fixture['seat_lock_version'],
        $fixture['username'],
        $beforePasswordHash,
        $leasePasswordHash,
    )) {
        privateE2ETeacherFixtureFail('teacher_fixture_credential_install_cas_conflict');
    }
    $revokeCredentials($fixture['user_id']);
    $appendAudit(privateE2ETeacherFixtureCredentialAuditData($fixture, 'LEASE_OPEN'));
}

/** @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture */
function privateE2ETeacherFixtureCloseCredential(
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $lease,
    array $fixture,
    string $leasePasswordHash,
    string $closedPasswordHash,
    callable $revokeCredentials,
    callable $appendAudit,
): bool {
    $leasePasswordHash = privateE2ETeacherFixtureHash($leasePasswordHash, 'teacher_fixture_lease_hash_invalid');
    $closedPasswordHash = privateE2ETeacherFixtureHash($closedPasswordHash, 'teacher_fixture_closed_hash_invalid');
    $closed = $leaseService->compareAndSetLeasedPasswordHash(
        $lease,
        $fixture['user_id'],
        $fixture['username'],
        $leasePasswordHash,
        $closedPasswordHash,
    );
    // Revocation is deliberately unconditional: a failed CAS can represent a
    // concurrent administrator password update, whose sessions must not remain
    // valid while the fixture is being quarantined.
    $revokeCredentials($fixture['user_id']);
    $appendAudit(privateE2ETeacherFixtureCredentialAuditData(
        $fixture,
        $closed ? 'LEASE_CLOSED' : 'LEASE_CONFLICT',
    ));
    return $closed;
}

/** @param array{identity_id:int,lock_version:int,roster_code:string,seat_id:int,seat_lock_version:int,account_id:int,principal_id:int,user_id:int,username:string} $fixture */
function privateE2ETeacherFixtureRecoveryDocument(
    array $fixture,
    string $run,
    string $beforePasswordHash,
    string $leasePasswordHash,
    string $closedPasswordHash,
): array {
    $run = privateE2ETeacherFixtureRun($run);
    $beforePasswordHash = privateE2ETeacherFixtureHash($beforePasswordHash, 'teacher_fixture_before_hash_invalid');
    $leasePasswordHash = privateE2ETeacherFixtureHash($leasePasswordHash, 'teacher_fixture_lease_hash_invalid');
    $closedPasswordHash = privateE2ETeacherFixtureHash($closedPasswordHash, 'teacher_fixture_closed_hash_invalid');
    return [
        'version' => PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_DOCUMENT_VERSION,
        'environment' => PRIVATE_E2E_TEACHER_FIXTURE_RECOVERY_ENVIRONMENT,
        'run' => $run,
        'fixture' => ['role' => PRIVATE_E2E_TEACHER_FIXTURE_ROLE, 'roster' => $fixture['roster_code']],
        'recovery_plan' => [
            'identity_id' => $fixture['identity_id'],
            'seat_id' => $fixture['seat_id'],
            'seat_lock_version' => $fixture['seat_lock_version'],
            'account_id' => $fixture['account_id'],
            'principal_id' => $fixture['principal_id'],
            'user_id' => $fixture['user_id'],
            'username' => $fixture['username'],
            'before_password_sha256' => hash('sha256', $beforePasswordHash),
            'lease_password_sha256' => hash('sha256', $leasePasswordHash),
            'closed_password_hash' => $closedPasswordHash,
            'closed_password_sha256' => hash('sha256', $closedPasswordHash),
        ],
    ];
}

/** @return array{version:int,environment:string,run:string,lease:array{role:string,roster:string},roles:array{teacher:array{username:string,password:string}}} */
function privateE2ETeacherFixtureBrowserCredentialDocument(array $fixture, string $run, string $password): array
{
    $run = privateE2ETeacherFixtureRun($run);
    if ($fixture['roster_code'] !== privateE2ETeacherFixtureRoster($run)
        || $fixture['username'] !== privateE2ETeacherFixtureUsername($run)
        || preg_match('/\A[A-Za-z0-9_-]{64}\z/D', $password) !== 1
    ) {
        privateE2ETeacherFixtureFail('teacher_fixture_browser_document_invalid');
    }
    return [
        'version' => PRIVATE_E2E_TEACHER_FIXTURE_BROWSER_DOCUMENT_VERSION,
        'environment' => PRIVATE_E2E_TEACHER_FIXTURE_BROWSER_ENVIRONMENT,
        'run' => $run,
        'lease' => ['role' => PRIVATE_E2E_TEACHER_FIXTURE_ROLE, 'roster' => $fixture['roster_code']],
        'roles' => ['teacher' => ['username' => $fixture['username'], 'password' => $password]],
    ];
}

if (defined('PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY') && PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY === true) {
    return;
}

// This file is an adapter library, not a provisioning endpoint. A direct run
// has no mutating command and stays blocked even when an operator has supplied
// the explicit feature gates. The future owner-only broker must be a separate
// audited orchestration step that creates and cleans the test identity.
try {
    privateE2ETeacherFixtureRequirePrivateCli();
    privateE2ETeacherFixtureFail('teacher_fixture_orchestrator_required');
} catch (PrivateE2ETeacherFixtureLeaseFailure $error) {
    fwrite(STDOUT, 'PRIVATE_E2E_TEACHER_FIXTURE=BLOCKED code=' . $error->getMessage() . "\n");
    exit(2);
}
