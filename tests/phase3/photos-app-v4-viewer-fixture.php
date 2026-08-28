<?php

declare(strict_types=1);

/**
 * Synthetic-only fixture for the V4 Chrome Viewer companion.
 *
 * `prepare` creates exactly two opaque, run-scoped Anonymous comments on two
 * visible HERITAGE photos. The browser receives only their public UUIDs and
 * must render context pseudonyms without leaking the backing principal. The
 * PowerShell wrapper always calls `cleanup`, which removes only exact marker
 * rows and their create-audit rows. This never scans a private runtime, a
 * filename, a source path, or an album name.
 */

const CAVF_PIWIGO_ROOT = '/var/www/html/piwigo';

function cavfFail(string $code): never
{
    fwrite(STDERR, 'V4_VIEWER_FIXTURE=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $code) . "\n");
    exit(1);
}

function cavfRun(string $value): string
{
    if (preg_match('/\A[a-f0-9]{16}\z/D', $value) !== 1) {
        cavfFail('run_invalid');
    }
    return $value;
}

function cavfRequireRuntime(): void
{
    if (PHP_SAPI !== 'cli'
        || getenv('CLASS_ARCHIVE_V4_VIEWER_FIXTURE') !== '1'
        || !function_exists('posix_geteuid')
        || posix_geteuid() === 0
        || !is_file('/workspace/tests/phase3/photos-app-v4-viewer-fixture.php')
    ) {
        cavfFail('explicit_unprivileged_synthetic_runtime_required');
    }
    if (realpath(CAVF_PIWIGO_ROOT) !== CAVF_PIWIGO_ROOT || is_link(CAVF_PIWIGO_ROOT)) {
        cavfFail('piwigo_root_untrusted');
    }
    chdir(CAVF_PIWIGO_ROOT) || cavfFail('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function cavfBootstrap(): \ClassIdentity\Repository
{
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    if (!class_exists(\ClassIdentity\PhotoCommentService::class, false)
        || !defined('CLASS_IDENTITY_VERSION')
        || \ClassIdentity\Schema::CURRENT_VERSION !== 18) {
        cavfFail('v18_comment_domain_required');
    }
    // This is deliberately a read-only attestation. The fixture must never
    // "repair" schema state as a side effect of creating its two comments.
    \ClassIdentity\Schema::fromPiwigo((string) CLASS_IDENTITY_VERSION)->verifyCurrent();
    return \ClassIdentity\Repository::fromPiwigo();
}

function cavfMarker(string $run, string $suffix): string
{
    if (!in_array($suffix, ['a', 'b'], true)) {
        cavfFail('marker_suffix_invalid');
    }
    return 'v4-viewer-fixture-' . $run . '-' . $suffix;
}

/** @return array{user_id:int} */
function cavfAnonymousFixtureUser(\ClassIdentity\Repository $repository): array
{
    global $prefixeTable;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        cavfFail('piwigo_prefix_invalid');
    }
    $rows = $repository->fetchAll(
        'SELECT `id` FROM `' . $prefixeTable . "users` WHERE `username`='fixture-anonymous' LIMIT 2",
    );
    if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) <= 0) {
        cavfFail('anonymous_fixture_user_missing');
    }
    $userId = (int) $rows[0]['id'];
    \ClassIdentity\Access::resetRepositoryForTests();
    $context = \ClassIdentity\Access::resolveAuthorizationContext($userId);
    if (!is_array($context) || ($context['role'] ?? null) !== \ClassIdentity\Access::ROLE_ANONYMOUS
        || (int) ($context['principal_id'] ?? 0) <= 0) {
        cavfFail('anonymous_fixture_principal_invalid');
    }
    return ['user_id' => $userId];
}

