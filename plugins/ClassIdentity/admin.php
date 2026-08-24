<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

global $template, $user;

require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
require_once CLASS_IDENTITY_PATH . 'src/Schema.php';
require_once CLASS_IDENTITY_PATH . 'src/Repository.php';
require_once CLASS_IDENTITY_PATH . 'src/Audit.php';
require_once CLASS_IDENTITY_PATH . 'src/Access.php';
require_once CLASS_IDENTITY_PATH . 'src/Http.php';
require_once CLASS_IDENTITY_PATH . 'src/AdminService.php';
require_once CLASS_IDENTITY_PATH . 'src/SubmissionService.php';
require_once CLASS_IDENTITY_PATH . 'src/ArchiveService.php';
require_once CLASS_IDENTITY_PATH . 'src/AnonymousGovernanceService.php';

check_status(ACCESS_ADMINISTRATOR);
ClassIdentityHttp::noStore();
ClassIdentityHttp::requireSystemAdmin();

$allowedTabs = ['dashboard', 'identities', 'teachers', 'invitations', 'submissions', 'anonymous', 'archive', 'audit', 'system'];
$tab = ClassIdentityHttp::requestedTab($allowedTabs);
$service = ClassIdentityAdminService::fromPiwigo();
$submissionService = ClassIdentitySubmissionService::fromPiwigo();
$archiveService = ClassIdentityArchiveService::fromPiwigo();
$anonymousService = ClassIdentityAnonymousGovernanceService::fromPiwigo();
$actorUserId = (int) ($user['id'] ?? 0);
$baseUrl = get_root_url() . 'admin.php?page=plugin-ClassIdentity-';
$navigation = [
    ['id' => 'dashboard', 'label' => '仪表盘'],
    ['id' => 'identities', 'label' => '班级成员'],
    ['id' => 'teachers', 'label' => '教师'],
    ['id' => 'invitations', 'label' => '邀请与认领'],
    ['id' => 'submissions', 'label' => '投稿审核'],
    ['id' => 'anonymous', 'label' => '匿名管理'],
    ['id' => 'archive', 'label' => '班级档案'],
    ['id' => 'audit', 'label' => '操作审计'],
    ['id' => 'system', 'label' => '系统状态'],
];
$headerTemplate = realpath(CLASS_IDENTITY_PATH . 'template/admin/_header.tpl');
if ($headerTemplate === false) {
    ClassIdentityHttp::abort(503, '管理页面模板暂时不可用');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    ClassIdentityHttp::requireMutation();
    $action = ClassIdentityHttp::postString('action', 64, true);

    try {
        switch ($action) {
            case 'create_classmate':
                $identityId = $service->createIdentity(
                    'CLASSMATE',
                    ClassIdentityHttp::postString('roster_code', 64, true),
                    ClassIdentityHttp::postString('real_name', 190, true),
                    ClassIdentityHttp::requireReason(),
                    $actorUserId
                );
                ClassIdentityHttp::flash('success', '同学身份已建立。');
                ClassIdentityHttp::redirectTo('identities', ['identity_id' => $identityId]);

            case 'create_teacher':
                $identityId = $service->createIdentity(
                    'TEACHER',
                    ClassIdentityHttp::postString('roster_code', 64, true),
                    ClassIdentityHttp::postString('real_name', 190, true),
                    ClassIdentityHttp::requireReason(),
                    $actorUserId
                );
                ClassIdentityHttp::flash('success', '老师身份已建立。');
                ClassIdentityHttp::redirectTo('teachers', ['identity_id' => $identityId]);

            case 'issue_claim':
            case 'reissue_claim':
                $identityId = ClassIdentityHttp::postPositiveInt('identity_id');
                $issued = $service->issueClaim(
                    $identityId,
                    ClassIdentityHttp::requireReason(),
                    $actorUserId
                );
                // The raw Code is rendered in this one no-store POST response.
                // Use a tiny terminal response rather than deferring it to the
                // surrounding Smarty render: once the transaction commits, a
                // later template exception must not strand an active token
                // whose only plaintext copy was never delivered.
                ClassIdentityHttp::renderIssuedClaim(
                    $issued,
                    $baseUrl . rawurlencode($tab),
                );

            case 'revoke_claim':
                $service->revokeClaim(
                    ClassIdentityHttp::postPositiveInt('token_id'),
                    ClassIdentityHttp::requireReason(),
                    $actorUserId
                );
                ClassIdentityHttp::flash('success', '认领码已撤销。');
                ClassIdentityHttp::redirectTo('invitations');

            case 'reissue_family_invitation':
                $issued = $service->reissueFamilyInvitation(
                    ClassIdentityHttp::postPositiveInt('seat_id'),
                    ClassIdentityHttp::requireReason(),
                    $actorUserId,
                );
                // Exactly like Claim issuance, the raw validator exists only
                // in this terminal no-store response and is never flashed.
                ClassIdentityHttp::renderIssuedFamilyInvitation(
                    $issued,
                    $baseUrl . 'invitations',
                );

            case 'revoke_family_invitation':
                $service->revokeFamilyInvitation(
                    ClassIdentityHttp::postPositiveInt('token_id'),
                    ClassIdentityHttp::requireReason(),
                    $actorUserId,
                );
                ClassIdentityHttp::flash('success', '家庭邀请已撤销，席位可再次使用。');
                ClassIdentityHttp::redirectTo('invitations');

            case 'compensate_provisioning':
                $service->compensateProvisioningIncident(
                    ClassIdentityHttp::postPositiveInt('operation_id'),
                    ClassIdentityHttp::requireReason(),
                    $actorUserId,
                );
                ClassIdentityHttp::flash('success', '已完成安全补偿；Core tombstone 保留用于后续归档核查。');
                ClassIdentityHttp::redirectTo('system');

            case 'freeze_identity':
            case 'unfreeze_identity':
                $identityId = ClassIdentityHttp::postPositiveInt('identity_id');
                $service->setIdentityFrozen(
                    $identityId,
                    $action === 'freeze_identity',
                    ClassIdentityHttp::requireReason(),
                    $actorUserId
                );
                ClassIdentityHttp::flash('success', $action === 'freeze_identity' ? '身份已冻结。' : '身份已解除冻结。');
                ClassIdentityHttp::redirectTo($tab, ['identity_id' => $identityId]);

            case 'approve_submission':
                $albumId = null;
                if (isset($_POST['album_id']) && is_scalar($_POST['album_id']) && ctype_digit((string) $_POST['album_id'])) {
                    $albumId = (int) $_POST['album_id'];
                }
                $approved = $submissionService->review(
                    ClassIdentityHttp::postPositiveInt('submission_id'),
                    $actorUserId,
                    true,
                    ClassIdentityHttp::requireReason(),
                    $albumId,
                    isset($_POST['archive_date']) && is_string($_POST['archive_date']) ? trim($_POST['archive_date']) : null,
                    isset($_POST['date_precision']) && is_string($_POST['date_precision']) ? $_POST['date_precision'] : 'UNKNOWN',
                    isset($_POST['event_label']) && is_string($_POST['event_label']) ? $_POST['event_label'] : '',
                );
                if ($approved === null) {
                    throw new RuntimeException('submission_approval_projection_missing');
                }
                // Approval already invalidates the exact promoted photo and
                // every dependent aggregate through promotePendingMapping().
                // Publish only that committed photo instead of making one
                // administrator review rebuild the whole gallery.
                \ClassIdentity\Gateway\ReadProjectionBuilder::rebuildChangedPhotos(
                    [(string) $approved['class_photo_id']],
                    \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
                );
                ClassIdentityHttp::flash('success', '投稿已通过并收录到班级历史。');
                ClassIdentityHttp::redirectTo('submissions');

            case 'reject_submission':
                $submissionService->review(
                    ClassIdentityHttp::postPositiveInt('submission_id'),
                    $actorUserId,
                    false,
                    ClassIdentityHttp::requireReason(),
                );
                ClassIdentityHttp::flash('success', '投稿已拒绝，原文件仍仅管理员可见。');
                ClassIdentityHttp::redirectTo('submissions');

            case 'save_archive_metadata':
                $albumId = null;
                if (isset($_POST['album_id']) && is_scalar($_POST['album_id']) && ctype_digit((string) $_POST['album_id'])) {
                    $albumId = (int) $_POST['album_id'];
                }
                $projection = $archiveService->saveMetadata(
                    $actorUserId,
                    ClassIdentityHttp::postPositiveInt('image_id'),
                    ClassIdentityHttp::postString('era', 16, true),
                    isset($_POST['archive_date']) && is_string($_POST['archive_date']) ? trim($_POST['archive_date']) : null,
                    isset($_POST['date_precision']) && is_string($_POST['date_precision']) ? $_POST['date_precision'] : 'UNKNOWN',
                    isset($_POST['date_confidence']) && is_string($_POST['date_confidence']) ? $_POST['date_confidence'] : 'UNKNOWN',
                    isset($_POST['date_source']) && is_string($_POST['date_source']) ? $_POST['date_source'] : 'UNKNOWN',
                    isset($_POST['event_label']) && is_string($_POST['event_label']) ? $_POST['event_label'] : null,
                    isset($_POST['official']) && (string) $_POST['official'] === '1',
                    $albumId,
                    ClassIdentityHttp::requireReason(),
                );
                if (($projection['projection_rebuild_mode'] ?? null) === 'FULL_NATIVE_SOURCE') {
                    \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
                } elseif (($projection['projection_rebuild_mode'] ?? null) === 'BOUNDED') {
                    \ClassIdentity\Gateway\ReadProjectionBuilder::rebuildChangedPhotos(
                        [(string) $projection['class_photo_id']],
                        (array) $projection['projection_kinds'],
                    );
                } else {
                    throw new RuntimeException('archive_projection_rebuild_mode_invalid');
                }
                ClassIdentityHttp::flash('success', '档案信息已保存，原图仍保持单份。');
                ClassIdentityHttp::redirectTo('archive');

            case 'create_archive_album':
                $archiveService->createOfficialAlbum(
                    $actorUserId,
                    ClassIdentityHttp::postString('era', 16, true),
                    ClassIdentityHttp::postString('album_name', 190, true),
                    isset($_POST['album_comment']) && is_string($_POST['album_comment']) ? $_POST['album_comment'] : null,
                    ClassIdentityHttp::requireReason(),
                );
                // Piwigo category INSERT is protected by the v11 native guard,
                // which rotates the catalog generation before the MyISAM row
                // exists. Publish a fresh catalog and its aggregates together.
                \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
                ClassIdentityHttp::flash('success', '正式档案相册已建立。');
                ClassIdentityHttp::redirectTo('archive');

            case 'disable_anonymous':
            case 'enable_anonymous':
                $anonymousService->setSeatState(
                    $actorUserId,
                    ClassIdentityHttp::postPositiveInt('seat_id'),
                    $action === 'enable_anonymous',
                    ClassIdentityHttp::requireReason(),
                );
                ClassIdentityHttp::flash('success', $action === 'enable_anonymous' ? '匿名席位已恢复。' : '匿名席位已禁用，现有会话已撤销。');
                ClassIdentityHttp::redirectTo('anonymous');

            case 'resolve_anonymous':
                $mapping = $anonymousService->resolve(
                    $actorUserId,
                    ClassIdentityHttp::postString('context_type', 16, true),
                    ClassIdentityHttp::postPositiveInt('context_id'),
                    ClassIdentityHttp::postString('alias', 128, true),
                    ClassIdentityHttp::requireReason(),
                );
                ClassIdentityHttp::renderAnonymousResolution($mapping, $baseUrl . 'anonymous');

            default:
                ClassIdentityHttp::abort(400, '未知操作');
        }
    } catch (InvalidArgumentException $error) {
        ClassIdentityHttp::flash('error', $error->getMessage());
        ClassIdentityHttp::redirectTo($tab);
    } catch (Throwable $error) {
        error_log('ClassIdentity admin mutation failed [' . get_class($error) . ']');
        ClassIdentityHttp::flash('error', '操作未完成。系统已按默认拒绝处理，请检查系统健康与服务日志。');
        ClassIdentityHttp::redirectTo($tab);
    }
}

