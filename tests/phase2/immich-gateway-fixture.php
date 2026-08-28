<?php

declare(strict_types=1);

/**
 * Narrow, test-only Piwigo-side state fixture for the Phase 2 Immich bridge.
 *
 * It never creates a Piwigo user, image, original or public route. It picks
 * one pre-existing synthetic HERITAGE and LIVING canonical mapping, records
 * their prior null Immich bindings in an owner-mode state file, and restores
 * that exact state in finally. The feature flag remains disabled unless a
 * per-exec test gate, matching state file and private bridge token all exist.
 */

const CI_IMMICH_FIXTURE_ROOT = '/var/www/html/piwigo';
const CI_IMMICH_FIXTURE_DATA = CI_IMMICH_FIXTURE_ROOT . '/_data';
const CI_IMMICH_FIXTURE_FLAG = 'class_identity_immich_bridge_enabled';

function fixtureFail(string $code): never
{
    fwrite(STDERR, 'IMMICH_GATEWAY_FIXTURE=FAIL reason=' . $code . "\n");
    exit(1);
}

/** @param array<string,mixed> $value */
function fixtureJson(array $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function fixtureRun(string $run): string
{
    if (preg_match('/\A[a-f0-9]{16}\z/D', $run) !== 1) {
        fixtureFail('run_invalid');
    }
    return $run;
}

function fixtureStatePath(string $run): string
{
    return CI_IMMICH_FIXTURE_DATA . '/.class-archive-immich-gateway-' . fixtureRun($run) . '.json';
}

function fixtureSecretPath(): string
{
    return CI_IMMICH_FIXTURE_DATA . '/.class-archive-immich-bridge.json';
}

function fixtureRequireCli(): void
{
    if (
        PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || posix_geteuid() === 0
        || getenv('CLASS_ARCHIVE_ALLOW_IMMICH_GATEWAY_FIXTURE') !== '1'
        || !is_file('/workspace/tests/phase2/immich-gateway-fixture.php')
    ) {
        fixtureFail('test_gate_required');
    }
}

function fixtureConfigureRuntime(): void
{
    fixtureRequireCli();
    chdir(CI_IMMICH_FIXTURE_ROOT) || fixtureFail('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    // This test control plane has no HTTP session and never calls a Piwigo
    // web-service method. Mark its CLI bootstrap as stateless before common
    // initializes user/session redirects; Access still independently checks
    // the active ClassIdentity runtime below.
    define('PWG_API_KEY_REQUEST', true);
}

/** @return array<string,mixed> */
function fixtureReadInput(): array
{
    $raw = stream_get_contents(STDIN, 131073);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 131072) {
        fixtureFail('input_invalid');
    }
    // Windows PowerShell 5.1 native stdin framing can prepend UTF-8 BOM.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        fixtureFail('input_json_invalid');
    }
    if (!is_array($value)) {
        fixtureFail('input_shape_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $value @param list<string> $keys */
function fixtureExactKeys(array $value, array $keys): bool
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    return $actual === $keys;
}

function fixtureAssertOwnedMode(string $path, bool $requireExists = true): void
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        if ($requireExists) {
            fixtureFail('private_file_missing');
        }
        return;
    }
    if (
        is_link($path)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || (int) ($stat['uid'] ?? -1) !== posix_geteuid()
        || (int) ($stat['size'] ?? 0) < 2
        || (int) ($stat['size'] ?? 0) > 65536
    ) {
        fixtureFail('private_file_invalid');
    }
}

/** @param array<string,mixed> $state */
function fixtureWriteState(string $path, array $state): void
{
    if (file_exists($path) || is_link($path)) {
        fixtureFail('state_already_exists');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $raw = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        fixtureFail('state_temporary_create_failed');
    }
    try {
        if (!chmod($temporary, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            fixtureFail('state_write_failed');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            fixtureFail('state_sync_failed');
        }
    } finally {
        fclose($handle);
    }
    fixtureAssertOwnedMode($temporary);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        fixtureFail('state_publish_failed');
    }
    fixtureAssertOwnedMode($path);
}

/** @param array<string,mixed> $state */
function fixtureReplaceState(string $path, array $state): void
{
    fixtureAssertOwnedMode($path);
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $raw = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        fixtureFail('state_temporary_create_failed');
    }
    try {
        if (!chmod($temporary, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            fixtureFail('state_write_failed');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            fixtureFail('state_sync_failed');
        }
    } finally {
        fclose($handle);
    }
    fixtureAssertOwnedMode($temporary);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        fixtureFail('state_publish_failed');
    }
    fixtureAssertOwnedMode($path);
}

