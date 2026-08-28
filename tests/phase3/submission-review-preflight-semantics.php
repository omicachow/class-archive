<?php

declare(strict_types=1);

/**
 * Synthetic-only runtime semantics for the approval preflight/claim boundary.
 *
 * The fixture borrows one existing synthetic submission inside a surrounding
 * InnoDB transaction and always rolls it back. It never invokes the Piwigo
 * upload path, touches an original, or changes a projection generation.
 */

function submissionPreflightFail(string $reason): never
{
    throw new RuntimeException($reason);
}

/** @param list<array<string,mixed>> $rows */
function submissionPreflightSnapshot(array $rows): string
{
    return json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "SUBMISSION_REVIEW_PREFLIGHT=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "SUBMISSION_REVIEW_PREFLIGHT=FAIL reason=nginx_user_required\n");
    exit(1);
}

chdir('/var/www/html/piwigo') || submissionPreflightFail('application_root_unavailable');
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

$assertions = 0;
$exit = 0;
$repository = null;
$projectionBefore = null;
try {
    $workspaceRoot = dirname(__DIR__, 2);
    $serviceSource = file_get_contents($workspaceRoot . '/plugins/ClassIdentity/src/SubmissionService.php');
    $auditSource = file_get_contents($workspaceRoot . '/plugins/ClassIdentity/src/Audit.php');
    if (!is_string($serviceSource) || !is_string($auditSource)) {
        submissionPreflightFail('workspace_sources_unavailable');
    }
    $albumPreflight = strpos($serviceSource, '$albumId = $this->requireHeritageAlbum($albumId);');
    $sourcePreflight = strpos($serviceSource, '$sourcePath = $this->validatePendingApprovalSource($preflightRow);');
    $claimBoundary = strpos($serviceSource, '$row = $this->claimSubmissionForReview($submissionId, $adminContext);');
    $toctouRecheck = strpos($serviceSource, 'self::approvalCandidateFingerprint($preflightRow)');
    $toctouRelease = strpos($serviceSource, '$this->releaseFailedApprovalClaim($row, $adminContext, $reason, $error);');
    $coreMutation = strpos($serviceSource, '$imageId = add_uploaded_file(');
    if (
        $albumPreflight === false
        || $sourcePreflight === false
        || $claimBoundary === false
        || !($albumPreflight < $claimBoundary && $sourcePreflight < $claimBoundary)
    ) {
        submissionPreflightFail('preflight_not_before_claim');
    }
    ++$assertions;
    if (
        $toctouRecheck === false
        || $toctouRelease === false
        || $coreMutation === false
        || !($claimBoundary < $toctouRecheck && $toctouRecheck < $toctouRelease && $toctouRelease < $coreMutation)
    ) {
        submissionPreflightFail('toctou_release_not_before_core_mutation');
    }
    ++$assertions;
    if (str_contains($serviceSource, 'ProjectionMutationBoundary::invalidatePhotos(')) {
        submissionPreflightFail('approval_claim_invalidates_projection_before_core_mutation');
    }
    ++$assertions;
    if (!str_contains($auditSource, "'SUBMISSION_APPROVE_ABORT',")) {
        submissionPreflightFail('failed_claim_release_not_high_risk_audited');
    }
    ++$assertions;

    if (!class_exists(ClassIdentitySubmissionService::class)) {
        submissionPreflightFail('submission_service_unavailable');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $admin = $repository->fetchOne(
        'SELECT `id`,`piwigo_user_id` FROM `' . $repository->table('principal') . '` '
            . "WHERE `principal_type`='SYSTEM_ACCOUNT' AND `system_role`='SYSTEM_ADMIN' AND `state`='ACTIVE' "
            . 'ORDER BY `id` ASC LIMIT 1',
    );
    $adminUserId = (int) ($admin['piwigo_user_id'] ?? 0);
    $adminContext = \ClassIdentity\Access::resolveAuthorizationContext($adminUserId);
    if ($adminUserId <= 0 || ($adminContext['role'] ?? null) !== \ClassIdentity\Access::ROLE_SYSTEM_ADMIN) {
        submissionPreflightFail('synthetic_admin_unavailable');
    }
    $family = $repository->fetchOne(
        'SELECT p.`id` AS `principal_id`,p.`piwigo_user_id`,a.`id` AS `account_id`,'
            . 's.`id` AS `seat_id`,i.`id` AS `identity_id` '
            . 'FROM `' . $repository->table('principal') . '` p '
            . 'JOIN `' . $repository->table('account') . '` a ON a.`id`=p.`account_id` '
            . 'JOIN `' . $repository->table('seat') . '` s ON s.`id`=a.`seat_id` '
            . 'JOIN `' . $repository->table('identity') . '` i ON i.`id`=s.`identity_id` '
            . "WHERE p.`state`='ACTIVE' AND a.`state`='ACTIVE' AND s.`state`='ACTIVE' "
            . "AND i.`state`='ACTIVE' AND s.`seat_type`='FAMILY' ORDER BY p.`id` ASC LIMIT 1",
    );
    if ($family === null || (int) ($family['principal_id'] ?? 0) <= 0) {
        submissionPreflightFail('synthetic_family_unavailable');
    }
    global $prefixeTable;
    $heritage = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=? LIMIT 1',
        ['class-archive-heritage'],
    );
    $heritageId = (int) ($heritage['id'] ?? 0);
    if ($heritageId <= 0) {
        submissionPreflightFail('synthetic_heritage_album_unavailable');
    }
    $projectionSql = 'SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,'
        . 'HEX(`generation`) AS `generation`,`item_count`,`invalidated_reason`,`built_at`,`invalidated_at`,`updated_at` '
        . 'FROM `' . $repository->table('read_projection') . '` '
        . "WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key`";
    $projectionRows = $repository->fetchAll($projectionSql);
    if (count($projectionRows) !== 6) {
        submissionPreflightFail('projection_fixture_incomplete');
    }
    foreach ($projectionRows as $projectionRow) {
        if (($projectionRow['state'] ?? null) !== 'ACTIVE') {
            submissionPreflightFail('projection_fixture_not_active');
        }
    }
    $projectionBefore = submissionPreflightSnapshot($projectionRows);
    $submissionCountBefore = (int) ($repository->fetchOne(
        'SELECT COUNT(*) AS `count` FROM `' . $repository->table('submission') . '`',
    )['count'] ?? -1);
    ++$assertions;

    $rollbackMarker = 'submission_preflight_fixture_rollback';
    try {
        $repository->transaction(function (\ClassIdentity\Repository $transaction) use (
            $family,
            $admin,
            $adminContext,
            $adminUserId,
            $heritageId,
            $projectionSql,
            $projectionBefore,
            $rollbackMarker,
            &$assertions,
        ): void {
            $nonce = bin2hex(random_bytes(24));
            $thumbnailNonce = bin2hex(random_bytes(24));
            $transaction->execute(
                'INSERT INTO `' . $transaction->table('submission') . '` '
                    . '(`seat_id`,`account_id`,`principal_id`,`identity_id`,`state`,`original_filename`,`storage_ref`,`thumbnail_ref`,'
                    . '`mime_type`,`extension`,`byte_size`,`sha256`,`width`,`height`,`date_precision`,`uploaded_at`,`created_at`,`updated_at`) '
                    . "VALUES (?,?,?,?,'PENDING','synthetic-preflight.jpg',?,?,'image/jpeg','jpg',1,?,1,1,'UNKNOWN',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
                [
                    (int) $family['seat_id'],
                    (int) $family['account_id'],
                    (int) $family['principal_id'],
                    (int) $family['identity_id'],
                    'class_identity_pending/' . $nonce . '.jpg',
                    'class_identity_pending/' . $thumbnailNonce . '.jpg',
                    random_bytes(32),
                ],
            );
            $submissionId = $transaction->lastInsertId();
            if ($submissionId <= 0) {
                submissionPreflightFail('synthetic_submission_insert_failed');
            }
            $service = new ClassIdentitySubmissionService(
                $transaction,
                (string) realpath(PHPWG_ROOT_PATH . '_data') . '/class_identity_pending',
            );

            try {
                $service->review($submissionId, $adminUserId, true, '合成无效相册校验', 16777215);
                submissionPreflightFail('invalid_album_was_accepted');
            } catch (InvalidArgumentException $error) {
                if ($error->getMessage() !== 'heritage_album_invalid') {
                    throw $error;
                }
            }
            $afterAlbum = $transaction->fetchOne(
                'SELECT `state`,`reviewed_by_principal_id` FROM `' . $transaction->table('submission') . '` WHERE `id`=?',
                [$submissionId],
            );
            if (($afterAlbum['state'] ?? null) !== 'PENDING' || ($afterAlbum['reviewed_by_principal_id'] ?? null) !== null) {
                submissionPreflightFail('invalid_album_stranded_reviewer_claim');
            }
            if (!hash_equals($projectionBefore, submissionPreflightSnapshot($transaction->fetchAll($projectionSql)))) {
                submissionPreflightFail('invalid_album_invalidated_projection');
            }
            $assertions += 2;

            try {
                $service->review($submissionId, $adminUserId, true, '合成缺失原图校验', $heritageId);
                submissionPreflightFail('missing_source_was_accepted');
            } catch (RuntimeException $error) {
                if ($error->getMessage() !== 'family_submission_storage_missing') {
                    throw $error;
                }
            }
            $afterSource = $transaction->fetchOne(
                'SELECT `state`,`reviewed_by_principal_id` FROM `' . $transaction->table('submission') . '` WHERE `id`=?',
                [$submissionId],
            );
            if (($afterSource['state'] ?? null) !== 'PENDING' || ($afterSource['reviewed_by_principal_id'] ?? null) !== null) {
                submissionPreflightFail('missing_source_stranded_reviewer_claim');
            }
            if (!hash_equals($projectionBefore, submissionPreflightSnapshot($transaction->fetchAll($projectionSql)))) {
                submissionPreflightFail('missing_source_invalidated_projection');
            }
            $assertions += 2;

            $transaction->execute(
                'UPDATE `' . $transaction->table('submission') . '` SET `reviewed_by_principal_id`=? WHERE `id`=? AND `state`=\'PENDING\'',
                [(int) $admin['id'], $submissionId],
            );
            $claimed = $transaction->fetchOne(
                'SELECT * FROM `' . $transaction->table('submission') . '` WHERE `id`=?',
                [$submissionId],
            );
            if ($claimed === null) {
                submissionPreflightFail('compensation_fixture_missing');
            }
            $release = new ReflectionMethod(ClassIdentitySubmissionService::class, 'releaseFailedApprovalClaim');
            $release->setAccessible(true);
            $release->invoke(
                $service,
                $claimed,
                $adminContext,
                '合成审核补偿校验',
                new RuntimeException('submission_original_checksum_drift'),
            );
            $released = $transaction->fetchOne(
                'SELECT `state`,`reviewed_by_principal_id` FROM `' . $transaction->table('submission') . '` WHERE `id`=?',
                [$submissionId],
            );
            if (($released['state'] ?? null) !== 'PENDING' || ($released['reviewed_by_principal_id'] ?? null) !== null) {
                submissionPreflightFail('exact_compensation_did_not_release_claim');
            }
            $audit = $transaction->fetchAll(
                'SELECT `result`,`error_code`,`reason` FROM `' . $transaction->table('audit_event') . '` '
                    . 'WHERE `action`=\'SUBMISSION_APPROVE_ABORT\' AND `target_id`=? ORDER BY `id` DESC LIMIT 2',
                [(string) $submissionId],
            );
            if (
                count($audit) !== 1
                || ($audit[0]['result'] ?? null) !== 'FAILED'
                || ($audit[0]['error_code'] ?? null) !== 'SUBMISSION_ORIGINAL_CHECKSUM_DRIFT'
                || ($audit[0]['reason'] ?? null) !== '合成审核补偿校验'
            ) {
                submissionPreflightFail('exact_compensation_audit_invalid');
            }
            if (!hash_equals($projectionBefore, submissionPreflightSnapshot($transaction->fetchAll($projectionSql)))) {
                submissionPreflightFail('exact_compensation_invalidated_projection');
            }
            $assertions += 3;

            throw new RuntimeException($rollbackMarker);
        });
        submissionPreflightFail('fixture_transaction_did_not_rollback');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== $rollbackMarker) {
            throw $error;
        }
    }

    if (!hash_equals($projectionBefore, submissionPreflightSnapshot($repository->fetchAll($projectionSql)))) {
        submissionPreflightFail('projection_changed_after_fixture_rollback');
    }
    $submissionCountAfter = (int) ($repository->fetchOne(
        'SELECT COUNT(*) AS `count` FROM `' . $repository->table('submission') . '`',
    )['count'] ?? -1);
    if ($submissionCountAfter !== $submissionCountBefore) {
        submissionPreflightFail('submission_fixture_not_rolled_back');
    }
    $assertions += 2;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'SUBMISSION_REVIEW_PREFLIGHT=FAIL reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
}

if ($exit === 0) {
    fwrite(STDOUT, "SUBMISSION_REVIEW_PREFLIGHT=PASS assertions={$assertions}\n");
}
exit($exit);
