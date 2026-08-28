<?php

declare(strict_types=1);

/**
 * Test-only cleanup boundary for the synthetic V4 Chrome upload lifecycle.
 *
 * It is intentionally CLI/nginx-only and requires an explicit environment
 * gate. Browser code never invokes it. Every mutable operation is bounded by
 * an opaque response UUID plus the SHA-256 of a fixture selected by Chrome;
 * it never searches by a filename, upload path fragment, account name, or a
 * random cleanup glob.
 */

const CIUL_ROOT = '/var/www/html/piwigo';

function ciulFail(string $code): never
{
    fwrite(STDERR, "V4_UPLOAD_FIXTURE=FAIL code={$code}\n");
    exit(1);
}

/** @param array<string,mixed> $value */
function ciulJson(array $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function ciulRequireCli(): void
{
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0
        || getenv('CLASS_ARCHIVE_V4_UPLOAD_LIFECYCLE') !== '1'
        || !is_file('/workspace/tests/phase3/photos-app-v4-upload-lifecycle-fixture.php')) {
        ciulFail('test_gate_required');
    }
    if (realpath(CIUL_ROOT) !== CIUL_ROOT || is_link(CIUL_ROOT)) {
        ciulFail('piwigo_root_untrusted');
    }
    chdir(CIUL_ROOT) || ciulFail('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function ciulUuid(string $value): string
{
    $value = strtolower($value);
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{12}\z/D', $value) !== 1) {
        ciulFail('uuid_invalid');
    }
    return $value;
}

function ciulChecksum(string $value): string
{
    $value = strtolower($value);
    if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
        ciulFail('checksum_invalid');
    }
    return $value;
}

function ciulSubmissionId(string $value): int
{
    if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
        ciulFail('submission_id_invalid');
    }
    return (int) $value;
}

function ciulRepo(): \ClassIdentity\Repository
{
    return \ClassIdentity\Repository::fromPiwigo();
}

function ciulHex(string $binary): string
{
    if (strlen($binary) !== 32) {
        ciulFail('binary_checksum_invalid');
    }
    return strtolower(bin2hex($binary));
}

function ciulPhotoBinary(string $uuid): string
{
    return \ClassIdentity\DomainSupport::idToBinary(ciulUuid($uuid));
}

function ciulChecksumBinary(string $checksum): string
{
    return \ClassIdentity\ClassArchivePhoto::checksumToBinary(ciulChecksum($checksum));
}

