<?php

declare(strict_types=1);

const CLASS_ARCHIVE_PIWIGO_ROOT = '/var/www/html/piwigo';

/** @var array<string, string> */
const CLASS_ARCHIVE_ACTIVATION_ALLOWLIST = [
    'ClassArchivePolicy' => '0.1.0',
    'ClassIdentity' => '0.1.0',
];

function fail(string $message): never
{
    throw new RuntimeException($message);
}

try {
    if (
        PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || !function_exists('posix_getpwuid')
    ) {
        fail('Run custom plugin activation through PHP CLI with POSIX support.');
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        fail('Run custom plugin activation as the nginx user, never root.');
    }

    $arguments = $_SERVER['argv'] ?? [];
    if (count($arguments) > 2) {
        fail('Usage: activate-class-archive-policy.php [ClassArchivePolicy|ClassIdentity]');
    }
    // Preserve the original helper's zero-argument contract for older local
    // tooling while allowing the installer to activate either owned plugin.
    $pluginId = (string) ($arguments[1] ?? 'ClassArchivePolicy');
    $expectedVersion = CLASS_ARCHIVE_ACTIVATION_ALLOWLIST[$pluginId] ?? null;
    if (!is_string($expectedVersion)) {
        fail('Refusing to activate a plugin outside the Class Archive allowlist.');
    }

    chdir(CLASS_ARCHIVE_PIWIGO_ROOT) || fail('Cannot enter the Piwigo root.');
    define('PHPWG_ROOT_PATH', './');
    // Piwigo permits its login context through the gallery-lock/no-photo
    // presentation exits. The helper remains an in-container CLI operation.
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    require_once '/workspace/infra/scripts/class-archive-trusted-bootstrap-context.php';
    classArchiveEnableTrustedCliBootstrapContext();

    // Piwigo intentionally creates global bootstrap state. Keep this include at
    // file scope; wrapping it in a helper strands $conf/$user/database locals.
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'admin/include/plugins.class.php';

    $manager = new plugins();
    $errors = $manager->perform_action('activate', $pluginId);
    if ($errors !== []) {
        fail("Piwigo rejected {$pluginId} activation: " . implode('; ', $errors));
    }
    $rows = query2array(
        'SELECT id, version, state FROM ' . PLUGINS_TABLE
        . " WHERE id = '" . pwg_db_real_escape_string($pluginId) . "'"
    );
    if (
        count($rows) !== 1
        || $rows[0]['state'] !== 'active'
        || $rows[0]['version'] !== $expectedVersion
    ) {
        fail("{$pluginId} is not active at the expected version.");
    }

    fwrite(STDOUT, "ACTIVATED {$pluginId} {$expectedVersion}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
