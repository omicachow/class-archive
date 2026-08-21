<?php

declare(strict_types=1);

/**
 * Disposable localhost-only Phase 2.1 fixture.
 *
 * It imports only committed fictional AI-generated images, records every
 * affected Piwigo/ClassArchive row in an owner-only state file, and tears the
 * exact set back down. The normal 72-image deterministic baseline is both a
 * precondition and a post-cleanup assertion.
 */

const CI_PEOPLE_ROOT = '/var/www/html/piwigo';
const CI_PEOPLE_DATA = CI_PEOPLE_ROOT . '/_data';
const CI_PEOPLE_FIXTURE_FLAG = 'class_identity_immich_bridge_enabled';
const CI_PEOPLE_MARKER = 'CLASS_ARCHIVE_PHASE2_PEOPLE_SYNTHETIC';

function ciPeopleFail(string $code): never
{
    fwrite(STDERR, 'IMMICH_PEOPLE_FIXTURE=FAIL reason=' . $code . "\n");
    exit(1);
}

/** @param array<string,mixed> $value */
function ciPeopleJson(array $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function ciPeopleRun(string $run): string
{
    if (preg_match('/\A[a-f0-9]{16}\z/D', $run) !== 1) {
        ciPeopleFail('run_invalid');
    }
    return $run;
}

function ciPeopleStatePath(string $run): string
{
    return CI_PEOPLE_DATA . '/.class-archive-immich-people-' . ciPeopleRun($run) . '.json';
}

function ciPeopleBridgeSecretPath(): string
{
    return CI_PEOPLE_DATA . '/.class-archive-immich-bridge.json';
}

function ciPeopleRequireCli(): void
{
    if (
        PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || posix_geteuid() === 0
        || getenv('CLASS_ARCHIVE_ALLOW_IMMICH_PEOPLE_FIXTURE') !== '1'
        || !is_file('/workspace/tests/phase2/immich-people-fixture.php')
    ) {
        ciPeopleFail('test_gate_required');
    }
}

function ciPeopleConfigureRuntime(): void
{
    ciPeopleRequireCli();
    chdir(CI_PEOPLE_ROOT) || ciPeopleFail('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    define('PWG_API_KEY_REQUEST', true);
}

/** @param array<string,mixed> $value @param list<string> $keys */
function ciPeopleExactKeys(array $value, array $keys): bool
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    return $actual === $keys;
}

function ciPeopleAssertPrivate(string $path): void
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (
        !is_array($stat)
        || is_link($path)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($stat['nlink'] ?? 0) !== 1
        || (int) ($stat['uid'] ?? -1) !== posix_geteuid()
        || (int) ($stat['size'] ?? 0) < 2
        || (int) ($stat['size'] ?? 0) > 262144
    ) {
        ciPeopleFail('private_state_invalid');
    }
}

/** @param array<string,mixed> $state */
function ciPeopleWriteState(string $path, array $state): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $raw = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        ciPeopleFail('state_create_failed');
    }
    try {
        if (!chmod($temporary, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            ciPeopleFail('state_write_failed');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            ciPeopleFail('state_sync_failed');
        }
    } finally {
        fclose($handle);
    }
    ciPeopleAssertPrivate($temporary);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        ciPeopleFail('state_publish_failed');
    }
    ciPeopleAssertPrivate($path);
}

/** @param array<string,mixed> $state */
function ciPeopleReplaceState(string $path, array $state): void
{
    ciPeopleAssertPrivate($path);
    ciPeopleWriteState($path . '.next', $state);
    if (!rename($path . '.next', $path)) {
        @unlink($path . '.next');
        ciPeopleFail('state_replace_failed');
    }
    ciPeopleAssertPrivate($path);
}