/** @return array<string,mixed> */
function ciulBaseline(): array
{
    global $prefixeTable;
    $repository = ciulRepo();
    $photo = $repository->table('photo');
    $images = query2array('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`');
    $multi = query2array(
        'SELECT COUNT(*) AS `count` FROM (SELECT `image_id` FROM `' . $prefixeTable . 'image_category` '
        . 'GROUP BY `image_id` HAVING COUNT(*) > 1) AS `multi_album_images`',
    );
    $active = $repository->fetchOne(
        'SELECT COUNT(*) AS `count`,COUNT(DISTINCT `media_reference`) AS `physical_originals` FROM `'
        . $photo . '` WHERE `state`=?',
        [\ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    $result = [
        'images' => (int) ($images[0]['count'] ?? -1),
        'active_canonical' => (int) ($active['count'] ?? -1),
        'physical_originals' => (int) ($active['physical_originals'] ?? -1),
        'multi_album_images' => (int) ($multi[0]['count'] ?? -1),
    ];
    if ($result !== ['images' => 72, 'active_canonical' => 72, 'physical_originals' => 72, 'multi_album_images' => 8]) {
        ciulFail('synthetic_baseline_drift');
    }
    return $result;
}

function ciulAssertAbsent(string $checksum): void
{
    $repository = ciulRepo();
    $photo = $repository->table('photo');
    $submission = $repository->table('submission');
    $binary = ciulChecksumBinary($checksum);
    $photoRows = $repository->fetchAll('SELECT `class_photo_id` FROM `' . $photo . '` WHERE `media_checksum`=? LIMIT 2', [$binary]);
    $submissionRows = $repository->fetchAll('SELECT `id` FROM `' . $submission . '` WHERE `sha256`=? LIMIT 2', [$binary]);
    if ($photoRows !== [] || $submissionRows !== []) {
        ciulFail('fixture_checksum_already_present');
    }
}

/** @return array{submission_id:int,photo_id:string,checksum:string,state:string} */
function ciulLocatePending(string $checksum): array
{
    $repository = ciulRepo();
    $submission = $repository->table('submission');
    $photo = $repository->table('photo');
    $binary = ciulChecksumBinary($checksum);
    $rows = $repository->fetchAll(
        'SELECT s.`id`,s.`state` AS `submission_state`,s.`sha256`,p.`class_photo_id`,p.`state` AS `photo_state`,p.`media_checksum` '
        . 'FROM `' . $submission . '` s JOIN `' . $photo . '` p ON p.`source_submission_id`=s.`id` '
        . 'WHERE s.`sha256`=? AND p.`media_checksum`=? ORDER BY s.`id` ASC LIMIT 2',
        [$binary, $binary],
    );
    if (count($rows) !== 1 || (string) ($rows[0]['submission_state'] ?? '') !== 'PENDING'
        || (string) ($rows[0]['photo_state'] ?? '') !== \ClassIdentity\ClassArchivePhoto::STATE_PENDING
        || !is_string($rows[0]['class_photo_id'] ?? null) || strlen((string) $rows[0]['class_photo_id']) !== 16
        || !is_string($rows[0]['sha256'] ?? null) || !hash_equals($binary, (string) $rows[0]['sha256'])
        || !is_string($rows[0]['media_checksum'] ?? null) || !hash_equals($binary, (string) $rows[0]['media_checksum'])) {
        ciulFail('pending_lookup_ambiguous');
    }
    return [
        'submission_id' => (int) $rows[0]['id'],
        'photo_id' => \ClassIdentity\DomainSupport::binaryToId((string) $rows[0]['class_photo_id']),
        'checksum' => ciulChecksum($checksum),
        'state' => 'PENDING',
    ];
}

/** @return array{photo_id:string,checksum:string,state:string}|null */
function ciulFindPublished(string $checksum): ?array
{
    $repository = ciulRepo();
    $photo = $repository->table('photo');
    $archive = $repository->table('archive_image');
    $binary = ciulChecksumBinary($checksum);
    $rows = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`state`,p.`media_checksum` FROM `' . $photo . '` p '
        . 'JOIN `' . $archive . '` a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
        . 'WHERE p.`media_checksum`=? AND p.`state`=? ORDER BY p.`class_photo_id` ASC LIMIT 2',
        [$binary, \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1 || !is_string($rows[0]['class_photo_id'] ?? null)
        || strlen((string) $rows[0]['class_photo_id']) !== 16 || !is_string($rows[0]['media_checksum'] ?? null)
        || !hash_equals($binary, (string) $rows[0]['media_checksum'])) {
        ciulFail('published_lookup_ambiguous');
    }
    return [
        'photo_id' => \ClassIdentity\DomainSupport::binaryToId((string) $rows[0]['class_photo_id']),
        'checksum' => ciulChecksum($checksum),
        'state' => 'PUBLISHED',
    ];
}

/** @return array{photo_id:string,checksum:string,state:string} */
function ciulLocatePublished(string $checksum): array
{
    $result = ciulFindPublished($checksum);
    if ($result === null) {
        ciulFail('published_lookup_absent');
    }
    return $result;
}

/** @param array<string,mixed> $row */
function ciulAssertPendingRef(array $row, string $field, bool $checksum): string
{
    $reference = (string) ($row[$field] ?? '');
    if (preg_match('#\Aclass_identity_pending/[a-f0-9]{48}\.(?:jpg|jpeg|png|webp)\z#D', $reference) !== 1) {
        ciulFail('pending_reference_invalid');
    }
    $path = CIUL_ROOT . '/_data/' . $reference;
    if (!is_file($path) || is_link($path)) {
        ciulFail('pending_file_missing');
    }
    if ($checksum) {
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals(ciulChecksum((string) $row['checksum']), strtolower($actual))) {
            ciulFail('pending_file_checksum_drift');
        }
    }
    return $path;
}

function ciulCompleteExactWarmup(string $uuid, int $imageId): void
{
    if (!class_exists('ClassArchiveDerivativeWarmupQueue', false)) {
        return;
    }
    foreach (\ClassArchiveDerivativeWarmupQueue::pending() as $entry) {
        if (($entry['class_photo_id'] ?? null) === $uuid && (int) ($entry['piwigo_image_id'] ?? 0) === $imageId) {
            \ClassArchiveDerivativeWarmupQueue::complete($uuid, $imageId);
            return;
        }
    }
}

function ciulAssertNoUnexpectedPublishedDependents(\ClassIdentity\Repository $repository, string $photoBinary): void
{
    $checks = [
        ['person_photo_rule', 'class_photo_id'],
        ['album', 'manual_cover_class_photo_id'],
        ['person', 'manual_cover_class_photo_id'],
        ['photo_source', 'class_photo_id'],
        ['photo_duplicate', 'left_class_photo_id'],
        ['photo_duplicate', 'right_class_photo_id'],
        ['photo_duplicate', 'canonical_class_photo_id'],
        ['batch_operation_item', 'class_photo_id'],
        ['private_library_import_item', 'class_photo_id'],
        ['photo_comment', 'class_photo_id'],
    ];
    foreach ($checks as [$suffix, $column]) {
        $row = $repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM `' . $repository->table($suffix) . '` WHERE `' . $column . '`=?',
            [$photoBinary],
        );
        if ((int) ($row['count'] ?? -1) !== 0) {
            ciulFail('unexpected_published_dependent');
        }
    }
}

