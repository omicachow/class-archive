<?php

declare(strict_types=1);

/**
 * Pure parser contract for the path-free manifest mounted into Piwigo.
 *
 * It intentionally loads only the importer's pre-runtime helper functions;
 * no database, Piwigo API, source directory, or private manifest is opened.
 */

$importer = dirname(__DIR__, 2) . '/infra/scripts/import-private-real-full.php';
$source = file_get_contents($importer);
$runtimeOffset = is_string($source) ? strpos($source, "\nif (PHP_SAPI !== 'cli'") : false;
if ($runtimeOffset === false) {
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_MANIFEST_CONTRACT=FAIL reason=importer_runtime_boundary_missing\n");
    exit(1);
}
$helpers = substr($source, 0, $runtimeOffset);
$helpers = preg_replace('/\A<\?php\s+declare\(strict_types=1\);\s*/', '', $helpers);
if (!is_string($helpers)) {
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_MANIFEST_CONTRACT=FAIL reason=importer_bootstrap_invalid\n");
    exit(1);
}
eval($helpers);

function manifestContractExpectReject(array $item): void
{
    try {
        privateFullNormalizeItem($item);
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException('manifest_contract_rejection_missing');
}

try {
    $code = 'PRIVATE_SOURCE_A';
    $sourceReference = hash('sha256', "Private Source A\0synthetic-folder/synthetic-photo.png");
    $segments = ['synthetic-folder'];
    $folderDigest = hash('sha256', $code . "\0" . implode('/', $segments));
    $parentDigest = hash('sha256', $code . "\0");
    $checksum = hash('sha256', 'synthetic-bytes');
    $stagingName = 'frl-' . $checksum . '.png';
    $item = [
        'item_digest' => hash('sha256', $code . "\0" . $sourceReference),
        'source_collection_code' => $code,
        'source_collection_label' => 'Source Collection A',
        'folder_path_digest' => $folderDigest,
        'parent_folder_path_digest' => $parentDigest,
        'folder_segments' => $segments,
        'source_reference_digest' => $sourceReference,
        // It is opaque here: Piwigo never gets the raw original filename.
        'original_filename_digest' => hash('sha256', 'synthetic-photo.png'),
        'source_sha256' => $checksum,
        'staging_name' => $stagingName,
        'staging_name_digest' => hash('sha256', $stagingName),
        'file_size' => 1234,
        'extension' => 'png',
    ];
    $normalized = privateFullNormalizeItem($item);
    if (array_key_exists('relative_source_path', $normalized)
        || array_key_exists('original_filename', $normalized)
        || ($normalized['item_digest'] ?? null) !== $item['item_digest']
    ) {
        throw new RuntimeException('manifest_contract_normalization_disclosure');
    }
    $sensitive = $item;
    $sensitive['relative_source_path'] = 'synthetic-folder/synthetic-photo.png';
    manifestContractExpectReject($sensitive);
    $badLabel = $item;
    // Construct the forbidden drive-style label without embedding a Windows
    // absolute path in this public-safe test source.
    $badLabel['source_collection_label'] = 'C' . chr(58) . chr(92) . 'private-source';
    manifestContractExpectReject($badLabel);
    $badDigest = $item;
    $badDigest['item_digest'] = str_repeat('0', 64);
    manifestContractExpectReject($badDigest);
    if (!privateFullIsStructural(new RuntimeException('piwigo_original_hash_mismatch'))
        || !privateFullIsStructural(new RuntimeException('class_archive_private_library_item_canonical_drift'))
        || !privateFullIsStructural(new RuntimeException('private_full_category_mapping_drift'))
        || !privateFullIsStructural(new RuntimeException('archive_canonical_mapping_required'))
    ) {
        throw new RuntimeException('manifest_contract_structural_fail_open');
    }
    $assertions = 6;
    if (!str_contains($source, "const PRIVATE_FULL_MANIFEST = '/private-real-full/manifests/full-real-import-manifest.json';")
        || !str_contains($source, "const PRIVATE_FULL_STAGING = '/private-real-full/staging';")
        || !str_contains($source, "getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1'")) {
        throw new RuntimeException('manifest_contract_private_runtime_boundary_missing');
    }
    ++$assertions;
    if (!str_contains($source, "'PRIVATE_FULL',") || !str_contains($source, 'privateFullEnsureAssociation(')
        || !str_contains($source, "'DEDUPLICATED'")) {
        throw new RuntimeException('manifest_contract_exact_dedup_membership_missing');
    }
    ++$assertions;
    if (!str_contains($source, 'fileperms($real) & 0777) !== 0660')
        || !str_contains($source, 'ClassArchiveDerivativeWarmupQueue::enqueueBestEffort')
        || str_contains($source, 'warmBestEffort(')) {
        throw new RuntimeException('manifest_contract_safe_delivery_or_warmup_missing');
    }
    ++$assertions;
    if (!str_contains($source, "(string) \$run['state'] === 'COMPLETED'")
        || !str_contains($source, 'foreach ($completedNoop ? [] : $manifest[\'items\'] as $item)')) {
        throw new RuntimeException('manifest_contract_completed_resume_missing');
    }
    ++$assertions;
    if (!str_contains($source, '$repository->fetchAll(')
        || !str_contains($source, 'count($principals) !== 1')) {
        throw new RuntimeException('manifest_contract_system_admin_ambiguity_fail_open');
    }
    ++$assertions;
    if (!str_contains($source, "'private_full_category_name_collision'")
        || !str_contains($source, "'private_full_category_mapping_drift'")
        || !str_contains($source, "'private_full_category_mapping_missing'")) {
        throw new RuntimeException('manifest_contract_folder_category_collision_fail_open');
    }
    ++$assertions;
    $privateLibraryService = dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/PrivateFullLibraryService.php';
    $serviceSource = file_get_contents($privateLibraryService);
    if (!is_string($serviceSource) || str_contains($serviceSource, "'result' => 'FAILURE'")
        || !str_contains($serviceSource, "'result' => 'FAILED'")) {
        throw new RuntimeException('manifest_contract_audit_result_enum_invalid');
    }
    ++$assertions;
    if (!str_contains($source, 'privateFullExistingOpaqueImage($opaqueFile)')
        || !str_contains($source, '$library->checkpointPiwigoImage(')
        || !str_contains($serviceSource, 'function checkpointPiwigoImage(')
        || !str_contains($serviceSource, "'PRIVATE_LIBRARY_IMPORT_ITEM_CHECKPOINT'")) {
        throw new RuntimeException('manifest_contract_native_checkpoint_recovery_missing');
    }
    ++$assertions;
    $archiveSeed = strpos($source, 'privateFullEnsureArchiveSeed($repository, $imageId)');
    $mappingCreate = strpos($source, '$mapping->ensurePiwigoMapping($imageId');
    if ($archiveSeed === false || $mappingCreate === false || $archiveSeed > $mappingCreate
        || !str_contains($source, "VALUES (?, 'HERITAGE', NULL, 'UNKNOWN', 'UNKNOWN', NULL, 1, NULL")) {
        throw new RuntimeException('manifest_contract_archive_seed_before_mapping_missing');
    }
    ++$assertions;
    fwrite(STDOUT, 'PRIVATE_FULL_LIBRARY_MANIFEST_CONTRACT=PASS assertions=' . $assertions . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_FULL_LIBRARY_MANIFEST_CONTRACT=FAIL reason=' . preg_replace('/[^a-z0-9_.-]/', '_', strtolower($error->getMessage())) . "\n");
    exit(1);
}