/** @return array<string,mixed> */
function ciPeopleReadState(string $path, string $run): array
{
    ciPeopleAssertPrivate($path);
    $raw = file_get_contents($path);
    try {
        $state = is_string($raw) ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable) {
        $state = null;
    }
    if (
        !is_array($state)
        || !ciPeopleExactKeys($state, ['albums', 'baseline', 'config', 'images', 'run', 'stage', 'version'])
        || $state['version'] !== 1
        || $state['run'] !== $run
        || !in_array($state['stage'], ['PREPARING', 'PREPARED', 'BOUND'], true)
        || !is_array($state['albums'])
        || !is_array($state['images'])
        || !is_array($state['baseline'])
        || !is_array($state['config'])
    ) {
        ciPeopleFail('state_shape_invalid');
    }
    foreach (['image_count', 'original_count', 'person_count'] as $key) {
        if (!is_int($state['baseline'][$key] ?? null) || $state['baseline'][$key] < 0) {
            ciPeopleFail('state_baseline_invalid');
        }
    }
    if (!ciPeopleExactKeys($state['config'], ['present', 'value']) || !is_bool($state['config']['present']) || ($state['config']['present'] && !is_string($state['config']['value']))) {
        ciPeopleFail('state_config_invalid');
    }
    if (count($state['albums']) > 2 || count($state['images']) > 40) {
        ciPeopleFail('state_size_invalid');
    }
    return $state;
}

/** @return array{present:bool,value:?string} */
function ciPeopleConfig(ClassIdentity\Repository $repository): array
{
    global $prefixeTable;
    $row = $repository->fetchOne('SELECT `value` FROM `' . $prefixeTable . 'config` WHERE `param` = ? LIMIT 1', [CI_PEOPLE_FIXTURE_FLAG]);
    return $row === null ? ['present' => false, 'value' => null] : ['present' => true, 'value' => (string) $row['value']];
}

/** @param array{present:bool,value:?string} $config */
function ciPeopleRestoreConfig(ClassIdentity\Repository $repository, array $config): void
{
    global $prefixeTable;
    if ($config['present']) {
        $repository->execute(
            'INSERT INTO `' . $prefixeTable . 'config` (`param`,`value`,`comment`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`comment`=VALUES(`comment`)',
            [CI_PEOPLE_FIXTURE_FLAG, $config['value'], 'Class Archive synthetic Phase 2 test flag'],
        );
        return;
    }
    $repository->execute('DELETE FROM `' . $prefixeTable . 'config` WHERE `param` = ?', [CI_PEOPLE_FIXTURE_FLAG]);
}

function ciPeopleReadInput(): array
{
    $raw = stream_get_contents(STDIN, 262145);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 262144) {
        ciPeopleFail('input_invalid');
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    try {
        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        ciPeopleFail('input_json_invalid');
    }
    if (!is_array($value)) {
        ciPeopleFail('input_shape_invalid');
    }
    return $value;
}

function ciPeopleAlbum(int $parentId, string $name, string $permalink): int
{
    global $prefixeTable;
    $row = query2array('SELECT `id`,`id_uppercat`,`name`,`permalink` FROM `' . $prefixeTable . 'categories` WHERE `permalink` = \'' . pwg_db_real_escape_string($permalink) . '\'');
    if ($row !== []) {
        ciPeopleFail('fixture_album_collision');
    }
    $created = create_virtual_category($name, $parentId, ['status' => 'private', 'visible' => false, 'commentable' => false, 'inherit' => true]);
    if (!is_array($created) || !ctype_digit((string) ($created['id'] ?? null))) {
        ciPeopleFail('fixture_album_create_failed');
    }
    $id = (int) $created['id'];
    single_update(CATEGORIES_TABLE, ['permalink' => $permalink], ['id' => $id]);
    return $id;
}

function ciPeopleRootId(ClassIdentity\Repository $repository, string $permalink): int
{
    global $prefixeTable;
    $row = $repository->fetchOne('SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink` = ? LIMIT 1', [$permalink]);
    $id = $row === null ? 0 : (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        ciPeopleFail('archive_root_missing');
    }
    return $id;
}

