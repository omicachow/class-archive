<?php

declare(strict_types=1);

/**
 * Static regression gate for the ClassIdentity maintenance lifecycle.
 *
 * The destructive/recovery behavior is also exercised against the real local
 * Piwigo/MariaDB runtime, but these assertions cheaply prevent the orchestration
 * invariants from disappearing during later script refactors.
 */

$root = dirname(__DIR__);
$paths = [
    'installer' => $root . '/infra/scripts/install-class-archive-plugins.php',
    'bootstrap' => $root . '/infra/scripts/bootstrap-class-identity.php',
    'prepare' => $root . '/infra/scripts/prepare-class-archive-maintenance.php',
    'backup_audit' => $root . '/infra/scripts/audit-backup.sh',
    'dev' => $root . '/infra/scripts/dev.ps1',
    'access' => $root . '/plugins/ClassIdentity/src/Access.php',
    'media_guard' => $root . '/plugins/ClassArchivePolicy/src/MediaGuard.php',
];
$sources = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "MAINTENANCE_PROTOCOL=FAIL missing={$name}\n");
        exit(1);
    }
    $sources[$name] = $contents;
}

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};
$position = static function (string $haystack, string $needle): int {
    $offset = strpos($haystack, $needle);
    return $offset === false ? -1 : $offset;
};
$lastPosition = static function (string $haystack, string $needle): int {
    $offset = strrpos($haystack, $needle);
    return $offset === false ? -1 : $offset;
};

$installer = $sources['installer'];
$bootstrap = $sources['bootstrap'];
$prepare = $sources['prepare'];
$backupAudit = $sources['backup_audit'];
$dev = $sources['dev'];
$access = $sources['access'];
$mediaGuard = $sources['media_guard'];

$assert(substr_count($installer, 'closeMaintenanceGate();') === 1, 'only the finalizer may close the marker');
$assert(
    $position($installer, "elseif (\$mode === 'finalize')") < $position($installer, 'closeMaintenanceGate();'),
    'marker close must occur in the explicit finalize branch',
);
$installCatch = substr($installer, $position($installer, '} catch (Throwable $exception) {'));
$assert(!str_contains($installCatch, "if (\$enforcementRestored) {\n                closeMaintenanceGate();"), 'failed install must not remove marker');
$assert(str_contains($installer, 'MAINTENANCE PENDING_RESTART_VERIFICATION'), 'successful install must remain pending finalization');
$assert(str_contains($installer, "elseif (\$mode === 'verify-runtime')"), 'post-restart runtime verification mode is required');
$assert(str_contains($installer, "elseif (\$mode === 'finalize')"), 'independent finalization mode is required');
$assert(substr_count($installer, 'verifyInstalledRuntime($results, $withSyntheticFixtures, true);') === 2, 'verify and finalize must independently repeat runtime assertions');
$assert(str_contains($installer, 'assertTrustedMaintenanceGate();'), 'installer must validate exact marker state');
$sameDigestReturn = $position($installer, 'return "VERIFIED {$pluginId} {$definition[\'version\']}";');
$presenterReset = $position($installer, 'resetAnonymousPresenterReadiness();');
$atomicReplace = $position($installer, 'rename($destination, $backup)');
$assert($sameDigestReturn >= 0 && $sameDigestReturn < $presenterReset, 'same-digest install must preserve presenter attestation');
$assert($presenterReset >= 0 && $presenterReset < $atomicReplace, 'changed ClassIdentity tree must reset presenter readiness before atomic replace');
$assert(substr_count($installer, 'resetAnonymousPresenterReadiness();') === 1, 'presenter reset must occur only on the replacement path');
$assert(str_contains($installer, 'class_identity_anon_presenter_ready'), 'installer must reset the exact persisted presenter gate');
$assert(str_contains($prepare, "posix_geteuid() !== 0"), 'ownership bridge must require container root');
$assert(str_contains($prepare, 'in_array(($mode & 0777), [0660, 0670], true)'), 'ownership bridge must accept only the two exact observed normalized modes');
$assert(str_contains($prepare, "\$uid === (int) (\$directory['uid'] ?? -2)"), 'normalized marker owner must match the persistent directory');
$assert(str_contains($prepare, 'rename($temporary, CLASS_ARCHIVE_PREPARE_MARKER)'), 'ownership bridge must atomically publish its staged inode');
$assert(str_contains($prepare, 'chown($temporary, $nginxUid)'), 'ownership bridge may chown only its newly staged inode');
$assert(!str_contains($prepare, 'chmod(CLASS_ARCHIVE_PREPARE_MARKER'), 'ownership bridge must never chmod the existing marker inode');
$assert(str_contains($backupAudit, 'chmod 0660 "$temporary"'), 'backup freshness evidence must remain private inside _data');
$assert(!str_contains($backupAudit, 'chmod 0644 "$temporary"'), 'backup freshness evidence must never become world-readable');