/** @return list<array{photo_id:string,image_id:int}> */
function cavfHeritagePhotos(\ClassIdentity\Repository $repository): array
{
    global $prefixeTable;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        cavfFail('piwigo_prefix_invalid');
    }
    $heritage = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . "categories` WHERE `permalink`='class-archive-heritage' LIMIT 1",
    );
    $living = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . "categories` WHERE `permalink`='class-archive-living' LIMIT 1",
    );
    $heritageId = (int) ($heritage['id'] ?? 0);
    $livingId = (int) ($living['id'] ?? 0);
    if ($heritageId <= 0 || $livingId <= 0 || $heritageId === $livingId) {
        cavfFail('era_roots_invalid');
    }
    $photo = $repository->table('photo');
    $archive = $repository->table('archive_image');
    $rows = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`piwigo_image_id` FROM `' . $photo . '` p '
        . 'INNER JOIN `' . $archive . '` a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
        . 'INNER JOIN `' . $prefixeTable . 'image_category` ic ON ic.`image_id`=p.`piwigo_image_id` '
        . 'INNER JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
        . "WHERE p.`state`='ACTIVE' AND p.`piwigo_image_id` IS NOT NULL AND a.`era`='HERITAGE' "
        . 'GROUP BY p.`class_photo_id`,p.`piwigo_image_id` '
        . 'HAVING MAX(CASE WHEN ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END)=1 '
        . 'AND MAX(CASE WHEN ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END)=0 '
        . 'ORDER BY p.`piwigo_image_id` ASC LIMIT 2',
        [$heritageId, $heritageId, $livingId, $livingId],
    );
    if (count($rows) !== 2) {
        cavfFail('two_heritage_fixture_photos_required');
    }
    $result = [];
    foreach ($rows as $row) {
        $binary = $row['class_photo_id'] ?? null;
        $imageId = (int) ($row['piwigo_image_id'] ?? 0);
        if (!is_string($binary) || strlen($binary) !== 16 || $imageId <= 0) {
            cavfFail('heritage_fixture_photo_invalid');
        }
        $result[] = [
            'photo_id' => \ClassIdentity\DomainSupport::binaryToId($binary),
            'image_id' => $imageId,
        ];
    }
    if ($result[0]['photo_id'] === $result[1]['photo_id']) {
        cavfFail('heritage_fixture_photo_duplicate');
    }
    return $result;
}

/** @return list<array{comment_id:string}> */
function cavfExisting(\ClassIdentity\Repository $repository, string $run): array
{
    $comment = $repository->table('photo_comment');
    $rows = $repository->fetchAll(
        'SELECT `comment_id` FROM `' . $comment . '` WHERE `body` IN (?,?) ORDER BY `comment_id` ASC',
        [cavfMarker($run, 'a'), cavfMarker($run, 'b')],
    );
    $result = [];
    foreach ($rows as $row) {
        $binary = $row['comment_id'] ?? null;
        if (!is_string($binary) || strlen($binary) !== 16) {
            cavfFail('fixture_comment_id_invalid');
        }
        $result[] = ['comment_id' => \ClassIdentity\DomainSupport::binaryToId($binary)];
    }
    return $result;
}

function cavfAssertPublicAlias(\ClassIdentity\PhotoCommentService $service, array $photo, string $commentId): string
{
    $projection = $service->listForVisiblePhoto(
        $photo['photo_id'],
        $photo['image_id'],
        \ClassIdentity\Access::ROLE_ANONYMOUS,
        null,
        100,
    );
    $item = null;
    foreach ((array) ($projection['items'] ?? []) as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $commentId) {
            $item = $candidate;
            break;
        }
    }
    $author = is_array($item) ? ($item['author'] ?? null) : null;
    $label = is_array($author) ? ($author['label'] ?? null) : null;
    if (!is_array($author) || ($author['kind'] ?? null) !== 'ANONYMOUS'
        || !is_string($label) || preg_match('/\A匿名\s+[^\s]{1,32}\z/uD', $label) !== 1) {
        cavfFail('anonymous_public_alias_invalid');
    }
    return $label;
}

function cavfPrepare(\ClassIdentity\Repository $repository, string $run): void
{
    if (cavfExisting($repository, $run) !== []) {
        cavfFail('fixture_marker_already_present');
    }
    $anonymous = cavfAnonymousFixtureUser($repository);
    $photos = cavfHeritagePhotos($repository);
    $service = \ClassIdentity\PhotoCommentService::fromPiwigo();
    $first = $service->create($anonymous['user_id'], $photos[0]['photo_id'], $photos[0]['image_id'], null, cavfMarker($run, 'a'));
    $second = $service->create($anonymous['user_id'], $photos[1]['photo_id'], $photos[1]['image_id'], null, cavfMarker($run, 'b'));
    $firstId = $first['comment_id'] ?? null;
    $secondId = $second['comment_id'] ?? null;
    if (!is_string($firstId) || !is_string($secondId)) {
        cavfFail('fixture_comment_create_invalid');
    }
    \ClassIdentity\DomainSupport::idToBinary($firstId);
    \ClassIdentity\DomainSupport::idToBinary($secondId);
    $firstAlias = cavfAssertPublicAlias($service, $photos[0], $firstId);
    $secondAlias = cavfAssertPublicAlias($service, $photos[1], $secondId);
    if (hash_equals($firstAlias, $secondAlias)) {
        cavfFail('anonymous_context_alias_not_distinct');
    }
    fwrite(
        STDOUT,
        'V4_VIEWER_FIXTURE=READY run=' . $run
        . ' photo_a=' . $photos[0]['photo_id'] . ' photo_b=' . $photos[1]['photo_id']
        . ' comment_a=' . strtolower($firstId) . ' comment_b=' . strtolower($secondId) . "\n",
    );
}