$streamAction = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($streamAction, ['submission_thumbnail', 'submission_original'], true)) {
    $submissionId = ClassIdentityHttp::queryPositiveInt('submission_id');
    if ($submissionId === null) {
        ClassIdentityHttp::abort(404, '资源不存在');
    }
    $submissionService->stream($submissionId, $streamAction === 'submission_thumbnail' ? 'thumbnail' : 'original');
}

$view = [
    'CA_TAB' => $tab,
    'CA_BASE_URL' => $baseUrl,
    'CA_NATIVE_ADMIN_URL' => get_root_url() . 'admin.php',
    'CA_PWG_TOKEN' => get_pwg_token(),
    'CA_FLASH' => ClassIdentityHttp::consumeFlash(),
    'CA_NAV' => $navigation,
    'CA_HEADER_TEMPLATE' => 'file:' . $headerTemplate,
];

try {
    switch ($tab) {
        case 'dashboard':
            $view['CA_DASHBOARD'] = $service->dashboard();
            $view['CA_RECENT_AUDIT'] = array_slice($service->auditEvents(), 0, 12);
            break;

        case 'identities':
            $view['CA_IDENTITIES'] = $service->identities('CLASSMATE');
            $identityId = ClassIdentityHttp::queryPositiveInt('identity_id');
            $view['CA_IDENTITY'] = $identityId === null ? null : $service->identity($identityId);
            break;

        case 'teachers':
            $view['CA_TEACHERS'] = $service->identities('TEACHER');
            $identityId = ClassIdentityHttp::queryPositiveInt('identity_id');
            $view['CA_IDENTITY'] = $identityId === null ? null : $service->identity($identityId);
            break;

        case 'invitations':
            $view['CA_INVITATIONS'] = $service->invitations();
            break;

        case 'submissions':
            $view['CA_SUBMISSIONS'] = $submissionService->adminList();
            $view['CA_ARCHIVE_ALBUMS'] = $archiveService->albums();
            break;

        case 'anonymous':
            $view['CA_ANONYMOUS'] = $anonymousService->list();
            break;

        case 'archive':
            $view['CA_ARCHIVE_IMAGES'] = $archiveService->images();
            $view['CA_ARCHIVE_ALBUMS'] = $archiveService->albums();
            break;

        case 'audit':
            $view['CA_AUDIT'] = $service->auditEvents();
            break;

        case 'system':
            $view['CA_SYSTEM'] = $service->systemHealth();
            $view['CA_PROVISIONING_INCIDENTS'] = $service->provisioningIncidents();
            break;
    }
} catch (Throwable $error) {
    error_log('ClassIdentity admin read failed [' . get_class($error) . ']');
    $view['CA_READ_ERROR'] = '数据读取失败；控制台已按默认拒绝处理。请查看“系统健康”和服务日志。';
}

$template->assign($view);
$template->assign('ADMIN_PAGE_TITLE', '班级数字档案馆管理控制台');
$template->set_filename('class_identity_admin', CLASS_IDENTITY_PATH . 'template/admin/' . $tab . '.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'class_identity_admin');