/** @return array{0:int,1:int,2:int,3:int} */
function ciPeopleCrop(string $subject, int $width, int $height): array
{
    if ($subject === 'A') {
        return [0, 0, intdiv($width, 3), $height];
    }
    if ($subject === 'B') {
        return [intdiv($width, 3), 0, intdiv($width, 3), $height];
    }
    if ($subject === 'C') {
        return [intdiv($width, 3) * 2, 0, $width - intdiv($width, 3) * 2, $height];
    }
    return [0, 0, $width, $height];
}

function ciPeopleCreateVariant(string $source, string $target, string $subject, int $ordinal): void
{
    if (!is_file($source) || is_link($source) || !function_exists('imagecreatefrompng')) {
        ciPeopleFail('synthetic_source_unavailable');
    }
    $input = @imagecreatefrompng($source);
    if ($input === false) {
        ciPeopleFail('synthetic_source_invalid');
    }
    try {
        $sourceWidth = imagesx($input);
        $sourceHeight = imagesy($input);
        [$x, $y, $width, $height] = ciPeopleCrop($subject, $sourceWidth, $sourceHeight);
        if ($width < 200 || $height < 200) {
            ciPeopleFail('synthetic_crop_invalid');
        }
        $outWidth = $subject === 'SCENE' ? 1200 : 720;
        $outHeight = $subject === 'SCENE' ? 800 : 900;
        $output = imagecreatetruecolor($outWidth, $outHeight);
        if ($output === false) {
            ciPeopleFail('synthetic_variant_create_failed');
        }
        try {
            imagecopyresampled($output, $input, 0, 0, $x, $y, $outWidth, $outHeight, $width, $height);
            // A tiny deterministic footer prevents Piwigo content-deduplication
            // while preserving the fictional face/scene under test.
            $red = ($ordinal * 37) % 255;
            $green = ($ordinal * 73) % 255;
            $blue = ($ordinal * 109) % 255;
            $color = imagecolorallocate($output, $red, $green, $blue);
            imagefilledrectangle($output, 0, $outHeight - 8, $outWidth, $outHeight, $color);
            if (!imagepng($output, $target, 6)) {
                ciPeopleFail('synthetic_variant_write_failed');
            }
        } finally {
            imagedestroy($output);
        }
    } finally {
        imagedestroy($input);
    }
    chmod($target, 0660) || ciPeopleFail('synthetic_variant_mode_failed');
}

/** @return array{date:?string,precision:string,source:string,event:?string} */
function ciPeopleArchiveProjection(string $kind, int $ordinal): array
{
    if ($kind === 'PLAYGROUND') {
        return ['date' => null, 'precision' => 'TERM', 'source' => 'EVENT_INFERENCE', 'event' => '2023年秋季运动会'];
    }
    if ($kind === 'CLASSROOM') {
        return ['date' => null, 'precision' => 'EVENT_ONLY', 'source' => 'EVENT_INFERENCE', 'event' => '高二班级活动'];
    }
    if ($kind === 'NIGHT') {
        return ['date' => null, 'precision' => 'UNKNOWN', 'source' => 'UNKNOWN', 'event' => null];
    }
    if ($kind === 'OUTDOOR') {
        return ['date' => null, 'precision' => 'EVENT_ONLY', 'source' => 'EVENT_INFERENCE', 'event' => '毕业后户外活动'];
    }
    $precision = ['EXACT', 'DAY', 'MONTH', 'YEAR'][$ordinal % 4];
    $date = sprintf('2023-%02d-%02d', ($ordinal % 10) + 1, ($ordinal % 24) + 1);
    return ['date' => $date, 'precision' => $precision, 'source' => 'ARCHIVE_CONFIRMED', 'event' => null];
}