function ciulAssertPendingReferencesExclusive(\ClassIdentity\Repository $repository, int $submissionId, string $storageReference, string $thumbnailReference): void
{
    if ($storageReference === '' || $thumbnailReference === '' || hash_equals($storageReference, $thumbnailReference)) {
        ciulFail('pending_references_shared');
    }
    $submission = $repository->table('submission');
    $row = $repository->fetchOne(
        'SELECT COUNT(*) AS `count` FROM `' . $submission . '` WHERE `id`<>? AND '
        . '(`storage_ref` IN (?,?) OR `thumbnail_ref` IN (?,?))',
        [$submissionId, $storageReference, $thumbnailReference, $storageReference, $thumbnailReference],
    );
    if ((int) ($row['count'] ?? -1) !== 0) {
        ciulFail('pending_reference_not_exclusive');
    }
}

function ciulCleanupPublished(string $uuid, string $checksum): void
{
    global $prefixeTable;
    $uuid = ciulUuid($uuid);
    $checksum = ciulChecksum($checksum);
    $repository = ciulRepo();
    $photo = $repository->table('photo');
    $archive = $repository->table('archive_image');
    $binaryId = ciulPhotoBinary($uuid);
    $binaryChecksum = ciulChecksumBinary($checksum);
    $rows = $repository->fetchAll(
        'SELECT p.`piwigo_image_id`,p.`media_reference`,p.`immich_asset_id`,p.`state`,p.`source_submission_id`,p.`media_checksum`,a.`era` '
        . 'FROM `' . $photo . '` p JOIN `' . $archive . '` a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
        . 'WHERE p.`class_photo_id`=? AND p.`media_checksum`=? LIMIT 2',
        [$binaryId, $binaryChecksum],
    );
    if (count($rows) !== 1 || (string) ($rows[0]['state'] ?? '') !== \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE
        || $rows[0]['source_submission_id'] !== null || $rows[0]['immich_asset_id'] !== null
        || !in_array((string) ($rows[0]['era'] ?? ''), ['HERITAGE', 'LIVING'], true)) {
        ciulFail('published_mapping_ambiguous');
    }
    $imageId = (int) ($rows[0]['piwigo_image_id'] ?? 0);
    $reference = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($rows[0]['media_reference'] ?? ''));
    if ($imageId <= 0 || !str_starts_with($reference, 'upload/')) {
        ciulFail('published_media_reference_invalid');
    }
    $escapedReference = pwg_db_real_escape_string($reference);
    $imageRows = query2array('SELECT `id`,`path` FROM `' . $prefixeTable . 'images` WHERE `path`=\'' . $escapedReference . '\'');
    if (count($imageRows) !== 1 || (int) ($imageRows[0]['id'] ?? 0) !== $imageId
        || !hash_equals($reference, (string) ($imageRows[0]['path'] ?? ''))) {
        ciulFail('published_core_mapping_drift');
    }
    $sourcePath = CIUL_ROOT . '/' . $reference;
    $actual = hash_file('sha256', $sourcePath);
    if (!is_file($sourcePath) || is_link($sourcePath) || !is_string($actual) || !hash_equals($checksum, strtolower($actual))) {
        ciulFail('published_media_checksum_drift');
    }
    $index = $repository->fetchOne('SELECT `immich_asset_id` FROM `' . $repository->table('ai_asset_index') . '` WHERE `class_photo_id`=? LIMIT 1', [$binaryId]);
    if ($index !== null && $index['immich_asset_id'] !== null) {
        // Never delete a possibly indexed external asset from an HTTP test.
        ciulFail('published_external_ai_asset_present');
    }
    ciulAssertNoUnexpectedPublishedDependents($repository, $binaryId);
    ciulCompleteExactWarmup($uuid, $imageId);
    $repository->transaction(function (\ClassIdentity\Repository $transaction) use ($binaryId, $binaryChecksum, $imageId, $uuid): void {
        // These are generated projections for this test photo only. If it
        // unexpectedly became a cover, discard that derived collection/snapshot
        // then force a complete synthetic projection rebuild in the wrapper.
        $collections = $transaction->fetchAll('SELECT `auto_collection_id` FROM `' . $transaction->table('auto_collection') . '` WHERE `cover_class_photo_id`=?', [$binaryId]);
        foreach ($collections as $collection) {
            $id = $collection['auto_collection_id'] ?? null;
            if (!is_string($id) || strlen($id) !== 16) ciulFail('generated_collection_invalid');
            $transaction->execute('DELETE FROM `' . $transaction->table('auto_collection_photo') . '` WHERE `auto_collection_id`=?', [$id]);
            $transaction->execute('DELETE FROM `' . $transaction->table('auto_collection') . '` WHERE `auto_collection_id`=? AND `cover_class_photo_id`=?', [$id, $binaryId]);
        }
        $transaction->execute('DELETE FROM `' . $transaction->table('auto_collection_photo') . '` WHERE `class_photo_id`=?', [$binaryId]);
        $snapshots = $transaction->fetchAll('SELECT DISTINCT `snapshot_id` FROM `' . $transaction->table('collection_snapshot_item') . '` WHERE `cover_class_photo_id`=?', [$binaryId]);
        foreach ($snapshots as $snapshot) {
            $id = $snapshot['snapshot_id'] ?? null;
            if (!is_string($id) || strlen($id) !== 16) ciulFail('generated_snapshot_invalid');
            $transaction->execute('DELETE FROM `' . $transaction->table('collection_snapshot_pointer') . '` WHERE `active_snapshot_id`=?', [$id]);
            $transaction->execute('DELETE FROM `' . $transaction->table('collection_snapshot_item') . '` WHERE `snapshot_id`=?', [$id]);
            $transaction->execute('DELETE FROM `' . $transaction->table('collection_snapshot') . '` WHERE `snapshot_id`=?', [$id]);
        }
        $transaction->execute('DELETE FROM `' . $transaction->table('ai_index_job') . '` WHERE `class_photo_id`=?', [$binaryId]);
        $transaction->execute('DELETE FROM `' . $transaction->table('ai_asset_index') . '` WHERE `class_photo_id`=?', [$binaryId]);
        $transaction->execute('DELETE FROM `' . $transaction->table('read_photo') . '` WHERE `class_photo_id`=?', [$binaryId]);
        $transaction->execute('DELETE FROM `' . $transaction->table('audit_event') . '` WHERE `target_id`=?', [$uuid]);
        $archiveDeleted = $transaction->execute('DELETE FROM `' . $transaction->table('archive_image') . '` WHERE `piwigo_image_id`=?', [$imageId]);
        $photoDeleted = $transaction->execute('DELETE FROM `' . $transaction->table('photo') . '` WHERE `class_photo_id`=? AND `media_checksum`=? AND `state`=?', [$binaryId, $binaryChecksum, \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE]);
        if ($archiveDeleted !== 1 || $photoDeleted !== 1) ciulFail('published_exact_delete_failed');
    });
    if (delete_elements([$imageId], true) !== 1 || query2array('SELECT `id` FROM `' . $prefixeTable . 'images` WHERE `id`=' . $imageId) !== [] || is_file($sourcePath) || is_link($sourcePath)) {
        ciulFail('published_core_delete_failed');
    }
}

