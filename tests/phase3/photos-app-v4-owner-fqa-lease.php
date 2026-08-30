<?php

declare(strict_types=1);

/**
 * Local-only, bounded credential lease for one historical FQA aggregate.
 *
 * This broker deliberately keeps the database advisory lock and its stdin
 * control channel open for the complete browser run.  Credentials cross the
 * process boundary only through one 0600 file.  EOF, STOP, timeout, signals,
 * or any Throwable enter the same freeze-first cleanup path.
 */

const V4_FQA_ROOT = '/var/www/html/piwigo';
const V4_FQA_RECOVERY_ROOT = '/var/lib/class-archive-private-e2e';
const V4_FQA_ROSTER = 'FQA-C-99CA3B3B6AF1';
const V4_FQA_LOCK = 'class_archive_v4_owner_fqa_lease_v1';
const V4_FQA_ROLES = ['ANONYMOUS', 'CLASSMATE', 'FAMILY'];
// AdminService::setIdentityFrozen now participates in the durable fixture
// lease and the same identity-version CAS. This constant records capability,
// not enablement: acquisition still requires the local process-only
// CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1 gate.
const V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN = true;
const V4_FQA_OWNER = 'v4-owner-fqa-broker';

final class V4FqaLeaseFailure extends RuntimeException
{
}

function v4fqaFail(string $code): never
{
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'invalid_failure_code';
    }
    throw new V4FqaLeaseFailure($code);
}

function v4fqaSecret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

function v4fqaScalar(mysqli $db, string $sql): string
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        v4fqaFail('database_query_failed');
    }
    try {
        $row = $result->fetch_row();
    } finally {
        $result->free();
    }
    if (!is_array($row) || count($row) !== 1) {
        v4fqaFail('database_scalar_invalid');
    }
    return (string) $row[0];
}

/** @return list<array<string,mixed>> */
function v4fqaRows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        v4fqaFail('database_query_failed');
    }
    try {
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        return $rows;
    } finally {
        $result->free();
    }
}

/** @param list<string> $allowedIdentityStates
 * @return array{identity_id:int,identity_state:string,lock_version:int,admin_user_id:int,admin_principal_id:int,accounts:array<string,array<string,mixed>>}
 */
