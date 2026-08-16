<?php
/*
Plugin Name: Class Identity
Version: 0.1.0
Description: Identity, Seat and independent system-administrator policy for Class Archive.
Author: Class Archive contributors
*/

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

defined('CLASS_IDENTITY_ID') or define('CLASS_IDENTITY_ID', basename(__DIR__));
defined('CLASS_IDENTITY_PATH') or define(
    'CLASS_IDENTITY_PATH',
    PHPWG_PLUGINS_PATH . CLASS_IDENTITY_ID . '/'
);
defined('CLASS_IDENTITY_VERSION') or define('CLASS_IDENTITY_VERSION', '0.1.0');

require_once CLASS_IDENTITY_PATH . 'src/Repository.php';
require_once CLASS_IDENTITY_PATH . 'src/Audit.php';
require_once CLASS_IDENTITY_PATH . 'src/CoreAdapter.php';
require_once CLASS_IDENTITY_PATH . 'src/Access.php';
require_once CLASS_IDENTITY_PATH . 'src/CapabilityGuard.php';
require_once CLASS_IDENTITY_PATH . 'src/AnonymousPresenter.php';
require_once CLASS_IDENTITY_PATH . 'src/AnonymousResolutionService.php';
require_once CLASS_IDENTITY_PATH . 'public.php';

if (!class_exists('ClassIdentityAccess', false)) {
    class_alias(\ClassIdentity\Access::class, 'ClassIdentityAccess');
}

// Remove the one Core credential that cannot be revoked by deleting server-
// side sessions/auth keys. This runs while plugins load, before Piwigo calls
// auto_login() from user.inc.php.
\ClassIdentity\Access::disableRememberMeRuntime();
\ClassIdentity\AnonymousPresenter::boot();

add_event_handler('register_user_check', [\ClassIdentity\Access::class, 'onRegisterUserCheck'], 5);
add_event_handler('finalize_login', [\ClassIdentity\Access::class, 'onFinalizeLogin'], 5);
add_event_handler('user_login', [\ClassIdentity\Access::class, 'onUserLogin'], 5);
add_event_handler('user_init', [\ClassIdentity\Access::class, 'onUserInit'], 5);
add_event_handler('user_logout', [\ClassIdentity\Access::class, 'onUserLogout'], 5);
add_event_handler('ws_invoke_allowed', [\ClassIdentity\Access::class, 'onWsInvokeAllowed'], 5);
add_event_handler('ws_invoke_allowed', [\ClassIdentity\CapabilityGuard::class, 'onWsInvokeAllowed'], 10);
add_event_handler('loc_begin_picture', [\ClassIdentity\CapabilityGuard::class, 'guardPictureMutation'], 5);
add_event_handler('loc_begin_comments', [\ClassIdentity\CapabilityGuard::class, 'guardCommentsMutation'], 5);
add_event_handler('loc_begin_index', [\ClassIdentity\CapabilityGuard::class, 'guardCommunityRoute'], 10);
add_event_handler('loc_begin_admin', [\ClassIdentity\Access::class, 'guardClassIdentityAdminRoute'], 5);
add_event_handler('loc_begin_password', [\ClassIdentity\Access::class, 'guardCorePasswordRoute'], 5);
add_event_handler('loc_begin_profile', [\ClassIdentity\Access::class, 'guardCoreProfileMutation'], 5);
add_event_handler('get_admin_plugin_menu_links', [\ClassIdentity\Access::class, 'addAdminMenuLink'], 5);
add_event_handler('loc_end_section_init', [ClassIdentityPublicController::class, 'onSectionInit'], 5);
add_event_handler('loc_begin_index', [ClassIdentityPublicController::class, 'onBeginIndex'], 5);
add_event_handler('loc_end_index', [ClassIdentityPublicController::class, 'onEndIndex'], 5);

$GLOBALS['class_identity_runtime'] = [
    'version' => CLASS_IDENTITY_VERSION,
    'enforcement' => \ClassIdentity\Access::isEnforcementEnabled() ? 'enabled' : 'bootstrap-disabled',
];