/** @return list<array{era:string,source:string,subject:string,title:string,kind:string}> */
function ciPeoplePlan(): array
{
    $plan = [];
    foreach ([['A', 'HERITAGE', 8], ['A', 'LIVING', 5], ['B', 'HERITAGE', 6], ['C', 'HERITAGE', 2], ['C', 'LIVING', 7]] as [$subject, $era, $count]) {
        for ($index = 1; $index <= $count; ++$index) {
            $plan[] = ['subject' => $subject, 'era' => $era, 'source' => 'fictional-cast-portraits.png', 'kind' => 'PORTRAIT', 'title' => '合成人物 ' . $subject . ' 测试照片 ' . $index];
        }
    }
    foreach ([
        ['HERITAGE', 'fictional-cast-playground.png', 'PLAYGROUND', '合成操场集体照'],
        ['HERITAGE', 'fictional-cast-classroom.png', 'CLASSROOM', '合成教室黑板篮球活动'],
        ['LIVING', 'fictional-cast-night-cake.png', 'NIGHT', '合成夜晚蛋糕集体照'],
        ['LIVING', 'fictional-cast-outdoor.png', 'OUTDOOR', '合成户外活动集体照'],
    ] as [$era, $source, $kind, $title]) {
        $plan[] = ['subject' => 'SCENE', 'era' => $era, 'source' => $source, 'kind' => $kind, 'title' => $title];
    }
    return $plan;
}

