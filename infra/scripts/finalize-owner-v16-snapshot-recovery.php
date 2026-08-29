<?php

declare(strict_types=1);

/**
 * One-purpose recovery finalizer for an Owner V16 -> V18 pre-migration
 * snapshot that failed before migration began.
 *
 * It deliberately does not compare the installed V16 plugin tree to the
 * current V18 checkout.  Instead it accepts only the exact V16 plugin trees
 * recorded below, verifies the V16 ledger/configuration read-only, proves no
 * V17/V18 DDL exists, then removes the trusted maintenance marker.  The only
 * permitted mutation in this program is that final marker unlink.
 */

const RECOVERY_PIWIGO_ROOT = '/var/www/html/piwigo';
const RECOVERY_MARKER = RECOVERY_PIWIGO_ROOT . '/_data/.class-archive-maintenance';
const RECOVERY_MARKER_CONTENT = "class-archive-identity-bootstrap\n";
const RECOVERY_MARKER_NAME = '.class-archive-source.json';
const RECOVERY_V16_SOURCE_COMMIT = '57e419e832897cabdc2d3d45ed0ea1bf8ac88b8b';
const RECOVERY_EXPECTED_PIWIGO_VERSION = '16.4.0';

/** @var array<string, array{version:string,tree_digest:string}> */
const RECOVERY_V16_PLUGIN_LOCK = [
    'ClassArchivePolicy' => [
        'version' => '0.1.0',
        'tree_digest' => 'b23e907140b6a19a8af8a03ecf2eeec73a0e199f75d1bb441505b312365bd5e7',
    ],
    'ClassIdentity' => [
        'version' => '0.1.0',
        'tree_digest' => 'b11eb0010d8e76b4c63da7171df31ea3bd0b43507972b16dea48e2a56dfe257d',
    ],
];

/** @var list<string> */
const RECOVERY_V17_V18_TABLE_SUFFIXES = [
    'collection_snapshot',
    'collection_snapshot_item',
    'collection_snapshot_pointer',
    'collection_pin',
    'collection_feedback',
    'collection_maintenance_state',
    'spotlight_rotation_state',
];

function recoveryFail(string $code): never
{
    $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($code));
    fwrite(STDERR, 'OWNER_V16_SNAPSHOT_RECOVERY_FINALIZER=FAIL code=' . $safe . "\n");
    exit(1);
}

function recoveryAssertRuntime(): void
{
    if (PHP_SAPI !== 'cli' || getenv('CLASS_ARCHIVE_OWNER_V16_SNAPSHOT_RECOVERY') !== '1') {
        recoveryFail('scope_confirmation_missing');
    }
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        recoveryFail('posix_required');
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        recoveryFail('nginx_user_required');
    }
    if (($_SERVER['argv'] ?? []) !== [$_SERVER['argv'][0] ?? '', '--finalize-owner-v16-snapshot-recovery']) {
        recoveryFail('exact_argument_required');
    }
}

/** @param array<string,int> $metadata */
function recoveryTrustedMarkerOwnership(array $metadata, string $dataDirectory, int $nginxUid, int $nginxGid): bool
{
    $mode = (int) ($metadata['mode'] ?? 0) & 0777;
    $uid = (int) ($metadata['uid'] ?? -1);
    $gid = (int) ($metadata['gid'] ?? -1);
    if ($uid === $nginxUid && $gid === $nginxGid && in_array($mode, [0600, 0660, 0670], true)) {
        return true;
    }
    $directory = @lstat($dataDirectory);
    return is_array($directory)
        && $uid > 0
        && $uid === (int) ($directory['uid'] ?? -2)
        && $gid === (int) ($directory['gid'] ?? -2)
        && in_array($mode, [0660, 0670], true);
}

