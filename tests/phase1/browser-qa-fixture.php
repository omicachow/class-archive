<?php

declare(strict_types=1);

/**
 * Narrow CLI-only helper for the Phase 1.5 browser acceptance run.
 *
 * It creates one independent, short-lived SYSTEM_ADMIN. All member-facing
 * identities are deliberately created through the browser and public/admin
 * HTTP forms; this helper never shortcuts Claim, Family Invite, or Seat
 * binding. Cleanup is namespace-bound and refuses ambiguous targets.
 */

const BQA_PIWIGO_ROOT = '/var/www/html/piwigo';

function bqaFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function bqaJson(array $payload): never
{
    fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function bqaRequireRunId(string $runId): void
{
    if (preg_match('/\A[a-f0-9]{12}\z/D', $runId) !== 1) {
        bqaFail('Run id must be exactly 12 lowercase hexadecimal characters.');
    }
}

function bqaUsername(string $runId): string
{
    return 'bqa_admin_' . $runId;
}

function bqaRosterCode(string $runId): string
{
    return 'CIT-C-' . strtoupper($runId);
}

function bqaCommentContextStatePath(string $runId): string
{
    return '/tmp/class-archive-bqa-comment-contexts-' . $runId . '.json';
}

function bqaQuoted(string $value): string
{
    return "'" . pwg_db_real_escape_string($value) . "'";
}

function bqaBoot(): void
{
    if (PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || !function_exists('posix_getpwuid')
        || posix_geteuid() === 0
        || (posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'nginx'
    ) {
        bqaFail('Run this browser-QA fixture only as the unprivileged nginx CLI user.');
    }
    chdir(BQA_PIWIGO_ROOT) || bqaFail('Cannot enter the Piwigo root.');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function bqaReadPassword(): string
{
    $secretFile = getenv('CLASS_ARCHIVE_BROWSER_QA_SECRET_FILE');
    if (is_string($secretFile) && $secretFile !== '') {
        if (!preg_match('/\A\/tmp\/\.browser-qa-secret-[a-f0-9]{12}\.bin\z/D', $secretFile)) {
            bqaFail('Temporary secret path is invalid.');
        }
        clearstatcache(true, $secretFile);
        $metadata = @lstat($secretFile);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if (!is_array($metadata)
            || is_link($secretFile)
            || (($metadata['mode'] ?? 0) & 0170000) !== 0100000
            || (($metadata['mode'] ?? 0) & 0777) !== 0600
            || (int) ($metadata['uid'] ?? -1) !== $uid
            || (int) ($metadata['nlink'] ?? 0) !== 1
        ) {
            bqaFail('Temporary secret file is not trusted.');
        }
        $raw = @file_get_contents($secretFile, false, null, 0, 1025);
        if (!@unlink($secretFile)) {
            bqaFail('Temporary secret file cannot be consumed safely.');
        }
    } else {
        $raw = stream_get_contents(STDIN, 1025);
    }
    if (!is_string($raw) || $raw === '' || strlen($raw) > 1024) {
        bqaFail('One bounded temporary password is required over STDIN.');
    }
    $password = rtrim($raw, "\r\n");
    unset($raw);
    if ($password === '' || strlen($password) < 24 || strlen($password) > 256
        || preg_match('//u', $password) !== 1 || preg_match('/[\x00\r\n]/', $password) === 1
    ) {
        unset($password);
        bqaFail('Temporary password is invalid.');
    }
    return $password;
}

/** @return array{id:int,principal_id:int,username:string,password_sha256:string,password_bytes:int,password_has_utf8_bom:bool} */
function bqaCreateAdmin(string $runId): array
{
    $username = bqaUsername($runId);
    $password = bqaReadPassword();
    $passwordFingerprint = hash('sha256', $password);
    $passwordBytes = strlen($password);
    $passwordHasUtf8Bom = str_starts_with($password, "\xEF\xBB\xBF");
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $principalTable = '`' . $repository->table('principal') . '`';
    $existing = query2array(
        'SELECT u.id, p.id AS principal_id FROM ' . USERS_TABLE . ' u '
        . "LEFT JOIN {$principalTable} p ON p.piwigo_user_id = u.id "
        . 'WHERE BINARY u.username = ' . bqaQuoted($username),
    );
    if ($existing !== []) {
        unset($password);
        bqaFail('Browser-QA SYSTEM_ADMIN namespace is not clean.');
    }

    $userId = 0;
    try {
        $errors = [];
        $created = \ClassIdentity\Access::withProvisioningPermit(
            static function () use ($username, $password, &$errors) {
                return register_user(
                    $username,
                    $password,
                    $username . '@class-archive.invalid',
                    false,
                    $errors,
                    false,
                );
            },
        );
        unset($password);
        if ($created === false || (int) $created <= 0 || $errors !== []) {
            bqaFail('Could not create the short-lived browser-QA Core account.');
        }
        $userId = (int) $created;
        single_update(USER_INFOS_TABLE, ['status' => 'admin'], ['user_id' => $userId]);
        $principalId = $repository->transaction(
            static function (\ClassIdentity\Repository $transaction) use ($userId): int {
                $table = '`' . $transaction->table('principal') . '`';
                if ($transaction->fetchOne("SELECT id FROM {$table} WHERE piwigo_user_id = ? FOR UPDATE", [$userId]) !== null) {
                    throw new RuntimeException('browser_qa_principal_conflict');
                }
                if ($transaction->execute(
                    "INSERT INTO {$table} (`principal_type`,`system_role`,`account_id`,`piwigo_user_id`,`state`,`auth_epoch`) "
                    . "VALUES ('SYSTEM_ACCOUNT','SYSTEM_ADMIN',NULL,?,'ACTIVE',0)",
                    [$userId],
                ) !== 1) {
                    throw new RuntimeException('browser_qa_principal_insert_failed');
                }
                $row = $transaction->fetchOne("SELECT id FROM {$table} WHERE piwigo_user_id = ? FOR UPDATE", [$userId]);
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('browser_qa_principal_verify_failed');
                }
                return $id;
            },
        );
        invalidate_user_cache();
        return [
            'id' => $userId,
            'principal_id' => $principalId,
            'username' => $username,
            'password_sha256' => $passwordFingerprint,
            'password_bytes' => $passwordBytes,
            'password_has_utf8_bom' => $passwordHasUtf8Bom,
        ];
    } catch (Throwable $error) {
        unset($password);
        if ($userId > 0) {
            try {
                $repository->transaction(static function (\ClassIdentity\Repository $transaction) use ($userId): void {
                    $table = '`' . $transaction->table('principal') . '`';
                    $transaction->execute("DELETE FROM {$table} WHERE piwigo_user_id = ? AND principal_type = 'SYSTEM_ACCOUNT' AND system_role = 'SYSTEM_ADMIN' AND account_id IS NULL", [$userId]);
                });
                delete_user($userId);
            } catch (Throwable) {
                // Leave an unambiguous namespace for the explicit cleanup
                // command rather than broadening this failure path.
            }
        }
        bqaFail('Browser-QA SYSTEM_ADMIN provisioning failed [' . get_class($error) . '].');
    }
}

/** @return list<int> */
function bqaAnonymousUserIdsForRun(string $runId): array
{
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $seat = '`' . $repository->table('seat') . '`';
    $account = '`' . $repository->table('account') . '`';
    $principal = '`' . $repository->table('principal') . '`';
    $identity = '`' . $repository->table('identity') . '`';
    $rows = query2array(
        "SELECT p.piwigo_user_id FROM {$principal} p "
        . "JOIN {$account} a ON a.id = p.account_id AND a.current_marker = 1 "
        . "JOIN {$seat} s ON s.id = a.seat_id AND s.seat_type = 'ANONYMOUS' "
        . "JOIN {$identity} i ON i.id = s.identity_id "
        . 'WHERE BINARY i.roster_code = ' . bqaQuoted(bqaRosterCode($runId)),
    );
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['piwigo_user_id'] ?? 0);
        if ($id <= 0) {
            bqaFail('Browser-QA cleanup found an invalid anonymous Core user.');
        }
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function bqaCleanupComments(string $runId): int
{
    global $prefixeTable;
    $ids = bqaAnonymousUserIdsForRun($runId);
    if ($ids === []) {
        return 0;
    }
    $commentTable = $prefixeTable . 'comments';
    $marker = 'BQA-' . strtoupper($runId) . '-';
    $idList = implode(',', array_map('intval', $ids));
    $rows = query2array(
        'SELECT id, author_id, content FROM `' . $commentTable . '` WHERE author_id IN (' . $idList . ') '
        . 'AND content LIKE ' . bqaQuoted($marker . '%'),
    );
    foreach ($rows as $row) {
        if ((int) ($row['author_id'] ?? 0) <= 0 || !str_starts_with((string) ($row['content'] ?? ''), $marker)) {
            bqaFail('Browser-QA cleanup refused an ambiguous comment.');
        }
    }
    if ($rows === []) {
        return 0;
    }
    $rowIds = implode(',', array_map(static fn(array $row): int => (int) $row['id'], $rows));
    pwg_query('DELETE FROM `' . $commentTable . '` WHERE id IN (' . $rowIds . ')');
    $remaining = query2array('SELECT id FROM `' . $commentTable . '` WHERE id IN (' . $rowIds . ')');
    if ($remaining !== []) {
        bqaFail('Browser-QA comment cleanup did not converge.');
    }
    return count($rows);
}

function bqaCleanupAdmin(string $runId): void
{
    $username = bqaUsername($runId);
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $principalTable = '`' . $repository->table('principal') . '`';
    $auditTable = '`' . $repository->table('audit_event') . '`';
    $rows = query2array(
        'SELECT u.id AS user_id,p.id AS principal_id,p.principal_type,p.system_role,p.account_id '
        . 'FROM ' . USERS_TABLE . " u LEFT JOIN {$principalTable} p ON p.piwigo_user_id=u.id "
        . 'WHERE BINARY u.username = ' . bqaQuoted($username),
    );
    if ($rows === []) {
        return;
    }
    if (count($rows) !== 1) {
        bqaFail('Browser-QA SYSTEM_ADMIN cleanup found an ambiguous Core account.');
    }
    $row = $rows[0];
    $userId = (int) ($row['user_id'] ?? 0);
    $principalId = (int) ($row['principal_id'] ?? 0);
    if ($userId <= 0 || $principalId <= 0
        || ($row['principal_type'] ?? null) !== 'SYSTEM_ACCOUNT'
        || ($row['system_role'] ?? null) !== 'SYSTEM_ADMIN'
        || ($row['account_id'] ?? null) !== null
    ) {
        bqaFail('Browser-QA cleanup refused a non-fixture SYSTEM_ADMIN.');
    }
    \ClassIdentity\CoreAdapter::revokeAllCredentials($userId);
    $repository->transaction(static function (\ClassIdentity\Repository $transaction) use ($auditTable, $principalTable, $principalId, $userId): void {
        $transaction->execute(
            "DELETE FROM {$auditTable} WHERE actor_principal_id=? OR target_principal_id=? OR actor_user_id=?",
            [$principalId, $principalId, $userId],
        );
        if ($transaction->execute(
            "DELETE FROM {$principalTable} WHERE id=? AND piwigo_user_id=? AND principal_type='SYSTEM_ACCOUNT' AND system_role='SYSTEM_ADMIN' AND account_id IS NULL",
            [$principalId, $userId],
        ) !== 1) {
            throw new RuntimeException('browser_qa_principal_delete_failed');
        }
    });
    delete_user($userId);
    if (query2array('SELECT id FROM ' . USERS_TABLE . ' WHERE id=' . $userId) !== []
        || query2array("SELECT id FROM {$principalTable} WHERE id=" . $principalId) !== []
    ) {
        bqaFail('Browser-QA SYSTEM_ADMIN cleanup did not converge.');
    }
    invalidate_user_cache();
}

/** @return array{heritage_image_id:int,living_image_id:int} */
function bqaMediaFixture(): array
{
    $heritage = query2array(
        'SELECT i.id FROM ' . IMAGES_TABLE . ' i '
        . 'JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id=i.id '
        . 'JOIN ' . CATEGORIES_TABLE . " c ON c.id=ic.category_id WHERE c.permalink='fixture-heritage-graduation' "
        . 'ORDER BY i.id ASC LIMIT 1',
    );
    $living = query2array(
        'SELECT i.id FROM ' . IMAGES_TABLE . ' i '
        . 'JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id=i.id '
        . 'JOIN ' . CATEGORIES_TABLE . " c ON c.id=ic.category_id WHERE c.permalink='fixture-living-reunion' "
        . 'ORDER BY i.id ASC LIMIT 1',
    );
    if (count($heritage) !== 1 || count($living) !== 1
        || (int) ($heritage[0]['id'] ?? 0) <= 0 || (int) ($living[0]['id'] ?? 0) <= 0
    ) {
        bqaFail('Canonical synthetic browser-QA media is missing or ambiguous.');
    }
    return [
        'heritage_image_id' => (int) $heritage[0]['id'],
        'living_image_id' => (int) $living[0]['id'],
    ];
}

/**
 * Browser acceptance needs two real Piwigo comment contexts. The canonical
 * synthetic albums are intentionally non-commentable by baseline, so this
 * test-only, run-scoped setup records their exact previous state and enables
 * the narrow Core comment switch until the runner's finally block restores
 * it. No product setting, image, account, token or real media is created
 * here permanently.
 *
 * @return array{prepared:bool,contexts:int}
 */
function bqaPrepareCommentContexts(string $runId): array
{
    $statePath = bqaCommentContextStatePath($runId);
    if (file_exists($statePath) || is_link($statePath)) {
        bqaFail('Browser-QA comment context state already exists.');
    }
    $permalinks = ['fixture-heritage-graduation', 'fixture-living-reunion'];
    $quoted = implode(',', array_map(static fn(string $permalink): string => bqaQuoted($permalink), $permalinks));
    $rows = query2array(
        'SELECT id,permalink,commentable FROM ' . CATEGORIES_TABLE
        . ' WHERE permalink IN (' . $quoted . ') ORDER BY permalink ASC',
    );
    if (count($rows) !== count($permalinks)) {
        bqaFail('Browser-QA comment contexts are missing or ambiguous.');
    }
    $contexts = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $permalink = (string) ($row['permalink'] ?? '');
        $commentable = (string) ($row['commentable'] ?? '');
        if ($id <= 0 || !in_array($permalink, $permalinks, true) || !in_array($commentable, ['true', 'false'], true)) {
            bqaFail('Browser-QA comment context state is invalid.');
        }
        $contexts[] = ['id' => $id, 'permalink' => $permalink, 'commentable' => $commentable];
    }
    usort($contexts, static fn(array $left, array $right): int => strcmp($left['permalink'], $right['permalink']));
    $configOverrides = [
        'activate_comments' => true,
        'comments_validation' => false,
        'email_admin_on_comment' => false,
        'email_admin_on_comment_validation' => false,
        // Two distinct anonymous contexts are intentionally exercised in
        // immediate succession; disable only the Core anti-flood timer for
        // this ephemeral local fixture and restore the exact prior value.
        'anti-flood_time' => 0,
    ];
    $configState = [];
    foreach ($configOverrides as $parameter => $_value) {
        $rows = query2array(
            'SELECT value FROM ' . CONFIG_TABLE . ' WHERE param=' . bqaQuoted($parameter) . ' LIMIT 2',
        );
        if (count($rows) > 1) {
            bqaFail('Browser-QA comment configuration is ambiguous.');
        }
        $configState[$parameter] = [
            'exists' => count($rows) === 1,
            'value' => count($rows) === 1 ? (string) ($rows[0]['value'] ?? '') : null,
        ];
    }
    $state = ['run_id' => $runId, 'contexts' => $contexts, 'config_state' => $configState];
    $temporary = $statePath . '.next';
    $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_exists($temporary) || is_link($temporary)
        || file_put_contents($temporary, $encoded, LOCK_EX) === false
        || !chmod($temporary, 0600)
        || !rename($temporary, $statePath)
    ) {
        if (is_file($temporary) && !is_link($temporary)) {
            @unlink($temporary);
        }
        bqaFail('Browser-QA comment context state cannot be created.');
    }
    try {
        foreach ($contexts as $context) {
            single_update(CATEGORIES_TABLE, ['commentable' => 'true'], ['id' => $context['id']]);
        }
        foreach ($configOverrides as $parameter => $value) {
            conf_update_param($parameter, $value, true);
        }
        invalidate_user_cache();
        return ['prepared' => true, 'contexts' => count($contexts)];
    } catch (Throwable $error) {
        foreach ($contexts as $context) {
            single_update(CATEGORIES_TABLE, ['commentable' => $context['commentable']], ['id' => $context['id']]);
        }
        foreach ($configState as $parameter => $saved) {
            if ($saved['exists'] === true) {
                single_update(CONFIG_TABLE, ['value' => (string) $saved['value']], ['param' => $parameter]);
            } else {
                pwg_query('DELETE FROM ' . CONFIG_TABLE . ' WHERE param=' . bqaQuoted($parameter));
            }
        }
        @unlink($statePath);
        throw $error;
    }
}

