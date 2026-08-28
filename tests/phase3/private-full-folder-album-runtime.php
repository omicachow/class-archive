<?php

declare(strict_types=1);

/**
 * Read-only, count-only acceptance for the private-full folder -> album import.
 *
 * The test deliberately does not open the private manifest, staging tree, or
 * owner source directories. SQL allowlists only opaque ids/digests, numeric
 * category relationships, states, and counts; display names, original names,
 * media references, and filesystem paths are never selected or printed.
 */

const PRIVATE_FULL_FOLDER_SAMPLE = 20;
const PRIVATE_FULL_ITEM_SAMPLE = 30;
const PRIVATE_FULL_DUPLICATE_GROUP_SAMPLE = 3;

$assertions = 0;
$db = null;
$transactionStarted = false;

function privateFullRuntimeFail(string $code): never
{
    throw new RuntimeException($code);
}

function privateFullRuntimeCheck(bool $condition, string $code): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        privateFullRuntimeFail($code);
    }
}

function privateFullRuntimeTable(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1) {
        privateFullRuntimeFail('table_identifier_invalid');
    }
    return '`' . $name . '`';
}

/** @param list<mixed> $parameters @return list<array<string,mixed>> */
function privateFullRuntimeRows(mysqli $db, string $sql, array $parameters = []): array
{
    $statement = $db->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        privateFullRuntimeFail('database_prepare_failed');
    }
    try {
        if (!$statement->execute($parameters)) {
            privateFullRuntimeFail('database_execute_failed');
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            privateFullRuntimeFail('database_result_failed');
        }
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

/** @param list<mixed> $parameters @return array<string,mixed>|null */
function privateFullRuntimeRow(mysqli $db, string $sql, array $parameters = []): ?array
{
    $rows = privateFullRuntimeRows($db, $sql, $parameters);
    if (count($rows) > 1) {
        privateFullRuntimeFail('database_row_ambiguous');
    }
    return $rows[0] ?? null;
}

function privateFullRuntimeBinary(mixed $value, int $length): bool
{
    return is_string($value) && strlen($value) === $length;
}

/** @return list<int> */
function privateFullRuntimeNumericChain(mixed $value): array
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*(?:,[1-9][0-9]*)*\z/D', $value) !== 1) {
        privateFullRuntimeFail('category_chain_invalid');
    }
    return array_map('intval', explode(',', $value));
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDOUT, "PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=FAIL code=cli_posix_required assertions=0 source_names_read=0 source_paths_read=0\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDOUT, "PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=FAIL code=nginx_user_required assertions=0 source_names_read=0 source_paths_read=0\n");
    exit(1);
}
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1'
    || getenv('CLASS_ARCHIVE_PRIVATE_FULL_FOLDER_ALBUM_FIXTURE') !== '1'
) {
    fwrite(STDOUT, "PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=FAIL code=private_full_fixture_gate_required assertions=0 source_names_read=0 source_paths_read=0\n");
    exit(1);
}

