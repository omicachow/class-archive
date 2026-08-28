<?php

declare(strict_types=1);

/**
 * Read-only schema/input gate and durable postflight for the bounded owner
 * supplemental import.  It accepts only the fixed path-free container mounts
 * and emits aggregate evidence; source paths and filenames never enter this
 * process.
 */

use ClassIdentity\Repository;
use ClassIdentity\Schema;

const PRIVATE_SUPPLEMENTAL_TARGET_MANIFEST = '/private-real-full/manifests/supplemental-import-manifest.json';
const PRIVATE_SUPPLEMENTAL_TARGET_STAGING = '/private-real-full/supplemental-staging';
const PRIVATE_SUPPLEMENTAL_TARGET_SOURCES = 28;
const PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS = 26;

function supplementalTargetFail(string $reason): never
{
    $safe = preg_replace('/[^a-z0-9_.-]/', '_', strtolower($reason));
    fwrite(STDERR, "PRIVATE_REAL_SUPPLEMENTAL_TARGET=FAIL reason={$safe}\n");
    exit(1);
}

function supplementalTargetHex(mixed $value): string
{
    if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/Di', $value) !== 1) {
        throw new RuntimeException('manifest_digest_invalid');
    }
    return strtolower($value);
}

/** @return array{digest:string,item_digests:list<string>,presentations:list<string>,item_presentations:array<string,string>} */
function supplementalTargetManifest(): array
{
    $manifestPath = PRIVATE_SUPPLEMENTAL_TARGET_MANIFEST;
    $stagingPath = PRIVATE_SUPPLEMENTAL_TARGET_STAGING;
    if (realpath($manifestPath) !== $manifestPath || realpath($stagingPath) !== $stagingPath
        || !is_file($manifestPath) || is_link($manifestPath) || !is_dir($stagingPath) || is_link($stagingPath)
    ) {
        throw new RuntimeException('fixed_private_mount_invalid');
    }
    $raw = file_get_contents($manifestPath);
    if (!is_string($raw) || strlen($raw) < 20 || strlen($raw) > 4 * 1024 * 1024) {
        throw new RuntimeException('manifest_unavailable');
    }
    try {
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('manifest_json_invalid');
    }
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
        || ($manifest['kind'] ?? null) !== 'class_archive_private_supplemental_library'
        || ($manifest['canonical_identity_basis'] ?? null) !== 'PRESENTATION_SHA256'
        || !is_array($manifest['items'] ?? null)
        || count($manifest['items']) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
    ) {
        throw new RuntimeException('manifest_contract_invalid');
    }
    $digest = supplementalTargetHex($manifest['import_digest'] ?? null);
    $items = [];
    $presentations = [];
    foreach ($manifest['items'] as $item) {
        if (!is_array($item) || array_key_exists('relative_source_path', $item)
            || array_key_exists('original_filename', $item) || array_key_exists('source_root', $item)
        ) {
            throw new RuntimeException('manifest_sensitive_or_invalid_item');
        }
        $itemDigest = supplementalTargetHex($item['item_digest'] ?? null);
        $presentation = supplementalTargetHex($item['presentation_sha256'] ?? null);
        $stagingName = $item['presentation_staging_name'] ?? null;
        if (isset($items[$itemDigest]) || !is_string($stagingName)
            || !hash_equals('frs-' . $presentation . '.jpg', $stagingName)
        ) {
            throw new RuntimeException('manifest_item_identity_invalid');
        }
        $file = $stagingPath . '/' . $stagingName;
        if (realpath($file) !== $file || !is_file($file) || is_link($file)
            || !hash_equals($presentation, (string) hash_file('sha256', $file))
        ) {
            throw new RuntimeException('presentation_integrity_invalid');
        }
        $items[$itemDigest] = $presentation;
        $presentations[$presentation] = true;
    }
    if (count($items) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
        || count($presentations) !== PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
    ) {
        throw new RuntimeException('supplemental_26_plus_2_contract_invalid');
    }
    return [
        'digest' => $digest,
        'item_digests' => array_keys($items),
        'presentations' => array_keys($presentations),
        'item_presentations' => $items,
    ];
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    supplementalTargetFail('runtime_forbidden');
}
$action = (string) ($_SERVER['argv'][1] ?? '');
if (!in_array($action, ['schema', 'preflight', 'postflight'], true)
    || getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1'
    || getenv('CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL') !== '1'
    || getenv('CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_APPLY') !== '1'
) {
    supplementalTargetFail('supplemental_runtime_required');
}

