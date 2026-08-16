<?php

declare(strict_types=1);

/**
 * Test-only SYSTEM_ADMIN HTTP-session lease.
 *
 * A caller first creates an ordinary guest session through the real HTTP
 * endpoint, then sends only that pre-authentication cookie over STDIN. This
 * CLI helper upgrades exactly the matching, fresh guest session after proving
 * that the target is an active independent SYSTEM_ADMIN principal. The cookie
 * and database session id are never written to stdout or process arguments.
 *
 * The owner-mode lease permits exact revocation even if the caller has already
 * discarded its CookieContainer. This helper is deliberately unavailable to
 * PHP-FPM and requires a per-exec environment gate that is not present in the
 * Compose service definition.
 */

const CA_TEST_PIWIGO_ROOT = '/var/www/html/piwigo';
const CA_TEST_LEASE_DIRECTORY = '/tmp/class-archive-system-admin-sessions';
const CA_TEST_MAX_GUEST_AGE_SECONDS = 60;
const CA_TEST_STALE_LEASE_SECONDS = 7200;
const CA_TEST_FAULT_AFTER_DB_COMMIT = 'after_db_commit_before_json';

function caSessionFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function caSessionJson(array $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function caSessionRequestedFault(): ?string
{
    $fault = getenv('CLASS_ARCHIVE_SYNTHETIC_SESSION_FAULT');
    if ($fault === false || $fault === '') {
        return null;
    }
    if ($fault !== CA_TEST_FAULT_AFTER_DB_COMMIT) {
        caSessionFail('Unknown synthetic-session fault injection.');
    }

    return $fault;
}

function caSessionBoot(): void
{
    if (PHP_SAPI !== 'cli') {
        caSessionFail('CLI required.');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        caSessionFail('Refusing to run the test fixture as root.');
    }
    if (getenv('CLASS_ARCHIVE_ALLOW_SYNTHETIC_ADMIN_SESSION') !== '1') {
        caSessionFail('Explicit per-exec synthetic-session gate required.');
    }
    if (!is_file('/workspace/tests/support/system-admin-session.php')) {
        caSessionFail('The fixture must run from the read-only workspace test path.');
    }

    chdir(CA_TEST_PIWIGO_ROOT) || caSessionFail('Cannot enter the Piwigo root.');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function caSessionEnsureLeaseDirectory(): void
{
    if (!is_dir(CA_TEST_LEASE_DIRECTORY)) {
        if (!mkdir(CA_TEST_LEASE_DIRECTORY, 0700, false)) {
            caSessionFail('Could not create the session-lease directory.');
        }
    }

    $stat = lstat(CA_TEST_LEASE_DIRECTORY);
    $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($stat === false
        || (($stat['mode'] ?? 0) & 0170000) !== 0040000
        || (($stat['mode'] ?? 0) & 0777) !== 0700
        || ($expectedUid !== null && (int) ($stat['uid'] ?? -1) !== $expectedUid)
    ) {
        caSessionFail('The session-lease directory is not an owner-mode directory.');
    }
}

function caSessionLeasePath(string $handle): string
{
    if (preg_match('/\A[a-f0-9]{24}\z/D', $handle) !== 1) {
        caSessionFail('Lease handle must be exactly 24 lowercase hexadecimal characters.');
    }

    return CA_TEST_LEASE_DIRECTORY . '/' . $handle . '.lease';
}

function caSessionReadCookie(): string
{
    $input = stream_get_contents(STDIN, 513);
    if (!is_string($input) || $input === '' || strlen($input) > 512) {
        caSessionFail('Exactly one bounded guest cookie is required over STDIN.');
    }
    // Windows PowerShell 5.1's native-process stdin writer unconditionally
    // emits one UTF-8 BOM. Treat that transport marker as framing, then keep
    // the cookie itself under the same strict ASCII grammar.
    if (str_starts_with($input, "\xEF\xBB\xBF")) {
        $input = substr($input, 3);
    }
    $cookie = rtrim($input, "\r\n");
    if ($cookie === ''
        || str_contains($cookie, "\r")
        || str_contains($cookie, "\n")
        || preg_match('/\A[A-Za-z0-9,-]{16,128}\z/D', $cookie) !== 1
    ) {
        caSessionFail('The guest cookie format is invalid.');
    }

    return $cookie;
}

/** @return array{id:string,data:string,age_seconds:int} */
function caSessionFindFreshGuestRow(string $cookie): array
{
    $escaped = pwg_db_real_escape_string($cookie);
    $length = strlen($cookie);
    $rows = query2array(
        'SELECT `id`, `data`, TIMESTAMPDIFF(SECOND, `expiration`, NOW()) AS `age_seconds` '
        . 'FROM ' . SESSIONS_TABLE . ' WHERE RIGHT(`id`, ' . $length . ") = '{$escaped}' LIMIT 3",
    );
    if (count($rows) !== 1) {
        caSessionFail('The guest session row is missing or ambiguous.');
    }

    $row = $rows[0];
    $databaseId = (string) ($row['id'] ?? '');
    $data = (string) ($row['data'] ?? '');
    $age = (int) ($row['age_seconds'] ?? PHP_INT_MAX);
    if (!str_ends_with($databaseId, $cookie)
        || !in_array(strlen($databaseId), [$length, $length + 4], true)
        || $age < 0
        || $age > CA_TEST_MAX_GUEST_AGE_SECONDS
        || preg_match('/(?:^|;)pwg_uid\|/D', $data) === 1
    ) {
        caSessionFail('Only an exact fresh unauthenticated guest session may be upgraded.');
    }

    return ['id' => $databaseId, 'data' => $data, 'age_seconds' => $age];
}

/** @return array{id:int,piwigo_user_id:int,username:string,auth_epoch:int} */
function caSessionFindSystemAdmin(string $username): array
{
    if (preg_match('/\A[A-Za-z0-9_.@+-]{1,100}\z/D', $username) !== 1) {
        caSessionFail('SYSTEM_ADMIN username format is invalid.');
    }
    if (!class_exists(\ClassIdentity\Repository::class)
        || !class_exists(\ClassIdentity\Access::class)
        || !\ClassIdentity\Access::isEnforcementEnabled()
    ) {
        caSessionFail('Active fail-closed ClassIdentity runtime required.');
    }

    global $conf;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $principal = '`' . $repository->table('principal') . '`';
    $idField = (string) ($conf['user_fields']['id'] ?? 'id');
    $usernameField = (string) ($conf['user_fields']['username'] ?? 'username');
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $idField) !== 1
        || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $usernameField) !== 1
    ) {
        caSessionFail('Unsupported Core user-field mapping.');
    }

    $rows = query2array(
        "SELECT p.id, p.piwigo_user_id, p.auth_epoch, u.`{$usernameField}` AS username, ui.status "
        . "FROM {$principal} p "
        . 'JOIN ' . USERS_TABLE . " u ON u.`{$idField}` = p.piwigo_user_id "
        . 'JOIN ' . USER_INFOS_TABLE . ' ui ON ui.user_id = p.piwigo_user_id '
        . "WHERE BINARY u.`{$usernameField}` = '" . pwg_db_real_escape_string($username) . "' "
        . "AND p.principal_type = 'SYSTEM_ACCOUNT' AND p.system_role = 'SYSTEM_ADMIN' "
        . "AND p.account_id IS NULL AND p.state = 'ACTIVE' LIMIT 2",
    );
    if (count($rows) !== 1
        || (int) ($rows[0]['id'] ?? 0) <= 0
        || (int) ($rows[0]['piwigo_user_id'] ?? 0) <= 0
        || !in_array((string) ($rows[0]['status'] ?? ''), ['admin', 'webmaster'], true)
    ) {
        caSessionFail('An active independent SYSTEM_ADMIN principal could not be proven.');
    }

    return [
        'id' => (int) $rows[0]['id'],
        'piwigo_user_id' => (int) $rows[0]['piwigo_user_id'],
        'username' => (string) $rows[0]['username'],
        'auth_epoch' => (int) ($rows[0]['auth_epoch'] ?? 0),
    ];
}