try {
    define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
    $conf = [];
    $prefixeTable = null;
    require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        privateFullRuntimeFail('database_prefix_invalid');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
        privateFullRuntimeFail('database_unavailable');
    }
    if (!$db->query('START TRANSACTION READ ONLY')) {
        privateFullRuntimeFail('read_only_transaction_unavailable');
    }
    $transactionStarted = true;

    $ci = $prefixeTable . 'class_identity_';
    $tables = [
        'collection' => privateFullRuntimeTable($ci . 'private_library_collection'),
        'folder' => privateFullRuntimeTable($ci . 'private_library_folder'),
        'import' => privateFullRuntimeTable($ci . 'private_library_import'),
        'item' => privateFullRuntimeTable($ci . 'private_library_import_item'),
        'album' => privateFullRuntimeTable($ci . 'album'),
        'photo' => privateFullRuntimeTable($ci . 'photo'),
        'source' => privateFullRuntimeTable($ci . 'photo_source'),
        'category' => privateFullRuntimeTable($prefixeTable . 'categories'),
        'association' => privateFullRuntimeTable($prefixeTable . 'image_category'),
    ];

    $heritage = privateFullRuntimeRow(
        $db,
        'SELECT `id`,`uppercats`,`status`,`visible` FROM ' . $tables['category'] . ' WHERE `permalink`=? LIMIT 2',
        ['class-archive-heritage'],
    );
    privateFullRuntimeCheck($heritage !== null, 'heritage_root_missing');
    $heritageId = (int) ($heritage['id'] ?? 0);
    $heritageChain = privateFullRuntimeNumericChain($heritage['uppercats'] ?? null);
    privateFullRuntimeCheck(
        $heritageId > 0
            && end($heritageChain) === $heritageId
            && ($heritage['status'] ?? null) === 'private'
            && ($heritage['visible'] ?? null) === 'true',
        'heritage_root_invalid',
    );

    $folderCache = [];
    $categoryCache = [];
    $albumCache = [];
    $collectionCache = [];

    $folderById = static function (string $folderId) use ($db, $tables, &$folderCache): array {
        if (!privateFullRuntimeBinary($folderId, 16)) {
            privateFullRuntimeFail('folder_id_invalid');
        }
        $key = bin2hex($folderId);
        if (!isset($folderCache[$key])) {
            $row = privateFullRuntimeRow(
                $db,
                'SELECT `folder_id`,`source_collection_id`,`relative_path_digest`,`parent_folder_id`,'
                    . '`piwigo_category_id`,`class_album_id`,`depth` FROM ' . $tables['folder']
                    . ' WHERE `folder_id`=? LIMIT 2',
                [$folderId],
            );
            if ($row === null) {
                privateFullRuntimeFail('folder_mapping_missing');
            }
            $folderCache[$key] = $row;
        }
        return $folderCache[$key];
    };

    $categoryById = static function (int $categoryId) use ($db, $tables, &$categoryCache): array {
        if ($categoryId <= 0) {
            privateFullRuntimeFail('category_id_invalid');
        }
        if (!isset($categoryCache[$categoryId])) {
            $row = privateFullRuntimeRow(
                $db,
                'SELECT `id`,`id_uppercat`,`uppercats`,`status`,`visible` FROM ' . $tables['category']
                    . ' WHERE `id`=? LIMIT 2',
                [$categoryId],
            );
            if ($row === null) {
                privateFullRuntimeFail('category_missing');
            }
            $categoryCache[$categoryId] = $row;
        }
        return $categoryCache[$categoryId];
    };

    $albumById = static function (string $albumId) use ($db, $tables, &$albumCache): array {
        if (!privateFullRuntimeBinary($albumId, 16)) {
            privateFullRuntimeFail('album_id_invalid');
        }
        $key = bin2hex($albumId);
        if (!isset($albumCache[$key])) {
            $row = privateFullRuntimeRow(
                $db,
                'SELECT `class_album_id`,`piwigo_category_id`,`album_type`,`owner_principal_id`,`era`,`state`'
                    . ' FROM ' . $tables['album'] . ' WHERE `class_album_id`=? LIMIT 2',
                [$albumId],
            );
            if ($row === null) {
                privateFullRuntimeFail('album_mapping_missing');
            }
            $albumCache[$key] = $row;
        }
        return $albumCache[$key];
    };

    $collectionById = static function (string $collectionId) use ($db, $tables, &$collectionCache): array {
        if (!privateFullRuntimeBinary($collectionId, 16)) {
            privateFullRuntimeFail('collection_id_invalid');
        }
        $key = bin2hex($collectionId);
        if (!isset($collectionCache[$key])) {
            $row = privateFullRuntimeRow(
                $db,
                'SELECT `source_collection_id`,`source_code`,`state` FROM ' . $tables['collection']
                    . ' WHERE `source_collection_id`=? LIMIT 2',
                [$collectionId],
            );
            if ($row === null) {
                privateFullRuntimeFail('collection_missing');
            }
            $collectionCache[$key] = $row;
        }
        return $collectionCache[$key];
    };

    $validateAlbum = static function (array $folder) use ($albumById): void {
        $album = $albumById((string) ($folder['class_album_id'] ?? ''));
        privateFullRuntimeCheck(
            (int) ($album['piwigo_category_id'] ?? 0) === (int) ($folder['piwigo_category_id'] ?? 0)
                && ($album['album_type'] ?? null) === 'OFFICIAL'
                && ($album['owner_principal_id'] ?? null) === null
                && ($album['era'] ?? null) === 'HERITAGE'
                && ($album['state'] ?? null) === 'ACTIVE',
            'folder_album_mapping_invalid',
        );
    };

    $validateFolder = static function (array $selected) use (
        $folderById,
        $categoryById,
        $validateAlbum,
        $heritageId,
        $heritageChain,
    ): void {
        privateFullRuntimeCheck(
            privateFullRuntimeBinary($selected['folder_id'] ?? null, 16)
                && privateFullRuntimeBinary($selected['source_collection_id'] ?? null, 16)
                && privateFullRuntimeBinary($selected['relative_path_digest'] ?? null, 32)
                && privateFullRuntimeBinary($selected['class_album_id'] ?? null, 16)
                && (int) ($selected['piwigo_category_id'] ?? 0) > 0
                && (int) ($selected['depth'] ?? -1) >= 0
                && (int) ($selected['depth'] ?? -1) <= 255,
            'folder_shape_invalid',
        );
        $selectedCollection = (string) $selected['source_collection_id'];
        $selectedCategory = $categoryById((int) $selected['piwigo_category_id']);
        $categoryChain = [];
        $seen = [];
        $current = $selected;
        for ($hop = 0; $hop <= 255; ++$hop) {
            $folderId = (string) ($current['folder_id'] ?? '');
            if (!privateFullRuntimeBinary($folderId, 16) || isset($seen[bin2hex($folderId)])) {
                privateFullRuntimeFail('folder_parent_cycle');
            }
            $seen[bin2hex($folderId)] = true;
            privateFullRuntimeCheck(
                hash_equals($selectedCollection, (string) ($current['source_collection_id'] ?? '')),
                'folder_collection_drift',
            );
            $category = $categoryById((int) ($current['piwigo_category_id'] ?? 0));
            privateFullRuntimeCheck(
                ($category['status'] ?? null) === 'private' && ($category['visible'] ?? null) === 'true',
                'folder_category_visibility_invalid',
            );
            $validateAlbum($current);
            $categoryChain[] = (int) $category['id'];
            $parentId = $current['parent_folder_id'] ?? null;
            if ($parentId === null) {
                privateFullRuntimeCheck(
                    (int) ($current['depth'] ?? -1) === 0
                        && (int) ($category['id_uppercat'] ?? 0) === $heritageId,
                    'folder_root_parent_invalid',
                );
                break;
            }
            if (!privateFullRuntimeBinary($parentId, 16)) {
                privateFullRuntimeFail('folder_parent_id_invalid');
            }
            $parent = $folderById($parentId);
            privateFullRuntimeCheck(
                (int) ($parent['depth'] ?? -1) + 1 === (int) ($current['depth'] ?? -1)
                    && (int) ($category['id_uppercat'] ?? 0) === (int) ($parent['piwigo_category_id'] ?? 0),
                'folder_parent_hierarchy_invalid',
            );
            $current = $parent;
            if ($hop === 255) {
                privateFullRuntimeFail('folder_depth_unbounded');
            }
        }
        $expectedChain = array_merge($heritageChain, array_reverse($categoryChain));
        privateFullRuntimeCheck(
            privateFullRuntimeNumericChain($selectedCategory['uppercats'] ?? null) === $expectedChain,
            'folder_materialized_hierarchy_invalid',
        );
    };

    $activeImports = privateFullRuntimeRow(
        $db,
        'SELECT COUNT(*) AS `total` FROM ' . $tables['import'] . " WHERE `state` IN ('PREPARED','RUNNING')",
    );
    privateFullRuntimeCheck((int) ($activeImports['total'] ?? -1) === 0, 'import_not_quiescent');

    $import = privateFullRuntimeRow(
        $db,
        'SELECT `import_id`,`item_total`,`applied_count`,`deduplicated_count`,`failed_count`,`state`'
            . ' FROM ' . $tables['import'] . " WHERE `state`='COMPLETED' ORDER BY `completed_at` DESC LIMIT 1",
    );
    privateFullRuntimeCheck($import !== null, 'completed_import_missing');
    privateFullRuntimeCheck(
        privateFullRuntimeBinary($import['import_id'] ?? null, 16)
            && (int) ($import['item_total'] ?? 0) >= PRIVATE_FULL_ITEM_SAMPLE
            && (int) ($import['failed_count'] ?? -1) === 0
            && (int) ($import['applied_count'] ?? -1) + (int) ($import['deduplicated_count'] ?? -1)
                === (int) ($import['item_total'] ?? -1),
        'completed_import_counts_invalid',
    );
    $importId = (string) $import['import_id'];
    $unfinishedItems = privateFullRuntimeRow(
        $db,
        'SELECT COUNT(*) AS `total` FROM ' . $tables['item']
            . " WHERE `import_id`=? AND `state` NOT IN ('APPLIED','DEDUPLICATED')",
        [$importId],
    );
    privateFullRuntimeCheck((int) ($unfinishedItems['total'] ?? -1) === 0, 'completed_import_items_invalid');

    $folderPopulation = privateFullRuntimeRow(
        $db,
        'SELECT COUNT(*) AS `total` FROM ' . $tables['folder'] . ' f JOIN ' . $tables['collection']
            . " c ON c.`source_collection_id`=f.`source_collection_id` WHERE c.`state`='ACTIVE'",
    );
    privateFullRuntimeCheck(
        (int) ($folderPopulation['total'] ?? 0) >= PRIVATE_FULL_FOLDER_SAMPLE,
        'folder_population_insufficient',
    );
    $folders = privateFullRuntimeRows(
        $db,
        'SELECT f.`folder_id`,f.`source_collection_id`,f.`relative_path_digest`,f.`parent_folder_id`,'
            . 'f.`piwigo_category_id`,f.`class_album_id`,f.`depth` FROM ' . $tables['folder'] . ' f JOIN '
            . $tables['collection'] . " c ON c.`source_collection_id`=f.`source_collection_id` WHERE c.`state`='ACTIVE'"
            . ' ORDER BY RAND() LIMIT ' . PRIVATE_FULL_FOLDER_SAMPLE,
    );
    privateFullRuntimeCheck(count($folders) === PRIVATE_FULL_FOLDER_SAMPLE, 'folder_sample_incomplete');
    foreach ($folders as $folder) {
        $validateFolder($folder);
    }

    $items = privateFullRuntimeRows(
        $db,
        'SELECT `item_digest`,`source_collection_id`,`folder_id`,`source_reference_digest`,'
            . '`original_filename_digest`,`source_checksum`,`byte_size`,`state`,`class_photo_id`,`piwigo_image_id`'
            . ' FROM ' . $tables['item'] . " WHERE `import_id`=? AND `state` IN ('APPLIED','DEDUPLICATED')"
            . ' ORDER BY RAND() LIMIT ' . PRIVATE_FULL_ITEM_SAMPLE,
        [$importId],
    );
    privateFullRuntimeCheck(count($items) === PRIVATE_FULL_ITEM_SAMPLE, 'item_sample_incomplete');

    $provenanceChecked = 0;
    $validateItem = static function (array $item) use (
        $db,
        $tables,
        $folderById,
        $collectionById,
        $validateFolder,
        $validateAlbum,
        &$provenanceChecked,
    ): void {
        privateFullRuntimeCheck(
            privateFullRuntimeBinary($item['item_digest'] ?? null, 32)
                && privateFullRuntimeBinary($item['source_collection_id'] ?? null, 16)
                && privateFullRuntimeBinary($item['folder_id'] ?? null, 16)
                && privateFullRuntimeBinary($item['source_reference_digest'] ?? null, 32)
                && privateFullRuntimeBinary($item['original_filename_digest'] ?? null, 32)
                && privateFullRuntimeBinary($item['source_checksum'] ?? null, 32)
                && privateFullRuntimeBinary($item['class_photo_id'] ?? null, 16)
                && (int) ($item['byte_size'] ?? 0) > 0
                && (int) ($item['piwigo_image_id'] ?? 0) > 0
                && in_array($item['state'] ?? null, ['APPLIED', 'DEDUPLICATED'], true),
            'item_shape_invalid',
        );
        $folder = $folderById((string) $item['folder_id']);
        privateFullRuntimeCheck(
            hash_equals((string) $item['source_collection_id'], (string) ($folder['source_collection_id'] ?? '')),
            'item_folder_collection_invalid',
        );
        $validateFolder($folder);
        $validateAlbum($folder);

        $membership = privateFullRuntimeRow(
            $db,
            'SELECT COUNT(*) AS `total` FROM ' . $tables['association']
                . ' WHERE `image_id`=? AND `category_id`=?',
            [(int) $item['piwigo_image_id'], (int) $folder['piwigo_category_id']],
        );
        privateFullRuntimeCheck((int) ($membership['total'] ?? 0) === 1, 'leaf_album_membership_missing');

        $photo = privateFullRuntimeRow(
            $db,
            'SELECT `piwigo_image_id`,`media_checksum`,`state` FROM ' . $tables['photo']
                . ' WHERE `class_photo_id`=? LIMIT 2',
            [(string) $item['class_photo_id']],
        );
        privateFullRuntimeCheck(
            $photo !== null
                && (int) ($photo['piwigo_image_id'] ?? 0) === (int) $item['piwigo_image_id']
                && privateFullRuntimeBinary($photo['media_checksum'] ?? null, 32)
                && hash_equals((string) $item['source_checksum'], (string) $photo['media_checksum'])
                && ($photo['state'] ?? null) === 'ACTIVE',
            'canonical_photo_mapping_invalid',
        );

        $collection = $collectionById((string) $item['source_collection_id']);
        $sourceCode = (string) ($collection['source_code'] ?? '');
        privateFullRuntimeCheck(
            ($collection['state'] ?? null) === 'ACTIVE'
                && in_array($sourceCode, ['PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B'], true),
            'item_collection_invalid',
        );
        $provenanceCode = 'FULL.' . ($sourceCode === 'PRIVATE_SOURCE_A' ? 'A' : 'B') . '.'
            . substr(bin2hex((string) $item['item_digest']), 0, 56);
        $provenance = privateFullRuntimeRow(
            $db,
            'SELECT COUNT(*) AS `total` FROM ' . $tables['source']
                . " WHERE `class_photo_id`=? AND `source_kind`='PRIVATE_FULL' AND `provenance_code`=?"
                . ' AND `source_reference_digest`=? AND `original_filename_digest`=?'
                . ' AND `source_checksum`=? AND `byte_size`=?',
            [
                (string) $item['class_photo_id'],
                $provenanceCode,
                (string) $item['source_reference_digest'],
                (string) $item['original_filename_digest'],
                (string) $item['source_checksum'],
                (int) $item['byte_size'],
            ],
        );
        privateFullRuntimeCheck((int) ($provenance['total'] ?? 0) === 1, 'source_provenance_missing');
        ++$provenanceChecked;
    };

    foreach ($items as $item) {
        $validateItem($item);
    }

    $duplicateGroups = privateFullRuntimeRows(
        $db,
        'SELECT `source_checksum`,COUNT(*) AS `item_count`,COUNT(DISTINCT `folder_id`) AS `folder_count`'
            . ' FROM ' . $tables['item'] . " WHERE `import_id`=? AND `state` IN ('APPLIED','DEDUPLICATED')"
            . ' GROUP BY `source_checksum` HAVING COUNT(*)>=2 AND COUNT(DISTINCT `folder_id`)>=2'
            . ' ORDER BY RAND() LIMIT ' . PRIVATE_FULL_DUPLICATE_GROUP_SAMPLE,
        [$importId],
    );
    privateFullRuntimeCheck(count($duplicateGroups) >= 1, 'cross_album_duplicate_missing');

    $duplicateItemsChecked = 0;
    $duplicateAlbumsChecked = 0;
    foreach ($duplicateGroups as $group) {
        privateFullRuntimeCheck(
            privateFullRuntimeBinary($group['source_checksum'] ?? null, 32)
                && (int) ($group['item_count'] ?? 0) >= 2
                && (int) ($group['folder_count'] ?? 0) >= 2,
            'duplicate_group_shape_invalid',
        );
        $duplicateItems = privateFullRuntimeRows(
            $db,
            'SELECT `item_digest`,`source_collection_id`,`folder_id`,`source_reference_digest`,'
                . '`original_filename_digest`,`source_checksum`,`byte_size`,`state`,`class_photo_id`,`piwigo_image_id`'
                . ' FROM ' . $tables['item']
                . " WHERE `import_id`=? AND `source_checksum`=? AND `state` IN ('APPLIED','DEDUPLICATED')",
            [$importId, (string) $group['source_checksum']],
        );
        privateFullRuntimeCheck(count($duplicateItems) === (int) $group['item_count'], 'duplicate_group_item_count_invalid');
        $canonicalIds = [];
        $imageIds = [];
        $categoryIds = [];
        foreach ($duplicateItems as $item) {
            $validateItem($item);
            $canonicalIds[bin2hex((string) $item['class_photo_id'])] = true;
            $imageIds[(int) $item['piwigo_image_id']] = true;
            $folder = $folderById((string) $item['folder_id']);
            $categoryIds[(int) $folder['piwigo_category_id']] = true;
        }
        privateFullRuntimeCheck(
            count($canonicalIds) === 1 && count($imageIds) === 1 && count($categoryIds) >= 2,
            'duplicate_canonical_album_retention_invalid',
        );
        $canonicalBinary = hex2bin((string) array_key_first($canonicalIds));
        if (!is_string($canonicalBinary)) {
            privateFullRuntimeFail('duplicate_canonical_id_invalid');
        }
        $retained = privateFullRuntimeRow(
            $db,
            'SELECT COUNT(DISTINCT `provenance_code`) AS `total` FROM ' . $tables['source']
                . " WHERE `class_photo_id`=? AND `source_kind`='PRIVATE_FULL' AND `source_checksum`=?",
            [$canonicalBinary, (string) $group['source_checksum']],
        );
        privateFullRuntimeCheck(
            (int) ($retained['total'] ?? 0) >= count($duplicateItems),
            'duplicate_provenance_retention_invalid',
        );
        $duplicateItemsChecked += count($duplicateItems);
        $duplicateAlbumsChecked += count($categoryIds);
    }

    privateFullRuntimeCheck($provenanceChecked >= PRIVATE_FULL_ITEM_SAMPLE, 'provenance_sample_incomplete');
    privateFullRuntimeCheck($duplicateAlbumsChecked >= 2, 'duplicate_album_sample_incomplete');

    fwrite(
        STDOUT,
        'PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=PASS assertions=' . $assertions
            . ' folders_sampled=' . count($folders)
            . ' items_sampled=' . count($items)
            . ' provenance_checked=' . $provenanceChecked
            . ' duplicate_groups_sampled=' . count($duplicateGroups)
            . ' duplicate_items_checked=' . $duplicateItemsChecked
            . ' duplicate_albums_checked=' . $duplicateAlbumsChecked
            . " source_names_read=0 source_paths_read=0\n",
    );
} catch (Throwable $error) {
    $code = $error->getMessage();
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'unexpected_runtime_failure';
    }
    fwrite(
        STDOUT,
        'PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=FAIL code=' . $code . ' assertions=' . $assertions
            . " source_names_read=0 source_paths_read=0\n",
    );
    exit(1);
} finally {
    if ($db instanceof mysqli) {
        if ($transactionStarted) {
            $db->rollback();
        }
        $db->close();
    }
}