function ciulCleanupPending(int $submissionId, string $uuid, string $checksum): void
{
    $submissionId = ciulSubmissionId((string) $submissionId);
    $uuid = ciulUuid($uuid);
    $checksum = ciulChecksum($checksum);
    $repository = ciulRepo();
    $submission = $repository->table('submission');
    $photo = $repository->table('photo');
    $binaryId = ciulPhotoBinary($uuid);
    $binaryChecksum = ciulChecksumBinary($checksum);
    $rows = $repository->fetchAll(
        'SELECT s.`storage_ref`,s.`thumbnail_ref`,s.`sha256` AS `checksum`,s.`state` AS `submission_state`,p.`state` AS `photo_state`,p.`media_checksum` '
        . 'FROM `' . $submission . '` s JOIN `' . $photo . '` p ON p.`source_submission_id`=s.`id` '
        . 'WHERE s.`id`=? AND p.`class_photo_id`=? AND s.`sha256`=? AND p.`media_checksum`=? LIMIT 2',
        [$submissionId, $binaryId, $binaryChecksum, $binaryChecksum],
    );
    if (count($rows) !== 1 || (string) ($rows[0]['submission_state'] ?? '') !== 'PENDING'
        || (string) ($rows[0]['photo_state'] ?? '') !== \ClassIdentity\ClassArchivePhoto::STATE_PENDING) {
        ciulFail('pending_mapping_ambiguous');
    }
    $rows[0]['checksum'] = $checksum;
    $sourcePath = ciulAssertPendingRef($rows[0], 'storage_ref', true);
    $thumbnailPath = ciulAssertPendingRef($rows[0], 'thumbnail_ref', false);
    ciulAssertPendingReferencesExclusive(
        $repository,
        $submissionId,
        (string) $rows[0]['storage_ref'],
        (string) $rows[0]['thumbnail_ref'],
    );
    $repository->transaction(function (\ClassIdentity\Repository $transaction) use ($submissionId, $binaryId, $binaryChecksum): void {
        $photoDeleted = $transaction->execute('DELETE FROM `' . $transaction->table('photo') . '` WHERE `class_photo_id`=? AND `source_submission_id`=? AND `media_checksum`=? AND `state`=?', [$binaryId, $submissionId, $binaryChecksum, \ClassIdentity\ClassArchivePhoto::STATE_PENDING]);
        $transaction->execute('DELETE FROM `' . $transaction->table('audit_event') . '` WHERE `target_type`=? AND `target_id`=?', ['SUBMISSION', (string) $submissionId]);
        $submissionDeleted = $transaction->execute('DELETE FROM `' . $transaction->table('submission') . '` WHERE `id`=? AND `state`=? AND `sha256`=?', [$submissionId, 'PENDING', $binaryChecksum]);
        if ($photoDeleted !== 1 || $submissionDeleted !== 1) ciulFail('pending_exact_delete_failed');
    });
    foreach ([$sourcePath, $thumbnailPath] as $path) {
        if (!unlink($path) || is_file($path) || is_link($path)) ciulFail('pending_file_delete_failed');
    }
}