function caSessionEncodeAdminData(string $guestData, array $admin): string
{
    $fixtureSession = $_SESSION ?? [];
    $_SESSION = [];
    try {
        if ($guestData !== '' && !session_decode($guestData)) {
            caSessionFail('The exact guest session data could not be decoded.');
        }
        if (isset($_SESSION['pwg_uid'])) {
            caSessionFail('An authenticated session cannot be upgraded by the fixture.');
        }

        // Preserve benign Core fields such as the CSRF token, but replace the
        // complete authorization snapshot as one indivisible state change.
        unset(
            $_SESSION['pwg_uid'],
            $_SESSION['class_identity_principal_id'],
            $_SESSION['class_identity_principal_auth_epoch'],
            $_SESSION['class_identity_issued_at'],
        );
        $_SESSION['pwg_uid'] = (int) $admin['piwigo_user_id'];
        $_SESSION['class_identity_principal_id'] = (int) $admin['id'];
        $_SESSION['class_identity_principal_auth_epoch'] = (int) $admin['auth_epoch'];
        $_SESSION['class_identity_issued_at'] = time();
        $encoded = session_encode();
        if (!is_string($encoded) || $encoded === '') {
            caSessionFail('The SYSTEM_ADMIN session snapshot could not be encoded.');
        }
        return $encoded;
    } finally {
        $_SESSION = $fixtureSession;
    }
}

