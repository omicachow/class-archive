<?php

declare(strict_types=1);

const PIWIGO_ROOT = '/var/www/html/piwigo';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

$password = getenv('CLASS_ARCHIVE_FIXTURE_PASSWORD');
if (!is_string($password) || strlen($password) < 24) {
    fail('A transient 24+ character fixture password is required.');
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fail('Refusing to provision fixtures as root.');
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
        $errors = [];
        $userId = register_user(
            $username,
            $password,
            $username . '@class-archive.local',
            false,
            $errors,
            false
        );
        if ($userId === false) {
            fail("Cannot create {$username}: " . implode('; ', $errors));
        }
    }

    $hash = $conf['password_hash']($password);
    single_update(USERS_TABLE, ['password' => $hash], ['id' => (int) $userId]);
    single_update(USER_INFOS_TABLE, ['status' => 'normal'], ['user_id' => (int) $userId]);
    pwg_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . (int) $userId);
    single_insert(
        USER_GROUP_TABLE,
        ['user_id' => (int) $userId, 'group_id' => (int) $groups[$groupName]['id']]
    );

    $row = query2array('SELECT password FROM ' . USERS_TABLE . ' WHERE id = ' . (int) $userId);
    if (
        count($row) !== 1
        || !str_starts_with((string) $row[0]['password'], '$P$')
        || hash_equals((string) $row[0]['password'], $password)
    ) {
        fail("Fixture password storage check failed for {$username}.");
    }
}

invalidate_user_cache();
fwrite(STDOUT, "ACCESS_FIXTURES_READY\n");