function ciPeopleOriginalCount(): int
{
    $count = 0;
    foreach ([CI_PEOPLE_ROOT . '/upload', CI_PEOPLE_ROOT . '/galleries'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            // Piwigo containers keep harmless index.htm sentinels beneath the
            // upload tree. They are not originals and must not make the
            // canonical 72-original precondition appear dirty. The fixture
            // only imports the explicitly allowed image types below.
            $extension = strtolower((string) pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if ($file->isFile() && !$file->isLink() && in_array($extension, ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'], true)) {
                ++$count;
            }
        }
    }
    return $count;
}

function ciPeopleWriteBridgeSecret(string $token): void
{
    if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $token) !== 1) {
        ciPeopleFail('bridge_token_invalid');
    }
    $path = ciPeopleBridgeSecretPath();
    if (file_exists($path) || is_link($path)) {
        ciPeopleFail('bridge_secret_already_exists');
    }
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        ciPeopleFail('bridge_secret_create_failed');
    }
    try {
        $raw = json_encode(['version' => 1, 'token' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!chmod($path, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            ciPeopleFail('bridge_secret_write_failed');
        }
    } finally {
        fclose($handle);
    }
    ciPeopleAssertPrivate($path);
}

function ciPeoplePrepare(string $run): never
{
    global $prefixeTable, $user;
    $repository = ClassIdentity\Repository::fromPiwigo();
    $statePath = ciPeopleStatePath($run);
    if (file_exists($statePath) || is_link($statePath) || file_exists(ciPeopleBridgeSecretPath()) || is_link(ciPeopleBridgeSecretPath())) {
        ciPeopleFail('fixture_not_clean');
    }
    $config = ciPeopleConfig($repository);
    if ($config['present'] && !in_array($config['value'], ['', '0', 'false'], true)) {
        ciPeopleFail('bridge_not_disabled');
    }
    $imageCount = (int) query2array('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`')[0]['count'];
    $originalCount = ciPeopleOriginalCount();
    $personCount = (int) $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $repository->table('person') . '`')['count'];
    if ($imageCount !== 72 || $originalCount !== 72 || $personCount !== 0) {
        ciPeopleFail('deterministic_baseline_required');
    }
    $heritage = ciPeopleRootId($repository, 'class-archive-heritage');
    $living = ciPeopleRootId($repository, 'class-archive-living');
    $state = [
        'version' => 1,
        'run' => $run,
        'stage' => 'PREPARING',
        'config' => $config,
        'baseline' => ['image_count' => $imageCount, 'original_count' => $originalCount, 'person_count' => $personCount],
        'albums' => [],
        'images' => [],
    ];
    ciPeopleWriteState($statePath, $state);
    $temporary = [];
    try {
        $state['albums'] = [
            ['id' => ciPeopleAlbum($heritage, 'Phase 2 人物测试：班级历史', 'phase2-people-heritage-' . $run), 'permalink' => 'phase2-people-heritage-' . $run],
            ['id' => ciPeopleAlbum($living, 'Phase 2 人物测试：毕业后动态', 'phase2-people-living-' . $run), 'permalink' => 'phase2-people-living-' . $run],
        ];
        ciPeopleReplaceState($statePath, $state);
        $mapping = new ClassIdentity\ClassArchivePhotoMappingService($repository);
        $fixtures = '/workspace/tests/fixtures/phase2-synthetic';
        $ordinal = 0;
        foreach (ciPeoplePlan() as $item) {
            ++$ordinal;
            $tmp = '/tmp/class-archive-people-' . $run . '-' . sprintf('%02d', $ordinal) . '.png';
            $temporary[] = $tmp;
            ciPeopleCreateVariant($fixtures . '/' . $item['source'], $tmp, $item['subject'], $ordinal);
            $album = $item['era'] === 'HERITAGE' ? (int) $state['albums'][0]['id'] : (int) $state['albums'][1]['id'];
            $imageId = add_uploaded_file($tmp, basename($tmp), [$album], 0);
            if (!is_int($imageId) && !ctype_digit((string) $imageId)) {
                ciPeopleFail('piwigo_import_failed');
            }
            $imageId = (int) $imageId;
            $projection = ciPeopleArchiveProjection($item['kind'], $ordinal);
            single_update(
                IMAGES_TABLE,
                [
                    'name' => $item['title'],
                    'author' => CI_PEOPLE_MARKER . ':' . $run,
                    'comment' => 'Synthetic fictional AI test asset only; no real person or class archive media.',
                ],
                ['id' => $imageId],
            );
            $row = $repository->fetchOne('SELECT `path` FROM `' . $prefixeTable . 'images` WHERE `id` = ? LIMIT 1', [$imageId]);
            $reference = is_array($row) && is_string($row['path'] ?? null) ? ClassIdentity\ClassArchivePhoto::normalizeMediaReference($row['path']) : null;
            if ($reference === null) {
                ciPeopleFail('piwigo_reference_invalid');
            }
            $fullPath = CI_PEOPLE_ROOT . '/' . $reference;
            clearstatcache(true, $fullPath);
            $stat = @lstat($fullPath);
            if (!is_array($stat) || is_link($fullPath) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
                ciPeopleFail('imported_original_invalid');
            }
            chmod($fullPath, 0660) || ciPeopleFail('imported_original_mode_failed');
            clearstatcache(true, $fullPath);
            $stat = @lstat($fullPath);
            if (!is_array($stat) || (($stat['mode'] ?? 0) & 0777) !== 0660) {
                ciPeopleFail('imported_original_mode_invalid');
            }
            $checksum = hash_file('sha256', $fullPath);
            if (!is_string($checksum)) {
                ciPeopleFail('imported_checksum_failed');
            }
            $canonical = $mapping->ensurePiwigoMapping($imageId, $checksum, $reference);
            $repository->execute(
                'INSERT INTO `' . $repository->table('archive_image') . '` '
                . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`date_source`,`event_label`,`official`,`created_at`,`updated_at`) '
                . 'VALUES (?, ?, ?, ?, \'HIGH\', ?, ?, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                [$imageId, $item['era'], $projection['date'], $projection['precision'], $projection['source'], $projection['event']],
            );
            $state['images'][] = [
                'id' => $imageId,
                'class_photo_id' => (string) $canonical['class_photo_id'],
                'era' => $item['era'],
                'media_reference' => $reference,
                'immich_asset_id' => null,
            ];
            ciPeopleReplaceState($statePath, $state);
        }
        $state['stage'] = 'PREPARED';
        ciPeopleReplaceState($statePath, $state);
        invalidate_user_cache();
        ciPeopleJson(['ok' => true, 'run' => $run, 'photos' => array_map(static fn (array $photo): array => ['class_photo_id' => $photo['class_photo_id'], 'era' => $photo['era'], 'media_reference' => $photo['media_reference']], $state['images'])]);
    } finally {
        foreach ($temporary as $path) {
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
    }
}