function v4fqaPreflight(mysqli $db, string $prefix, array $allowedIdentityStates = ['FROZEN']): array
{
    $ci = $prefix . 'class_identity_';
    foreach ([$ci, $prefix] as $candidate) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $candidate) !== 1) {
            v4fqaFail('table_prefix_invalid');
        }
    }

    $identityRows = v4fqaRows(
        $db,
        "SELECT id,state,identity_type,lock_version FROM `{$ci}identity` "
        . "WHERE roster_code='" . V4_FQA_ROSTER . "' LIMIT 2"
    );
    if (count($identityRows) !== 1
        || !in_array(($identityRows[0]['state'] ?? null), $allowedIdentityStates, true)
        || ($identityRows[0]['identity_type'] ?? null) !== 'CLASSMATE'
        || (int) ($identityRows[0]['id'] ?? 0) <= 0
    ) {
        v4fqaFail('candidate_identity_not_frozen');
    }
    $identityId = (int) $identityRows[0]['id'];

    $accounts = v4fqaRows($db, <<<SQL
SELECT s.id AS seat_id,s.seat_type,s.state AS seat_state,s.lock_version AS seat_lock_version,
       a.id AS account_id,a.requested_username,
       a.state AS account_state,a.current_marker,p.id AS principal_id,
       p.piwigo_user_id,p.state AS principal_state,p.auth_epoch,u.username AS core_username,
       u.password AS core_password_hash,
       ui.status AS core_status,
       (SELECT COUNT(*) FROM `{$prefix}user_group` ug WHERE ug.user_id=p.piwigo_user_id) AS group_count,
       (SELECT COUNT(*) FROM `{$prefix}user_group` ug
          JOIN `{$prefix}groups` g ON g.id=ug.group_id
         WHERE ug.user_id=p.piwigo_user_id AND g.name=s.seat_type) AS expected_group_count
  FROM `{$ci}seat` s
  JOIN `{$ci}account` a ON a.seat_id=s.id AND a.current_marker=1
  JOIN `{$ci}principal` p ON p.account_id=a.id AND p.principal_type='SEAT_ACCOUNT'
  JOIN `{$prefix}users` u ON u.id=p.piwigo_user_id
  JOIN `{$prefix}user_infos` ui ON ui.user_id=u.id
 WHERE s.identity_id={$identityId}
 ORDER BY s.seat_type
SQL);
    if (count($accounts) !== 3) {
        v4fqaFail('candidate_account_topology_invalid');
    }

    $bound = [];
    foreach ($accounts as $row) {
        $role = (string) ($row['seat_type'] ?? '');
        $username = (string) ($row['requested_username'] ?? '');
        $validUsername = match ($role) {
            'CLASSMATE' => $username === 'fqa_99ca3b3b6af1_classmate',
            'FAMILY' => $username === 'fqa_99ca3b3b6af1_family',
            'ANONYMOUS' => preg_match('/\Aanon_[a-f0-9]{20}\z/D', $username) === 1,
            default => false,
        };
        if (!$validUsername
            || isset($bound[$role])
            || ($row['core_username'] ?? null) !== $username
            || ($row['seat_state'] ?? null) !== 'ACTIVE'
            || ($row['account_state'] ?? null) !== 'ACTIVE'
            || (int) ($row['current_marker'] ?? 0) !== 1
            || ($row['principal_state'] ?? null) !== 'ACTIVE'
            || ($row['core_status'] ?? null) !== 'normal'
            || (int) ($row['group_count'] ?? -1) !== 1
            || (int) ($row['expected_group_count'] ?? -1) !== 1
            || (int) ($row['principal_id'] ?? 0) <= 0
            || (int) ($row['piwigo_user_id'] ?? 0) <= 0
        ) {
            v4fqaFail('candidate_account_invariant_failed');
        }
        $bound[$role] = [
            'seat_id' => (int) $row['seat_id'],
            'seat_lock_version' => (int) $row['seat_lock_version'],
            'account_id' => (int) $row['account_id'],
            'principal_id' => (int) $row['principal_id'],
            'user_id' => (int) $row['piwigo_user_id'],
            'username' => $username,
            'auth_epoch' => (int) $row['auth_epoch'],
            // Ephemeral CAS verifier. Never serialized, audited or printed.
            'password_hash' => (string) $row['core_password_hash'],
        ];
    }
    if (array_keys($bound) !== V4_FQA_ROLES) {
        v4fqaFail('candidate_role_set_invalid');
    }

    $principalIds = implode(',', array_map(
        static fn(array $row): int => $row['principal_id'],
        array_values($bound),
    ));
    $invariants = [
        'extra_current_account' => "SELECT COUNT(*) - 3 FROM `{$ci}account` a JOIN `{$ci}seat` s ON s.id=a.seat_id WHERE s.identity_id={$identityId} AND a.current_marker=1",
        'extra_seat_principal' => "SELECT COUNT(*) - 3 FROM `{$ci}principal` p JOIN `{$ci}account` a ON a.id=p.account_id JOIN `{$ci}seat` s ON s.id=a.seat_id WHERE s.identity_id={$identityId} AND p.principal_type='SEAT_ACCOUNT'",
        'issued_token' => "SELECT COUNT(*) FROM `{$ci}token` t WHERE t.state='ISSUED' AND (t.seat_id IN (SELECT id FROM `{$ci}seat` WHERE identity_id={$identityId}) OR t.principal_id IN ({$principalIds}))",
        'submission' => "SELECT COUNT(*) FROM `{$ci}submission` WHERE identity_id={$identityId}",
        'active_pin' => "SELECT COUNT(*) FROM `{$ci}collection_pin` WHERE principal_id IN ({$principalIds}) AND state='ACTIVE'",
        'unfinished_operation' => "SELECT COUNT(*) FROM `{$ci}operation` WHERE identity_id={$identityId} AND state<>'COMMITTED'",
        'live_comment' => "SELECT COUNT(*) FROM `{$ci}photo_comment` WHERE author_principal_id IN ({$principalIds}) AND state<>'DELETED'",
        'active_auth_key' => "SELECT COUNT(*) FROM `{$prefix}user_auth_keys` WHERE user_id IN (SELECT piwigo_user_id FROM `{$ci}principal` WHERE id IN ({$principalIds})) AND revoked_on IS NULL AND expired_on>UTC_TIMESTAMP()",
    ];
    foreach ($invariants as $name => $sql) {
        if ((int) v4fqaScalar($db, $sql) !== 0) {
            v4fqaFail('candidate_' . $name . '_present');
        }
    }

    $admins = v4fqaRows($db, <<<SQL
SELECT p.id,p.piwigo_user_id
  FROM `{$ci}principal` p
  JOIN `{$prefix}user_infos` ui ON ui.user_id=p.piwigo_user_id
 WHERE p.principal_type='SYSTEM_ACCOUNT' AND p.system_role='SYSTEM_ADMIN'
   AND p.account_id IS NULL AND p.state='ACTIVE' AND ui.status IN ('admin','webmaster')
 ORDER BY p.id
SQL);
    if (count($admins) !== 1) {
        v4fqaFail('single_system_admin_required');
    }

    return [
        'identity_id' => $identityId,
        'identity_state' => (string) $identityRows[0]['state'],
        'lock_version' => (int) $identityRows[0]['lock_version'],
        'admin_user_id' => (int) $admins[0]['piwigo_user_id'],
        'admin_principal_id' => (int) $admins[0]['id'],
        'accounts' => $bound,
    ];
}

/** @param array<string,mixed> $left @param array<string,mixed> $right */
function v4fqaSameTopology(array $left, array $right, bool $includePassword = false): bool
{
    $normalize = static function (array $accounts) use ($includePassword): array {
        if (!$includePassword) {
            foreach ($accounts as &$account) {
                unset($account['password_hash']);
            }
            unset($account);
        }
        return $accounts;
    };
    if (($left['identity_id'] ?? null) !== ($right['identity_id'] ?? null)
        || ($left['lock_version'] ?? null) !== ($right['lock_version'] ?? null)
        || $normalize((array) ($left['accounts'] ?? [])) !== $normalize((array) ($right['accounts'] ?? []))
    ) {
        return false;
    }
    return true;
}

function v4fqaPasswordHash(string $password): string
{
    global $conf;
    if (!isset($conf['password_hash']) || !is_callable($conf['password_hash'])) {
        v4fqaFail('core_password_hasher_unavailable');
    }
    $hash = $conf['password_hash']($password);
    if (!is_string($hash) || $hash === '') {
        v4fqaFail('core_password_hash_failed');
    }
    return $hash;
}

