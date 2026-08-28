<?php
/*
Plugin Name: Class Archive Policy
Version: 0.1.0
Description: Server-side Class Archive media, era, action and privacy policy.
Author: Class Archive contributors
*/

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

defined('CLASS_ARCHIVE_POLICY_ID') or define('CLASS_ARCHIVE_POLICY_ID', basename(__DIR__));
defined('CLASS_ARCHIVE_POLICY_PATH') or define(
    'CLASS_ARCHIVE_POLICY_PATH',
    PHPWG_PLUGINS_PATH . CLASS_ARCHIVE_POLICY_ID . '/'
);
defined('CLASS_ARCHIVE_POLICY_VERSION') or define('CLASS_ARCHIVE_POLICY_VERSION', '0.1.0');

require_once CLASS_ARCHIVE_POLICY_PATH . 'src/MediaGuard.php';
require_once CLASS_ARCHIVE_POLICY_PATH . 'src/MediaFilePolicy.php';
require_once CLASS_ARCHIVE_POLICY_PATH . 'src/DerivativeWarmupQueue.php';
require_once CLASS_ARCHIVE_POLICY_PATH . 'src/DerivativeCacheWarmer.php';

/**
 * This hook is deliberately small. Nginx routes every media byte path through
 * media-gateway.php, including URLs produced before this plugin was enabled.
 * The hook exists so the future Admin Console can inspect the active runtime.
 */
add_event_handler('init', static function (): void {
    $GLOBALS['class_archive_policy_runtime']['media_guard'] = 'loaded';
});

// Piwigo Core applies 0644 after each upload. Tighten the resulting original
// and optional format file before control returns to the caller. This protects
// direct Core uploads as well as the ClassIdentity approval path.
add_event_handler('loc_end_add_uploaded_file', [ClassArchiveMediaFilePolicy::class, 'normalizeUploadedFile'], 100);
add_event_handler('loc_end_add_format', [ClassArchiveMediaFilePolicy::class, 'normalizeUploadedFormat'], 100);
