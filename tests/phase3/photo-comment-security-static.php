<?php

declare(strict_types=1);

/**
 * Static security contract for the Phase 3.3C photo-comment boundary.
 *
 * This is deliberately source-only: it is safe on every workstation and in
 * public synthetic CI, and guards the narrow BFF/Gateway contract before the
 * companion runtime fixture performs real synthetic writes.  It does not
 * inspect, mount, or depend on private-library data.
 */

function photoCommentStaticFail(string $message): never
{
    throw new RuntimeException($message);
}

/** @return string */
function photoCommentStaticRead(string $relative): string
{
    $root = dirname(__DIR__, 2);
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        photoCommentStaticFail('source_unavailable_' . str_replace(['/', '.'], '_', $relative));
    }
    return $source;
}

/** @return string */
function photoCommentStaticBetween(string $source, string $start, string $end, string $label): string
{
    $from = strpos($source, $start);
    if ($from === false) {
        photoCommentStaticFail('source_marker_missing_' . $label . '_start');
    }
    $to = strpos($source, $end, $from + strlen($start));
    if ($to === false || $to <= $from) {
        photoCommentStaticFail('source_marker_missing_' . $label . '_end');
    }
    return substr($source, $from, $to - $from);
}

$assertions = 0;
try {
    $service = photoCommentStaticRead('plugins/ClassIdentity/src/PhotoCommentService.php');
    $controller = photoCommentStaticRead('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php');
    $gateway = photoCommentStaticRead('plugins/ClassIdentity/src/Gateway/GatewayService.php');
    $bff = photoCommentStaticRead('infra/immich-spike/web-compat/server.mjs');
    $audit = photoCommentStaticRead('plugins/ClassIdentity/src/Audit.php');

    $roleMayWrite = photoCommentStaticBetween(
        $service,
        'private static function roleMayWrite',
        'private static function publicTimestamp',
        'role_may_write',
    );
    foreach ([
        'Access::ROLE_CLASSMATE',
        'Access::ROLE_TEACHER',
        'Access::ROLE_ANONYMOUS',
    ] as $allowedRole) {
        if (!str_contains($roleMayWrite, $allowedRole)) {
            photoCommentStaticFail('comment_writer_role_missing_' . strtolower(str_replace('Access::ROLE_', '', $allowedRole)));
        }
        ++$assertions;
    }
    if (str_contains($roleMayWrite, 'Access::ROLE_FAMILY') || str_contains($roleMayWrite, 'Access::ROLE_SYSTEM_ADMIN')) {
        photoCommentStaticFail('comment_writer_role_expanded');
    }
    ++$assertions;

    $create = photoCommentStaticBetween(
        $service,
        'public function create(',
        '/** @return array{deleted:bool} */',
        'comment_create',
    );
    foreach ([
        'Access::resolveAuthorizationContext($userId)',
        'class_archive_comment_write_forbidden',
        'DomainSupport::requireActivePhoto($repository, $classPhotoId, true)',
        'WHERE `comment_id`=? FOR UPDATE',
        'hash_equals((string) $photo[\'class_photo_id\'], (string) $parent[\'class_photo_id\'])',
        'class_archive_comment_parent_invalid',
        "'action' => 'PHOTO_COMMENT_CREATE'",
    ] as $needle) {
        if (!str_contains($create, $needle)) {
            photoCommentStaticFail('comment_create_guard_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }
    // Text is intentionally not in the structured audit payload.  The word
    // "body" may exist as a local input variable, so check the new_value
    // literal separately rather than relying on a broad substring ban.
    $createAudit = photoCommentStaticBetween(
        $create,
        "'new_value' => [",
        "// Deliberately do not audit comment text.",
        'comment_create_audit',
    );
    if (str_contains($createAudit, "'body'")) {
        photoCommentStaticFail('comment_create_audit_body_leak');
    }
    ++$assertions;

    $publicDto = photoCommentStaticBetween(
        $service,
        '$items[] = [',
        "];\n        }\n        return ['total' => count(\$items)",
        'comment_public_dto',
    );
    foreach (["'id'", "'parentId'", "'body'", "'author'", "'createdAt'", "'canReply'", "'canDelete'"] as $field) {
        if (!str_contains($publicDto, $field)) {
            photoCommentStaticFail('comment_public_dto_field_missing_' . trim($field, "'"));
        }
        ++$assertions;
    }
    foreach (["'principal'", "'account'", "'seat'", "'identity'", "'pseudonym_subject'", "'source_path'"] as $forbiddenField) {
        if (str_contains($publicDto, $forbiddenField)) {
            photoCommentStaticFail('comment_public_dto_identifier_leak_' . trim($forbiddenField, "'"));
        }
        ++$assertions;
    }
    foreach ([
        'private function safeAnonymousAuthor',
        'AnonymousPresenter::displayAliasForPhotoContext(',
        "'kind' => 'ANONYMOUS'",
        ", '班级成员', 'CLASSMATE')",
        ", '老师', 'TEACHER')",
    ] as $needle) {
        if (!str_contains($service, $needle)) {
            photoCommentStaticFail('comment_public_author_contract_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }

    $delete = photoCommentStaticBetween(
        $service,
        'public function delete(',
        'private static function roleMayWrite',
        'comment_delete',
    );
    foreach ([
        'DomainSupport::requireSystemAdmin($adminUserId)',
        "'action' => 'PHOTO_COMMENT_DELETE'",
        "'new_value' => ['state' => 'DELETED']",
        "'reason' => \$reason",
    ] as $needle) {
        if (!str_contains($delete, $needle)) {
            photoCommentStaticFail('comment_delete_guard_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }
    if (str_contains($delete, "'body'")) {
        photoCommentStaticFail('comment_delete_audit_body_leak');
    }
    ++$assertions;

    foreach ([
        "'comments/create' => [\n                ['csrfToken', 'photoUuid', 'parentId', 'body'],\n                ['csrfToken', 'photoUuid', 'parentId', 'body'],",
        "'comments/reply' => [\n                ['csrfToken', 'photoUuid', 'parentId', 'body'],\n                ['csrfToken', 'photoUuid', 'parentId', 'body'],",
        "'manage/comments/delete' => [\n                ['csrfToken', 'commentId', 'reason'],\n                ['csrfToken', 'commentId', 'reason'],",
        "if ((\$_SERVER['CLASS_ARCHIVE_WEB_COMPAT_INTERNAL'] ?? '') !== '1')",
        'self::requireMutationToken($body);',
        'if ($reply && $parentId === null)',
        'if (!$reply && $parentId !== null)',
        'class_archive_gateway_comment_parent_required',
        'class_archive_gateway_comment_parent_unexpected',
        "str_contains(\$code, '_comment_write_forbidden')",
    ] as $needle) {
        if (!str_contains($controller, $needle)) {
            photoCommentStaticFail('gateway_comment_contract_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }
    foreach ([
        'public function comments(string $classPhotoId): ?array',
        'public function createComment(string $classPhotoId, ?string $parentCommentId, string $body): array',
        'public function deleteComment(string $commentId, string $reason): array',
        '$this->commentDomain->listForVisiblePhoto(',
        '$this->commentDomain->create(',
        '$this->commentDomain->delete(',
    ] as $needle) {
        if (!str_contains($gateway, $needle)) {
            photoCommentStaticFail('gateway_comment_domain_bridge_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }

    foreach ([
        "['/api/class-archive/comments/create', '/api/comments/create']",
        "['/api/class-archive/comments/reply', '/api/comments/reply']",
        "['/api/class-archive/manage/comments/delete', '/api/manage/comments/delete']",
        "url.pathname === '/api/class-archive/manage/comments/delete' && role !== 'SYSTEM_ADMIN'",
        "request.method !== 'POST'",
        "const csrf = request.headers['x-class-archive-csrf'];",
        "'X-Class-Archive-CSRF': csrf",
    ] as $needle) {
        if (!str_contains($bff, $needle)) {
            photoCommentStaticFail('bff_comment_contract_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }

    $auditValueFields = photoCommentStaticBetween($audit, 'private const VALUE_FIELDS', 'private const HIGH_RISK_ACTIONS', 'audit_value_fields');
    foreach (['password', 'token', 'secret', 'session', 'body'] as $forbiddenField) {
        if (str_contains(strtolower($auditValueFields), "'{$forbiddenField}'")) {
            photoCommentStaticFail('audit_value_field_allows_sensitive_' . $forbiddenField);
        }
        ++$assertions;
    }
    foreach ([
        'PHOTO_COMMENT_DELETE',
        'class_identity_audit_sensitive_field_rejected',
        'class_identity_sensitive_audit_reason_rejected',
    ] as $needle) {
        if (!str_contains($audit, $needle)) {
            photoCommentStaticFail('audit_comment_redaction_guard_missing_' . hash('sha256', $needle));
        }
        ++$assertions;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'PHOTO_COMMENT_SECURITY_STATIC=FAIL reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}

fwrite(STDOUT, 'PHOTO_COMMENT_SECURITY_STATIC=PASS assertions=' . $assertions . "\n");