/** @param array<string,array<string,mixed>> $accounts */
function v4fqaBuildCredentialPlan(array $accounts): array
{
    $plan = [];
    foreach ($accounts as $role => $account) {
        $secret = v4fqaSecret();
        $leaseHash = v4fqaPasswordHash($secret);
        $closedSecret = v4fqaSecret();
        $closedHash = v4fqaPasswordHash($closedSecret);
        $closedSecret = str_repeat("\0", strlen($closedSecret));
        $plan[$role] = [
            'role' => $role,
            'seat_id' => (int) $account['seat_id'],
            'seat_lock_version' => (int) $account['seat_lock_version'],
            'account_id' => (int) $account['account_id'],
            'principal_id' => (int) $account['principal_id'],
            'user_id' => (int) $account['user_id'],
            'username' => (string) $account['username'],
            'before_password_hash' => (string) $account['password_hash'],
            // Keep the non-secret digest in the in-memory plan as well as the
            // durable recovery plan. If the first credential helper throws,
            // the outer finally block must be able to distinguish untouched
            // verifiers without re-reading a file that may itself be damaged.
            'before_password_sha256' => hash('sha256', (string) $account['password_hash']),
            'lease_password_hash' => $leaseHash,
            'lease_password_sha256' => hash('sha256', $leaseHash),
            'closed_password_hash' => $closedHash,
            'closed_password_sha256' => hash('sha256', $closedHash),
            'browser_password' => $secret,
        ];
    }
    return $plan;
}

function v4fqaInstallCredentialPlan(
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $leaseContext,
    array &$plan,
    int $identityId,
    int $adminUserId,
    int $adminPrincipalId,
    ?callable $revokeCredentials = null,
    ?callable $appendAudit = null,
    ?callable $afterCredentialInstalled = null,
): array {
    $credentials = [];
    foreach ($plan as $role => &$item) {
        $changed = $leaseService->compareAndSetFixturePasswordHash(
            $leaseContext,
            (int) $item['user_id'],
            (int) $item['principal_id'],
            (int) $item['account_id'],
            (int) $item['seat_id'],
            (int) $item['seat_lock_version'],
            (string) $item['username'],
            (string) $item['before_password_hash'],
            (string) $item['lease_password_hash'],
        );
        if (!$changed) {
            v4fqaFail('fixture_credential_install_cas_conflict');
        }
        if ($revokeCredentials !== null) {
            $revokeCredentials((int) $item['user_id']);
        } else {
            \ClassIdentity\CoreAdapter::revokeAllCredentials((int) $item['user_id']);
        }
        $auditEvent = [
            'actor_principal_id' => $adminPrincipalId,
            'actor_user_id' => $adminUserId,
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => 'PRINCIPAL_SECURITY_CHANGE',
            'target_type' => 'PRINCIPAL',
            'target_id' => (string) $item['principal_id'],
            'target_identity_id' => $identityId,
            'target_principal_id' => (int) $item['principal_id'],
            'new_value' => [
                'state' => 'LEASE_OPEN',
                'role_code' => $role,
                'reason_code' => 'LOCAL_FQA_LEASE',
            ],
            'reason' => '本地 V4 浏览器验收临时凭据租约开始',
            'result' => 'SUCCESS',
        ];
        if ($appendAudit !== null) {
            $appendAudit($auditEvent);
        } else {
            \ClassIdentity\Audit::fromPiwigo()->append($auditEvent);
        }
        $credentials[strtolower($role)] = [
            'username' => (string) $item['username'],
            'password' => (string) $item['browser_password'],
        ];
        $item['browser_password'] = str_repeat("\0", strlen((string) $item['browser_password']));
        unset($item['before_password_hash'], $item['lease_password_hash']);
        if ($afterCredentialInstalled !== null) {
            $afterCredentialInstalled((string) $role);
        }
    }
    unset($item);
    return $credentials;
}

function v4fqaWriteCredentialFile(string $path, string $run, array $credentials, array $plan): void
{
    if (file_exists($path) || is_link($path)) {
        v4fqaFail('credential_path_not_fresh');
    }
    $recoveryPlan = [];
    foreach ($plan as $role => $item) {
        $recoveryPlan[$role] = [
            'role' => (string) $item['role'],
            'seat_id' => (int) $item['seat_id'],
            'seat_lock_version' => (int) $item['seat_lock_version'],
            'account_id' => (int) $item['account_id'],
            'principal_id' => (int) $item['principal_id'],
            'user_id' => (int) $item['user_id'],
            'username' => (string) $item['username'],
            'before_password_sha256' => (string) $item['before_password_sha256'],
            'lease_password_sha256' => (string) $item['lease_password_sha256'],
            'closed_password_hash' => (string) $item['closed_password_hash'],
            'closed_password_sha256' => (string) $item['closed_password_sha256'],
        ];
    }
    $document = [
        'version' => 3,
        'environment' => 'PRIVATE_REAL_FULL_OWNER_V4_FQA_LEASE',
        'run' => $run,
        'lease' => ['roster' => V4_FQA_ROSTER, 'roles' => 3],
        'roles' => $credentials,
        // This plan contains no plaintext closed credential and no prior
        // password verifier. It lets an abandoned broker remove only a hash
        // that is proven to have been installed by this exact lease.
        'recovery_plan' => $recoveryPlan,
    ];
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    // Keep the file private from the instant of creation; chmod after fopen is
    // retained as an explicit invariant, not relied on to close a race window.
    $previousUmask = umask(0077);
    try {
        $handle = fopen($path, 'x');
    } finally {
        umask($previousUmask);
    }
    if (!is_resource($handle)) {
        v4fqaFail('credential_file_create_failed');
    }
    try {
        if (!function_exists('fsync')) {
            v4fqaFail('credential_file_fsync_unavailable');
        }
        if (!chmod($path, 0600)
            || fwrite($handle, $json) !== strlen($json)
            || !fflush($handle)
            || !fsync($handle)
        ) {
            v4fqaFail('credential_file_write_failed');
        }
    } finally {
        fclose($handle);
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
    ) {
        v4fqaFail('credential_file_mode_invalid');
    }
    // Persist the directory entry too. The recovery plan is only considered
    // ready after both its bytes and its parent directory have reached the
    // filesystem; the first password CAS happens strictly after this returns.
    $directoryHandle = @fopen(dirname($path), 'r');
    if (!is_resource($directoryHandle)) {
        v4fqaFail('credential_directory_open_failed');
    }
    try {
        if (!fsync($directoryHandle)) {
            v4fqaFail('credential_directory_fsync_failed');
        }
    } finally {
        fclose($directoryHandle);
    }
}