/** @return array<string,mixed> */
function fixtureReadState(string $path, string $run): array
{
    fixtureAssertOwnedMode($path);
    $raw = file_get_contents($path);
    try {
        $state = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable) {
        $state = null;
    }
    if (!is_array($state) || !fixtureExactKeys($state, ['config', 'photos', 'run', 'stage', 'version'])) {
        fixtureFail('state_shape_invalid');
    }
    if ($state['version'] !== 1 || $state['run'] !== $run || !in_array($state['stage'], ['PREPARED', 'BINDING', 'BOUND'], true)) {
        fixtureFail('state_value_invalid');
    }
    if (!is_array($state['config']) || !fixtureExactKeys($state['config'], ['present', 'value']) || !is_bool($state['config']['present'])) {
        fixtureFail('state_config_invalid');
    }
    if ($state['config']['present'] && !is_string($state['config']['value'])) {
        fixtureFail('state_config_invalid');
    }
    if (!$state['config']['present'] && $state['config']['value'] !== null) {
        fixtureFail('state_config_invalid');
    }
    if (!is_array($state['photos']) || count($state['photos']) < 2 || count($state['photos']) > 500) {
        fixtureFail('state_photos_invalid');
    }
    $eras = [];
    $ids = [];
    $references = [];
    foreach ($state['photos'] as $photo) {
        if (!is_array($photo) || !fixtureExactKeys($photo, ['bound_immich_asset_id', 'class_photo_id', 'era', 'media_reference'])) {
            fixtureFail('state_photo_invalid');
        }
        if (
            !is_string($photo['class_photo_id'])
            || !is_string($photo['media_reference'])
            || !in_array($photo['era'], ['HERITAGE', 'LIVING'], true)
            || !is_null($photo['bound_immich_asset_id']) && !is_string($photo['bound_immich_asset_id'])
        ) {
            fixtureFail('state_photo_invalid');
        }
        try {
            ClassIdentity\ClassArchivePhoto::idToBinary($photo['class_photo_id']);
            ClassIdentity\ClassArchivePhoto::normalizeMediaReference($photo['media_reference']);
            if ($photo['bound_immich_asset_id'] !== null) {
                ClassIdentity\ClassArchivePhoto::normalizeImmichAssetId($photo['bound_immich_asset_id']);
            }
        } catch (Throwable) {
            fixtureFail('state_photo_invalid');
        }
        if (isset($ids[$photo['class_photo_id']]) || isset($references[$photo['media_reference']])) {
            fixtureFail('state_photo_duplicate');
        }
        $ids[$photo['class_photo_id']] = true;
        $references[$photo['media_reference']] = true;
        $eras[$photo['era']] = true;
    }
    if (!isset($eras['HERITAGE'], $eras['LIVING'])) {
        fixtureFail('state_photo_era_invalid');
    }
    return $state;
}

/** @return array{present:bool,value:?string} */
function fixtureConfigState(ClassIdentity\Repository $repository): array
{
    global $prefixeTable;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        fixtureFail('piwigo_prefix_invalid');
    }
    $row = $repository->fetchOne(
        'SELECT `value` FROM `' . $prefixeTable . 'config` WHERE `param` = ? LIMIT 2',
        [CI_IMMICH_FIXTURE_FLAG],
    );
    return $row === null
        ? ['present' => false, 'value' => null]
        : ['present' => true, 'value' => (string) ($row['value'] ?? '')];
}

function fixtureConfigIsDisabled(array $config): bool
{
    return !$config['present'] || in_array($config['value'], ['', '0', 'false'], true);
}

/** @param array{present:bool,value:?string} $config */
function fixtureRestoreConfig(ClassIdentity\Repository $repository, array $config): void
{
    global $prefixeTable;
    if ($config['present']) {
        $repository->execute(
            'INSERT INTO `' . $prefixeTable . 'config` (`param`,`value`,`comment`) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `comment` = VALUES(`comment`)',
            [CI_IMMICH_FIXTURE_FLAG, $config['value'], 'Class Archive Immich bridge runtime test flag'],
        );
    } else {
        $repository->execute('DELETE FROM `' . $prefixeTable . 'config` WHERE `param` = ?', [CI_IMMICH_FIXTURE_FLAG]);
    }
}

function fixtureSetEnabled(ClassIdentity\Repository $repository): void
{
    global $prefixeTable;
    $repository->execute(
        'INSERT INTO `' . $prefixeTable . 'config` (`param`,`value`,`comment`) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `comment` = VALUES(`comment`)',
        [CI_IMMICH_FIXTURE_FLAG, '1', 'Class Archive Immich bridge runtime test flag'],
    );
}

