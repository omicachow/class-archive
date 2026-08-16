<?php

declare(strict_types=1);

const CLASS_ARCHIVE_POLICY_ID = 'ClassArchivePolicy';
const CLASS_ARCHIVE_POLICY_VERSION = '0.1.0';
const CLASS_ARCHIVE_PIWIGO_ROOT = '/var/www/html/piwigo';

function fail(string $message): never
{
    throw new RuntimeException($message);
}

try {
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
        fail('Run policy activation through PHP CLI as the nginx user.');
    }
    chdir(CLASS_ARCHIVE_PIWIGO_ROOT) || fail('Cannot enter the Piwigo root.');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    // Piwigo intentionally creates global bootstrap state. Keep this include at
    // file scope; wrapping it in a helper strands $conf/$user/database locals.
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'admin/include/plugins.class.php';

    $manager = new plugins();
    $errors = $manager->perform_action('activate', CLASS_ARCHIVE_POLICY_ID);
    if ($errors !== []) {
        fail('Piwigo rejected ClassArchivePolicy activation: ' . implode('; ', $errors));
    }
    $rows = query2array(
        "SELECT id, version, state FROM " . PLUGINS_TABLE . " WHERE id = 'ClassArchivePolicy'"
    );
    if (
        count($rows) !== 1
        || $rows[0]['state'] !== 'active'
        || $rows[0]['version'] !== CLASS_ARCHIVE_POLICY_VERSION
    ) {
        fail('ClassArchivePolicy is not active at the expected version.');
    }

    fwrite(STDOUT, "ACTIVATED ClassArchivePolicy " . CLASS_ARCHIVE_POLICY_VERSION . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