function v4fqaReadCredentialPlan(string $path, string $run): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || is_link($path)
        || (int) ($stat['size'] ?? 0) < 128
        || (int) ($stat['size'] ?? 0) > 65536
    ) {
        v4fqaFail('credential_recovery_plan_file_invalid');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        v4fqaFail('credential_recovery_plan_read_failed');
    }
    $document = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($document)
        || ($document['version'] ?? null) !== 3
        || ($document['run'] ?? null) !== $run
        || !is_array($document['recovery_plan'] ?? null)
        || array_keys($document['recovery_plan']) !== V4_FQA_ROLES
    ) {
        v4fqaFail('credential_recovery_plan_contract_invalid');
    }
    foreach ($document['recovery_plan'] as $role => $item) {
        if (!is_array($item)
            || ($item['role'] ?? null) !== $role
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($item['before_password_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($item['lease_password_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($item['closed_password_sha256'] ?? '')) !== 1
            || !is_string($item['closed_password_hash'] ?? null)
            || (string) $item['closed_password_hash'] === ''
            || (int) ($item['user_id'] ?? 0) <= 0
            || (int) ($item['principal_id'] ?? 0) <= 0
            || (int) ($item['account_id'] ?? 0) <= 0
            || (int) ($item['seat_id'] ?? 0) <= 0
        ) {
            v4fqaFail('credential_recovery_plan_item_invalid');
        }
    }
    return $document['recovery_plan'];
}

function v4fqaRemoveCredentialFile(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || is_link($path)
    ) {
        v4fqaFail('credential_cleanup_target_invalid');
    }
    if (!unlink($path)) {
        v4fqaFail('credential_cleanup_failed');
    }
}

function v4fqaCurrentPasswordHash(mysqli $db, string $prefix, int $userId, string $username): ?string
{
    if ($userId <= 0 || $username === '' || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
        v4fqaFail('credential_lookup_input_invalid');
    }
    $stmt = $db->prepare("SELECT `password` FROM `{$prefix}users` WHERE `id`=? AND `username`=? LIMIT 2");
    if (!$stmt instanceof mysqli_stmt) {
        v4fqaFail('credential_lookup_prepare_failed');
    }
    $stmt->bind_param('is', $userId, $username);
    if (!$stmt->execute()) {
        $stmt->close();
        v4fqaFail('credential_lookup_execute_failed');
    }
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_NUM);
    $result->free();
    $stmt->close();
    if (count($rows) !== 1 || !is_string($rows[0][0] ?? null)) {
        return null;
    }
    return (string) $rows[0][0];
}

function v4fqaCloseCredentialPlan(
    mysqli $db,
    string $prefix,
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $leaseContext,
    array $plan,
    ?callable $revokeCredentials = null,
): bool {
    $conflict = false;
    foreach ($plan as $role => $item) {
        $userId = (int) ($item['user_id'] ?? 0);
        $username = (string) ($item['username'] ?? '');
        $currentHash = v4fqaCurrentPasswordHash($db, $prefix, $userId, $username);
        if ($currentHash === null) {
            $conflict = true;
            continue;
        }
        $currentDigest = hash('sha256', $currentHash);
        $beforeDigest = (string) ($item['before_password_sha256'] ?? '');
        $leaseDigest = (string) ($item['lease_password_sha256'] ?? '');
        $closedDigest = (string) ($item['closed_password_sha256'] ?? '');
        if (hash_equals($beforeDigest, $currentDigest) || hash_equals($closedDigest, $currentDigest)) {
            if ($revokeCredentials !== null) {
                $revokeCredentials($userId);
            } else {
                \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
            }
            continue;
        }
        if (!hash_equals($leaseDigest, $currentDigest)) {
            // A concurrent administrator verifier always wins.
            if ($revokeCredentials !== null) {
                $revokeCredentials($userId);
            } else {
                \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
            }
            $conflict = true;
            continue;
        }
        $changed = $leaseService->compareAndSetLeasedPasswordHash(
            $leaseContext,
            $userId,
            $username,
            $currentHash,
            (string) ($item['closed_password_hash'] ?? ''),
        );
        if ($revokeCredentials !== null) {
            $revokeCredentials($userId);
        } else {
            \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
        }
        if (!$changed) {
            $conflict = true;
        }
    }
    return !$conflict;
}

/**
 * Bind a persisted recovery plan to the exact fixture topology observed by
 * the broker. The plan is intentionally unusable after a seat/account/user
 * replacement, even when a recycled user id happens to carry the lease hash.
 */
