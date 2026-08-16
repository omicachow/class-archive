<?php

declare(strict_types=1);

/**
 * Root-only ownership bridge for the pinned Piwigo container.
 *
 * Its startup permission normalizer rewrites persistent-volume files to the
 * configured storage uid/mode. This helper never chmods/chowns/unlinks that
 * inode. It accepts only ABSENT, the exact normalized historical marker, or the
 * current trusted nginx marker; then publishes a separately verified temporary
 * inode by atomic rename.
 */

const CLASS_ARCHIVE_PREPARE_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_PREPARE_DATA = CLASS_ARCHIVE_PREPARE_ROOT . '/_data';
const CLASS_ARCHIVE_PREPARE_MARKER = CLASS_ARCHIVE_PREPARE_DATA . '/.class-archive-maintenance';
const CLASS_ARCHIVE_PREPARE_CONTENT = "class-archive-identity-bootstrap\n";

function prepareFail(string $message): never
{
    fwrite(STDERR, "MAINTENANCE_PREPARE=ERROR {$message}\n");
    exit(1);
}

/** @return array{uid: int, gid: int} */
function assertPrepareRuntime(): array
{
    if (
        PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || !function_exists('posix_getpwnam')
        || posix_geteuid() !== 0
    ) {
        prepareFail('Run the maintenance ownership bridge as container root.');
    }
    if (
        realpath(CLASS_ARCHIVE_PREPARE_ROOT) !== CLASS_ARCHIVE_PREPARE_ROOT
        || realpath(CLASS_ARCHIVE_PREPARE_DATA) !== CLASS_ARCHIVE_PREPARE_DATA
        || !is_dir(CLASS_ARCHIVE_PREPARE_DATA)
        || is_link(CLASS_ARCHIVE_PREPARE_ROOT)
        || is_link(CLASS_ARCHIVE_PREPARE_DATA)
    ) {
        prepareFail('The persistent maintenance root is unsafe.');
    }
    $nginx = posix_getpwnam('nginx');
    if (
        !is_array($nginx)
        || (int) ($nginx['uid'] ?? 0) <= 0
        || (int) ($nginx['gid'] ?? 0) <= 0
    ) {
        prepareFail('The container nginx account is unavailable.');
    }
    return ['uid' => (int) $nginx['uid'], 'gid' => (int) $nginx['gid']];
}

/**
 * @return array{state: string, dev?: int, ino?: int, uid?: int, gid?: int, mode?: int, nlink?: int, size?: int}
 */
function classifyMarker(string $path, int $nginxUid, int $nginxGid): array
{
    clearstatcache(true, $path);
    if (is_link($path)) {
        return ['state' => 'UNKNOWN'];
    }
    $metadata = @lstat($path);
    if (!is_array($metadata)) {
        return file_exists($path) ? ['state' => 'UNKNOWN'] : ['state' => 'ABSENT'];
    }
    $mode = (int) ($metadata['mode'] ?? 0);
    $uid = (int) ($metadata['uid'] ?? -1);
    $gid = (int) ($metadata['gid'] ?? -1);
    $nlink = (int) ($metadata['nlink'] ?? 0);
    $contents = (($mode & 0170000) === 0100000 && realpath($path) === $path)
        ? file_get_contents($path)
        : false;
    if (
        ($mode & 0170000) !== 0100000
        || $nlink !== 1
        || !is_string($contents)
        || !hash_equals(CLASS_ARCHIVE_PREPARE_CONTENT, $contents)
    ) {
        return ['state' => 'UNKNOWN'];
    }

    $state = 'UNKNOWN';
    if ($uid === $nginxUid && $gid === $nginxGid && ($mode & 0777) === 0600) {
        $state = 'TRUSTED';
    } else {
        $directory = @lstat(CLASS_ARCHIVE_PREPARE_DATA);
        if (
            is_array($directory)
            && $uid > 0
            && $uid === (int) ($directory['uid'] ?? -2)
            && $gid === (int) ($directory['gid'] ?? -2)
            && ($mode & 0777) === 0660
        ) {
            $state = 'NORMALIZED';
        }
    }

    return [
        'state' => $state,
        'dev' => (int) ($metadata['dev'] ?? -1),
        'ino' => (int) ($metadata['ino'] ?? -1),
        'uid' => $uid,
        'gid' => $gid,
        'mode' => $mode,
        'nlink' => $nlink,
        'size' => (int) ($metadata['size'] ?? -1),
    ];
}

/** @param array<string, int|string> $before */
function markerStateUnchanged(array $before, array $after): bool
{
    if (($before['state'] ?? null) === 'ABSENT') {
        return ($after['state'] ?? null) === 'ABSENT';
    }
    foreach (['state', 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size'] as $key) {
        if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
            return false;
        }
    }
    return true;
}

function prepareMarker(int $nginxUid, int $nginxGid): string
{
    $before = classifyMarker(CLASS_ARCHIVE_PREPARE_MARKER, $nginxUid, $nginxGid);
    if (($before['state'] ?? null) === 'TRUSTED') {
        return 'TRUSTED_UNCHANGED';
    }
    if (!in_array($before['state'] ?? null, ['ABSENT', 'NORMALIZED'], true)) {
        prepareFail('Refusing an unrecognized maintenance marker.');
    }

    $temporary = CLASS_ARCHIVE_PREPARE_DATA . '/.class-archive-maintenance-prepare-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'x+b');
    if (!is_resource($handle)) {
        prepareFail('Cannot create the staged maintenance marker.');
    }
    $published = false;
    try {
        if (!flock($handle, LOCK_EX)) {
            prepareFail('Cannot lock the staged maintenance marker.');
        }
        $written = fwrite($handle, CLASS_ARCHIVE_PREPARE_CONTENT);
        if ($written !== strlen(CLASS_ARCHIVE_PREPARE_CONTENT) || !fflush($handle)) {
            prepareFail('Cannot write the staged maintenance marker.');
        }
        if (!chown($temporary, $nginxUid) || !chgrp($temporary, $nginxGid) || !chmod($temporary, 0600)) {
            prepareFail('Cannot restrict the staged maintenance marker.');
        }
        fclose($handle);
        $handle = null;

        $staged = classifyMarker($temporary, $nginxUid, $nginxGid);
        if (($staged['state'] ?? null) !== 'TRUSTED') {
            prepareFail('The staged maintenance marker is untrusted.');
        }
        $current = classifyMarker(CLASS_ARCHIVE_PREPARE_MARKER, $nginxUid, $nginxGid);
        if (!markerStateUnchanged($before, $current)) {
            prepareFail('The maintenance marker changed during preparation.');
        }
        if (!rename($temporary, CLASS_ARCHIVE_PREPARE_MARKER)) {
            prepareFail('Cannot atomically publish the trusted maintenance marker.');
        }
        $published = true;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (!$published && (file_exists($temporary) || is_link($temporary))) {
            @unlink($temporary);
        }
    }

    $after = classifyMarker(CLASS_ARCHIVE_PREPARE_MARKER, $nginxUid, $nginxGid);
    if (($after['state'] ?? null) !== 'TRUSTED') {
        prepareFail('The trusted maintenance marker did not converge.');
    }
    return ($before['state'] ?? null) === 'ABSENT' ? 'CREATED' : 'NORMALIZED_UPGRADED';
}

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 2 || ($arguments[1] ?? null) !== '--prepare') {
    prepareFail('Usage: prepare-class-archive-maintenance.php --prepare');
}
$nginx = assertPrepareRuntime();
$result = prepareMarker($nginx['uid'], $nginx['gid']);
fwrite(STDOUT, "MAINTENANCE_PREPARE={$result}\n");
