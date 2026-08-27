<?php

declare(strict_types=1);

/**
 * Disposable MariaDB gate for supplemental-source preflight and the durable,
 * bounded post-import AI reconciliation source. No image, path, or private
 * manifest is opened; all tables use a random prefix and are dropped finally.
 */

function supplementalRuntimeFail(string $code): never
{
    throw new RuntimeException($code);
}

function supplementalRuntimeIdent(string $value): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $value) !== 1) {
        supplementalRuntimeFail('identifier_invalid');
    }
    return '`' . $value . '`';
}

function supplementalRuntimeExec(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        supplementalRuntimeFail('query_failed_' . $db->errno);
    }
}

/** @param list<mixed> $values */
function supplementalRuntimeInsert(mysqli $db, string $sql, array $values): void
{
    $statement = $db->prepare($sql);
    if (!$statement instanceof mysqli_stmt || !$statement->execute($values)) {
        supplementalRuntimeFail('insert_failed_' . $db->errno);
    }
    $statement->close();
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    fwrite(STDERR, "PRIVATE_REAL_SUPPLEMENTAL_RUNTIME=FAIL reason=non_root_cli_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "PRIVATE_REAL_SUPPLEMENTAL_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/PrivateFullLibraryService.php';

$importer = file_get_contents($root . '/infra/scripts/import-private-real-full.php');
$runtimeOffset = is_string($importer) ? strpos($importer, "\nif (PHP_SAPI !== 'cli'") : false;
if ($runtimeOffset === false) {
    fwrite(STDERR, "PRIVATE_REAL_SUPPLEMENTAL_RUNTIME=FAIL reason=importer_helpers_unavailable\n");
    exit(1);
}
$helpers = substr($importer, 0, $runtimeOffset);
$helpers = preg_replace('/\A<\?php\s+declare\(strict_types=1\);\s*/', '', $helpers);
if (!is_string($helpers)) {
    fwrite(STDERR, "PRIVATE_REAL_SUPPLEMENTAL_RUNTIME=FAIL reason=importer_helpers_invalid\n");
    exit(1);
}
eval($helpers);

$run = bin2hex(random_bytes(6));
$base = 'ci_supp_' . $run . '_';
$ci = $base . 'class_identity_';
$created = [];
$assertions = 0;
$assert = static function (bool $condition, string $code) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        supplementalRuntimeFail($code);
    }
};
$expectFailure = static function (callable $callback, string $expected) use (&$assertions): void {
    ++$assertions;
    try {
        $callback();
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $expected) {
            return;
        }
        throw $error;
    }
    supplementalRuntimeFail('expected_failure_missing_' . $expected);
};