function v4fqaCredentialPlanMatchesState(array $plan, array $state): bool
{
    if (array_keys($plan) !== V4_FQA_ROLES || array_keys((array) ($state['accounts'] ?? [])) !== V4_FQA_ROLES) {
        return false;
    }
    foreach (V4_FQA_ROLES as $role) {
        $item = $plan[$role] ?? null;
        $account = $state['accounts'][$role] ?? null;
        if (!is_array($item) || !is_array($account)
            || (string) ($item['role'] ?? '') !== $role
            || (int) ($item['seat_id'] ?? 0) !== (int) ($account['seat_id'] ?? -1)
            || (int) ($item['seat_lock_version'] ?? -1) !== (int) ($account['seat_lock_version'] ?? -2)
            || (int) ($item['account_id'] ?? 0) !== (int) ($account['account_id'] ?? -1)
            || (int) ($item['principal_id'] ?? 0) !== (int) ($account['principal_id'] ?? -1)
            || (int) ($item['user_id'] ?? 0) !== (int) ($account['user_id'] ?? -1)
            || !hash_equals((string) ($item['username'] ?? ''), (string) ($account['username'] ?? ''))
        ) {
            return false;
        }
    }
    return true;
}

/**
 * Read only the identity/admin envelope needed to quarantine an abandoned
 * lease. Recovery deliberately does not require the mutable Seat topology to
 * still match before broker-owned password verifiers can be removed.
 *
 * @return array<string,mixed>
 */
function v4fqaRecoveryIdentityEnvelope(mysqli $db, string $prefix): array
{
    $ci = $prefix . 'class_identity_';
    foreach ([$ci, $prefix] as $candidate) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $candidate) !== 1) {
            v4fqaFail('table_prefix_invalid');
        }
    }
    $identityRows = v4fqaRows(
        $db,
        "SELECT id,state,identity_type,lock_version FROM `{$ci}identity` "
        . "WHERE roster_code='" . V4_FQA_ROSTER . "' LIMIT 2",
    );
    if (count($identityRows) !== 1
        || !in_array(($identityRows[0]['state'] ?? null), ['ACTIVE', 'FROZEN'], true)
        || ($identityRows[0]['identity_type'] ?? null) !== 'CLASSMATE'
        || (int) ($identityRows[0]['id'] ?? 0) <= 0
    ) {
        v4fqaFail('recovery_identity_envelope_invalid');
    }
    $admins = v4fqaRows($db, <<<SQL
SELECT p.id,p.piwigo_user_id
  FROM `{$ci}principal` p
  JOIN `{$prefix}user_infos` ui ON ui.user_id=p.piwigo_user_id
 WHERE p.principal_type='SYSTEM_ACCOUNT' AND p.system_role='SYSTEM_ADMIN'
   AND p.account_id IS NULL AND p.state='ACTIVE' AND ui.status IN ('admin','webmaster')
 ORDER BY p.id
SQL);
    if (count($admins) !== 1) {
        v4fqaFail('recovery_single_system_admin_required');
    }
    return [
        'identity_id' => (int) $identityRows[0]['id'],
        'identity_state' => (string) $identityRows[0]['state'],
        'lock_version' => (int) $identityRows[0]['lock_version'],
        'admin_user_id' => (int) $admins[0]['piwigo_user_id'],
        'admin_principal_id' => (int) $admins[0]['id'],
        'accounts' => [],
    ];
}

/**
 * Remove only exact user-id + username + lease-digest matches, then preserve a
 * fail-closed identity/lease state. A changed administrator verifier is never
 * overwritten. This path never releases the lease.
 *
 * @param array<string,mixed> $state
 * @param array<string,array<string,mixed>> $plan
 */
function v4fqaQuarantineTopologyConflict(
    mysqli $db,
    string $prefix,
    ClassIdentityAdminService $admin,
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $leaseContext,
    array $state,
    array $plan,
    ?callable $revokeCredentials = null,
): bool {
    // Credential cleanup comes first and is independently pinned to the exact
    // broker-owned verifier. It is safe even when Seat/Principal topology has
    // drifted because it cannot replace an administrator's newer verifier.
    $credentialsClosed = false;
    try {
        $credentialsClosed = v4fqaCloseCredentialPlan(
            $db,
            $prefix,
            $leaseService,
            $leaseContext,
            $plan,
            $revokeCredentials,
        );
    } catch (Throwable) {
        // Continue to freeze/CONFLICT. A helper failure must never strand an
        // ACTIVE lease merely because credential cleanup could not attest.
        $credentialsClosed = false;
    }
    $identityFrozen = ($state['identity_state'] ?? null) === 'FROZEN';
    if (!$identityFrozen && ($state['identity_state'] ?? null) === 'ACTIVE') {
        try {
            $admin->setIdentityFrozen(
                (int) $state['identity_id'],
                true,
                '本地 V4 浏览器验收拓扑冲突隔离',
                (int) $state['admin_user_id'],
                $leaseContext,
                $leaseContext->expectedLockVersion(),
            );
            $identityFrozen = true;
        } catch (Throwable) {
            $identityFrozen = false;
        }
    }
    try {
        $leaseService->markConflict($leaseContext);
    } catch (Throwable) {
        return false;
    }
    // A false credential close result can mean that an administrator already
    // replaced one verifier; that is a successfully preserved admin change,
    // not a reason to undo the quarantine. Exact broker-owned matches have
    // still been processed and the durable CONFLICT keeps all access denied.
    return $identityFrozen;
}

