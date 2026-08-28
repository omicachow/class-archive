<?php

declare(strict_types=1);

const PIWIGO_ROOT = '/var/www/html/piwigo';
const REQUIRED_PIWIGO_VERSION = '16.4.0';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function preparePiwigoBootstrap(): void
{
    if (!is_file(PIWIGO_ROOT . '/local/config/database.inc.php')) {
        fail('Piwigo is not installed. Run the bootstrap command first.');
    }

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        fail('Refusing to configure Piwigo as root.');
    }

    chdir(PIWIGO_ROOT) || fail('Cannot enter the Piwigo application directory.');
    define('PHPWG_ROOT_PATH', './');
    // Piwigo's login route is the only normal Core context that passes both
    // its gallery-lock and no-photo presentation exits. This remains a local
    // CLI bootstrap: no browser request, session credential, or HTTP route is
    // invoked by the helper.
    $_SERVER['SCRIPT_NAME'] = '/identification.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    require_once '/workspace/infra/scripts/class-archive-trusted-bootstrap-context.php';
    classArchiveEnableTrustedCliBootstrapContext();
}

function fetchOne(string $query): ?array
{
    $result = pwg_query($query);
    $row = pwg_db_fetch_assoc($result);
    return $row === false ? null : $row;
}

function ensureGroup(string $name, bool $verifyOnly): int
{
    $escaped = pwg_db_real_escape_string($name);
    $rows = query2array('SELECT id, name, is_default FROM ' . GROUPS_TABLE . " WHERE name = '{$escaped}'");
    if (count($rows) > 1) {
        fail("More than one group is named {$name}.");
    }
    if (count($rows) === 1) {
        if ($rows[0]['is_default'] !== 'false') {
            fail("Security baseline requires {$name} to be non-default.");
        }
        return (int) $rows[0]['id'];
    }
    if ($verifyOnly) {
        fail("Required group {$name} is missing.");
    }

    single_insert(GROUPS_TABLE, ['name' => $name, 'is_default' => 'false']);
    return (int) pwg_db_insert_id(GROUPS_TABLE);
}

function ensureEraAlbum(string $name, string $permalink, bool $verifyOnly): int
{
    $escapedPermalink = pwg_db_real_escape_string($permalink);
    $rows = query2array(
        'SELECT id, name, permalink, status, visible, commentable, id_uppercat FROM ' . CATEGORIES_TABLE
        . " WHERE permalink = '{$escapedPermalink}'"
    );
    if (count($rows) > 1) {
        fail("More than one album uses permalink {$permalink}.");
    }
    if (count($rows) === 0) {
        if ($verifyOnly) {
            fail("Required era album {$name} is missing.");
        }
        $result = create_virtual_category(
            $name,
            null,
            ['status' => 'private', 'visible' => true, 'commentable' => false]
        );
        if (isset($result['error'])) {
            fail("Cannot create {$name}: {$result['error']}");
        }
        $id = (int) $result['id'];
        single_update(CATEGORIES_TABLE, ['permalink' => $permalink], ['id' => $id]);
        $rows = query2array('SELECT id, name, permalink, status, visible, commentable, id_uppercat FROM ' . CATEGORIES_TABLE . ' WHERE id = ' . $id);
    }

    $album = $rows[0];
    $expected = [
        'name' => $name,
        'permalink' => $permalink,
        'status' => 'private',
        'visible' => 'true',
        // Fail closed until ClassArchivePolicy enforces group-specific comments.
        'commentable' => 'false',
    ];
    foreach ($expected as $field => $value) {
        if ((string) $album[$field] !== $value) {
            fail("Album {$name} has unexpected {$field}; expected {$value}.");
        }
    }
    if ($album['id_uppercat'] !== null) {
        fail("Era album {$name} must remain a root album.");
    }
    return (int) $album['id'];
}

function ensureAlbumAcl(int $albumId, array $expectedGroupIds, bool $verifyOnly): void
{
    sort($expectedGroupIds, SORT_NUMERIC);
    $actual = array_map(
        'intval',
        query2array('SELECT group_id FROM ' . GROUP_ACCESS_TABLE . ' WHERE cat_id = ' . $albumId, null, 'group_id')
    );
    sort($actual, SORT_NUMERIC);

    if ($actual === $expectedGroupIds) {
        return;
    }
    if ($verifyOnly) {
        fail("Album {$albumId} group ACL differs from the locked baseline.");
    }

    pwg_query('DELETE FROM ' . GROUP_ACCESS_TABLE . ' WHERE cat_id = ' . $albumId);
    $rows = [];
    foreach ($expectedGroupIds as $groupId) {
        $rows[] = ['group_id' => $groupId, 'cat_id' => $albumId];
    }
    if ($rows !== []) {
        mass_inserts(GROUP_ACCESS_TABLE, ['group_id', 'cat_id'], $rows);
    }
}