/** @return array{class_photo_id:string,media_reference:string,era:string} */
function fixtureCandidates(ClassIdentity\ClassArchivePhotoMappingService $mapping): array
{
    $result = [];
    $eras = [];
    foreach (ClassIdentity\Gateway\PiwigoGatewayAdapter::fromPiwigo()->photoCandidates() as $candidate) {
        if (!$candidate instanceof ClassIdentity\Gateway\GatewayPhotoCandidate || !in_array($candidate->era(), ['HERITAGE', 'LIVING'], true)) {
            continue;
        }
        $mapped = $mapping->findByClassPhotoId($candidate->id());
        if (
            $mapped === null
            || ($mapped['state'] ?? null) !== ClassIdentity\ClassArchivePhoto::STATE_ACTIVE
            || ($mapped['immich_asset_id'] ?? null) !== null
            || !is_string($mapped['media_reference'] ?? null)
        ) {
            fixtureFail('canonical_mapping_not_clean');
        }
        $id = $candidate->id();
        if (isset($result[$id])) {
            fixtureFail('canonical_mapping_duplicate');
        }
        $result[$id] = [
            'class_photo_id' => $candidate->id(),
            'media_reference' => $mapped['media_reference'],
            'era' => $candidate->era(),
        ];
        $eras[$candidate->era()] = true;
    }
    if (count($result) < 2 || count($result) > 500 || !isset($eras['HERITAGE'], $eras['LIVING'])) {
        fixtureFail('synthetic_candidate_unavailable');
    }
    return array_values($result);
}

function fixtureSnapshot(string $run): never
{
    $repository = ClassIdentity\Repository::fromPiwigo();
    $config = fixtureConfigState($repository);
    if (!fixtureConfigIsDisabled($config) || file_exists(fixtureSecretPath()) || is_link(fixtureSecretPath())) {
        fixtureFail('bridge_not_cleanly_disabled');
    }
    $mapping = new ClassIdentity\ClassArchivePhotoMappingService($repository);
    $photos = fixtureCandidates($mapping);
    fixtureWriteState(fixtureStatePath($run), [
        'version' => 1,
        'run' => $run,
        'stage' => 'PREPARED',
        'config' => $config,
        'photos' => array_map(static fn (array $photo): array => $photo + ['bound_immich_asset_id' => null], $photos),
    ]);
    fixtureJson(['ok' => true, 'run' => $run, 'photos' => $photos]);
}

/** @return array<string,string> */
function fixtureInputBindings(array $input, array $state): array
{
    if (!fixtureExactKeys($input, ['assets', 'version']) || $input['version'] !== 1 || !is_array($input['assets']) || count($input['assets']) !== count($state['photos'])) {
        fixtureFail('binding_input_invalid');
    }
    $expected = [];
    foreach ($state['photos'] as $photo) {
        $expected[$photo['class_photo_id']] = true;
    }
    $result = [];
    foreach ($input['assets'] as $asset) {
        if (!is_array($asset) || !fixtureExactKeys($asset, ['class_photo_id', 'immich_asset_id'])) {
            fixtureFail('binding_input_invalid');
        }
        try {
            $id = ClassIdentity\ClassArchivePhoto::idToBinary((string) ($asset['class_photo_id'] ?? ''));
            unset($id);
            $assetId = ClassIdentity\ClassArchivePhoto::normalizeImmichAssetId((string) ($asset['immich_asset_id'] ?? ''));
        } catch (Throwable) {
            fixtureFail('binding_input_invalid');
        }
        $classPhotoId = (string) $asset['class_photo_id'];
        if (!isset($expected[$classPhotoId]) || isset($result[$classPhotoId]) || $assetId === null) {
            fixtureFail('binding_input_invalid');
        }
        $result[$classPhotoId] = $assetId;
    }
    if (count($result) !== count($expected)) {
        fixtureFail('binding_input_invalid');
    }
    return $result;
}