function recoveryAssertTrustedMarker(): void
{
    $root = realpath(RECOVERY_PIWIGO_ROOT);
    $dataDirectory = realpath(RECOVERY_PIWIGO_ROOT . '/_data');
    if ($root !== RECOVERY_PIWIGO_ROOT || $dataDirectory !== RECOVERY_PIWIGO_ROOT . '/_data'
        || !is_dir($dataDirectory) || is_link(RECOVERY_PIWIGO_ROOT) || is_link(RECOVERY_PIWIGO_ROOT . '/_data')) {
        recoveryFail('maintenance_directory_untrusted');
    }
    clearstatcache(true, RECOVERY_MARKER);
    $metadata = @lstat(RECOVERY_MARKER);
    if (!is_array($metadata) || is_link(RECOVERY_MARKER)
        || (($metadata['mode'] ?? 0) & 0170000) !== 0100000
        || realpath(RECOVERY_MARKER) !== RECOVERY_MARKER
        || (int) ($metadata['nlink'] ?? 0) !== 1
        || !recoveryTrustedMarkerOwnership($metadata, $dataDirectory, posix_geteuid(), posix_getegid())) {
        recoveryFail('maintenance_marker_untrusted');
    }
    $content = file_get_contents(RECOVERY_MARKER);
    if (!is_string($content) || !hash_equals(RECOVERY_MARKER_CONTENT, $content)) {
        recoveryFail('maintenance_marker_content_untrusted');
    }
}

/** @return array<string, array{path:string,size:int,sha256:string}> */
function recoveryScanInstalledPlugin(string $pluginId): array
{
    $expected = RECOVERY_V16_PLUGIN_LOCK[$pluginId] ?? null;
    if (!is_array($expected)) {
        recoveryFail('plugin_not_allowlisted');
    }
    $root = RECOVERY_PIWIGO_ROOT . '/plugins/' . $pluginId;
    $resolved = realpath($root);
    if ($resolved !== $root || !is_dir($resolved) || is_link($root)) {
        recoveryFail('plugin_tree_untrusted_' . $pluginId);
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            recoveryFail('plugin_symlink_' . $pluginId);
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($resolved) + 1));
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
            || preg_match('~(?:^|/)\.\.(?:/|$)~D', $relative) === 1) {
            recoveryFail('plugin_relative_path_untrusted_' . $pluginId);
        }
        if ($entry->isDir()) {
            continue;
        }
        if (!$entry->isFile()) {
            recoveryFail('plugin_special_entry_' . $pluginId);
        }
        if ($relative === RECOVERY_MARKER_NAME) {
            continue;
        }
        $size = $entry->getSize();
        if ($size < 0 || $size > 16_777_216) {
            recoveryFail('plugin_file_size_untrusted_' . $pluginId);
        }
        $digest = hash_file('sha256', $entry->getPathname());
        if ($digest === false) {
            recoveryFail('plugin_file_hash_unavailable_' . $pluginId);
        }
        $files[$relative] = ['path' => $entry->getPathname(), 'size' => $size, 'sha256' => $digest];
    }
    ksort($files, SORT_STRING);
    if (!isset($files['main.inc.php'])) {
        recoveryFail('plugin_main_missing_' . $pluginId);
    }
    $main = file_get_contents($files['main.inc.php']['path']);
    if (!is_string($main) || preg_match('/^Version:\s*([^\r\n]+)$/mi', $main, $matches) !== 1
        || trim($matches[1]) !== $expected['version']) {
        recoveryFail('plugin_header_untrusted_' . $pluginId);
    }
    return $files;
}

/** @param array<string, array{path:string,size:int,sha256:string}> $files */
function recoveryTreeDigest(array $files): string
{
    $context = hash_init('sha256');
    foreach ($files as $relative => $metadata) {
        hash_update($context, $relative . "\0" . $metadata['size'] . "\0" . $metadata['sha256'] . "\n");
    }
    return hash_final($context);
}