function ensureConfig(array $expected, bool $verifyOnly): void
{
    global $conf;
    foreach ($expected as $key => $value) {
        if ($verifyOnly) {
            if (!array_key_exists($key, $conf) || $conf[$key] !== $value) {
                fail("Configuration {$key} differs from the locked private baseline.");
            }
            continue;
        }
        conf_update_param($key, $value, true);
    }
}

function ensureChineseLanguage(bool $verifyOnly): void
{
    $languageId = 'zh_CN';
    $languagePath = PIWIGO_ROOT . '/language/' . $languageId . '/common.lang.php';
    if (!is_file($languagePath)) {
        fail('Pinned Simplified Chinese language files are not installed.');
    }

    require_once PHPWG_ROOT_PATH . 'admin/include/languages.class.php';
    $languages = new languages();
    $languages->get_db_languages();

    if (!isset($languages->fs_languages[$languageId])) {
        fail('Piwigo cannot discover the Simplified Chinese language files.');
    }

    if (!isset($languages->db_languages[$languageId])) {
        if ($verifyOnly) {
            fail('Simplified Chinese is not active in the Piwigo language table.');
        }
        $errors = $languages->perform_action('activate', $languageId);
        if (!empty($errors)) {
            fail('Cannot activate Simplified Chinese: ' . implode('; ', $errors));
        }
        $languages->get_db_languages();
    }

    if (!isset($languages->db_languages[$languageId])) {
        fail('Simplified Chinese activation did not persist.');
    }

    if (!$verifyOnly) {
        $errors = $languages->perform_action('set_default', $languageId);
        if (!empty($errors)) {
            fail('Cannot set Simplified Chinese as the default language: ' . implode('; ', $errors));
        }

        // The private deployment intentionally starts with one language. Set
        // existing accounts as well so the admin, synthetic fixtures and guest
        // all become Chinese immediately; future accounts inherit the default.
        pwg_query(
            'UPDATE ' . USER_INFOS_TABLE
            . " SET language = '" . $languageId . "'"
        );

        // Core caches the default user's row during bootstrap. Drop only that
        // read cache so the verification below observes the committed value.
        global $cache;
        unset($cache['default_user']);
    }

    $nonChinese = query2array(
        'SELECT user_id, language FROM ' . USER_INFOS_TABLE
        . " WHERE language IS NULL OR language <> '" . $languageId . "'"
    );
    if ($nonChinese !== []) {
        fail('One or more Piwigo accounts are not using Simplified Chinese.');
    }

    if (get_default_language() !== $languageId) {
        fail('Simplified Chinese is not the active default language.');
    }
}

function ensureTheme(bool $verifyOnly): void
{
    $themeId = 'bootstrap_darkroom';
    $themePath = PIWIGO_ROOT . '/themes/' . $themeId . '/themeconf.inc.php';
    if (!is_file($themePath)) {
        fail('Pinned Bootstrap Darkroom theme is not installed.');
    }

    $active = get_pwg_themes();
    if (!array_key_exists($themeId, $active)) {
        if ($verifyOnly) {
            fail('Bootstrap Darkroom is not active.');
        }
        $themes = new themes();
        $errors = $themes->perform_action('activate', $themeId);
        if (!empty($errors)) {
            fail('Cannot activate Bootstrap Darkroom: ' . implode('; ', $errors));
        }
    }

    if (get_default_theme() !== $themeId) {
        if ($verifyOnly) {
            fail('Bootstrap Darkroom is not the default theme.');
        }
        $themes = $themes ?? new themes();
        $themes->set_default_theme($themeId);
    }
}

function ensureUnsafeExtensionsInactive(): void
{
    $unsafe = ['Community', 'UserCollections'];
    foreach ($unsafe as $pluginId) {
        $escaped = pwg_db_real_escape_string($pluginId);
        $row = fetchOne('SELECT id, state FROM ' . PLUGINS_TABLE . " WHERE id = '{$escaped}'");
        if ($row !== null && $row['state'] === 'active') {
            fail("{$pluginId} is active before its recorded security gate has been resolved.");
        }
    }
}

function ensureClassArchivePolicyActive(): void
{
    $row = fetchOne(
        "SELECT id, version, state FROM " . PLUGINS_TABLE . " WHERE id = 'ClassArchivePolicy'"
    );
    if (
        $row === null
        || $row['state'] !== 'active'
        || $row['version'] !== '0.1.0'
        || !is_file(PIWIGO_ROOT . '/plugins/ClassArchivePolicy/media-gateway.php')
    ) {
        fail('ClassArchivePolicy 0.1.0 must be installed and active.');
    }
}

