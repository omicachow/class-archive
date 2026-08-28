<?php

declare(strict_types=1);

// Shared, narrow CLI precondition for the two Piwigo-native helpers that must
// load Core while ClassIdentity is intentionally in fail-closed maintenance.
// It does not grant a general bypass: it accepts only the nginx CLI account,
// the exact Piwigo root/data paths, and the same regular private marker forms
// accepted by the owned installer/bootstrap scripts.

const CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_MARKER = CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT . '/_data/.class-archive-maintenance';
const CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_CONTENT = "class-archive-identity-bootstrap\n";
const CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_VALUE = 'class-archive-cli-bootstrap-v1';

function classArchiveEnableTrustedCliBootstrapContext(): void
{
    if (
        PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || !function_exists('posix_getegid')
        || !function_exists('posix_getpwuid')
    ) {
        throw new RuntimeException('Trusted Class Archive bootstrap requires PHP CLI with POSIX support.');
    }
    if (defined('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT')) {
        throw new RuntimeException('Refusing a pre-defined ClassIdentity bootstrap context.');
    }

    $uid = posix_geteuid();
    $gid = posix_getegid();
    $account = posix_getpwuid($uid);
    if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        throw new RuntimeException('Trusted Class Archive bootstrap requires the nginx CLI account.');
    }

    $root = realpath(CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT);
    $dataDirectory = realpath(CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT . '/_data');
    if (
        $root !== CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT
        || $dataDirectory !== CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT . '/_data'
        || !is_dir($dataDirectory)
        || is_link(CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT)
        || is_link(CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT . '/_data')
    ) {
        throw new RuntimeException('Trusted Class Archive bootstrap root is unsafe.');
    }

    $markerPath = CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_MARKER;
    clearstatcache(true, $markerPath);
    $marker = @lstat($markerPath);
    $directory = @lstat($dataDirectory);
    if (
        !is_array($marker)
        || !is_array($directory)
        || is_link($markerPath)
        || (($marker['mode'] ?? 0) & 0170000) !== 0100000
        || realpath($markerPath) !== $markerPath
        || (int) ($marker['nlink'] ?? 0) !== 1
    ) {
        throw new RuntimeException('Trusted Class Archive maintenance marker is invalid.');
    }

    $mode = (int) ($marker['mode'] ?? 0) & 0777;
    $markerUid = (int) ($marker['uid'] ?? -1);
    $markerGid = (int) ($marker['gid'] ?? -1);
    $runtimeOwned = $markerUid === $uid && $markerGid === $gid && in_array($mode, [0600, 0660, 0670], true);
    $dataOwned = $markerUid > 0
        && $markerUid === (int) ($directory['uid'] ?? -2)
        && $markerGid === (int) ($directory['gid'] ?? -2)
        && in_array($mode, [0660, 0670], true);
    if (!$runtimeOwned && !$dataOwned) {
        throw new RuntimeException('Trusted Class Archive maintenance marker ownership is invalid.');
    }

    $contents = @file_get_contents($markerPath);
    if (!is_string($contents) || !hash_equals(CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_CONTENT, $contents)) {
        throw new RuntimeException('Trusted Class Archive maintenance marker content is invalid.');
    }

    define('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT', CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_VALUE);
}