function recoveryAssertInstalledPluginLock(string $pluginId): void
{
    $expected = RECOVERY_V16_PLUGIN_LOCK[$pluginId];
    $root = RECOVERY_PIWIGO_ROOT . '/plugins/' . $pluginId;
    $markerPath = $root . '/' . RECOVERY_MARKER_NAME;
    if (!is_file($markerPath) || is_link($markerPath)) {
        recoveryFail('plugin_marker_missing_' . $pluginId);
    }
    try {
        $marker = json_decode((string) file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        recoveryFail('plugin_marker_invalid_' . $pluginId);
    }
    if (!is_array($marker) || array_is_list($marker) || ($marker['format'] ?? null) !== 1
        || ($marker['id'] ?? null) !== $pluginId || ($marker['version'] ?? null) !== $expected['version']
        || !is_string($marker['treeDigest'] ?? null)
        || !hash_equals($expected['tree_digest'], $marker['treeDigest'])) {
        recoveryFail('plugin_marker_lock_mismatch_' . $pluginId);
    }
    if (!hash_equals($expected['tree_digest'], recoveryTreeDigest(recoveryScanInstalledPlugin($pluginId)))) {
        recoveryFail('plugin_tree_lock_mismatch_' . $pluginId);
    }
}

function recoveryAssertPiwigoVersion(): void
{
    $constants = RECOVERY_PIWIGO_ROOT . '/include/constants.php';
    if (!is_file($constants) || is_link($constants)) {
        recoveryFail('piwigo_constants_untrusted');
    }
    $contents = file_get_contents($constants);
    if (!is_string($contents) || preg_match("~define\\(['\"]PHPWG_VERSION['\"],\\s*['\"]([^'\"]+)['\"]\\)~", $contents, $matches) !== 1
        || !hash_equals(RECOVERY_EXPECTED_PIWIGO_VERSION, $matches[1])) {
        recoveryFail('piwigo_version_untrusted');
    }
    if (!defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', RECOVERY_PIWIGO_ROOT . '/');
    }
    if (!defined('PHPWG_VERSION')) {
        define('PHPWG_VERSION', $matches[1]);
    }
}

/** @return array{0:mysqli,1:string} */
function recoveryOpenReadonlyDatabase(): array
{
    $config = RECOVERY_PIWIGO_ROOT . '/local/config/database.inc.php';
    if (!is_file($config) || is_link($config)) {
        recoveryFail('database_config_untrusted');
    }
    $conf = [];
    $prefixeTable = null;
    require $config;
    if (!is_array($conf) || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1
        || ($conf['dblayer'] ?? null) !== 'mysqli') {
        recoveryFail('database_config_invalid');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if (!$db->set_charset('utf8mb4') || !$db->query('SET SESSION TRANSACTION READ ONLY')
        || !$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY)) {
        recoveryFail('database_readonly_transaction_unavailable');
    }
    return [$db, $prefixeTable];
}

function recoveryQuotedIdentifier(string $value): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $value) !== 1) {
        recoveryFail('database_identifier_invalid');
    }
    return '`' . $value . '`';
}

function recoveryAssertPluginRows(mysqli $db, string $prefix): void
{
    $table = recoveryQuotedIdentifier($prefix . 'plugins');
    $statement = $db->prepare('SELECT `id`,`version`,`state` FROM ' . $table . ' WHERE `id` IN (?, ?) ORDER BY `id`');
    $first = 'ClassArchivePolicy';
    $second = 'ClassIdentity';
    $statement->bind_param('ss', $first, $second);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    if (count($rows) !== 2) {
        recoveryFail('plugin_rows_invalid');
    }
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        $expected = RECOVERY_V16_PLUGIN_LOCK[$id] ?? null;
        if (!is_array($expected) || ($row['version'] ?? null) !== $expected['version'] || ($row['state'] ?? null) !== 'active') {
            recoveryFail('plugin_state_invalid_' . $id);
        }
    }
}