function ciPeopleBind(string $run): never
{
    $path = ciPeopleStatePath($run);
    $state = ciPeopleReadState($path, $run);
    if ($state['stage'] !== 'PREPARED') {
        ciPeopleFail('bind_state_invalid');
    }
    $input = ciPeopleReadInput();
    if (!ciPeopleExactKeys($input, ['assets', 'version']) || $input['version'] !== 1 || !is_array($input['assets']) || count($input['assets']) !== count($state['images'])) {
        ciPeopleFail('bind_input_invalid');
    }
    $bindings = [];
    foreach ($input['assets'] as $asset) {
        if (!is_array($asset) || !ciPeopleExactKeys($asset, ['class_photo_id', 'immich_asset_id'])) {
            ciPeopleFail('bind_input_invalid');
        }
        try {
            $id = ClassIdentity\ClassArchivePhoto::idToBinary((string) ($asset['class_photo_id'] ?? ''));
            unset($id);
            $immich = ClassIdentity\ClassArchivePhoto::normalizeImmichAssetId((string) ($asset['immich_asset_id'] ?? ''));
        } catch (Throwable) {
            ciPeopleFail('bind_input_invalid');
        }
        if ($immich === null || isset($bindings[$asset['class_photo_id']])) {
            ciPeopleFail('bind_input_invalid');
        }
        $bindings[$asset['class_photo_id']] = $immich;
    }
    $repository = ClassIdentity\Repository::fromPiwigo();
    $repository->transaction(function (ClassIdentity\Repository $repository) use (&$state, $bindings): void {
        foreach ($state['images'] as &$photo) {
            $id = ClassIdentity\ClassArchivePhoto::idToBinary((string) $photo['class_photo_id']);
            $asset = $bindings[$photo['class_photo_id']] ?? null;
            if (!is_string($asset)) {
                throw new RuntimeException('binding_missing');
            }
            $changed = $repository->execute(
                'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = ?, `updated_at` = UTC_TIMESTAMP(6) '
                . 'WHERE `class_photo_id` = ? AND `state` = \'ACTIVE\' AND `immich_asset_id` IS NULL',
                [$asset, $id],
            );
            if ($changed !== 1) {
                throw new RuntimeException('binding_race');
            }
            $photo['immich_asset_id'] = $asset;
        }
        unset($photo);
    });
    $state['stage'] = 'BOUND';
    ciPeopleReplaceState($path, $state);
    ciPeopleJson(['ok' => true, 'run' => $run]);
}

function ciPeopleDescribe(string $run): never
{
    $state = ciPeopleReadState(ciPeopleStatePath($run), $run);
    // These are already synthetic Piwigo references and opaque Class Archive
    // UUIDs; returning them lets the isolated Immich runner consume the
    // current fixture without reading the private state file or its config.
    ciPeopleJson([
        'ok' => true,
        'run' => $run,
        'stage' => $state['stage'],
        'photos' => array_map(
            static fn (array $photo): array => [
                'class_photo_id' => (string) $photo['class_photo_id'],
                'era' => (string) $photo['era'],
                'media_reference' => (string) $photo['media_reference'],
            ],
            $state['images'],
        ),
    ]);
}

function ciPeopleEnable(string $run): never
{
    $path = ciPeopleStatePath($run);
    $state = ciPeopleReadState($path, $run);
    if ($state['stage'] !== 'BOUND') {
        ciPeopleFail('enable_state_invalid');
    }
    $input = ciPeopleReadInput();
    if (!ciPeopleExactKeys($input, ['token', 'version']) || $input['version'] !== 1 || !is_string($input['token'])) {
        ciPeopleFail('enable_input_invalid');
    }
    $repository = ClassIdentity\Repository::fromPiwigo();
    if (ciPeopleConfig($repository) !== $state['config']) {
        ciPeopleFail('enable_config_drift');
    }
    ciPeopleWriteBridgeSecret($input['token']);
    global $prefixeTable;
    try {
        $repository->execute(
            'INSERT INTO `' . $prefixeTable . 'config` (`param`,`value`,`comment`) VALUES (?, \'1\', ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`comment`=VALUES(`comment`)',
            [CI_PEOPLE_FIXTURE_FLAG, 'Class Archive synthetic Phase 2 test flag'],
        );
    } catch (Throwable $error) {
        @unlink(ciPeopleBridgeSecretPath());
        throw $error;
    }
    ciPeopleJson(['ok' => true, 'run' => $run]);
}