function cavfCleanup(\ClassIdentity\Repository $repository, string $run): void
{
    $rows = cavfExisting($repository, $run);
    if (count($rows) > 2) {
        cavfFail('fixture_comment_count_invalid');
    }
    if ($rows === []) {
        fwrite(STDOUT, 'V4_VIEWER_FIXTURE=CLEANUP run=' . $run . "\n");
        return;
    }
    $comment = $repository->table('photo_comment');
    $audit = $repository->table('audit_event');
    $ids = array_map(static fn (array $row): string => $row['comment_id'], $rows);
    $allowedBodies = [cavfMarker($run, 'a'), cavfMarker($run, 'b')];
    $repository->transaction(function (\ClassIdentity\Repository $transaction) use ($comment, $audit, $ids, $allowedBodies): void {
        foreach ($ids as $id) {
            $binary = \ClassIdentity\DomainSupport::idToBinary($id);
            $row = $transaction->fetchOne(
                'SELECT `author_role`,`state`,`body`,`parent_comment_id` FROM `' . $comment . '` WHERE `comment_id`=? FOR UPDATE',
                [$binary],
            );
            if ($row === null || ($row['author_role'] ?? null) !== \ClassIdentity\Access::ROLE_ANONYMOUS
                || ($row['state'] ?? null) !== 'ACTIVE' || ($row['parent_comment_id'] ?? null) !== null
                || !is_string($row['body'] ?? null) || !in_array($row['body'], $allowedBodies, true)) {
                cavfFail('fixture_comment_cleanup_boundary_invalid');
            }
            $auditRows = $transaction->fetchAll(
                'SELECT `id`,`action`,`target_type`,`target_id` FROM `' . $audit . '` '
                . "WHERE `target_type`='PHOTO_COMMENT' AND `target_id`=?",
                [$id],
            );
            if (count($auditRows) !== 1 || ($auditRows[0]['action'] ?? null) !== 'PHOTO_COMMENT_CREATE'
                || ($auditRows[0]['target_type'] ?? null) !== 'PHOTO_COMMENT' || ($auditRows[0]['target_id'] ?? null) !== $id) {
                cavfFail('fixture_comment_audit_boundary_invalid');
            }
            $auditDeleted = $transaction->execute(
                'DELETE FROM `' . $audit . '` WHERE `id`=? AND `action`=? AND `target_type`=? AND `target_id`=?',
                [(int) $auditRows[0]['id'], 'PHOTO_COMMENT_CREATE', 'PHOTO_COMMENT', $id],
            );
            $commentDeleted = $transaction->execute(
                'DELETE FROM `' . $comment . '` WHERE `comment_id`=? AND `author_role`=? AND `state`=?',
                [$binary, \ClassIdentity\Access::ROLE_ANONYMOUS, 'ACTIVE'],
            );
            if ($auditDeleted !== 1 || $commentDeleted !== 1) {
                cavfFail('fixture_comment_exact_cleanup_failed');
            }
        }
    });
    if (cavfExisting($repository, $run) !== []) {
        cavfFail('fixture_comment_cleanup_residue');
    }
    fwrite(STDOUT, 'V4_VIEWER_FIXTURE=CLEANUP run=' . $run . "\n");
}

try {
    cavfRequireRuntime();
    $repository = cavfBootstrap();
    $arguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
    $action = $arguments[0] ?? '';
    $run = cavfRun((string) ($arguments[1] ?? ''));
    if (count($arguments) !== 2) {
        cavfFail('usage_invalid');
    }
    if ($action === 'prepare') {
        cavfPrepare($repository, $run);
        exit(0);
    }
    if ($action === 'cleanup') {
        cavfCleanup($repository, $run);
        exit(0);
    }
    cavfFail('action_invalid');
} catch (Throwable $error) {
    cavfFail($error->getMessage());
}