/** @return array{cleaned:bool,absent:bool} */
function ciulCleanupPublishedByChecksum(string $checksum): array
{
    $found = ciulFindPublished($checksum);
    if ($found === null) {
        return ['cleaned' => false, 'absent' => true];
    }
    ciulCleanupPublished($found['photo_id'], $checksum);
    return ['cleaned' => true, 'absent' => false];
}

try {
    ciulRequireCli();
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    $arguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
    $command = $arguments[0] ?? '';
    if ($command === 'baseline' && count($arguments) === 1) ciulJson(ciulBaseline());
    if ($command === 'assert-absent' && count($arguments) === 2) {
        ciulAssertAbsent((string) $arguments[1]);
        ciulJson(['absent' => true]);
    }
    if ($command === 'locate-pending' && count($arguments) === 2) ciulJson(ciulLocatePending((string) $arguments[1]));
    if ($command === 'locate-published' && count($arguments) === 2) ciulJson(ciulLocatePublished((string) $arguments[1]));
    if ($command === 'cleanup-published' && count($arguments) === 3) {
        ciulCleanupPublished((string) $arguments[1], (string) $arguments[2]);
        ciulJson(['cleaned' => true]);
    }
    if ($command === 'cleanup-published-by-checksum' && count($arguments) === 2) {
        ciulJson(ciulCleanupPublishedByChecksum((string) $arguments[1]));
    }
    if ($command === 'cleanup-pending' && count($arguments) === 4) {
        ciulCleanupPending(ciulSubmissionId((string) $arguments[1]), (string) $arguments[2], (string) $arguments[3]);
        ciulJson(['cleaned' => true]);
    }
    ciulFail('argument_invalid');
} catch (Throwable $error) {
    fwrite(STDERR, 'V4_UPLOAD_FIXTURE=FAIL code=' . preg_replace('/[^a-z0-9_]/i', '_', $error->getMessage()) . "\n");
    exit(1);
}