function fixtureBind(string $run): never
{
    $path = fixtureStatePath($run);
    $state = fixtureReadState($path, $run);
    if ($state['stage'] !== 'PREPARED') {
        fixtureFail('binding_state_invalid');
    }
    $bindings = fixtureInputBindings(fixtureReadInput(), $state);
    foreach ($state['photos'] as &$photo) {
        $photo['bound_immich_asset_id'] = $bindings[$photo['class_photo_id']];
    }
    unset($photo);
    $state['stage'] = 'BINDING';
    fixtureReplaceState($path, $state);

    $repository = ClassIdentity\Repository::fromPiwigo();
    $repository->transaction(function (ClassIdentity\Repository $repository) use ($state): void {
        foreach ($state['photos'] as $photo) {
            $id = ClassIdentity\ClassArchivePhoto::idToBinary($photo['class_photo_id']);
            $row = $repository->fetchOne(
                'SELECT `state`,`immich_asset_id` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id` = ? FOR UPDATE',
                [$id],
            );
            if ($row === null || ($row['state'] ?? null) !== ClassIdentity\ClassArchivePhoto::STATE_ACTIVE || $row['immich_asset_id'] !== null) {
                throw new RuntimeException('mapping_precondition_drift');
            }
            $changed = $repository->execute(
                'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = ?, `updated_at` = UTC_TIMESTAMP(6) '
                . 'WHERE `class_photo_id` = ? AND `state` = ? AND `immich_asset_id` IS NULL',
                [$photo['bound_immich_asset_id'], $id, ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
            );
            if ($changed !== 1) {
                throw new RuntimeException('mapping_bind_race');
            }
        }
    });
    $state['stage'] = 'BOUND';
    fixtureReplaceState($path, $state);
    fixtureJson(['ok' => true, 'run' => $run]);
}

function fixtureWriteBridgeSecret(string $token): void
{
    if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $token) !== 1) {
        fixtureFail('bridge_token_invalid');
    }
    $path = fixtureSecretPath();
    if (file_exists($path) || is_link($path)) {
        fixtureFail('bridge_secret_already_exists');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $raw = json_encode(['version' => 1, 'token' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        fixtureFail('bridge_secret_create_failed');
    }
    try {
        if (!chmod($temporary, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            fixtureFail('bridge_secret_write_failed');
        }
    } finally {
        fclose($handle);
    }
    fixtureAssertOwnedMode($temporary);
    rename($temporary, $path) || fixtureFail('bridge_secret_publish_failed');
    fixtureAssertOwnedMode($path);
}

function fixtureEnable(string $run): never
{
    $path = fixtureStatePath($run);
    $state = fixtureReadState($path, $run);
    if ($state['stage'] !== 'BOUND') {
        fixtureFail('enable_state_invalid');
    }
    $input = fixtureReadInput();
    if (!fixtureExactKeys($input, ['token', 'version']) || $input['version'] !== 1 || !is_string($input['token'])) {
        fixtureFail('enable_input_invalid');
    }
    $repository = ClassIdentity\Repository::fromPiwigo();
    if (fixtureConfigState($repository) !== $state['config']) {
        fixtureFail('enable_config_drift');
    }
    fixtureWriteBridgeSecret($input['token']);
    try {
        fixtureSetEnabled($repository);
    } catch (Throwable $error) {
        @unlink(fixtureSecretPath());
        throw $error;
    } finally {
        unset($input);
    }
    fixtureJson(['ok' => true, 'run' => $run]);
}

/**
 * Test-only direct adapter probe. This runs in a separate short-lived CLI
 * process after enable, so Piwigo reloads the exact database configuration.
 * It deliberately returns only a small allowlisted machine code; no bridge
 * response, canonical id, asset id, path, token or upstream detail can leave
 * the isolated fixture.
 */
function fixtureProbe(string $run): never
{
    $state = fixtureReadState(fixtureStatePath($run), $run);
    if ($state['stage'] !== 'BOUND') {
        fixtureJson(['ok' => false, 'code' => 'state_invalid']);
    }
    $repository = ClassIdentity\Repository::fromPiwigo();
    $config = fixtureConfigState($repository);
    if (!$config['present'] || $config['value'] !== '1') {
        fixtureJson(['ok' => false, 'code' => 'config_invalid']);
    }
    $ids = array_map(static fn (array $photo): string => (string) $photo['class_photo_id'], $state['photos']);
    try {
        $adapter = ClassIdentity\Gateway\BridgeImmichAdapter::configuredOrNull();
        if ($adapter->availability() !== 'AVAILABLE') {
            fixtureJson(['ok' => false, 'code' => 'adapter_unavailable']);
        }
        $items = $adapter->memoriesForVisiblePhotos($ids);
        if (count($items) > 500) {
            fixtureJson(['ok' => false, 'code' => 'response_invalid']);
        }
    } catch (RuntimeException $error) {
        $code = $error->getMessage();
        $allowed = [
            'class_archive_immich_bridge_binding_invalid' => 'binding_invalid',
            'class_archive_immich_bridge_enablement_invalid' => 'enablement_invalid',
            'class_archive_immich_bridge_response_invalid' => 'response_invalid',
            'class_archive_immich_bridge_secret_unavailable' => 'secret_unavailable',
            'class_archive_immich_bridge_transport_unavailable' => 'transport_unavailable',
            'class_archive_immich_bridge_unavailable' => 'upstream_unavailable',
        ];
        fixtureJson(['ok' => false, 'code' => $allowed[$code] ?? 'adapter_failed']);
    } catch (Throwable) {
        fixtureJson(['ok' => false, 'code' => 'adapter_failed']);
    }
    fixtureJson(['ok' => true, 'run' => $run]);
}

function fixtureCleanup(string $run): never
{
    $path = fixtureStatePath($run);
    if (!file_exists($path) && !is_link($path)) {
        if (file_exists(fixtureSecretPath()) || is_link(fixtureSecretPath())) {
            fixtureFail('orphan_bridge_secret');
        }
        fixtureJson(['ok' => true, 'run' => $run, 'absent' => true]);
    }
    $state = fixtureReadState($path, $run);
    $repository = ClassIdentity\Repository::fromPiwigo();
    // Disable first, so no web request can observe a bridge config while
    // restoration is underway or a secret is being removed.
    fixtureRestoreConfig($repository, $state['config']);
    $secret = fixtureSecretPath();
    if (file_exists($secret) || is_link($secret)) {
        fixtureAssertOwnedMode($secret);
        unlink($secret) || fixtureFail('bridge_secret_cleanup_failed');
        if (file_exists($secret) || is_link($secret)) {
            fixtureFail('bridge_secret_cleanup_unproven');
        }
    }
    if ($state['stage'] !== 'PREPARED') {
        $repository->transaction(function (ClassIdentity\Repository $repository) use ($state): void {
            foreach ($state['photos'] as $photo) {
                $id = ClassIdentity\ClassArchivePhoto::idToBinary($photo['class_photo_id']);
                $bound = $photo['bound_immich_asset_id'];
                $row = $repository->fetchOne(
                    'SELECT `state`,`immich_asset_id` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id` = ? FOR UPDATE',
                    [$id],
                );
                if ($row === null || ($row['state'] ?? null) !== ClassIdentity\ClassArchivePhoto::STATE_ACTIVE) {
                    throw new RuntimeException('mapping_cleanup_drift');
                }
                $current = $row['immich_asset_id'] === null ? null : (string) $row['immich_asset_id'];
                if ($current === null) {
                    continue;
                }
                if (!is_string($bound) || !hash_equals($bound, $current)) {
                    throw new RuntimeException('mapping_cleanup_drift');
                }
                $changed = $repository->execute(
                    'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = NULL, `updated_at` = UTC_TIMESTAMP(6) '
                    . 'WHERE `class_photo_id` = ? AND `state` = ? AND `immich_asset_id` = ?',
                    [$id, ClassIdentity\ClassArchivePhoto::STATE_ACTIVE, $bound],
                );
                if ($changed !== 1) {
                    throw new RuntimeException('mapping_cleanup_race');
                }
            }
        });
    }
    unlink($path) || fixtureFail('state_cleanup_failed');
    if (file_exists($path) || is_link($path)) {
        fixtureFail('state_cleanup_unproven');
    }
    fixtureJson(['ok' => true, 'run' => $run, 'absent' => false]);
}

$action = (string) ($_SERVER['argv'][1] ?? '');
$run = fixtureRun((string) ($_SERVER['argv'][2] ?? ''));
fixtureConfigureRuntime();
// Piwigo's common bootstrap populates many globals. Include it from this file's
// global scope, rather than a helper function, so Core and plugin event hooks
// receive the same process-wide state as the existing integration fixtures.
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
if (
    !class_exists(ClassIdentity\Repository::class)
    || !class_exists(ClassIdentity\ClassArchivePhotoMappingService::class)
    || !class_exists(ClassIdentity\Gateway\PiwigoGatewayAdapter::class)
    || !ClassIdentity\Access::isEnforcementEnabled()
) {
    fixtureFail('active_class_identity_required');
}

try {
    match ($action) {
        'snapshot' => fixtureSnapshot($run),
        'bind' => fixtureBind($run),
        'enable' => fixtureEnable($run),
        'probe' => fixtureProbe($run),
        'cleanup' => fixtureCleanup($run),
        default => fixtureFail('action_invalid'),
    };
} catch (Throwable $error) {
    fixtureFail('unexpected');
}
