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
    $mode = is_array($stat) ? ((int) ($stat['mode'] ?? 0) & 0777) : 0;
    $groupModeIsSafe = $mode === 0660
        && function_exists('posix_getegid')
        && (int) ($stat['gid'] ?? -1) === posix_getegid();
    if (
        !is_array($stat)
        || is_link($path)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        // The production Piwigo lifecycle hook normalizes its private state
        // tree to 0660 for the service account and its sole service group.
        // Accept that exact documented policy alongside 0600, never a world
        // readable mode or an arbitrary shared group.
        || !($mode === 0600 || $groupModeIsSafe)
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
        || !ciPeopleExactKeys($state, ['albums', 'baseline', 'baseline_bindings', 'catalog', 'config', 'images', 'run', 'stage', 'version'])
        || $state['version'] !== 1
        || $state['run'] !== $run
        || !in_array($state['stage'], ['PREPARING', 'PREPARED', 'BOUND'], true)
        || !is_array($state['albums'])
        || !is_array($state['baseline_bindings'])
        || !is_array($state['catalog'])
        || !is_array($state['images'])
        || !is_array($state['baseline'])
        || !is_array($state['config'])
    ) {
        ciPeopleFail('state_shape_invalid');
    }
    foreach (['image_count', 'original_count', 'person_count', 'photo_mapping_count', 'immich_binding_count'] as $key) {
        if (!is_int($state['baseline'][$key] ?? null) || $state['baseline'][$key] < 0) {
            ciPeopleFail('state_baseline_invalid');
        }
    }
    if (!ciPeopleExactKeys($state['config'], ['present', 'value']) || !is_bool($state['config']['present']) || ($state['config']['present'] && !is_string($state['config']['value']))) {
        ciPeopleFail('state_config_invalid');
    }
    if (count($state['albums']) > 2 || count($state['images']) > 40 || count($state['catalog']) > 120 || count($state['baseline_bindings']) > 80) {
        ciPeopleFail('state_size_invalid');
    }
    foreach ($state['baseline_bindings'] as $binding) {
        if (!is_array($binding) || !ciPeopleExactKeys($binding, ['class_photo_id', 'immich_asset_id']) || !is_string($binding['class_photo_id']) || $binding['immich_asset_id'] !== null) {
            ciPeopleFail('state_baseline_binding_invalid');
        }
        try {
            ClassIdentity\ClassArchivePhoto::idToBinary($binding['class_photo_id']);
        } catch (Throwable) {
            ciPeopleFail('state_baseline_binding_invalid');
        }
    }
    foreach ($state['catalog'] as $photo) {
        if (!is_array($photo) || !ciPeopleExactKeys($photo, ['class_photo_id', 'era', 'fixture', 'immich_asset_id', 'media_reference', 'piwigo_image_id']) || !is_string($photo['class_photo_id']) || !is_string($photo['media_reference']) || !is_bool($photo['fixture']) || !is_int($photo['piwigo_image_id']) || $photo['piwigo_image_id'] <= 0 || !in_array($photo['era'], ['HERITAGE', 'LIVING'], true) || ($photo['immich_asset_id'] !== null && !is_string($photo['immich_asset_id']))) {
            ciPeopleFail('state_catalog_invalid');
        }
        try {
            ClassIdentity\ClassArchivePhoto::idToBinary($photo['class_photo_id']);
            ClassIdentity\ClassArchivePhoto::normalizeMediaReference($photo['media_reference']);
            if ($photo['immich_asset_id'] !== null && ClassIdentity\ClassArchivePhoto::normalizeImmichAssetId($photo['immich_asset_id']) === null) {
                ciPeopleFail('state_catalog_invalid');
            }
        } catch (Throwable) {
            ciPeopleFail('state_catalog_invalid');
        }
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
    // `private` is the authorization boundary. Piwigo's separate
    // `visible=false` flag is a hard lock which would make MediaGuard deny
    // even a role with inherited private-category access.
    $created = create_virtual_category($name, $parentId, ['status' => 'private', 'visible' => true, 'commentable' => false, 'inherit' => true]);
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
        // Match the durable Piwigo private-file lifecycle: service-owned
        // 0660 plus the inherited nginx ACL, never world-readable. The
        // production adapter validates the exact owner/group/parent before
        // accepting this form and still fails closed on any mismatch.
        if (!chmod($path, 0660) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
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
    $baselineRows = $repository->fetchAll(
        'SELECT `class_photo_id`,`piwigo_image_id`,`immich_asset_id`,`state` FROM `' . $repository->table('photo') . '` ORDER BY `piwigo_image_id` ASC',
    );
    $baselineBindings = [];
    foreach ($baselineRows as $row) {
        $binaryId = $row['class_photo_id'] ?? null;
        $piwigoImageId = (int) ($row['piwigo_image_id'] ?? 0);
        if (!is_string($binaryId) || $piwigoImageId <= 0 || ($row['state'] ?? null) !== ClassIdentity\ClassArchivePhoto::STATE_ACTIVE || $row['immich_asset_id'] !== null) {
            ciPeopleFail('deterministic_mapping_baseline_required');
        }
        $baselineBindings[] = [
            'class_photo_id' => ClassIdentity\ClassArchivePhoto::binaryToId($binaryId),
            // The deterministic baseline intentionally has no Immich index.
            // Keeping this explicit makes cleanup restore the precise state
            // rather than merely deleting the fixture images.
            'immich_asset_id' => null,
        ];
    }
    if ($imageCount !== 72 || $originalCount !== 72 || $personCount !== 0 || count($baselineRows) !== 72 || count($baselineBindings) !== 72) {
        ciPeopleFail('deterministic_baseline_required');
    }
    $heritage = ciPeopleRootId($repository, 'class-archive-heritage');
    $living = ciPeopleRootId($repository, 'class-archive-living');
    $state = [
        'version' => 1,
        'run' => $run,
        'stage' => 'PREPARING',
        'config' => $config,
        'baseline' => [
            'image_count' => $imageCount,
            'original_count' => $originalCount,
            'person_count' => $personCount,
            'photo_mapping_count' => count($baselineRows),
            'immich_binding_count' => 0,
        ],
        'baseline_bindings' => $baselineBindings,
        'albums' => [],
        'catalog' => [],
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
                'fixture_kind' => $item['kind'],
                'fixture_subject' => $item['subject'],
                'immich_asset_id' => null,
            ];
            ciPeopleReplaceState($statePath, $state);
        }
        // Force the canonical projection once after every imported fixture has
        // its archive association. This creates/validates every Class Archive
        // UUID for the whole 72+32 library, not merely the faces under test.
        // The resulting temporary all-library Immich binding is what makes a
        // runtime People/Search request prove the actual Gateway path instead
        // of accidentally testing a 32-photo partial index.
        $candidates = ClassIdentity\Gateway\PiwigoGatewayAdapter::fromPiwigo()->photoCandidates();
        if (count($candidates) !== $imageCount + count($state['images'])) {
            ciPeopleFail('canonical_catalog_count_invalid');
        }
        $fixtureIds = [];
        foreach ($state['images'] as $image) {
            $fixtureIds[(string) $image['class_photo_id']] = true;
        }
        $catalogRows = $repository->fetchAll(
            'SELECT `class_photo_id`,`piwigo_image_id`,`media_reference`,`immich_asset_id`,`state` FROM `' . $repository->table('photo') . '` WHERE `state` = ? AND `piwigo_image_id` IS NOT NULL',
            [ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
        );
        $catalogById = [];
        foreach ($catalogRows as $row) {
            $binaryId = $row['class_photo_id'] ?? null;
            $reference = $row['media_reference'] ?? null;
            if (!is_string($binaryId) || !is_string($reference) || $row['immich_asset_id'] !== null) {
                ciPeopleFail('canonical_catalog_mapping_invalid');
            }
            $classPhotoId = ClassIdentity\ClassArchivePhoto::binaryToId($binaryId);
            if (isset($catalogById[$classPhotoId])) {
                ciPeopleFail('canonical_catalog_mapping_invalid');
            }
            $catalogById[$classPhotoId] = [
                'piwigo_image_id' => (int) ($row['piwigo_image_id'] ?? 0),
                'media_reference' => ClassIdentity\ClassArchivePhoto::normalizeMediaReference($reference),
            ];
        }
        foreach ($candidates as $candidate) {
            $classPhotoId = $candidate->id();
            $catalog = $catalogById[$classPhotoId] ?? null;
            if (!is_array($catalog) || $candidate->era() === null || $candidate->piwigoImageIdForDelivery() !== $catalog['piwigo_image_id']) {
                ciPeopleFail('canonical_catalog_projection_invalid');
            }
            $state['catalog'][] = [
                'class_photo_id' => $classPhotoId,
                'piwigo_image_id' => $catalog['piwigo_image_id'],
                'era' => $candidate->era(),
                'media_reference' => $catalog['media_reference'],
                'fixture' => isset($fixtureIds[$classPhotoId]),
                'immich_asset_id' => null,
            ];
        }
        if (count($state['catalog']) !== $imageCount + count($state['images'])) {
            ciPeopleFail('canonical_catalog_projection_invalid');
        }
        $state['stage'] = 'PREPARED';
        ciPeopleReplaceState($statePath, $state);
        invalidate_user_cache();
        ciPeopleJson(['ok' => true, 'run' => $run, 'photos' => array_map(static fn (array $photo): array => [
            'class_photo_id' => $photo['class_photo_id'],
            'era' => $photo['era'],
            'media_reference' => $photo['media_reference'],
            'fixture_kind' => $photo['fixture_kind'],
            'fixture_subject' => $photo['fixture_subject'],
        ], $state['images']), 'catalog' => array_map(static fn (array $photo): array => [
            'class_photo_id' => $photo['class_photo_id'],
            'era' => $photo['era'],
            'media_reference' => $photo['media_reference'],
        ], $state['catalog'])]);
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
    if (!ciPeopleExactKeys($input, ['assets', 'version']) || $input['version'] !== 1 || !is_array($input['assets']) || count($input['assets']) !== count($state['catalog'])) {
        ciPeopleFail('bind_input_invalid');
    }
    $expected = [];
    foreach ($state['catalog'] as $photo) {
        $expected[(string) $photo['class_photo_id']] = true;
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
        if ($immich === null || !isset($expected[$asset['class_photo_id']]) || isset($bindings[$asset['class_photo_id']])) {
            ciPeopleFail('bind_input_invalid');
        }
        $bindings[$asset['class_photo_id']] = $immich;
    }
    if (count($bindings) !== count($expected)) {
        ciPeopleFail('bind_input_invalid');
    }
    $repository = ClassIdentity\Repository::fromPiwigo();
    $repository->transaction(function (ClassIdentity\Repository $repository) use (&$state, $bindings): void {
        foreach ($state['catalog'] as &$photo) {
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
        foreach ($state['images'] as &$photo) {
            $asset = $bindings[$photo['class_photo_id']] ?? null;
            if (!is_string($asset)) {
                throw new RuntimeException('binding_missing');
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
                'fixture_kind' => (string) ($photo['fixture_kind'] ?? ''),
                'fixture_subject' => (string) ($photo['fixture_subject'] ?? ''),
            ],
            $state['images'],
        ),
        'catalog' => array_map(
            static fn (array $photo): array => [
                'class_photo_id' => (string) $photo['class_photo_id'],
                'era' => (string) $photo['era'],
                'media_reference' => (string) $photo['media_reference'],
            ],
            $state['catalog'],
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

function ciPeopleProbe(string $run): never
{
    $state = ciPeopleReadState(ciPeopleStatePath($run), $run);
    if ($state['stage'] !== 'BOUND') {
        ciPeopleFail('probe_state_invalid');
    }
    $ids = array_map(static fn (array $photo): string => (string) $photo['class_photo_id'], $state['catalog']);
    try {
        $adapter = ClassIdentity\Gateway\BridgeImmichAdapter::configuredOrNull();
        if ($adapter->availability() !== 'AVAILABLE') {
            ciPeopleJson(['ok' => false, 'code' => 'adapter_unavailable']);
        }
        $people = $adapter->peopleForVisiblePhotos($ids);
        ciPeopleJson(['ok' => true, 'people' => count($people), 'run' => $run]);
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
        ciPeopleJson(['ok' => false, 'code' => $allowed[$code] ?? 'adapter_failed']);
    } catch (Throwable) {
        ciPeopleJson(['ok' => false, 'code' => 'adapter_failed']);
    }
}

function ciPeopleRequestProbe(string $run): never
{
    // This action intentionally runs as the nginx request account instead of
    // the durable Piwigo owner. It proves the HTTP-equivalent service account
    // can read a documented inherited-ACL bridge secret without exposing the
    // secret or any upstream identifier.
    $input = ciPeopleReadInput();
    if (!ciPeopleExactKeys($input, ['class_photo_ids', 'version']) || $input['version'] !== 1 || !is_array($input['class_photo_ids']) || count($input['class_photo_ids']) < 1 || count($input['class_photo_ids']) > 500) {
        ciPeopleFail('request_probe_input_invalid');
    }
    $ids = [];
    foreach ($input['class_photo_ids'] as $id) {
        if (!is_string($id) || isset($ids[$id])) {
            ciPeopleFail('request_probe_input_invalid');
        }
        try {
            ClassIdentity\ClassArchivePhoto::idToBinary($id);
        } catch (Throwable) {
            ciPeopleFail('request_probe_input_invalid');
        }
        $ids[$id] = true;
    }
    try {
        $adapter = ClassIdentity\Gateway\BridgeImmichAdapter::configuredOrNull();
        if ($adapter->availability() !== 'AVAILABLE') {
            ciPeopleJson(['ok' => false, 'code' => 'adapter_unavailable']);
        }
        $people = $adapter->peopleForVisiblePhotos(array_keys($ids));
        ciPeopleJson(['ok' => true, 'people' => count($people), 'run' => $run]);
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
        ciPeopleJson(['ok' => false, 'code' => $allowed[$code] ?? 'adapter_failed']);
    } catch (Throwable) {
        ciPeopleJson(['ok' => false, 'code' => 'adapter_failed']);
    }
}

function ciPeopleMediaProbe(string $run): never
{
    // The compatibility BFF must never make a People cover a weaker media
    // path than the rest of Class Archive.  Exercise the real MediaGuard
    // decision with the exact imported images before a browser is allowed to
    // request any thumbnail.  This is deliberately CLI-only and returns only
    // aggregate synthetic assertions -- never a Piwigo id, path or decision
    // reason that could become a public probing oracle.
    $state = ciPeopleReadState(ciPeopleStatePath($run), $run);
    // The policy is independent of the optional Immich binding. Allow the
    // preflight at PREPARED so an ACL regression can be diagnosed before the
    // expensive isolated ML import starts; the full runtime runner repeats it
    // after BOUND to cover the complete chain.
    if (!in_array($state['stage'], ['PREPARED', 'BOUND'], true) || !class_exists('ClassArchiveMediaGuard', false)) {
        ciPeopleFail('media_probe_runtime_unavailable');
    }

    $images = [];
    foreach ($state['images'] as $image) {
        if (!is_array($image) || !in_array($image['era'] ?? null, ['HERITAGE', 'LIVING'], true)) {
            ciPeopleFail('media_probe_state_invalid');
        }
        $era = (string) $image['era'];
        if (!isset($images[$era])) {
            $images[$era] = (int) ($image['id'] ?? 0);
        }
    }
    if (($images['HERITAGE'] ?? 0) <= 0 || ($images['LIVING'] ?? 0) <= 0) {
        ciPeopleFail('media_probe_state_invalid');
    }

    $roles = [
        'CLASSMATE' => 'fixture-classmate',
        'TEACHER' => 'fixture-teacher',
        'FAMILY' => 'fixture-family',
        'ANONYMOUS' => 'fixture-anonymous',
    ];
    $previousUser = $GLOBALS['user'] ?? null;
    $checks = 0;
    $outcome = 'ok';
    try {
        foreach ($roles as $role => $username) {
            $userId = (int) get_userid($username);
            if ($userId <= 0) {
                $outcome = 'principal_unavailable';
                break;
            }
            $candidateUser = build_user($userId, false);
            if (!is_array($candidateUser) || (int) ($candidateUser['id'] ?? 0) !== $userId) {
                $outcome = 'principal_unavailable';
                break;
            }
            $GLOBALS['user'] = $candidateUser;
            foreach ($images as $era => $imageId) {
                try {
                    $resolved = ClassArchiveMediaGuard::resolveCanonicalDelivery($imageId, 'thumbnail');
                    $decision = ClassArchiveMediaGuard::authorize($resolved['request'], $resolved['image']);
                } catch (Throwable) {
                    $outcome = 'resolution_failed';
                    break 2;
                }
                $expected = !($role === 'FAMILY' && $era === 'LIVING');
                if (!is_bool($decision->allowed ?? null) || $decision->allowed !== $expected) {
                    // Role and era are non-secret fixture assertions. Keeping
                    // this narrow label makes a failed policy preflight
                    // diagnosable without printing an image id, path or the
                    // MediaGuard reason.
                    $reason = is_string($decision->reason ?? null) && preg_match('/\A[a-z_]{1,48}\z/D', $decision->reason) === 1
                        ? $decision->reason
                        : 'invalid_reason';
                    $outcome = 'policy_' . strtolower($role) . '_' . strtolower($era) . '_' . $reason;
                    break 2;
                }
                ++$checks;
            }
        }
    } finally {
        if ($previousUser === null) {
            unset($GLOBALS['user']);
        } else {
            $GLOBALS['user'] = $previousUser;
        }
    }

    ciPeopleJson(['ok' => $outcome === 'ok', 'code' => $outcome, 'checks' => $checks, 'run' => $run]);
}

/**
 * Reversible fault injection against one disposable fixture image.  The
 * state file already owns that row and cleanup deletes it even if the outer
 * HTTP assertion aborts, so neither mode can mutate the canonical 72-image
 * baseline.  No identifier is returned to the terminal.
 */
function ciPeopleFault(string $run, string $mode, bool $restore, string $requestedClassPhotoId): never
{
    global $prefixeTable;
    $state = ciPeopleReadState(ciPeopleStatePath($run), $run);
    if ($state['stage'] !== 'BOUND') {
        ciPeopleFail('fault_state_invalid');
    }
    $photo = null;
    try {
        ClassIdentity\ClassArchivePhoto::idToBinary($requestedClassPhotoId);
    } catch (Throwable) {
        ciPeopleFail('fault_target_invalid');
    }
    foreach ($state['catalog'] as $candidate) {
        if (($candidate['fixture'] ?? false) === true
            && hash_equals((string) ($candidate['class_photo_id'] ?? ''), $requestedClassPhotoId)
            && is_string($candidate['immich_asset_id'] ?? null)) {
            $photo = $candidate;
            break;
        }
    }
    if (!is_array($photo)) {
        ciPeopleFail('fault_target_unavailable');
    }
    $repository = ClassIdentity\Repository::fromPiwigo();
    $imageId = (int) $photo['piwigo_image_id'];
    $classPhotoId = ClassIdentity\ClassArchivePhoto::idToBinary((string) $photo['class_photo_id']);
    $immichAssetId = ClassIdentity\ClassArchivePhoto::normalizeImmichAssetId((string) $photo['immich_asset_id']);
    if ($imageId <= 0 || $immichAssetId === null) {
        ciPeopleFail('fault_target_invalid');
    }

    if ($mode === 'mapping') {
        $changed = $restore
            ? $repository->execute(
                'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = ?, `updated_at` = UTC_TIMESTAMP(6) WHERE `class_photo_id` = ? AND `immich_asset_id` IS NULL',
                [$immichAssetId, $classPhotoId],
            )
            : $repository->execute(
                'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = NULL, `updated_at` = UTC_TIMESTAMP(6) WHERE `class_photo_id` = ? AND `immich_asset_id` = ?',
                [$classPhotoId, $immichAssetId],
            );
        if ($changed !== 1) {
            ciPeopleFail('fault_mapping_transition_invalid');
        }
    } elseif ($mode === 'era') {
        $oppositeIndex = $photo['era'] === 'HERITAGE' ? 1 : 0;
        $categoryId = (int) ($state['albums'][$oppositeIndex]['id'] ?? 0);
        if ($categoryId <= 0) {
            ciPeopleFail('fault_era_target_invalid');
        }
        $changed = $restore
            ? $repository->execute(
                'DELETE FROM `' . $prefixeTable . 'image_category` WHERE `image_id` = ? AND `category_id` = ?',
                [$imageId, $categoryId],
            )
            : $repository->execute(
                'INSERT INTO `' . $prefixeTable . 'image_category` (`image_id`,`category_id`,`rank`) VALUES (?, ?, NULL)',
                [$imageId, $categoryId],
            );
        if ($changed !== 1) {
            ciPeopleFail('fault_era_transition_invalid');
        }
        invalidate_user_cache();
    } else {
        ciPeopleFail('fault_mode_invalid');
    }
    ciPeopleJson(['ok' => true, 'mode' => $mode, 'restored' => $restore, 'run' => $run]);
}

function ciPeopleFaultRequest(string $run, string $mode, bool $restore): never
{
    $input = ciPeopleReadInput();
    if (!ciPeopleExactKeys($input, ['class_photo_id', 'version']) || $input['version'] !== 1 || !is_string($input['class_photo_id'])) {
        ciPeopleFail('fault_input_invalid');
    }
    ciPeopleFault($run, $mode, $restore, $input['class_photo_id']);
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
    $repository->transaction(function (ClassIdentity\Repository $repository) use (&$state, $ids): void {
        // A BOUND run temporarily associates every canonical 72+32 photo
        // with a disposable Immich asset. Restore the pre-run baseline before
        // removing only the 32 imported records. This makes cleanup prove
        // that gateway integration did not leave a hidden external index on
        // otherwise canonical Piwigo-first data.
        if ($state['stage'] === 'BOUND') {
            $catalog = [];
            foreach ($state['catalog'] as $photo) {
                $catalog[(string) $photo['class_photo_id']] = $photo;
            }
            foreach ($state['baseline_bindings'] as $binding) {
                $classPhotoId = (string) $binding['class_photo_id'];
                $catalogPhoto = $catalog[$classPhotoId] ?? null;
                if (!is_array($catalogPhoto) || !is_string($catalogPhoto['immich_asset_id'] ?? null)) {
                    throw new RuntimeException('cleanup_binding_state_invalid');
                }
                $changed = $repository->execute(
                    'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = ?, `updated_at` = UTC_TIMESTAMP(6) '
                    . 'WHERE `class_photo_id` = ? AND `state` = ? AND `piwigo_image_id` = ?',
                    [$binding['immich_asset_id'], ClassIdentity\ClassArchivePhoto::idToBinary($classPhotoId), ClassIdentity\ClassArchivePhoto::STATE_ACTIVE, (int) $catalogPhoto['piwigo_image_id']],
                );
                if ($changed !== 1) {
                    throw new RuntimeException('cleanup_binding_restore_failed');
                }
            }
        }
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $repository->execute('DELETE FROM `' . $repository->table('archive_image') . '` WHERE `piwigo_image_id` IN (' . $placeholders . ')', $ids);
            $repository->execute('DELETE FROM `' . $repository->table('photo') . '` WHERE `piwigo_image_id` IN (' . $placeholders . ')', $ids);
        }
        // The fixture requires no baseline ClassArchivePerson record. Any
        // row here was derived solely from this disposable Immich database,
        // not from the preserved canonical mapping rows, and is safe to drop.
        $repository->execute('DELETE FROM `' . $repository->table('person') . '`');
    });
    if ($ids !== []) {
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
    $mappingRow = $repository->fetchOne(
        'SELECT COUNT(*) AS `count`, COALESCE(SUM(CASE WHEN `immich_asset_id` IS NOT NULL THEN 1 ELSE 0 END), 0) AS `bound` FROM `' . $repository->table('photo') . '`',
    );
    $mappingCount = (int) ($mappingRow['count'] ?? -1);
    $bindingCount = (int) ($mappingRow['bound'] ?? -1);
    if ($imageCount !== $state['baseline']['image_count'] || $originalCount !== $state['baseline']['original_count'] || $personCount !== $state['baseline']['person_count'] || $mappingCount !== $state['baseline']['photo_mapping_count'] || $bindingCount !== $state['baseline']['immich_binding_count']) {
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
if (($user['status'] ?? null) !== 'webmaster' || !class_exists(ClassIdentity\ClassArchivePhotoMappingService::class) || !class_exists(ClassIdentity\ClassArchivePersonMappingService::class) || !class_exists(ClassIdentity\Gateway\PiwigoGatewayAdapter::class) || !ClassIdentity\Access::isEnforcementEnabled()) {
    ciPeopleFail('active_runtime_required');
}

try {
    match ($action) {
        'prepare' => ciPeoplePrepare($run),
        'describe' => ciPeopleDescribe($run),
        'bind' => ciPeopleBind($run),
        'enable' => ciPeopleEnable($run),
        'probe' => ciPeopleProbe($run),
        'request-probe' => ciPeopleRequestProbe($run),
        'media-probe' => ciPeopleMediaProbe($run),
        'fault-mapping-start' => ciPeopleFaultRequest($run, 'mapping', false),
        'fault-mapping-stop' => ciPeopleFaultRequest($run, 'mapping', true),
        'fault-era-start' => ciPeopleFaultRequest($run, 'era', false),
        'fault-era-stop' => ciPeopleFaultRequest($run, 'era', true),
        'cleanup' => ciPeopleCleanup($run),
        default => ciPeopleFail('action_invalid'),
    };
} catch (Throwable $error) {
    // This CLI fixture is explicitly gated and synthetic-only.  Keep failure
    // diagnostics actionable without printing arbitrary exception messages,
    // which could contain a path, a credential-bearing DSN, or input data.
    $class = preg_replace('/[^A-Za-z0-9_\\\\]/', '_', get_class($error));
    fwrite(STDERR, 'IMMICH_PEOPLE_FIXTURE_DIAGNOSTIC class=' . $class . ' line=' . $error->getLine() . "\n");
    ciPeopleFail('unexpected');
}
