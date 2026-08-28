<?php

declare(strict_types=1);

/**
 * Local browser-only Phase 3.2 fixture. It adds synthetic curation metadata to
 * an already isolated QA catalog. It never prints a photo id, path, filename,
 * checksum, person mapping, credential, or source label from the catalog.
 */

const PIWIGO_ROOT = '/var/www/html/piwigo';

function fixtureFail(string $code): never
{
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'unexpected';
    }
    fwrite(STDERR, "PHOTO_PRODUCT_BROWSER_FIXTURE=FAIL code={$code}\n");
    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fixtureFail('root_forbidden');
}

chdir(PIWIGO_ROOT) || fixtureFail('piwigo_root_unavailable');
define('PHPWG_ROOT_PATH', './');
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

try {
    if (!class_exists(\ClassIdentity\AlbumService::class)
        || !class_exists(\ClassIdentity\CanonicalPhotoService::class)
        || !class_exists(\ClassIdentity\Access::class)
    ) {
        fixtureFail('domain_unavailable');
    }
    global $conf, $prefixeTable;
    $adminUserId = (int) ($conf['webmaster_id'] ?? 0);
    $classmateUserId = (int) get_userid('fixture-classmate');
    $admin = \ClassIdentity\Access::resolveAuthorizationContext($adminUserId);
    $classmate = \ClassIdentity\Access::resolveAuthorizationContext($classmateUserId);
    if (($admin['role'] ?? null) !== 'SYSTEM_ADMIN' || ($classmate['role'] ?? null) !== 'CLASSMATE') {
        fixtureFail('principal_invalid');
    }
    $ownerPrincipalId = (int) ($classmate['principal_id'] ?? 0);
    if ($ownerPrincipalId <= 0) {
        fixtureFail('owner_invalid');
    }

    $repository = \ClassIdentity\Repository::fromPiwigo();
    $root = $repository->fetchOne(
        "SELECT `id` FROM `{$prefixeTable}categories` WHERE `permalink`='class-archive-heritage' LIMIT 1",
    );
    $rootId = (int) ($root['id'] ?? 0);
    if ($rootId <= 0) {
        fixtureFail('heritage_root_missing');
    }

    $permalink = 'class-archive-phase32-browser-fixture';
    $category = $repository->fetchOne(
        "SELECT `id`,`id_uppercat` FROM `{$prefixeTable}categories` WHERE `permalink`=? LIMIT 1",
        [$permalink],
    );
    if ($category === null) {
        $created = create_virtual_category('Phase 3.2 本地验收相册', $rootId, [
            'status' => 'private',
            'visible' => true,
            'commentable' => false,
            'inherit' => true,
            'comment' => '本地浏览器验收专用；不包含真实人物命名。',
        ]);
        if (!is_array($created) || !ctype_digit((string) ($created['id'] ?? null))) {
            fixtureFail('album_create_failed');
        }
        $categoryId = (int) $created['id'];
        single_update(CATEGORIES_TABLE, ['permalink' => $permalink], ['id' => $categoryId]);
    } else {
        $categoryId = (int) ($category['id'] ?? 0);
        if ($categoryId <= 0 || (int) ($category['id_uppercat'] ?? 0) !== $rootId) {
            fixtureFail('album_collision');
        }
    }

    $photos = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`piwigo_image_id`,p.`media_checksum`,i.`path` '
            . 'FROM `' . $repository->table('photo') . '` p '
            . 'JOIN `' . $repository->table('archive_image') . '` a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
            . "JOIN `{$prefixeTable}images` i ON i.`id`=p.`piwigo_image_id` "
            . "WHERE p.`state`='ACTIVE' AND a.`era`='HERITAGE' ORDER BY p.`piwigo_image_id` LIMIT 100",
    );
    if (count($photos) < 3) {
        fixtureFail('heritage_photos_missing');
    }
    $imageIds = array_map(static fn(array $row): int => (int) $row['piwigo_image_id'], array_slice($photos, 0, 3));
    associate_images_to_categories($imageIds, [$categoryId]);
    $coverId = \ClassIdentity\DomainSupport::binaryToId((string) $photos[0]['class_photo_id']);
    $albumService = \ClassIdentity\AlbumService::fromPiwigo();
    $mapping = $albumService->findByPiwigoCategoryId($categoryId);
    if ($mapping === null) {
        $mapping = $albumService->ensureMapping(
            $adminUserId,
            $categoryId,
            'COMMUNITY',
            'HERITAGE',
            $ownerPrincipalId,
            '由班级成员维护的本地验收相册。',
            '班级活动',
            $coverId,
            '建立 Phase 3.2 本地浏览器验收相册',
        );
    } else {
        if (($mapping['album_type'] ?? null) !== 'COMMUNITY'
            || ($mapping['era'] ?? null) !== 'HERITAGE'
            || (int) ($mapping['owner_principal_id'] ?? 0) !== $ownerPrincipalId
        ) {
            fixtureFail('album_mapping_drift');
        }
        $mapping = $albumService->updateMapping(
            $adminUserId,
            (string) $mapping['class_album_id'],
            'COMMUNITY',
            'HERITAGE',
            $ownerPrincipalId,
            '由班级成员维护的本地验收相册。',
            '班级活动',
            $coverId,
            'ACTIVE',
            '重置 Phase 3.2 本地浏览器验收相册',
        );
    }

    $canonical = \ClassIdentity\CanonicalPhotoService::fromPiwigo();
    foreach (array_slice($photos, 0, 3) as $index => $photo) {
        $path = (string) ($photo['path'] ?? '');
        $real = $path !== '' ? realpath($path) : false;
        if (!is_string($real) || !is_file($real) || is_link($real)) {
            fixtureFail('source_media_unavailable');
        }
        $actual = hash_file('sha256', $real);
        $stored = bin2hex((string) $photo['media_checksum']);
        if (!is_string($actual) || !hash_equals($stored, $actual)) {
            fixtureFail('source_checksum_mismatch');
        }
        $canonical->recordSource(
            $adminUserId,
            \ClassIdentity\DomainSupport::binaryToId((string) $photo['class_photo_id']),
            'PRIVATE_QA',
            'PHASE32-BROWSER-' . ($index + 1),
            hash('sha256', $real),
            hash('sha256', basename($real)),
            $stored,
            (int) filesize($real),
            null,
            '登记 Phase 3.2 本地来源证明',
        );
    }

    $pair = $repository->fetchOne(
        'SELECT p1.`class_photo_id` AS `left_id`,p2.`class_photo_id` AS `right_id` '
            . 'FROM `' . $repository->table('photo') . '` p1 '
            . 'JOIN `' . $repository->table('photo') . '` p2 ON p2.`media_checksum`=p1.`media_checksum` AND p2.`piwigo_image_id`>p1.`piwigo_image_id` '
            . 'JOIN `' . $repository->table('archive_image') . '` a1 ON a1.`piwigo_image_id`=p1.`piwigo_image_id` '
            . 'JOIN `' . $repository->table('archive_image') . '` a2 ON a2.`piwigo_image_id`=p2.`piwigo_image_id` '
            . "WHERE p1.`state`='ACTIVE' AND p2.`state`='ACTIVE' AND a1.`era`='HERITAGE' AND a2.`era`='HERITAGE' LIMIT 1",
    );
    $exact = false;
    if ($pair !== null) {
        $canonical->registerExactCandidate(
            $adminUserId,
            \ClassIdentity\DomainSupport::binaryToId((string) $pair['left_id']),
            \ClassIdentity\DomainSupport::binaryToId((string) $pair['right_id']),
            'Phase 3.2 本地精确重复候选',
        );
        $exact = true;
    } else {
        $canonical->registerNearCandidate(
            $adminUserId,
            \ClassIdentity\DomainSupport::binaryToId((string) $photos[0]['class_photo_id']),
            \ClassIdentity\DomainSupport::binaryToId((string) $photos[1]['class_photo_id']),
            0.90,
            'Phase 3.2 本地近似重复候选',
        );
    }
    invalidate_user_cache();
    fwrite(STDOUT, 'PHOTO_PRODUCT_BROWSER_FIXTURE=PASS album=COMMUNITY exact=' . ($exact ? 'YES' : 'NO') . " sources=3\n");
} catch (Throwable $error) {
    $message = strtolower($error->getMessage());
    if (preg_match('/\A[a-z0-9_]{1,72}\z/D', $message) !== 1) {
        $message = 'unexpected_' . strtolower((new ReflectionClass($error))->getShortName()) . '_line_' . $error->getLine();
    }
    fixtureFail($message);
}