function caSessionWriteLease(string $path, string $databaseId, int $adminUserId): void
{
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        caSessionFail('The session-lease handle already exists.');
    }
    try {
        if (!chmod($path, 0600)) {
            caSessionFail('Could not restrict the session lease to its owner.');
        }
        $payload = json_encode([
            // Same-UID PHP-FPM can read this owner-mode file, so persist only
            // a one-way row locator rather than the bearer-capable session id.
            'database_session_hash' => hash('sha256', $databaseId),
            'admin_user_id' => $adminUserId,
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
            caSessionFail('Could not durably write the session lease.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            caSessionFail('Could not sync the session lease.');
        }
    } finally {
        fclose($handle);
    }

    $stat = lstat($path);
    if ($stat === false
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
    ) {
        @unlink($path);
        caSessionFail('The session lease is not an owner-mode regular file.');
    }
}

/** @return array{database_session_hash:string,admin_user_id:int,created_at:int} */
function caSessionReadLease(string $path): array
{
    $stat = lstat($path);
    if ($stat === false
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
    ) {
        caSessionFail('The exact owner-mode session lease is unavailable.');
    }
    $raw = file_get_contents($path);
    $lease = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($lease)
        || !is_string($lease['database_session_hash'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $lease['database_session_hash']) !== 1
        || (int) ($lease['admin_user_id'] ?? 0) <= 0
        || (int) ($lease['created_at'] ?? 0) <= 0
    ) {
        caSessionFail('The session lease content is invalid.');
    }

    return [
        'database_session_hash' => $lease['database_session_hash'],
        'admin_user_id' => (int) $lease['admin_user_id'],
        'created_at' => (int) $lease['created_at'],
    ];
}

function caSessionDeleteDatabaseRowByHash(string $databaseHash): void
{
    $matches = [];
    foreach (query2array('SELECT id FROM ' . SESSIONS_TABLE) as $row) {
        $candidate = (string) ($row['id'] ?? '');
        if ($candidate !== '' && hash_equals($databaseHash, hash('sha256', $candidate))) {
            $matches[] = $candidate;
        }
    }
    if (count($matches) > 1) {
        caSessionFail('The hashed session lease resolved ambiguously.');
    }
    if ($matches !== []) {
        pwg_query(
            'DELETE FROM ' . SESSIONS_TABLE . " WHERE id = '"
            . pwg_db_real_escape_string($matches[0]) . "'",
        );
    }
}

function caSessionSweepStaleLeases(): void
{
    $now = time();
    $paths = glob(CA_TEST_LEASE_DIRECTORY . '/*.lease', GLOB_NOSORT);
    if ($paths === false) {
        caSessionFail('Could not enumerate stale session leases.');
    }
    foreach ($paths as $path) {
        $name = basename($path);
        if (preg_match('/\A[a-f0-9]{24}\.lease\z/D', $name) !== 1) {
            continue;
        }
        $lease = caSessionReadLease($path);
        if (($now - $lease['created_at']) <= CA_TEST_STALE_LEASE_SECONDS) {
            continue;
        }
        caSessionDeleteDatabaseRowByHash($lease['database_session_hash']);
        if (!unlink($path)) {
            caSessionFail('Could not remove a stale session lease.');
        }
    }
}

function caSessionMint(string $handle, string $username): never
{
    caSessionSweepStaleLeases();
    $requestedFault = caSessionRequestedFault();
    $cookie = caSessionReadCookie();
    $row = caSessionFindFreshGuestRow($cookie);
    $admin = caSessionFindSystemAdmin($username);
    $path = caSessionLeasePath($handle);
    caSessionWriteLease($path, $row['id'], $admin['piwigo_user_id']);

    $newData = caSessionEncodeAdminData($row['data'], $admin);
    global $mysqli;
    if (!($mysqli instanceof mysqli)) {
        caSessionFail('The Piwigo database connection is unavailable.');
    }
    $statement = $mysqli->prepare(
        'UPDATE ' . SESSIONS_TABLE . ' SET `data` = ?, `expiration` = NOW() '
        . 'WHERE `id` = ? AND `data` = ?',
    );
    if ($statement === false) {
        caSessionFail('Could not prepare the exact session transition.');
    }
    try {
        if (!$statement->bind_param('sss', $newData, $row['id'], $row['data'])) {
            throw new RuntimeException('Could not bind the exact session transition.');
        }
        if (!$statement->execute() || $statement->affected_rows !== 1) {
            throw new RuntimeException('The exact guest session transition did not commit.');
        }
    } finally {
        $statement->close();
    }

    // The owner-mode lease deliberately remains present across every failure
    // after it is created. The caller already knows the random handle and can
    // therefore revoke the exact row even when native-process completion is
    // ambiguous after the database accepted the UPDATE.
    if ($requestedFault === CA_TEST_FAULT_AFTER_DB_COMMIT) {
        fwrite(STDERR, "SYNTHETIC_SESSION_FAULT=" . CA_TEST_FAULT_AFTER_DB_COMMIT . "\n");
        throw new RuntimeException('Injected failure after the session transition committed.');
    }

    unset($cookie, $newData, $requestedFault);
    caSessionJson([
        'ok' => true,
        'handle' => $handle,
        'admin_user_id' => $admin['piwigo_user_id'],
    ]);
}

function caSessionRevoke(string $handle): never
{
    $path = caSessionLeasePath($handle);
    if (!file_exists($path) && !is_link($path)) {
        caSessionJson(['ok' => true, 'handle' => $handle, 'revoked' => true, 'absent' => true]);
    }
    $lease = caSessionReadLease($path);
    caSessionDeleteDatabaseRowByHash($lease['database_session_hash']);
    if (!unlink($path)) {
        caSessionFail('The session row was revoked but its lease could not be removed.');
    }
    caSessionJson(['ok' => true, 'handle' => $handle, 'revoked' => true, 'absent' => false]);
}

function caSessionStatus(string $username): never
{
    $admin = caSessionFindSystemAdmin($username);
    $paths = glob(CA_TEST_LEASE_DIRECTORY . '/*.lease', GLOB_NOSORT);
    if ($paths === false) {
        caSessionFail('Could not enumerate session leases.');
    }
    foreach ($paths as $path) {
        if (preg_match('/\A[a-f0-9]{24}\.lease\z/D', basename($path)) !== 1) {
            caSessionFail('An unexpected session-lease path exists.');
        }
        caSessionReadLease($path);
    }

    $needle = '%pwg_uid|i:' . $admin['piwigo_user_id'] . ';%';
    $rows = query2array(
        'SELECT COUNT(*) AS total FROM ' . SESSIONS_TABLE
        . " WHERE `data` LIKE '" . pwg_db_real_escape_string($needle) . "'",
    );
    if (count($rows) !== 1) {
        caSessionFail('Could not count SYSTEM_ADMIN-bound sessions.');
    }

    caSessionJson([
        'ok' => true,
        'lease_count' => count($paths),
        'admin_session_count' => (int) ($rows[0]['total'] ?? -1),
    ]);
}

$command = $_SERVER['argv'][1] ?? '';
$handle = $_SERVER['argv'][2] ?? '';
$username = $_SERVER['argv'][3] ?? '';
caSessionBoot();
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
// Prevent this CLI bootstrap's own guest session from being persisted.
if (!defined('PWG_API_KEY_REQUEST')) {
    define('PWG_API_KEY_REQUEST', true);
}
caSessionEnsureLeaseDirectory();

try {
    match ($command) {
        'mint' => caSessionMint((string) $handle, (string) $username),
        'revoke' => caSessionRevoke((string) $handle),
        'status' => caSessionStatus((string) $username),
        default => caSessionFail('Unknown session-lease command.'),
    };
} catch (Throwable $error) {
    // Never reflect database/session values or exception text.
    caSessionFail('Session-lease command failed [' . get_class($error) . '].');
}