try {
    $manifest = $action === 'schema' ? null : supplementalTargetManifest();
    chdir('/var/www/html/piwigo') || throw new RuntimeException('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    if (!class_exists(Schema::class) || Schema::CURRENT_VERSION !== 17) {
        throw new RuntimeException('schema_source_not_v17');
    }
    $repository = Repository::fromPiwigo();
    $migration = $repository->fetchOne(
        'SELECT COUNT(*) AS `ledger_count`,COALESCE(MAX(`version`),0) AS `schema_version` FROM `'
            . $repository->table('migration') . '`',
    );
    if ($migration === null || (int) ($migration['schema_version'] ?? 0) !== 17
        || (int) ($migration['ledger_count'] ?? 0) !== 17
    ) {
        throw new RuntimeException('target_schema_not_exact_v17');
    }
    foreach (['photo_source', 'photo_source_presentation', 'private_library_import', 'private_library_import_item'] as $suffix) {
        $repository->fetchOne('SELECT COUNT(*) AS `rows` FROM `' . $repository->table($suffix) . '`');
    }
    $admins = $repository->fetchAll(
        'SELECT `piwigo_user_id` FROM `' . $repository->table('principal') . '` '
            . "WHERE `principal_type`='SYSTEM_ACCOUNT' AND `system_role`='SYSTEM_ADMIN' AND `state`='ACTIVE' LIMIT 2",
    );
    if (count($admins) !== 1 || (int) ($admins[0]['piwigo_user_id'] ?? 0) <= 0) {
        throw new RuntimeException('system_admin_unavailable');
    }

    if ($action === 'schema') {
        fwrite(STDOUT, "PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=schema schema=17 source_paths=NOT_READ\n");
        exit(0);
    }

    if ($action === 'preflight') {
        $itemPlaceholders = implode(',', array_fill(0, count($manifest['item_digests']), '?'));
        $sourceState = $repository->fetchOne(
            'SELECT COUNT(*) AS `sources` FROM `' . $repository->table('photo_source_presentation') . '` '
                . 'WHERE `source_identity_digest` IN (' . $itemPlaceholders . ')',
            array_map(static fn(string $digest): string => hex2bin($digest), $manifest['item_digests']),
        );
        $presentationPlaceholders = implode(',', array_fill(0, count($manifest['presentations']), '?'));
        $canonicalState = $repository->fetchOne(
            'SELECT COUNT(*) AS `photos` FROM `' . $repository->table('photo') . '` '
                . "WHERE `state`='ACTIVE' AND `media_checksum` IN (" . $presentationPlaceholders . ')',
            array_map(static fn(string $digest): string => hex2bin($digest), $manifest['presentations']),
        );
        $sourceExisting = (int) ($sourceState['sources'] ?? -1);
        $canonicalExisting = (int) ($canonicalState['photos'] ?? -1);
        if ($sourceExisting < 0 || $sourceExisting > PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
            || $canonicalExisting < 0 || $canonicalExisting > PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
            || ($sourceExisting === PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
                && $canonicalExisting !== PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS)
        ) {
            throw new RuntimeException('supplemental_preflight_26_plus_2_state_invalid');
        }
        if ($sourceExisting === 0 && $canonicalExisting > 0) {
            // A crash after the native Piwigo checkpoint and canonical
            // mapping but before transformed provenance is committed is a
            // valid resume state only when the exact manifest journal proves
            // every pre-existing presentation. Never accept a checksum-only
            // collision from the wider library.
            $imports = $repository->fetchAll(
                'SELECT `import_id`,`item_total`,`state` FROM `' . $repository->table('private_library_import') . '` '
                    . 'WHERE `manifest_digest`=? LIMIT 2',
                [hex2bin((string) $manifest['digest'])],
            );
            if (count($imports) !== 1 || (int) ($imports[0]['item_total'] ?? 0) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
                || !in_array((string) ($imports[0]['state'] ?? ''), ['RUNNING', 'COMPLETED_WITH_ERRORS'], true)
                || !is_string($imports[0]['import_id'] ?? null)
            ) {
                throw new RuntimeException('supplemental_preflight_journal_resume_invalid');
            }
            $resumeRows = $repository->fetchAll(
                'SELECT HEX(i.`item_digest`) AS `item_digest`,i.`state`,i.`piwigo_image_id`,HEX(p.`media_checksum`) AS `presentation` '
                    . 'FROM `' . $repository->table('private_library_import_item') . '` i INNER JOIN `'
                    . $repository->table('photo') . '` p ON p.`piwigo_image_id`=i.`piwigo_image_id` AND p.`state`=\'ACTIVE\' '
                    . 'WHERE i.`import_id`=? AND i.`item_digest` IN (' . $itemPlaceholders . ')',
                array_merge([$imports[0]['import_id']], array_map(static fn(string $digest): string => hex2bin($digest), $manifest['item_digests'])),
            );
            $journalPresentations = [];
            foreach ($resumeRows as $row) {
                $itemDigest = strtolower((string) ($row['item_digest'] ?? ''));
                $presentation = strtolower((string) ($row['presentation'] ?? ''));
                if (!isset($manifest['item_presentations'][$itemDigest])
                    || !hash_equals((string) $manifest['item_presentations'][$itemDigest], $presentation)
                    || !in_array((string) ($row['state'] ?? ''), ['PROCESSING', 'FAILED'], true)
                    || (int) ($row['piwigo_image_id'] ?? 0) <= 0
                ) {
                    throw new RuntimeException('supplemental_preflight_journal_resume_invalid');
                }
                $journalPresentations[$presentation] = true;
            }
            if (count($journalPresentations) !== $canonicalExisting) {
                throw new RuntimeException('supplemental_preflight_journal_resume_invalid');
            }
        }
        $mode = $sourceExisting === 0 && $canonicalExisting === 0 ? 'FRESH'
            : ($sourceExisting === PRIVATE_SUPPLEMENTAL_TARGET_SOURCES ? 'REPLAY' : 'RESUME');
        fwrite(STDOUT, 'PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=preflight schema=17 sources='
            . PRIVATE_SUPPLEMENTAL_TARGET_SOURCES . ' presentations=' . PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
            . ' mode=' . $mode . ' source_existing=' . $sourceExisting . ' canonical_existing=' . $canonicalExisting
            . " source_paths=NOT_PRESENT\n");
        exit(0);
    }

    $import = $repository->fetchAll(
        'SELECT `import_id`,`item_total`,`state`,`applied_count`,`deduplicated_count`,`failed_count` FROM `'
            . $repository->table('private_library_import') . '` WHERE `manifest_digest`=? LIMIT 2',
        [hex2bin((string) $manifest['digest'])],
    );
    if (count($import) !== 1 || (int) ($import[0]['item_total'] ?? 0) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
        || (string) ($import[0]['state'] ?? '') !== 'COMPLETED'
        || (int) ($import[0]['applied_count'] ?? -1) !== PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
        || (int) ($import[0]['deduplicated_count'] ?? -1) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES - PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
        || (int) ($import[0]['failed_count'] ?? -1) !== 0
    ) {
        throw new RuntimeException('supplemental_terminal_counts_invalid');
    }
    $importId = $import[0]['import_id'] ?? null;
    if (!is_string($importId)) {
        throw new RuntimeException('supplemental_import_identity_invalid');
    }
    $states = $repository->fetchOne(
        'SELECT COUNT(*) AS `items`,COALESCE(SUM(`state`=\'APPLIED\'),0) AS `applied`,'
            . 'COALESCE(SUM(`state`=\'DEDUPLICATED\'),0) AS `deduplicated`,COALESCE(SUM(`state`=\'FAILED\'),0) AS `failed` '
            . 'FROM `' . $repository->table('private_library_import_item') . '` WHERE `import_id`=?',
        [$importId],
    );
    if ($states === null || (int) ($states['items'] ?? 0) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
        || (int) ($states['applied'] ?? -1) !== PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
        || (int) ($states['deduplicated'] ?? -1) !== 2 || (int) ($states['failed'] ?? -1) !== 0
    ) {
        throw new RuntimeException('supplemental_item_counts_invalid');
    }
    $placeholders = implode(',', array_fill(0, count($manifest['item_digests']), '?'));
    $provenance = $repository->fetchOne(
        'SELECT COUNT(*) AS `sources`,COUNT(DISTINCT pp.`presentation_checksum`) AS `presentations` FROM `'
            . $repository->table('photo_source_presentation') . '` pp INNER JOIN `'
            . $repository->table('photo_source') . '` ps ON ps.`id`=pp.`photo_source_id` '
            . 'WHERE pp.`source_identity_digest` IN (' . $placeholders . ') AND ps.`source_kind`=\'PRIVATE_FULL\'',
        array_map(static fn(string $digest): string => hex2bin($digest), $manifest['item_digests']),
    );
    if ($provenance === null || (int) ($provenance['sources'] ?? 0) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES
        || (int) ($provenance['presentations'] ?? 0) !== PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS
    ) {
        throw new RuntimeException('supplemental_provenance_counts_invalid');
    }
    fwrite(STDOUT, 'PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=postflight schema=17 sources=28 presentations=26 '
        . "applied=26 deduplicated=2 failed=0 idempotent=PASS source_paths=NOT_PRESENT\n");
} catch (Throwable $error) {
    supplementalTargetFail($error->getMessage());
}
