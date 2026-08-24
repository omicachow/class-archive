<?php

declare(strict_types=1);

const CLASS_ARCHIVE_ACTIVE_PROJECTION_ROOT = '/var/www/html/piwigo';

try {
    if (PHP_SAPI !== 'cli' || realpath(CLASS_ARCHIVE_ACTIVE_PROJECTION_ROOT) !== CLASS_ARCHIVE_ACTIVE_PROJECTION_ROOT) {
        throw new RuntimeException('active_projection_cli_required');
    }
    chdir(CLASS_ARCHIVE_ACTIVE_PROJECTION_ROOT) || throw new RuntimeException('active_projection_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';

    $store = \ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo();
    $assertions = 0;
    foreach ([
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
        \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
        \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
    ] as $kind) {
        foreach ([
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL,
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE,
        ] as $scope) {
            $payload = $store->aggregate($kind, $scope);
            if (!is_array($payload)) {
                throw new RuntimeException('active_projection_payload_invalid');
            }
            ++$assertions;
        }
    }
    fwrite(STDOUT, "READ_PROJECTION_ACTIVE_RUNTIME=PASS assertions={$assertions}\n");
} catch (Throwable $error) {
    $code = preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage());
    fwrite(STDERR, 'READ_PROJECTION_ACTIVE_RUNTIME=FAIL code=' . $code . "\n");
    exit(1);
}