function ciPeopleCleanup(string $run): never
{
    $path = ciPeopleStatePath($run);
    if (!file_exists($path) && !is_link($path)) {
        ciPeopleJson(['ok' => true, 'absent' => true, 'run' => $run]);
    }
    $state = ciPeopleReadState($path, $run);
    $repository = ClassIdentity\Repository::fromPiwigo();
    ciPeopleRestoreConfig($repository, $state['config']);
    $secret = ciPeopleBridgeSecretPath();
    if (file_exists($secret) || is_link($secret)) {
        ciPeopleAssertPrivate($secret);
        @unlink($secret) || ciPeopleFail('bridge_secret_cleanup_failed');
    }
    $ids = array_map(static fn (array $photo): int => (int) $photo['id'], $state['images']);
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $repository->transaction(function (ClassIdentity\Repository $repository) use ($ids, $placeholders): void {
            $repository->execute('DELETE FROM `' . $repository->table('archive_image') . '` WHERE `piwigo_image_id` IN (' . $placeholders . ')', $ids);
            $repository->execute('DELETE FROM `' . $repository->table('photo') . '` WHERE `piwigo_image_id` IN (' . $placeholders . ')', $ids);
            // The fixture requires an empty mapping table before setup; any
            // ClassArchivePerson row was therefore created solely from this
            // disposable Immich database and is safe to remove now.
            $repository->execute('DELETE FROM `' . $repository->table('person') . '`');
        });
        delete_elements($ids, true);
    }
    foreach (array_reverse($state['albums']) as $album) {
        if (!is_array($album) || !ctype_digit((string) ($album['id'] ?? null)) || !is_string($album['permalink'] ?? null)) {
            ciPeopleFail('cleanup_album_state_invalid');
        }
        global $prefixeTable;
        $row = $repository->fetchOne('SELECT `id`,`permalink` FROM `' . $prefixeTable . 'categories` WHERE `id` = ? LIMIT 1', [(int) $album['id']]);
        if ($row === null || !hash_equals((string) $album['permalink'], (string) ($row['permalink'] ?? ''))) {
            ciPeopleFail('cleanup_album_drift');
        }
        delete_categories([(int) $album['id']], 'no_delete');
    }
    if (!unlink($path) || file_exists($path) || is_link($path)) {
        ciPeopleFail('state_cleanup_failed');
    }
    $imageCount = (int) query2array('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`')[0]['count'];
    $originalCount = ciPeopleOriginalCount();
    $personCount = (int) $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $repository->table('person') . '`')['count'];
    if ($imageCount !== $state['baseline']['image_count'] || $originalCount !== $state['baseline']['original_count'] || $personCount !== $state['baseline']['person_count']) {
        ciPeopleFail('cleanup_baseline_mismatch');
    }
    invalidate_user_cache();
    ciPeopleJson(['ok' => true, 'absent' => false, 'run' => $run]);
}

$action = (string) ($_SERVER['argv'][1] ?? '');
$run = ciPeopleRun((string) ($_SERVER['argv'][2] ?? ''));
ciPeopleConfigureRuntime();
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
$user = build_user(1, false);
if (($user['status'] ?? null) !== 'webmaster' || !class_exists(ClassIdentity\ClassArchivePhotoMappingService::class) || !class_exists(ClassIdentity\ClassArchivePersonMappingService::class) || !ClassIdentity\Access::isEnforcementEnabled()) {
    ciPeopleFail('active_runtime_required');
}

try {
    match ($action) {
        'prepare' => ciPeoplePrepare($run),
        'describe' => ciPeopleDescribe($run),
        'bind' => ciPeopleBind($run),
        'enable' => ciPeopleEnable($run),
        'cleanup' => ciPeopleCleanup($run),
        default => ciPeopleFail('action_invalid'),
    };
} catch (Throwable) {
    ciPeopleFail('unexpected');
}