/** @return array{restored:bool,contexts:int} */
function bqaCleanupCommentContexts(string $runId): array
{
    $statePath = bqaCommentContextStatePath($runId);
    if (!file_exists($statePath)) {
        if (is_link($statePath)) {
            bqaFail('Browser-QA comment context state is a symbolic link.');
        }
        return ['restored' => true, 'contexts' => 0];
    }
    if (is_link($statePath) || !is_file($statePath)) {
        bqaFail('Browser-QA comment context state is untrusted.');
    }
    $raw = file_get_contents($statePath);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || ($state['run_id'] ?? null) !== $runId || !is_array($state['contexts'] ?? null) || count($state['contexts']) !== 2 || !is_array($state['config_state'] ?? null)) {
        bqaFail('Browser-QA comment context rollback state is invalid.');
    }
    $expected = ['fixture-heritage-graduation' => true, 'fixture-living-reunion' => true];
    $contexts = [];
    foreach ($state['contexts'] as $context) {
        if (!is_array($context)) {
            bqaFail('Browser-QA comment context rollback state is invalid.');
        }
        $id = (int) ($context['id'] ?? 0);
        $permalink = (string) ($context['permalink'] ?? '');
        $commentable = (string) ($context['commentable'] ?? '');
        if ($id <= 0 || !isset($expected[$permalink]) || !$expected[$permalink] || !in_array($commentable, ['true', 'false'], true)) {
            bqaFail('Browser-QA comment context rollback state is unsafe.');
        }
        unset($expected[$permalink]);
        $rows = query2array('SELECT id,permalink FROM ' . CATEGORIES_TABLE . ' WHERE id=' . $id . ' LIMIT 2');
        if (count($rows) !== 1 || (string) ($rows[0]['permalink'] ?? '') !== $permalink) {
            bqaFail('Browser-QA comment context changed during acceptance.');
        }
        $contexts[] = ['id' => $id, 'commentable' => $commentable];
    }
    if ($expected !== []) {
        bqaFail('Browser-QA comment context rollback state is incomplete.');
    }
    foreach ($contexts as $context) {
        single_update(CATEGORIES_TABLE, ['commentable' => $context['commentable']], ['id' => $context['id']]);
    }
    $allowedConfig = [
        'activate_comments' => true,
        'comments_validation' => true,
        'email_admin_on_comment' => true,
        'email_admin_on_comment_validation' => true,
        'anti-flood_time' => true,
    ];
    if (array_keys($state['config_state']) !== array_keys($allowedConfig)) {
        bqaFail('Browser-QA comment configuration rollback state is invalid.');
    }
    foreach ($state['config_state'] as $parameter => $saved) {
        if (!isset($allowedConfig[$parameter]) || !is_array($saved) || !array_key_exists('exists', $saved)) {
            bqaFail('Browser-QA comment configuration rollback state is unsafe.');
        }
        if ($saved['exists'] === true) {
            $value = $saved['value'] ?? null;
            if (!is_string($value) || strlen($value) > 100) {
                bqaFail('Browser-QA comment configuration rollback value is invalid.');
            }
            single_update(CONFIG_TABLE, ['value' => $value], ['param' => $parameter]);
        } elseif ($saved['exists'] === false) {
            pwg_query('DELETE FROM ' . CONFIG_TABLE . ' WHERE param=' . bqaQuoted($parameter));
        } else {
            bqaFail('Browser-QA comment configuration rollback state is invalid.');
        }
    }
    invalidate_user_cache();
    if (!unlink($statePath) || file_exists($statePath)) {
        bqaFail('Browser-QA comment context state cleanup failed.');
    }
    return ['restored' => true, 'contexts' => count($contexts)];
}