try {
    $photo = supplementalRuntimeIdent($ci . 'photo');
    $source = supplementalRuntimeIdent($ci . 'photo_source');
    $presentation = supplementalRuntimeIdent($ci . 'photo_source_presentation');
    $import = supplementalRuntimeIdent($ci . 'private_library_import');
    $item = supplementalRuntimeIdent($ci . 'private_library_import_item');
    foreach (['photo', 'photo_source', 'photo_source_presentation', 'private_library_import', 'private_library_import_item'] as $suffix) {
        $created[] = $ci . $suffix;
    }
    supplementalRuntimeExec($db, 'CREATE TABLE ' . $photo . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL,`piwigo_image_id` INT UNSIGNED NOT NULL,'
        . '`media_checksum` BINARY(32) NOT NULL,`state` VARCHAR(16) NOT NULL,PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB');
    supplementalRuntimeExec($db, 'CREATE TABLE ' . $source . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`class_photo_id` BINARY(16) NOT NULL,'
        . '`source_kind` VARCHAR(32) NOT NULL,`provenance_code` VARCHAR(64) NOT NULL,'
        . '`source_reference_digest` BINARY(32) NULL,`original_filename_digest` BINARY(32) NULL,'
        . '`source_checksum` BINARY(32) NOT NULL,`byte_size` BIGINT UNSIGNED NOT NULL,`observed_at` DATETIME(6) NULL,'
        . 'PRIMARY KEY (`id`),UNIQUE KEY (`source_kind`,`provenance_code`)'
        . ') ENGINE=InnoDB');
    supplementalRuntimeExec($db, 'CREATE TABLE ' . $presentation . ' ('
        . '`photo_source_id` BIGINT UNSIGNED NOT NULL,`source_identity_digest` BINARY(32) NOT NULL,'
        . '`presentation_checksum` BINARY(32) NOT NULL,`presentation_byte_size` BIGINT UNSIGNED NOT NULL,'
        . '`source_format` VARCHAR(16) NOT NULL,`presentation_format` VARCHAR(16) NOT NULL,'
        . '`transform_kind` VARCHAR(48) NOT NULL,`transform_tool` VARCHAR(32) NOT NULL,'
        . '`transform_version` VARCHAR(32) NOT NULL,`transform_recipe_digest` BINARY(32) NOT NULL,'
        . 'PRIMARY KEY (`photo_source_id`),UNIQUE KEY (`source_identity_digest`)'
        . ') ENGINE=InnoDB');
    supplementalRuntimeExec($db, 'CREATE TABLE ' . $import . ' ('
        . '`import_id` BINARY(16) NOT NULL,`manifest_digest` BINARY(32) NOT NULL,'
        . '`item_total` INT UNSIGNED NOT NULL,`state` VARCHAR(32) NOT NULL,`applied_count` INT UNSIGNED NOT NULL,'
        . 'PRIMARY KEY (`import_id`)'
        . ') ENGINE=InnoDB');
    supplementalRuntimeExec($db, 'CREATE TABLE ' . $item . ' ('
        . '`import_id` BINARY(16) NOT NULL,`item_digest` BINARY(32) NOT NULL,`class_photo_id` BINARY(16) NULL,'
        . '`piwigo_image_id` INT UNSIGNED NULL,`state` VARCHAR(32) NOT NULL,PRIMARY KEY (`import_id`,`item_digest`)'
        . ') ENGINE=InnoDB');

    $repository = new \ClassIdentity\Repository($db, $base);
    $sourceReference = hash('sha256', 'runtime-source-reference');
    $sourceChecksum = hash('sha256', 'runtime-source-mpo');
    $presentationChecksum = hash('sha256', 'runtime-presentation-jpeg');
    $itemDigest = hash('sha256', "PRIVATE_SOURCE_A\0" . $sourceReference);
    $assert(
        privateFullProvenanceCode('PRIVATE_SOURCE_A', $itemDigest) === 'FULL.A.' . strtoupper(substr($itemDigest, 0, 56)),
        'provenance_code_not_canonical_uppercase',
    );
    $folderDigest = hash('sha256', "PRIVATE_SOURCE_A\0folder");
    $parentDigest = hash('sha256', "PRIVATE_SOURCE_A\0");
    $spec = privateFullNormalizeSupplementalItem([
        'item_digest' => $itemDigest,
        'source_collection_code' => 'PRIVATE_SOURCE_A',
        'source_collection_label' => 'Source Collection A',
        'folder_path_digest' => $folderDigest,
        'parent_folder_path_digest' => $parentDigest,
        'folder_segments' => ['folder'],
        'source_reference_digest' => $sourceReference,
        'original_filename_digest' => hash('sha256', 'runtime-name'),
        'source_sha256' => $sourceChecksum,
        'source_byte_size' => 200,
        'presentation_sha256' => $presentationChecksum,
        'presentation_byte_size' => 100,
        'presentation_staging_name' => 'frs-' . $presentationChecksum . '.jpg',
        'source_format' => 'MPO',
        'presentation_format' => 'JPEG',
        'transform_kind' => 'MPO_PRIMARY_FRAME_JPEG',
        'transform_tool' => 'PILLOW',
        'transform_version' => '12.3.0',
        'transform_recipe_digest' => hash('sha256', 'runtime-recipe'),
        'canonical_identity_basis' => 'PRESENTATION_SHA256',
    ]);

    $new = privateFullPreflightSupplementalSources($repository, [$spec]);
    $assert($new === ['new' => 1, 'replay' => 0], 'new_source_preflight_invalid');
    $otherCollection = $spec;
    $otherCollection['source_collection_code'] = 'PRIVATE_SOURCE_B';
    $otherCollection['source_collection_label'] = 'Source Collection B';
    $otherCollection['item_digest'] = hash('sha256', "PRIVATE_SOURCE_B\0" . $sourceReference);
    $otherCollection['folder_path_digest'] = hash('sha256', "PRIVATE_SOURCE_B\0folder");
    $otherCollection['parent_folder_path_digest'] = hash('sha256', "PRIVATE_SOURCE_B\0");
    $expectFailure(
        static fn() => privateFullPreflightSupplementalSources($repository, [$spec, $otherCollection]),
        'supplemental_preflight_batch_source_collision',
    );

    $photoId = \ClassIdentity\DomainSupport::generateId();
    $photoBinary = \ClassIdentity\DomainSupport::idToBinary($photoId);
    supplementalRuntimeInsert($db, 'INSERT INTO ' . $photo . ' VALUES (?, ?, ?, ?)',
        [$photoBinary, 9001, hex2bin($presentationChecksum), \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE]);
    supplementalRuntimeInsert($db, 'INSERT INTO ' . $source
        . ' (`class_photo_id`,`source_kind`,`provenance_code`,`source_reference_digest`,`original_filename_digest`,`source_checksum`,`byte_size`,`observed_at`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, NULL)', [
            $photoBinary, 'PRIVATE_FULL', privateFullProvenanceCode('PRIVATE_SOURCE_A', $itemDigest),
            hex2bin($sourceReference), hex2bin($spec['original_filename_digest']), hex2bin($sourceChecksum), 200,
        ]);
    $sourceId = $db->insert_id;
    $expectFailure(
        static fn() => privateFullPreflightSupplementalSources($repository, [$spec]),
        'supplemental_preflight_existing_source_conflict',
    );
    supplementalRuntimeInsert($db, 'INSERT INTO ' . $presentation . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $sourceId, hex2bin($itemDigest), hex2bin($presentationChecksum), 100, 'MPO', 'JPEG',
        'MPO_PRIMARY_FRAME_JPEG', 'PILLOW', '12.3.0', hex2bin($spec['transform_recipe_digest']),
    ]);
    $replay = privateFullPreflightSupplementalSources($repository, [$spec]);
    $assert($replay === ['new' => 0, 'replay' => 1], 'exact_replay_preflight_invalid');
    supplementalRuntimeExec($db, 'UPDATE ' . $presentation . ' SET `presentation_checksum`=UNHEX(REPEAT(\'ab\',32))');
    $expectFailure(
        static fn() => privateFullPreflightSupplementalSources($repository, [$spec]),
        'supplemental_preflight_existing_source_drift_presentation_checksum',
    );
    supplementalRuntimeExec($db, 'UPDATE ' . $presentation . ' SET `presentation_checksum`=UNHEX(\'' . $presentationChecksum . '\')');

    $importId = \ClassIdentity\DomainSupport::generateId();
    $importBinary = \ClassIdentity\DomainSupport::idToBinary($importId);
    $manifestDigest = hash('sha256', 'runtime-manifest');
    supplementalRuntimeInsert($db, 'INSERT INTO ' . $import . ' VALUES (?, ?, ?, ?, ?)',
        [$importBinary, hex2bin($manifestDigest), 2, 'COMPLETED', 1]);
    supplementalRuntimeInsert($db, 'INSERT INTO ' . $item . ' VALUES (?, ?, ?, ?, ?)',
        [$importBinary, hex2bin(hash('sha256', 'applied-item')), $photoBinary, 9001, 'APPLIED']);
    supplementalRuntimeInsert($db, 'INSERT INTO ' . $item . ' VALUES (?, ?, ?, ?, ?)',
        [$importBinary, hex2bin(hash('sha256', 'dedup-item')), $photoBinary, 9001, 'DEDUPLICATED']);
    $service = new \ClassIdentity\PrivateFullLibraryService($repository);
    $first = $service->terminalAppliedPhotosForImport($importId, $manifestDigest, 2);
    $second = (new \ClassIdentity\PrivateFullLibraryService($repository))
        ->terminalAppliedPhotosForImport($importId, $manifestDigest, 2);
    $assert(count($first) === 1 && $first === $second
        && ($first[0]['class_photo_id'] ?? null) === $photoId
        && ($first[0]['piwigo_image_id'] ?? null) === 9001,
        'durable_applied_reconciliation_invalid');
    $expectFailure(
        static fn() => $service->terminalAppliedPhotosForImport($importId, hash('sha256', 'wrong-manifest'), 2),
        'class_archive_private_library_terminal_import_invalid',
    );
    supplementalRuntimeExec($db, 'UPDATE ' . $import . ' SET `applied_count`=0');
    $expectFailure(
        static fn() => $service->terminalAppliedPhotosForImport($importId, $manifestDigest, 2),
        'class_archive_private_library_terminal_applied_count_drift',
    );
    supplementalRuntimeExec($db, 'UPDATE ' . $import . ' SET `applied_count`=1');
    supplementalRuntimeExec($db, 'UPDATE ' . $import . " SET `state`='RUNNING'");
    $expectFailure(
        static fn() => $service->terminalAppliedPhotosForImport($importId, $manifestDigest, 2),
        'class_archive_private_library_terminal_import_invalid',
    );

    fwrite(STDOUT, 'PRIVATE_REAL_SUPPLEMENTAL_RUNTIME=PASS assertions=' . $assertions
        . " paths_read=0 images_read=0\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_REAL_SUPPLEMENTAL_RUNTIME=FAIL reason='
        . preg_replace('/[^a-z0-9_.-]/', '_', strtolower($error->getMessage()))
        . ' assertions=' . $assertions . "\n");
    exit(1);
} finally {
    foreach (array_reverse($created) as $table) {
        $db->query('DROP TABLE IF EXISTS ' . supplementalRuntimeIdent($table));
    }
    $db->close();
}
