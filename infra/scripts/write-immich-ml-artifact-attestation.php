<?php

declare(strict_types=1);

const CLASS_ARCHIVE_ML_ATTESTATION_ROOT = '/var/www/html/piwigo';

function mlAttestationCommit(array $argv): string
{
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/\A--commit=(.+)\z/D', $argument, $matches) !== 1 || isset($values['commit'])) {
            throw new InvalidArgumentException('ml_artifact_attestation_argument_invalid');
        }
        $values['commit'] = $matches[1];
    }
    if (!isset($values['commit']) || preg_match('/\A[0-9a-f]{40}\z/D', $values['commit']) !== 1) {
        throw new InvalidArgumentException('ml_artifact_attestation_argument_invalid');
    }
    return $values['commit'];
}

function mlAttestationPrepareRuntime(): void
{
    if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        throw new RuntimeException('ml_artifact_attestation_runtime_forbidden');
    }
    if (realpath(CLASS_ARCHIVE_ML_ATTESTATION_ROOT) !== CLASS_ARCHIVE_ML_ATTESTATION_ROOT || is_link(CLASS_ARCHIVE_ML_ATTESTATION_ROOT)) {
        throw new RuntimeException('ml_artifact_attestation_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_ML_ATTESTATION_ROOT) || throw new RuntimeException('ml_artifact_attestation_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

try {
    $commit = mlAttestationCommit($_SERVER['argv'] ?? []);
    mlAttestationPrepareRuntime();
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    $record = \ClassIdentity\MlArtifactAttestation::create($commit);
    \ClassIdentity\MlArtifactAttestation::persist($record);
    fwrite(STDOUT, 'ML_ARTIFACT_ATTESTATION=PASS commit=' . $commit . ' artifacts=' . $record['artifact_count'] . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'ML_ARTIFACT_ATTESTATION=FAILED code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
