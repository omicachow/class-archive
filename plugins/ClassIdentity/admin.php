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

check_status(ACCESS_ADMINISTRATOR);
ClassIdentityHttp::noStore();
ClassIdentityHttp::requireSystemAdmin();

$allowedTabs = ['dashboard', 'identities', 'teachers', 'invitations', 'audit', 'system'];
$tab = ClassIdentityHttp::requestedTab($allowedTabs);
$service = ClassIdentityAdminService::fromPiwigo();
$actorUserId = (int) ($user['id'] ?? 0);
$baseUrl = get_root_url() . 'admin.php?page=plugin-ClassIdentity-';
$navigation = [
    ['id' => 'dashboard', 'label' => '概览'],
    ['id' => 'identities', 'label' => '同学身份'],
    ['id' => 'teachers', 'label' => '老师身份'],
    ['id' => 'invitations', 'label' => 'Claim / 邀请'],
    ['id' => 'audit', 'label' => '审计记录'],
    ['id' => 'system', 'label' => '系统健康'],
];
$headerTemplate = realpath(CLASS_IDENTITY_PATH . 'template/admin/_header.tpl');
if ($headerTemplate === false) {
    ClassIdentityHttp::abort(503, 'Admin template unavailable');
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
                ClassIdentityHttp::flash('success', 'Claim 已撤销。');
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
                ClassIdentityHttp::flash('success', 'Family Invitation 已撤销，席位可再次使用。');
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

            default:
                ClassIdentityHttp::abort(400, 'Unknown action');
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
$template->assign('ADMIN_PAGE_TITLE', 'Class Archive 管理控制台');
$template->set_filename('class_identity_admin', CLASS_IDENTITY_PATH . 'template/admin/' . $tab . '.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'class_identity_admin');