$bootstrapFalse = $position($bootstrap, "conf_update_param('class_identity_enforcement', false, true);");
$bootstrapMarker = $position($bootstrap, 'assertTrustedMaintenanceGate();');
$assert($bootstrapMarker >= 0 && $bootstrapMarker < $bootstrapFalse, 'bootstrap must validate marker before enforcement=false');
$assert(substr_count($bootstrap, 'assertTrustedMaintenanceGate();') >= 3, 'bootstrap must revalidate marker at preparation, mutation and post-mutation boundaries');
$assert(str_contains($bootstrap, '(($metadata[\'mode\'] ?? 0) & 0170000) !== 0100000'), 'bootstrap must require a regular marker file');
$assert(str_contains($bootstrap, '(($metadata[\'mode\'] ?? 0) & 0777) !== 0600'), 'bootstrap must require marker mode 0600');
$assert(str_contains($bootstrap, "(int) (\$metadata['uid'] ?? -1) !== \$uid"), 'bootstrap must require nginx ownership');
$assert(str_contains($bootstrap, 'realpath($path) !== $path'), 'bootstrap must require the exact in-root path');
$contextDefinition = $position($bootstrap, "define('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT', 'class-archive-cli-bootstrap-v1');");
$commonBootstrap = $position($bootstrap, "require PHPWG_ROOT_PATH . 'include/common.inc.php';");
$assert($contextDefinition >= 0 && $contextDefinition < $commonBootstrap, 'trusted bootstrap context must be defined before plugin load');

$assert(str_contains($access, "PHP_SAPI !== 'cli'"), 'Access must restrict bootstrap bypass to CLI');
$assert(str_contains($access, 'CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT'), 'Access must require the explicit bootstrap context');
$assert(str_contains($access, 'hasUntrustedDisabledConfiguration'), 'Access must expose invalid disabled-state detection');
$assert(str_contains($access, 'self::denyCurrentRequest(503);'), 'untrusted disabled HTTP state must terminate fail closed');
$assert(!str_contains($mediaGuard, 'USER_GROUP_TABLE'), 'MediaGuard must not fall back to projected Core groups');
$assert(!str_contains($mediaGuard, 'GROUPS_TABLE'), 'MediaGuard must not resolve business roles from Core groups');

$assert(!str_contains($dev, "'php', '/workspace/infra/scripts/bootstrap-class-identity.php'"), 'dev must not expose direct online bootstrap');
$recreate = $position($dev, "@('up', '-d', '--force-recreate', '--no-deps', 'piwigo')");
$ready = $lastPosition($dev, 'Wait-ClassArchiveMaintenanceReady');
$verify = $position($dev, "'--verify-runtime'");
$finalize = $position($dev, "'--finalize-maintenance'");
$assert($recreate >= 0 && $recreate < $verify, 'fail-closed Piwigo recreation must precede runtime verification');
$assert($ready >= 0 && $ready < $verify, 'maintenance readiness must precede runtime verification');
$assert($verify >= 0 && $verify < $finalize, 'runtime verification must precede independent finalization');
$assert(str_contains($dev, "if (\$LASTEXITCODE -ne 0) { exit \$LASTEXITCODE }"), 'orchestrator failures must stop before finalization');
$assert(str_contains($dev, "'--with-synthetic-fixtures'"), 'synthetic bootstrap must use the same orchestrated lifecycle');
$assert(str_contains($dev, '/workspace/tests/phase1/php-fpm-ready.php'), 'FPM readiness must use an argv-safe mounted probe');
$assert(!str_contains($dev, "'php', '-r'"), 'FPM readiness must not pass PHP source through WSL shell quoting');
$assert(str_contains($dev, 'enforcement-fault-http.ps1'), 'aggregate must include the real false-without-marker HTTP fault gate');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "MAINTENANCE_PROTOCOL_ASSERTION_FAILED: {$failure}\n");
    }
    fwrite(STDERR, 'CLASS_IDENTITY_MAINTENANCE_PROTOCOL=FAIL assertions=' . $assertions . ' failures=' . count($failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'CLASS_IDENTITY_MAINTENANCE_PROTOCOL=PASS assertions=' . $assertions . "\n");