function ensureModernAdminHash(): void
{
    $row = fetchOne('SELECT password FROM ' . USERS_TABLE . ' WHERE id = 1');
    if ($row === null || !is_string($row['password']) || !str_starts_with($row['password'], '$P$')) {
        fail('Administrator password has not been upgraded from the installer MD5 hash.');
    }
}

function main(array $argv): void
{
    $verifyOnly = false;
    $verifyV4SyntheticExistingRuntime = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--verify-only' && !$verifyOnly) {
            $verifyOnly = true;
            continue;
        }
        if ($argument === '--verify-v4-synthetic-existing-runtime' && !$verifyV4SyntheticExistingRuntime) {
            $verifyV4SyntheticExistingRuntime = true;
            continue;
        }
        fail("Unknown or duplicate argument: {$argument}");
    }
    if (
        $verifyV4SyntheticExistingRuntime
        && (
            !$verifyOnly
            || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION'
            || getenv('CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION') !== '1'
        )
    ) {
        fail('The existing-runtime verifier is restricted to the isolated V4 synthetic migration laboratory.');
    }

    ensureConfig([
        // Class Archive's Policy, Identity and Gateway hooks are the security
        // boundary. A fresh Piwigo installation can leave plugins disabled
        // even while their database rows say "active", which silently serves
        // the Core HTML shell instead of the fail-closed Gateway API.
        'enable_plugins' => true,
        'allow_user_registration' => false,
        'guest_access' => false,
        'comments_forall' => false,
        'comments_validation' => true,
        'rate' => false,
        'rate_anonymous' => false,
        'authorize_remembering' => false,
        'browser_language' => false,
        'newcat_default_status' => 'private',
        'newcat_default_commentable' => false,
        'inheritance_by_default' => true,
        'show_exif' => false,
        'original_url_protection' => 'images',
        'upload_form_all_types' => false,
        'enable_extensions_install' => false,
        'gallery_title' => 'Class Archive',
    ], $verifyOnly);

    ensureChineseLanguage($verifyOnly);

    ensureUnsafeExtensionsInactive();
    ensureClassArchivePolicyActive();
    ensureModernAdminHash();
    // A DB-only migration snapshot intentionally carries no theme files. The
    // synthetic laboratory verifies all business and ACL baseline records but
    // must not copy the owner runtime's theme directory just to pass this
    // schema/recovery proof. Normal bootstrap/verification still requires the
    // pinned theme exactly as before.
    if (!$verifyV4SyntheticExistingRuntime) {
        ensureTheme($verifyOnly);
    }

    $groups = [];
    foreach (['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS'] as $name) {
        $groups[$name] = ensureGroup($name, $verifyOnly);
    }

    $heritageId = ensureEraAlbum('高中档案', 'class-archive-heritage', $verifyOnly);
    $livingId = ensureEraAlbum('后来的我们', 'class-archive-living', $verifyOnly);
    ensureAlbumAcl($heritageId, array_values($groups), $verifyOnly);
    ensureAlbumAcl($livingId, [$groups['CLASSMATE'], $groups['TEACHER'], $groups['ANONYMOUS']], $verifyOnly);

    if (!$verifyOnly) {
        // Rebuild permission caches after group/album ACL changes.
        invalidate_user_cache();
    }

    if ($verifyV4SyntheticExistingRuntime) {
        fwrite(STDOUT, "BASELINE_SYNTHETIC_EXISTING_RUNTIME_VERIFIED\n");
        return;
    }
    fwrite(STDOUT, $verifyOnly ? "BASELINE_VERIFIED\n" : "BASELINE_CONFIGURED\n");
}

// Piwigo's bootstrap intentionally defines global state. Keep these includes
// at file scope; including them inside a helper would strand $conf, $user and
// the database handle in that helper's local symbol table.
preparePiwigoBootstrap();
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
$bootstrapOutput = ob_get_clean();
unset($bootstrapOutput);
if (!defined('PHPWG_VERSION') || PHPWG_VERSION !== REQUIRED_PIWIGO_VERSION) {
    fail('Expected Piwigo ' . REQUIRED_PIWIGO_VERSION . ', found ' . (defined('PHPWG_VERSION') ? PHPWG_VERSION : 'unknown') . '.');
}
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
require_once PHPWG_ROOT_PATH . 'admin/include/themes.class.php';

try {
    main($_SERVER['argv'] ?? []);
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
