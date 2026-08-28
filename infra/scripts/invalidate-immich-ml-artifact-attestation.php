<?php

declare(strict_types=1);

const CLASS_ARCHIVE_ML_INVALIDATION_ROOT = '/var/www/html/piwigo';

try {
    if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        throw new RuntimeException('ml_artifact_attestation_invalidation_runtime_forbidden');
    }
    if (realpath(CLASS_ARCHIVE_ML_INVALIDATION_ROOT) !== CLASS_ARCHIVE_ML_INVALIDATION_ROOT || is_link(CLASS_ARCHIVE_ML_INVALIDATION_ROOT)) {
        throw new RuntimeException('ml_artifact_attestation_invalidation_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_ML_INVALIDATION_ROOT) || throw new RuntimeException('ml_artifact_attestation_invalidation_chdir_failed');
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
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    \ClassIdentity\MlArtifactAttestation::invalidate();
    fwrite(STDOUT, "ML_ARTIFACT_ATTESTATION=INVALIDATED\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'ML_ARTIFACT_ATTESTATION=INVALIDATION_FAILED code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
