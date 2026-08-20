<?php

declare(strict_types=1);

const PIWIGO_ROOT = '/var/www/html/piwigo';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fail('Refusing to provision fixtures as root.');
}

/**
 * Accept the legacy per-exec environment value for existing gates, or a
 * short-lived private file for callers that must keep the value out of the
 * Docker exec argument list. The file path is deliberately narrow and is
 * deleted immediately after an exact ownership/mode read.
 */
function transientFixturePassword(): string
{
    $environment = getenv('CLASS_ARCHIVE_FIXTURE_PASSWORD');
    $file = getenv('CLASS_ARCHIVE_FIXTURE_PASSWORD_FILE');
    $hasEnvironment = is_string($environment) && $environment !== '';
    $hasFile = is_string($file) && $file !== '';
    if ($hasEnvironment === $hasFile) {
        fail('Exactly one transient fixture password source is required.');
    }
    if ($hasEnvironment) {
        return $environment;
    }
    if (
        !is_string($file)
        || preg_match('/\A\/tmp\/class-archive-fixture-password-[a-f0-9]{16}\.txt\z/D', $file) !== 1
        || is_link($file)
    ) {
        fail('Fixture password file path is invalid.');
    }
    clearstatcache(true, $file);
    $stat = @lstat($file);
    if (
        !is_array($stat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || (int) ($stat['size'] ?? 0) < 24
        || (int) ($stat['size'] ?? 0) > 192
    ) {
        fail('Fixture password file is invalid.');
    }
    $password = file_get_contents($file);
    if (!is_string($password) || !unlink($file) || file_exists($file) || is_link($file)) {
        fail('Fixture password file cannot be consumed safely.');
    }
    return $password;
}

$password = transientFixturePassword();
if (strlen($password) < 24 || strlen($password) > 190 || str_contains($password, "\0")) {
    fail('A transient 24+ character fixture password is required.');
}

chdir(PIWIGO_ROOT) || fail('Cannot enter Piwigo root.');
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

$groups = query2array(
    "SELECT id, name FROM " . GROUPS_TABLE . " WHERE name IN ('CLASSMATE','TEACHER','FAMILY','ANONYMOUS')",
    'name'
);
foreach (['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS'] as $groupName) {
    if (!isset($groups[$groupName])) {
        fail("Missing baseline group {$groupName}.");
    }
}

$fixtures = [
    'fixture-classmate' => 'CLASSMATE',
    'fixture-teacher' => 'TEACHER',
    'fixture-family' => 'FAMILY',
    'fixture-anonymous' => 'ANONYMOUS',
];

foreach ($fixtures as $username => $groupName) {
    $userId = get_userid($username);
    if (!$userId) {
        fail("Missing bound {$username}; run the explicit synthetic ClassIdentity bootstrap first.");
    }
    if (!class_exists(\ClassIdentity\Access::class)
        || !class_exists(\ClassIdentity\CoreAdapter::class)
        || !\ClassIdentity\Access::isEnforcementEnabled()
    ) {
        fail('Active fail-closed ClassIdentity runtime required.');
    }
    $context = \ClassIdentity\Access::resolveAuthorizationContext((int) $userId);
    if (($context['role'] ?? null) !== $groupName) {
        fail("Fixture principal {$username} is not bound to {$groupName}.");
    }

    \ClassIdentity\CoreAdapter::setPassword((int) $userId, $password);
    single_update(USER_INFOS_TABLE, ['status' => 'normal'], ['user_id' => (int) $userId]);
    \ClassIdentity\CoreAdapter::reconcileManagedGroups((int) $userId, $groupName);

    $row = query2array('SELECT password FROM ' . USERS_TABLE . ' WHERE id = ' . (int) $userId);
    if (
        count($row) !== 1
        || !str_starts_with((string) $row[0]['password'], '$P$')
        || hash_equals((string) $row[0]['password'], $password)
    ) {
        fail("Fixture password storage check failed for {$username}.");
    }
}

unset($password);

invalidate_user_cache();
fwrite(STDOUT, "ACCESS_FIXTURES_READY\n");
