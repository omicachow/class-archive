<?php

declare(strict_types=1);

function stageFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    stageFail('Unprivileged PHP CLI required.');
}
$username = $_SERVER['argv'][1] ?? '';
if (!is_string($username) || preg_match('/\A[A-Za-z0-9_.@+-]{1,100}\z/D', $username) !== 1) {
    stageFail('A bounded webmaster username is required.');
}
chdir('/var/www/html/piwigo') || stageFail('Cannot enter Piwigo root.');
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
if (!defined('PWG_API_KEY_REQUEST')) {
    define('PWG_API_KEY_REQUEST', true);
}

global $conf, $prefixeTable;
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    stageFail('The Core table prefix is unavailable.');
}
$webmasterId = (int) ($conf['webmaster_id'] ?? 0);
$status = query2array('SELECT status FROM ' . USER_INFOS_TABLE . ' WHERE user_id = ' . $webmasterId);
if ($webmasterId <= 0
    || (int) get_userid($username) !== $webmasterId
    || $webmasterId === (int) ($conf['guest_id'] ?? 0)
    || count($status) !== 1
    || ($status[0]['status'] ?? null) !== 'webmaster'
) {
    stageFail('The exact Core webmaster could not be proven.');
}
$sentinels = [
    $prefixeTable . 'class_identity_migration',
    $prefixeTable . 'class_identity_principal',
];
$identityPrefix = $prefixeTable . 'class_identity_';
$tables = array_values(array_filter(
    query2array('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
    static fn(array $row): bool => str_starts_with((string) ($row['TABLE_NAME'] ?? ''), $identityPrefix),
));
if ($tables === []) {
    fwrite(STDOUT, "SYSTEM_ADMIN_PASSWORD_STAGE=PRE_CLASS_IDENTITY\n");
    exit(0);
}
$tableNames = array_column($tables, 'TABLE_NAME');
if (!in_array($sentinels[0], $tableNames, true)
    || !in_array($sentinels[1], $tableNames, true)
    || !class_exists(\ClassIdentity\Repository::class)
    || !class_exists(\ClassIdentity\Access::class)
    || !\ClassIdentity\Access::isEnforcementEnabled()
) {
    stageFail('ClassIdentity password stage is partial or unavailable.');
}
$repository = \ClassIdentity\Repository::fromPiwigo();
$rows = $repository->fetchAll(
    "SELECT id FROM `{$repository->table('principal')}` WHERE piwigo_user_id = ? "
    . "AND principal_type = 'SYSTEM_ACCOUNT' AND system_role = 'SYSTEM_ADMIN' "
    . "AND account_id IS NULL AND state = 'ACTIVE'",
    [$webmasterId],
);
if (count($rows) !== 1) {
    stageFail('The exact active SYSTEM_ADMIN principal could not be proven.');
}
fwrite(STDOUT, "SYSTEM_ADMIN_PASSWORD_STAGE=CLASS_IDENTITY\n");