/** @param array<string,mixed> $state */
function v4fqaAppendCredentialClosureAudit(array $state, bool $safe): void
{
    foreach ((array) ($state['accounts'] ?? []) as $role => $account) {
        \ClassIdentity\Audit::fromPiwigo()->append([
            'actor_principal_id' => (int) ($state['admin_principal_id'] ?? 0),
            'actor_user_id' => (int) ($state['admin_user_id'] ?? 0),
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => 'PRINCIPAL_SECURITY_CHANGE',
            'target_type' => 'PRINCIPAL',
            'target_id' => (string) ($account['principal_id'] ?? ''),
            'target_identity_id' => (int) ($state['identity_id'] ?? 0),
            'target_principal_id' => (int) ($account['principal_id'] ?? 0),
            'new_value' => [
                'state' => $safe ? 'LEASE_CLOSED' : 'LEASE_CONFLICT',
                'role_code' => (string) $role,
                'reason_code' => 'LOCAL_FQA_LEASE_CLEANUP',
            ],
            'reason' => $safe
                ? '本地 V4 浏览器验收临时凭据租约已安全关闭'
                : '本地 V4 浏览器验收临时凭据租约检测到并发冲突',
            'result' => $safe ? 'SUCCESS' : 'FAILED',
        ]);
    }
}

/** @param array<string,mixed> $state */
function v4fqaCloseAccess(
    mysqli $db,
    string $prefix,
    ClassIdentityAdminService $admin,
    \ClassIdentity\PrivateE2EFixtureLeaseService $leaseService,
    \ClassIdentity\PrivateE2EFixtureLeaseContext $leaseContext,
    array $state,
    array $credentialPlan,
    ?callable $revokeCredentials = null,
): array {
    // This is a single exact-version CAS, not a retrying rollback. If an
    // out-of-band write somehow changes the aggregate revision, AdminService
    // rejects the cleanup and this function never overwrites that write.
    $beforeCloseVersion = $leaseContext->expectedLockVersion();
    $freezeError = null;
    try {
        $admin->setIdentityFrozen(
            $state['identity_id'],
            true,
            '本地 V4 浏览器验收临时访问租约结束',
            $state['admin_user_id'],
            $leaseContext,
            $beforeCloseVersion,
        );
    } catch (Throwable $error) {
        $freezeError = $error;
    }

    $credentialCloseError = null;
    $credentialsClosed = false;
    try {
        $credentialsClosed = v4fqaCloseCredentialPlan(
            $db,
            $prefix,
            $leaseService,
            $leaseContext,
            $credentialPlan,
            $revokeCredentials,
        );
    } catch (Throwable $error) {
        $credentialCloseError = $error;
    }
    try {
        v4fqaAppendCredentialClosureAudit($state, $freezeError === null && $credentialsClosed);
    } catch (Throwable) {
        try {
            $leaseService->markConflict($leaseContext);
        } catch (Throwable) {
        }
        return ['safe' => false, 'reason' => 'credential_cleanup_audit_failed'];
    }
    if ($freezeError !== null || $credentialCloseError !== null || !$credentialsClosed) {
        try {
            $leaseService->markConflict($leaseContext);
        } catch (Throwable) {
            // Expired/conflicted durable state remains denied by Access.
        }
        return [
            'safe' => false,
            'reason' => $freezeError !== null
                ? 'identity_cas_conflict'
                : ($credentialCloseError !== null ? 'credential_cleanup_exception' : 'credential_cas_conflict'),
        ];
    }

    try {
        $closed = v4fqaPreflight($db, $prefix, ['FROZEN']);
    } catch (Throwable) {
        try {
            $leaseService->markConflict($leaseContext);
        } catch (Throwable) {
        }
        return ['safe' => false, 'reason' => 'closed_topology_conflict'];
    }
    if ($closed['identity_id'] !== $state['identity_id']
        || $closed['lock_version'] !== $leaseContext->expectedLockVersion()
    ) {
        try {
            $leaseService->markConflict($leaseContext);
        } catch (Throwable) {
        }
        return ['safe' => false, 'reason' => 'closed_identity_drift'];
    }
    $leaseService->releaseIdentityLease($leaseContext);
    return ['safe' => true, 'state' => $closed];
}

// Random-prefix broker runtime tests import the exact credential-plan helpers
// without executing the private Owner entry point.
if (defined('V4_FQA_LIBRARY_ONLY') && V4_FQA_LIBRARY_ONLY === true) {
    return;
}

$run = (string) getenv('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID');
$credentialFile = (string) getenv('CLASS_ARCHIVE_V4_OWNER_FQA_CREDENTIAL_FILE');
$ttlRaw = (string) getenv('CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS');
$leaseMode = getenv('CLASS_ARCHIVE_V4_OWNER_FQA_LEASE') === '1';
$recoveryMode = getenv('CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY') === '1';
if (!V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN
    || getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1'
    || $leaseMode === $recoveryMode
    || preg_match('/\A[a-f0-9]{24}\z/D', $run) !== 1
    || (($leaseMode && preg_match('/\A\/var\/lib\/class-archive-private-e2e\/credentials-[a-f0-9]{16}\.json\z/D', $credentialFile) !== 1)
        || ($recoveryMode && $credentialFile !== ''))
    || ($leaseMode && (preg_match('/\A[0-9]{3,4}\z/D', $ttlRaw) !== 1
        || (int) $ttlRaw < 300
        || (int) $ttlRaw > 1800))
    || ($recoveryMode && $ttlRaw !== '')
    || ($argv[1] ?? null) !== $run
    || (function_exists('posix_geteuid') && posix_geteuid() === 0)
) {
    $bootstrapCode = V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN
        ? 'runtime_gate_rejected'
        : 'mutation_exclusion_unavailable';
    fwrite(STDOUT, "V4_OWNER_FQA_LEASE=FAIL stage=bootstrap code={$bootstrapCode}\n");
    exit(2);
}