$command = $_SERVER['argv'][1] ?? '';
$runId = $_SERVER['argv'][2] ?? '';
if (!is_string($command) || !is_string($runId)) {
    bqaFail('Usage: browser-qa-fixture.php COMMAND RUN_ID');
}
bqaRequireRunId($runId);
bqaBoot();
// Piwigo bootstrap must run in global scope. Loading common.inc.php from a
// helper function strands its Core globals and causes a misleading CLI guest
// redirect instead of a usable test runtime.
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
if (!defined('PHPWG_VERSION') || PHPWG_VERSION !== '16.4.0'
    || !class_exists(\ClassIdentity\Repository::class)
    || !class_exists(\ClassIdentity\Access::class)
    || !class_exists(\ClassIdentity\CoreAdapter::class)
    || !\ClassIdentity\Access::isEnforcementEnabled()
) {
    bqaFail('Active fail-closed Piwigo 16.4 + ClassIdentity runtime required.');
}

try {
    match ($command) {
        'create-admin' => bqaJson(bqaCreateAdmin($runId)),
        'media' => bqaJson(bqaMediaFixture()),
        'prepare-comment-contexts' => bqaJson(bqaPrepareCommentContexts($runId)),
        'cleanup-comment-contexts' => bqaJson(bqaCleanupCommentContexts($runId)),
        'cleanup-comments' => bqaJson(['comments_deleted' => bqaCleanupComments($runId)]),
        'cleanup-admin' => (static function () use ($runId): never {
            bqaCleanupAdmin($runId);
            bqaJson(['admin_remaining' => 0]);
        })(),
        default => bqaFail('Unknown browser-QA fixture command.'),
    };
} catch (Throwable $error) {
    bqaFail('Browser-QA fixture command failed [' . get_class($error) . '].');
}
