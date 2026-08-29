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
const V4_FQA_ROSTER = 'FQA-C-99CA3B3B6AF1';
const V4_FQA_LOCK = 'class_archive_v4_owner_fqa_lease_v1';
const V4_FQA_ROLES = ['ANONYMOUS', 'CLASSMATE', 'FAMILY'];
// False until ordinary administrator mutations participate in the same
// identity lease/CAS. This prevents direct helper invocation from bypassing
// the wrapper's matching hard block.
const V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN = false;

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
 * @return array{identity_id:int,identity_state:string,lock_version:int,admin_user_id:int,admin_principal_id:int,accounts:array<string,array{principal_id:int,user_id:int,username:string,auth_epoch:int}>}
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
SELECT s.seat_type,s.state AS seat_state,a.id AS account_id,a.requested_username,
       a.state AS account_state,a.current_marker,p.id AS principal_id,
       p.piwigo_user_id,p.state AS principal_state,p.auth_epoch,u.username AS core_username,
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
            'principal_id' => (int) $row['principal_id'],
            'user_id' => (int) $row['piwigo_user_id'],
            'username' => $username,
            'auth_epoch' => (int) $row['auth_epoch'],
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
function v4fqaSameTopology(array $left, array $right): bool
{
    if (($left['identity_id'] ?? null) !== ($right['identity_id'] ?? null)
        || ($left['lock_version'] ?? null) !== ($right['lock_version'] ?? null)
        || ($left['accounts'] ?? null) !== ($right['accounts'] ?? null)
    ) {
        return false;
    }
    return true;
}

/** @param array<string,array{principal_id:int,user_id:int,username:string,auth_epoch:int}> $accounts */
function v4fqaRotate(array $accounts, int $identityId, int $adminUserId, int $adminPrincipalId, string $phase): array
{
    $credentials = [];
    foreach ($accounts as $role => $account) {
        $secret = v4fqaSecret();
        \ClassIdentity\CoreAdapter::setPassword($account['user_id'], $secret);
        \ClassIdentity\CoreAdapter::revokeAllCredentials($account['user_id']);
        \ClassIdentity\Audit::fromPiwigo()->append([
            'actor_principal_id' => $adminPrincipalId,
            'actor_user_id' => $adminUserId,
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => 'PRINCIPAL_SECURITY_CHANGE',
            'target_type' => 'PRINCIPAL',
            'target_id' => (string) $account['principal_id'],
            'target_identity_id' => $identityId,
            'target_principal_id' => $account['principal_id'],
            'new_value' => [
                'state' => $phase,
                'role_code' => $role,
                'reason_code' => 'LOCAL_FQA_LEASE',
            ],
            'reason' => $phase === 'LEASE_OPEN'
                ? '本地 V4 浏览器验收临时凭据租约开始'
                : '本地 V4 浏览器验收结束并撤销临时凭据',
            'result' => 'SUCCESS',
        ]);
        if ($phase === 'LEASE_OPEN') {
            $credentials[strtolower($role)] = [
                'username' => $account['username'],
                'password' => $secret,
            ];
        }
        $secret = str_repeat("\0", strlen($secret));
    }
    return $credentials;
}

function v4fqaWriteCredentialFile(string $path, string $run, array $credentials): void
{
    if (file_exists($path) || is_link($path)) {
        v4fqaFail('credential_path_not_fresh');
    }
    $document = [
        'version' => 2,
        'environment' => 'PRIVATE_REAL_FULL_OWNER_V4_FQA_LEASE',
        'run' => $run,
        'lease' => ['roster' => V4_FQA_ROSTER, 'roles' => 3],
        'roles' => $credentials,
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
        if (!chmod($path, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            v4fqaFail('credential_file_write_failed');
        }
    } finally {
        fclose($handle);
    }
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (!is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
    ) {
        v4fqaFail('credential_file_mode_invalid');
    }
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

/** @param array<string,mixed> $state */
function v4fqaCloseAccess(
    mysqli $db,
    string $prefix,
    ClassIdentityAdminService $admin,
    array $state,
    bool $expectOpenedVersion,
): array {
    $lastError = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            // Close access first. Every attempt starts by denying access. If an out-of-band admin
            // races the verifier, the next iteration denies access again.
            $admin->setIdentityFrozen(
                $state['identity_id'],
                true,
                '本地 V4 浏览器验收临时访问租约结束',
                $state['admin_user_id'],
            );
            v4fqaRotate(
                $state['accounts'],
                $state['identity_id'],
                $state['admin_user_id'],
                $state['admin_principal_id'],
                'LEASE_CLOSED',
            );
            $closed = v4fqaPreflight($db, $prefix, ['FROZEN']);
            if ($closed['identity_id'] !== $state['identity_id']) {
                v4fqaFail('closed_identity_drift');
            }
            if ($expectOpenedVersion && $closed['lock_version'] !== $state['lock_version'] + 2) {
                v4fqaFail('closed_lock_version_drift');
            }
            foreach ($state['accounts'] as $role => $account) {
                if (!isset($closed['accounts'][$role])
                    || $closed['accounts'][$role]['principal_id'] !== $account['principal_id']
                    || $closed['accounts'][$role]['user_id'] !== $account['user_id']
                    || $closed['accounts'][$role]['auth_epoch'] < $account['auth_epoch'] + 1
                ) {
                    v4fqaFail('closed_account_drift');
                }
            }
            return $closed;
        } catch (Throwable $error) {
            $lastError = $error;
        }
    }
    throw $lastError ?? new V4FqaLeaseFailure('close_verification_failed');
}

$run = (string) getenv('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID');
$credentialFile = (string) getenv('CLASS_ARCHIVE_V4_OWNER_FQA_CREDENTIAL_FILE');
$ttlRaw = (string) getenv('CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS');
$leaseMode = getenv('CLASS_ARCHIVE_V4_OWNER_FQA_LEASE') === '1';
$recoveryMode = getenv('CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY') === '1';
if (!V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN
    || $leaseMode === $recoveryMode
    || preg_match('/\A[a-f0-9]{24}\z/D', $run) !== 1
    || (($leaseMode && preg_match('/\A\/tmp\/class-archive-v4-fqa-credentials-[a-f0-9]{16}\.json\z/D', $credentialFile) !== 1)
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
$leaseOpened = false;
$recoveryCompleted = false;
$lockHeld = false;
$state = null;
$db = null;
$admin = null;
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
    $state = v4fqaPreflight($db, $prefixeTable, $recoveryMode ? ['ACTIVE', 'FROZEN'] : ['FROZEN']);
    $admin = ClassIdentityAdminService::fromPiwigo();

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, static function (): void {
                throw new V4FqaLeaseFailure('lease_signal_received');
            });
        }
    }

    if ($recoveryMode) {
        v4fqaCloseAccess($db, $prefixeTable, $admin, $state, false);
        $recoveryCompleted = true;
    } else {

    $credentials = v4fqaRotate(
        $state['accounts'],
        $state['identity_id'],
        $state['admin_user_id'],
        $state['admin_principal_id'],
        'LEASE_OPEN',
    );
    v4fqaWriteCredentialFile($credentialFile, $run, $credentials);
    $credentials = [];

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
    if (is_array($state) && $admin instanceof ClassIdentityAdminService && !$recoveryCompleted) {
        try {
            v4fqaCloseAccess($db, $prefixeTable, $admin, $state, $leaseOpened);
        } catch (Throwable) {
            $exitCode = 2;
        }
    }
    try {
        v4fqaRemoveCredentialFile($credentialFile);
    } catch (Throwable) {
        $exitCode = 2;
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
