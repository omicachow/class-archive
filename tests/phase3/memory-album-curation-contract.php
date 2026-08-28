<?php

declare(strict_types=1);

/**
 * Static contract for the narrow V4 Memory curation adapter.
 *
 * It intentionally uses no Docker, Piwigo, browser, synthetic database, or
 * private-media fixture. Runtime creation is separately guarded by Piwigo's
 * native category relationship APIs and current-policy rechecks; this suite
 * locks the public boundary, actor matrix and no-original-copy invariants.
 */

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . $relative);
    if (!is_string($contents)) {
        throw new RuntimeException('memory_album_curation_contract_source_unavailable');
    }
    return $contents;
};

$service = $read('plugins/ClassIdentity/src/MemoryAlbumCurationService.php');
$album = $read('plugins/ClassIdentity/src/AlbumService.php');
$gateway = $read('plugins/ClassIdentity/src/Gateway/GatewayService.php');
$controller = $read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php');
$audit = $read('plugins/ClassIdentity/src/Audit.php');
$bootstrap = $read('plugins/ClassIdentity/main.inc.php');
$installer = $read('infra/scripts/install-class-archive-plugins.php');
$bff = $read('infra/immich-spike/web-compat/server.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};

try {
    $assert(str_contains($bootstrap, "'src/MemoryAlbumCurationService.php'"), 'memory_curation_service_not_registered');
    $assert(str_contains($installer, "'src/MemoryAlbumCurationService.php'"), 'memory_curation_service_not_installed');
    foreach ([
        'saveFromCurrentMemory',
        'withMemoryLock',
        'GET_LOCK',
        'RELEASE_LOCK',
        'auto_collection',
        'auto_collection_photo',
        'FOR UPDATE',
        'permalinkForSourceReason',
        'class-archive-memory-',
        'class-archive-heritage',
        'class-archive-living',
        'class_archive_memory_album_mixed_era',
        'class_archive_memory_album_effective_era_invalid',
        'create_virtual_category',
        'associate_images_to_categories',
        "delete_categories([\$categoryId], 'no_delete')",
        'assertExactCategoryMembers',
        'class_archive_memory_album_projection_drift',
    ] as $needle) {
        $assert(str_contains($service, $needle), 'memory_service_missing_' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $needle) ?? 'contract'));
    }
    foreach (['copy(', 'rename(', 'unlink(', 'move_uploaded_file(', 'add_uploaded_file('] as $forbidden) {
        $assert(!str_contains($service, $forbidden), 'memory_service_must_not_copy_or_mutate_original_' . urlencode($forbidden));
    }
    $assert(!str_contains($service, "'INSERT INTO `' . \$collectionTable")
        && !str_contains($service, "'UPDATE `' . \$collectionTable")
        && !str_contains($service, "'DELETE FROM `' . \$collectionTable"), 'memory_service_must_not_mutate_build_owned_auto_collection_rows');
    $assert(!str_contains($service, '$_POST') && !str_contains($service, '$_GET') && !str_contains($service, '$_COOKIE'), 'memory_service_must_not_read_browser_input');
    $assert(str_contains($service, "'OFFICIAL'")
        && str_contains($service, "'COMMUNITY'")
        && str_contains($service, 'Access::ROLE_CLASSMATE')
        && str_contains($service, 'Access::ROLE_TEACHER')
        && str_contains($service, 'class_archive_memory_album_family_private_only')
        && str_contains($service, 'class_archive_memory_album_role_forbidden'), 'shared_album_actor_matrix_missing');
    $assert(str_contains($service, 'new AlbumService($repository)')
        && !str_contains($service, '$album = AlbumService::fromPiwigo();'), 'memory_mapping_must_share_locked_repository_transaction');
    $assert(str_contains($service, '(new AlbumService($this->repository))->findByPiwigoCategoryId'), 'idempotent_mapping_lookup_must_not_open_second_repository');
    $auditStart = strpos($service, "'action' => 'MEMORY_SAVE_AS_ALBUM'");
    $auditEnd = $auditStart === false ? false : strpos($service, "'result' => 'SUCCESS'", $auditStart);
    $auditBlock = ($auditStart === false || $auditEnd === false) ? '' : substr($service, $auditStart, $auditEnd - $auditStart);
    $assert($auditBlock !== ''
        && !str_contains($auditBlock, "'source_reason' =>")
        && !str_contains($auditBlock, "'source_path' =>")
        && !str_contains($auditBlock, "'photo_ids' =>"), 'memory_audit_must_not_contain_private_reason_or_membership');

    foreach ([
        'ensureOwnedCommunityMapping',
        'ensureMappingForActor',
        'setManualCoverForActor',
        'requirePhotoInExactCategory',
        'requirePhotoEffectiveEra',
        'class_archive_album_cover_role_forbidden',
        'ALBUM_COVER_UPDATE',
    ] as $needle) {
        $assert(str_contains($album, $needle), 'album_curation_missing_' . strtolower($needle));
    }
    $assert(str_contains($album, '[Access::ROLE_CLASSMATE, Access::ROLE_TEACHER]')
        && str_contains($album, '($before[\'album_type\'] ?? null) !== \'COMMUNITY\''), 'member_cover_must_be_owner_scoped');
    $assert(str_contains($album, 'manual_cover_class_photo_id')
        && str_contains($album, 'ProjectionMutationBoundary::invalidateAggregates'), 'manual_cover_must_persist_and_invalidate_projection');

    foreach ([
        'function saveMemoryAsAlbum(',
        'memoryProjectionForCuration',
        'ReadProjectionStore::MEMORIES',
        'source_reason',
        "'savedKind' => 'ALBUM'",
        "'savedKind' => 'PRIVATE_PIN'",
        'privateArrangement',
        'collectionSnapshotDomain()->pin(',
        'class_archive_memory_private_arrangement_customization_forbidden',
        'MemoryAlbumCurationService::fromPiwigo()->saveFromCurrentMemory',
        'function setAlbumCover(',
        'setManualCoverForActor',
    ] as $needle) {
        $assert(str_contains($gateway, $needle), 'gateway_curation_missing_' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $needle) ?? 'contract'));
    }
    $familyPin = strpos($gateway, "'savedKind' => 'PRIVATE_PIN'");
    $sharedSave = strpos($gateway, 'MemoryAlbumCurationService::fromPiwigo()->saveFromCurrentMemory');
    $assert($familyPin !== false && $sharedSave !== false && $familyPin < $sharedSave, 'family_private_arrangement_must_not_reach_shared_album_service');
    $assert(str_contains($gateway, 'ReadProjectionStore::SCOPE_HERITAGE')
        && str_contains($gateway, 'class_archive_memory_private_arrangement_customization_forbidden'), 'family_private_arrangement_must_stay_heritage_scoped_and_nonpublishing');
    $assert(!str_contains($gateway, 'create_virtual_category(') && !str_contains($gateway, 'associate_images_to_categories('), 'gateway_must_not_create_native_media_or_categories_directly');

    foreach ([
        "'collections/memories/save-as-album' => [",
        "'collections/albums/cover' => [",
        'mutateMemorySaveAsAlbum',
        'mutateCollectionAlbumCover',
        'self::requireMutationToken($body)',
        "'manage/albums/cover' => self::mutateAlbumCover(\$gateway, \$body)",
    ] as $needle) {
        $assert(str_contains($controller, $needle), 'controller_curation_contract_missing_' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $needle) ?? 'contract'));
    }
    $assert(str_contains($controller, "'_memory_album_role_forbidden'")
        && str_contains($controller, "'_album_cover_role_forbidden'"), 'controller_must_map_role_denials_to_forbidden');
    foreach ([
        "['/api/class-archive/collections/memories/save-as-album', '/api/collections/memories/save-as-album']",
        "['/api/class-archive/collections/albums/cover', '/api/collections/albums/cover']",
    ] as $route) {
        $assert(str_contains($bff, $route), 'bff_curation_allowlist_missing');
    }
    $assert(str_contains($gateway, "'/api/collections/memories/save-as-album'")
        && str_contains($gateway, "'/api/collections/albums/cover'"), 'gateway_route_contract_missing');
    $assert(str_contains($audit, "'MEMORY_SAVE_AS_ALBUM'")
        && str_contains($audit, "'ALBUM_COVER_UPDATE'"), 'curation_audit_actions_not_high_risk');

    fwrite(STDOUT, "MEMORY_ALBUM_CURATION_CONTRACT=PASS assertions={$assertions}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'MEMORY_ALBUM_CURATION_CONTRACT=FAIL reason=' . $error->getMessage() . "\n");
    exit(1);
}