function recoveryAssertEnforcement(mysqli $db, string $prefix): void
{
    $table = recoveryQuotedIdentifier($prefix . 'config');
    $parameter = 'class_identity_enforcement';
    $statement = $db->prepare('SELECT `value` FROM ' . $table . ' WHERE `param` = ?');
    $statement->bind_param('s', $parameter);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    if (count($rows) !== 1 || ($rows[0]['value'] ?? null) !== 'true') {
        recoveryFail('enforcement_not_enabled');
    }
}

function recoveryAssertNoV17V18Tables(mysqli $db, string $prefix): void
{
    $statement = $db->prepare(
        'SELECT COUNT(*) AS `count` FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach (RECOVERY_V17_V18_TABLE_SUFFIXES as $suffix) {
        $name = $prefix . 'class_identity_' . $suffix;
        $statement->bind_param('s', $name);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($rows) !== 1 || (int) ($rows[0]['count'] ?? -1) !== 0) {
            $statement->close();
            recoveryFail('v17_v18_table_present');
        }
    }
    $statement->close();
}

function recoveryAssertInstalledV16Schema(mysqli $db, string $prefix): void
{
    $ledger = recoveryQuotedIdentifier($prefix . 'class_identity_migration');
    $row = $db->query('SELECT COUNT(*) AS `count`, MIN(`version`) AS `min_version`, MAX(`version`) AS `max_version` FROM ' . $ledger)->fetch_assoc();
    if (!is_array($row) || (int) ($row['count'] ?? -1) !== 16 || (int) ($row['min_version'] ?? 0) !== 1 || (int) ($row['max_version'] ?? 0) !== 16) {
        recoveryFail('v16_ledger_not_exact');
    }
    $schemaPath = RECOVERY_PIWIGO_ROOT . '/plugins/ClassIdentity/src/Schema.php';
    if (!is_file($schemaPath) || is_link($schemaPath)) {
        recoveryFail('installed_schema_missing');
    }
    $GLOBALS['mysqli'] = $db;
    $GLOBALS['prefixeTable'] = $prefix;
    require_once $schemaPath;
    if (!class_exists('ClassIdentity\\Schema') || \ClassIdentity\Schema::CURRENT_VERSION !== 16) {
        recoveryFail('installed_schema_version_invalid');
    }
    \ClassIdentity\Schema::fromPiwigo(RECOVERY_V16_PLUGIN_LOCK['ClassIdentity']['version'])->verifyCurrent();
}

function recoveryCloseTrustedMarker(): void
{
    recoveryAssertTrustedMarker();
    if (!unlink(RECOVERY_MARKER)) {
        recoveryFail('maintenance_marker_unlink_failed');
    }
    clearstatcache(true, RECOVERY_MARKER);
    if (file_exists(RECOVERY_MARKER) || is_link(RECOVERY_MARKER)) {
        recoveryFail('maintenance_marker_remained');
    }
}

try {
    recoveryAssertRuntime();
    recoveryAssertTrustedMarker();
    recoveryAssertPiwigoVersion();
    foreach (array_keys(RECOVERY_V16_PLUGIN_LOCK) as $pluginId) {
        recoveryAssertInstalledPluginLock($pluginId);
    }
    [$db, $prefix] = recoveryOpenReadonlyDatabase();
    try {
        recoveryAssertPluginRows($db, $prefix);
        recoveryAssertEnforcement($db, $prefix);
        recoveryAssertNoV17V18Tables($db, $prefix);
        recoveryAssertInstalledV16Schema($db, $prefix);
    } finally {
        $db->rollback();
        $db->close();
    }
    recoveryCloseTrustedMarker();
    fwrite(STDOUT, 'OWNER_V16_SNAPSHOT_RECOVERY_FINALIZER=PASS source_commit=' . RECOVERY_V16_SOURCE_COMMIT . " schema=16 mutation=MAINTENANCE_MARKER_UNLINK_ONLY\n");
} catch (Throwable $exception) {
    recoveryFail('verification_failed');
}
