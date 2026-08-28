<?php

declare(strict_types=1);

const CLASS_ARCHIVE_ATTESTATION_ROOT = '/var/www/html/piwigo';

/** @return array{commit:string,probe_count:int,test_suite_version:string} */
function attestationArguments(array $argv): array
{
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/\A--(commit|probe-count|test-suite-version)=(.+)\z/D', $argument, $matches) !== 1 || isset($values[$matches[1]])) {
            throw new InvalidArgumentException('media_attestation_argument_invalid');
        }
        $values[$matches[1]] = $matches[2];
    }
    if (!isset($values['commit'], $values['probe-count'], $values['test-suite-version'])
        || preg_match('/\A[0-9a-f]{40}\z/D', $values['commit']) !== 1
        || !ctype_digit($values['probe-count'])) {
        throw new InvalidArgumentException('media_attestation_argument_invalid');
    }
    return [
        'commit' => $values['commit'],
        'probe_count' => (int) $values['probe-count'],
        'test_suite_version' => $values['test-suite-version'],
    ];
}

function attestationPrepareRuntime(): void
{
    if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        throw new RuntimeException('media_attestation_runtime_forbidden');
    }
    if (realpath(CLASS_ARCHIVE_ATTESTATION_ROOT) !== CLASS_ARCHIVE_ATTESTATION_ROOT || is_link(CLASS_ARCHIVE_ATTESTATION_ROOT)) {
        throw new RuntimeException('media_attestation_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_ATTESTATION_ROOT) || throw new RuntimeException('media_attestation_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

try {
    $arguments = attestationArguments($_SERVER['argv'] ?? []);
    attestationPrepareRuntime();
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    $record = \ClassIdentity\MediaAttestation::create(
        $arguments['commit'],
        $arguments['probe_count'],
        $arguments['test_suite_version'],
    );
    \ClassIdentity\MediaAttestation::persist($record);
    fwrite(STDOUT, 'MEDIA_ATTESTATION=PASS commit=' . $arguments['commit'] . ' probes=' . $arguments['probe_count'] . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'MEDIA_ATTESTATION=FAILED code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
