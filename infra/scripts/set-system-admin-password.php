<?php

declare(strict_types=1);

const CA_PASSWORD_PIWIGO_ROOT = '/var/www/html/piwigo';

function caPasswordFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function caAssertPasswordPersisted(int $userId, string $password): void
{
    global $conf;
    $rows = query2array('SELECT password FROM ' . USERS_TABLE . ' WHERE id = ' . $userId);
    if (count($rows) !== 1
        || !is_string($rows[0]['password'] ?? null)
        || !isset($conf['password_verify'])
        || !is_callable($conf['password_verify'])
        || !$conf['password_verify']($password, (string) $rows[0]['password'], $userId)
    ) {
        caPasswordFail('The persisted SYSTEM_ADMIN password hash could not be verified.');
    }
}

if (PHP_SAPI !== 'cli'
    || !function_exists('posix_geteuid')
    || !function_exists('posix_getpwuid')
    || posix_geteuid() === 0
    || (posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'nginx'
) {
    caPasswordFail('Run the password command as the unprivileged nginx CLI user.');
}

$username = $_SERVER['argv'][1] ?? '';
if (!is_string($username) || preg_match('/\A[A-Za-z0-9_.@+-]{1,100}\z/D', $username) !== 1) {
    caPasswordFail('A bounded SYSTEM_ADMIN username is required.');
}
$input = stream_get_contents(STDIN, 1025);
if (!is_string($input) || $input === '' || strlen($input) > 1024) {
    caPasswordFail('Exactly one bounded password is required over STDIN.');
}
$password = rtrim($input, "\r\n");
unset($input);
if ($password === ''
    || strlen($password) < 16
    || strlen($password) > 256
    || preg_match('//u', $password) !== 1
    || preg_match('/[\x00\r\n]/', $password) === 1
) {
    unset($password);
    caPasswordFail('Password must be 16-256 UTF-8 bytes with no line separator.');
}

chdir(CA_PASSWORD_PIWIGO_ROOT) || caPasswordFail('Cannot enter Piwigo root.');
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
$classIdentityAvailable = class_exists(\ClassIdentity\Repository::class)
    && class_exists(\ClassIdentity\CoreAdapter::class)
    && class_exists(\ClassIdentity\Audit::class)
    && class_exists(\ClassIdentity\Access::class);

if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    unset($password);
    caPasswordFail('The Core table prefix is unavailable.');
}
$identityTables = [
    $prefixeTable . 'class_identity_migration',
    $prefixeTable . 'class_identity_principal',
];
$identityTablePrefix = $prefixeTable . 'class_identity_';
$identityTableRows = array_values(array_filter(
    query2array('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
    static fn(array $row): bool => str_starts_with((string) ($row['TABLE_NAME'] ?? ''), $identityTablePrefix),
));

// Strict pre-ClassIdentity recovery: both sentinel tables must be absent. A
// disabled/damaged/partial established ClassIdentity stack never falls back to
// Core webmaster status and must be repaired behind its normal principal gate.
if ($identityTableRows === []) {
    $webmasterId = (int) ($conf['webmaster_id'] ?? 0);
    $resolvedId = (int) get_userid($username);
    $statusRows = query2array(
        'SELECT status FROM ' . USER_INFOS_TABLE . ' WHERE user_id = ' . $webmasterId,
    );
    if ($webmasterId <= 0
        || $resolvedId !== $webmasterId
        || $webmasterId === (int) ($conf['guest_id'] ?? 0)
        || count($statusRows) !== 1
        || ($statusRows[0]['status'] ?? null) !== 'webmaster'
        || !isset($conf['password_hash'])
        || !is_callable($conf['password_hash'])
    ) {
        unset($password);
        caPasswordFail('The exact pre-ClassIdentity Core webmaster could not be proven.');
    }
    $hash = $conf['password_hash']($password);
    if (!is_string($hash) || $hash === '') {
        unset($password);
        caPasswordFail('Core password hashing failed.');
    }
    single_update(USERS_TABLE, ['password' => $hash], ['id' => $webmasterId]);
    unset($hash);
    caAssertPasswordPersisted($webmasterId, $password);
    unset($password);
    delete_user_sessions($webmasterId);
    if (function_exists('deactivate_user_auth_keys')) {
        deactivate_user_auth_keys($webmasterId);
    }
    if (defined('USER_AUTH_KEYS_TABLE')) {
        pwg_query(
            'UPDATE ' . USER_AUTH_KEYS_TABLE . ' SET revoked_on = NOW() '
            . 'WHERE user_id = ' . $webmasterId . ' AND revoked_on IS NULL',
        );
    }
    $sessionRows = query2array(
        'SELECT COUNT(*) AS count FROM ' . SESSIONS_TABLE
        . " WHERE data LIKE '%pwg_uid|i:{$webmasterId};%'",
    );
    $activeKeyRows = defined('USER_AUTH_KEYS_TABLE')
        ? query2array(
            'SELECT COUNT(*) AS count FROM ' . USER_AUTH_KEYS_TABLE
            . ' WHERE user_id = ' . $webmasterId . ' AND revoked_on IS NULL',
        )
        : [['count' => 0]];
    if (count($sessionRows) !== 1
        || (int) ($sessionRows[0]['count'] ?? -1) !== 0
        || count($activeKeyRows) !== 1
        || (int) ($activeKeyRows[0]['count'] ?? -1) !== 0
    ) {
        caPasswordFail('Core credential revocation could not be proven.');
    }
    fwrite(STDOUT, "SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked\n");
    exit(0);
}
$identityTableNames = array_column($identityTableRows, 'TABLE_NAME');
if (!in_array($identityTables[0], $identityTableNames, true)
    || !in_array($identityTables[1], $identityTableNames, true)
    || !$classIdentityAvailable
    || !\ClassIdentity\Access::isEnforcementEnabled()
) {
    unset($password);
    caPasswordFail('Active fail-closed ClassIdentity runtime required.');
}

try {
    global $conf;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $principalTable = '`' . $repository->table('principal') . '`';
    $idField = (string) ($conf['user_fields']['id'] ?? 'id');
    $usernameField = (string) ($conf['user_fields']['username'] ?? 'username');
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $idField) !== 1
        || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $usernameField) !== 1
    ) {
        caPasswordFail('Unsupported Core user-field mapping.');
    }
    $rows = query2array(
        "SELECT p.id AS principal_id, p.piwigo_user_id, ui.status FROM {$principalTable} p "
        . 'JOIN ' . USERS_TABLE . " u ON u.`{$idField}` = p.piwigo_user_id "
        . 'JOIN ' . USER_INFOS_TABLE . ' ui ON ui.user_id = p.piwigo_user_id '
        . "WHERE BINARY u.`{$usernameField}` = '" . pwg_db_real_escape_string($username) . "' "
        . "AND p.principal_type = 'SYSTEM_ACCOUNT' AND p.system_role = 'SYSTEM_ADMIN' "
        . "AND p.account_id IS NULL AND p.state = 'ACTIVE' LIMIT 2",
    );
    if (count($rows) !== 1
        || (int) ($rows[0]['principal_id'] ?? 0) <= 0
        || (int) ($rows[0]['piwigo_user_id'] ?? 0) <= 0
        || !in_array((string) ($rows[0]['status'] ?? ''), ['admin', 'webmaster'], true)
    ) {
        caPasswordFail('The active independent SYSTEM_ADMIN target could not be proven.');
    }

    $principalId = (int) $rows[0]['principal_id'];
    $userId = (int) $rows[0]['piwigo_user_id'];
    $baseEvent = [
        'actor_principal_id' => $principalId,
        'actor_user_id' => $userId,
        'actor_kind' => 'SYSTEM_ADMIN',
        'target_type' => 'PRINCIPAL',
        'target_id' => (string) $principalId,
        'target_principal_id' => $principalId,
        'reason' => 'Secure local CLI password reset',
        'result' => 'SUCCESS',
    ];
    $newEpoch = $repository->transaction(
        static function (\ClassIdentity\Repository $transaction) use ($principalId, $userId, $baseEvent): int {
            $table = '`' . $transaction->table('principal') . '`';
            $locked = $transaction->fetchOne(
                "SELECT id, auth_epoch FROM {$table} WHERE id = ? AND piwigo_user_id = ? "
                . "AND principal_type = 'SYSTEM_ACCOUNT' AND system_role = 'SYSTEM_ADMIN' "
                . "AND account_id IS NULL AND state = 'ACTIVE' FOR UPDATE",
                [$principalId, $userId],
            );
            if ($locked === null) {
                throw new RuntimeException('system_admin_principal_changed');
            }
            $previousEpoch = (int) ($locked['auth_epoch'] ?? -1);
            if ($previousEpoch < 0
                || $transaction->execute(
                    "UPDATE {$table} SET auth_epoch = auth_epoch + 1 WHERE id = ? AND auth_epoch = ?",
                    [$principalId, $previousEpoch],
                ) !== 1
            ) {
                throw new RuntimeException('system_admin_epoch_update_failed');
            }
            $verified = $transaction->fetchOne(
                "SELECT auth_epoch FROM {$table} WHERE id = ? FOR UPDATE",
                [$principalId],
            );
            $nextEpoch = (int) ($verified['auth_epoch'] ?? -1);
            if ($nextEpoch !== $previousEpoch + 1) {
                throw new RuntimeException('system_admin_epoch_verification_failed');
            }
            (new \ClassIdentity\Audit($transaction))->append($baseEvent + [
                'action' => 'PASSWORD_RESET_INITIATED',
                'old_value' => ['auth_epoch' => $previousEpoch],
                'new_value' => ['auth_epoch' => $nextEpoch, 'state' => 'REQUESTED'],
            ]);
            return $nextEpoch;
        },
    );
    \ClassIdentity\CoreAdapter::setPassword($userId, $password);
    caAssertPasswordPersisted($userId, $password);
    unset($password);
    \ClassIdentity\Audit::fromPiwigo()->append($baseEvent + [
        'action' => 'PRINCIPAL_SECURITY_CHANGE',
        'new_value' => ['auth_epoch' => $newEpoch, 'state' => 'PASSWORD_ROTATED'],
    ]);

    fwrite(STDOUT, "SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked\n");
} catch (Throwable $error) {
    unset($password);
    caPasswordFail('Password update failed [' . get_class($error) . '].');
}