$ttl = $leaseMode ? (int) $ttlRaw : 0;
$credentialFile = $recoveryMode
    ? V4_FQA_RECOVERY_ROOT . '/credentials-' . substr($run, 0, 16) . '.json'
    : $credentialFile;
$leaseOpened = false;
$recoveryCompleted = false;
$cleanupTerminal = false;
$cleanupSafe = false;
$credentialPlan = [];
$lockHeld = false;
$state = null;
$db = null;
$admin = null;
$fixtureLeaseService = null;
$fixtureLeaseContext = null;
$recoveryTopologyConflict = false;
$exitCode = 0;

try {
    chdir(V4_FQA_ROOT) || v4fqaFail('piwigo_root_unavailable');
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
    $adminServicePath = realpath(CLASS_IDENTITY_PATH . 'src/AdminService.php');
    $expectedAdminServicePath = realpath(V4_FQA_ROOT . '/plugins/ClassIdentity/src/AdminService.php');
    if (!is_string($adminServicePath)
        || !is_string($expectedAdminServicePath)
        || !hash_equals($expectedAdminServicePath, $adminServicePath)
    ) {
        v4fqaFail('admin_service_path_untrusted');
    }
    require_once $adminServicePath;

    global $mysqli, $prefixeTable;
    if (!$mysqli instanceof mysqli || !is_string($prefixeTable)
        || !class_exists(\ClassIdentity\Schema::class)
        || !class_exists(\ClassIdentity\Access::class)
        || !class_exists(\ClassIdentity\CoreAdapter::class)
        || !class_exists(\ClassIdentity\Audit::class)
        || !class_exists(\ClassIdentity\PrivateE2EFixtureLeaseService::class)
        || !class_exists(\ClassIdentity\PrivateE2EFixtureLeaseContext::class)
        || !class_exists(ClassIdentityAdminService::class)
        || !\ClassIdentity\Access::isEnforcementEnabled()
    ) {
        v4fqaFail('class_identity_runtime_unavailable');
    }
    \ClassIdentity\Schema::fromPiwigo((string) CLASS_IDENTITY_VERSION)->verifyCurrent();
    $db = $mysqli;
    if ((int) v4fqaScalar($db, "SELECT GET_LOCK('" . V4_FQA_LOCK . "',0)") !== 1) {
        v4fqaFail('database_lease_busy');
    }
    $lockHeld = true;
    if ($recoveryMode) {
        $credentialPlan = v4fqaReadCredentialPlan($credentialFile, $run);
        $state = v4fqaRecoveryIdentityEnvelope($db, $prefixeTable);
        try {
            $candidateState = v4fqaPreflight($db, $prefixeTable, ['ACTIVE', 'FROZEN']);
            $state = $candidateState;
            $recoveryTopologyConflict = !v4fqaCredentialPlanMatchesState($credentialPlan, $candidateState);
        } catch (Throwable) {
            // A valid durable plan must still be able to remove its own exact
            // verifiers after Seat/Principal topology drift. The lease remains
            // fail-closed and is never released from this branch.
            $recoveryTopologyConflict = true;
        }
    } else {
        $state = v4fqaPreflight($db, $prefixeTable, ['FROZEN']);
    }
    $admin = ClassIdentityAdminService::fromPiwigo();
    $fixtureLeaseService = \ClassIdentity\PrivateE2EFixtureLeaseService::fromPiwigo();
    $fixtureLeaseContext = $recoveryMode
        ? $fixtureLeaseService->recoverAbandonedIdentityLease(
            $state['identity_id'],
            $run,
            V4_FQA_OWNER,
            300,
        )
        : $fixtureLeaseService->acquireIdentityLease(
            $state['identity_id'],
            $run,
            V4_FQA_OWNER,
            $ttl,
            $state['lock_version'],
        );

    if ($recoveryMode && $recoveryTopologyConflict) {
        $topologyQuarantined = v4fqaQuarantineTopologyConflict(
            $db,
            $prefixeTable,
            $admin,
            $fixtureLeaseService,
            $fixtureLeaseContext,
            $state,
            $credentialPlan,
        );
        if (!$topologyQuarantined) {
            v4fqaFail('credential_recovery_topology_quarantine_incomplete');
        }
        // Only declare a terminal cleanup after quarantine has actually
        // frozen the Identity and transitioned the durable lease to CONFLICT.
        $cleanupTerminal = true;
        // CONFLICT is intentionally not a safe close: preserve the durable
        // recovery plan for reconciliation and never emit RECOVERED.
        $cleanupSafe = false;
        $exitCode = 2;
    }

    if (!$recoveryTopologyConflict && function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, static function (): void {
                throw new V4FqaLeaseFailure('lease_signal_received');
            });
        }
    }

    if ($recoveryMode && !$recoveryTopologyConflict) {
        $closeResult = v4fqaCloseAccess(
            $db,
            $prefixeTable,
            $admin,
            $fixtureLeaseService,
            $fixtureLeaseContext,
            $state,
            $credentialPlan,
        );
        $cleanupTerminal = true;
        $cleanupSafe = ($closeResult['safe'] ?? false) === true;
        $recoveryCompleted = $cleanupSafe;
        if (!$cleanupSafe) {
            $exitCode = 2;
        }
    } elseif (!$recoveryMode) {

    $credentialPlan = v4fqaBuildCredentialPlan($state['accounts']);
    $plannedCredentials = [];
    foreach ($credentialPlan as $role => $item) {
        $plannedCredentials[strtolower($role)] = [
            'username' => (string) $item['username'],
            'password' => (string) $item['browser_password'],
        ];
    }
    // The 0600 recovery plan exists before the first password CAS. A crash at
    // any later point can therefore remove only this run's installed hashes.
    v4fqaWriteCredentialFile($credentialFile, $run, $plannedCredentials, $credentialPlan);
    $credentials = v4fqaInstallCredentialPlan(
        $fixtureLeaseService,
        $fixtureLeaseContext,
        $credentialPlan,
        $state['identity_id'],
        $state['admin_user_id'],
        $state['admin_principal_id'],
    );
    $credentials = [];
    $plannedCredentials = [];

    // Re-read every invariant after credential preparation. The named lock
    // serializes brokers; this immediate topology/version check also rejects
    // any out-of-band admin drift observed before activation.
    $activationState = v4fqaPreflight($db, $prefixeTable, ['FROZEN']);
    if (!v4fqaSameTopology($state, $activationState)) {
        v4fqaFail('candidate_changed_before_activation');
    }

    // Identity activation is deliberately the final opening action.
    $admin->setIdentityFrozen(
        $state['identity_id'],
        false,
        '本地 V4 浏览器验收临时访问租约开始',
        $state['admin_user_id'],
        $fixtureLeaseContext,
        $state['lock_version'],
    );
    $leaseOpened = true;
    $openedIdentity = v4fqaRows(
        $db,
        "SELECT state,lock_version FROM `{$prefixeTable}class_identity_identity` WHERE id=" . $state['identity_id'],
    );
    if (count($openedIdentity) !== 1
        || ($openedIdentity[0]['state'] ?? null) !== 'ACTIVE'
        || (int) ($openedIdentity[0]['lock_version'] ?? -1) !== $state['lock_version'] + 1
    ) {
        v4fqaFail('identity_unfreeze_failed');
    }
    foreach ($state['accounts'] as $role => $account) {
        $context = \ClassIdentity\Access::resolveAuthorizationContext($account['user_id']);
        if (!is_array($context) || ($context['role'] ?? null) !== $role) {
            v4fqaFail('leased_role_context_invalid');
        }
    }

    fwrite(STDOUT, "V4_OWNER_FQA_LEASE=READY roles=3 ttl={$ttl}\n");
    fflush(STDOUT);
    $deadline = time() + $ttl;
    $nextHeartbeat = time() + max(30, min(60, intdiv($ttl, 3)));
    stream_set_blocking(STDIN, false);
    while (time() < $deadline) {
        $read = [STDIN];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 1);
        if ($ready === false) {
            v4fqaFail('control_channel_failed');
        }
        if ($ready === 0) {
            if (time() >= $nextHeartbeat) {
                $fixtureLeaseService->heartbeat($fixtureLeaseContext, $ttl);
                $nextHeartbeat = time() + max(30, min(60, intdiv($ttl, 3)));
            }
            continue;
        }
        $line = fgets(STDIN);
        if ($line === false) {
            break; // parent EOF: cleanup immediately
        }
        if (hash_equals('STOP ' . $run, trim($line))) {
            break;
        }
        v4fqaFail('control_message_invalid');
    }
    }
}
catch (Throwable $error) {
    $code = $error instanceof V4FqaLeaseFailure ? $error->getMessage() : 'unexpected';
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'unexpected';
    }
    fwrite(STDOUT, "V4_OWNER_FQA_LEASE=FAIL stage=runtime code={$code}\n");
    $exitCode = 2;
}
finally {
    if (is_array($state)
        && $admin instanceof ClassIdentityAdminService
        && $fixtureLeaseService instanceof \ClassIdentity\PrivateE2EFixtureLeaseService
        && $fixtureLeaseContext instanceof \ClassIdentity\PrivateE2EFixtureLeaseContext
        && !$cleanupTerminal
    ) {
        try {
            $closeResult = v4fqaCloseAccess(
                $db,
                $prefixeTable,
                $admin,
                $fixtureLeaseService,
                $fixtureLeaseContext,
                $state,
                $credentialPlan,
            );
            $cleanupTerminal = true;
            $cleanupSafe = ($closeResult['safe'] ?? false) === true;
            if (!$cleanupSafe) {
                $exitCode = 2;
            }
        } catch (Throwable) {
            try {
                $cleanupTerminal = v4fqaQuarantineTopologyConflict(
                    $db,
                    $prefixeTable,
                    $admin,
                    $fixtureLeaseService,
                    $fixtureLeaseContext,
                    $state,
                    $credentialPlan,
                );
            } catch (Throwable) {
                $cleanupTerminal = false;
            }
            $exitCode = 2;
        }
    }
    if ($cleanupSafe) {
        try {
            v4fqaRemoveCredentialFile($credentialFile);
        } catch (Throwable) {
            $exitCode = 2;
        }
    }
    if ($lockHeld && $db instanceof mysqli) {
        try {
            if ((int) v4fqaScalar($db, "SELECT RELEASE_LOCK('" . V4_FQA_LOCK . "')") !== 1) {
                $exitCode = 2;
            }
        } catch (Throwable) {
            $exitCode = 2;
        }
    }
}

if ($exitCode === 0 && $recoveryCompleted) {
    fwrite(STDOUT, "V4_OWNER_FQA_LEASE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked\n");
} elseif ($exitCode === 0 && $leaseOpened) {
    fwrite(STDOUT, "V4_OWNER_FQA_LEASE=CLOSED identity=FROZEN credentials=unknown sessions=revoked\n");
}
exit($exitCode);
