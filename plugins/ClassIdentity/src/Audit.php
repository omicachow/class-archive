<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Append-only, redacted ClassIdentity audit writer. */
final class Audit
{
    private const MAX_JSON_BYTES = 16384;
    private const MAX_VALUE_DEPTH = 5;
    private const MAX_REASON_CHARACTERS = 500;

    /** @var list<string> */
    private const TOP_LEVEL_FIELDS = [
        'request_id',
        'actor_principal_id',
        'actor_user_id',
        'actor_kind',
        'action',
        'target_type',
        'target_id',
        'target_identity_id',
        'target_seat_id',
        'target_account_id',
        'target_principal_id',
        'old_value',
        'new_value',
        'reason',
        'source_ip_hash',
        'result',
        'error_code',
    ];

    /** @var list<string> */
    private const VALUE_FIELDS = [
        'id',
        'state',
        'status',
        'identity_type',
        'seat_type',
        'principal_type',
        'system_role',
        'role_code',
        'roster_code',
        'real_name',
        'family_relationship',
        'ordinal',
        'generation',
        'purpose',
        'expires_at',
        'piwigo_user_id',
        'piwigo_group_id',
        'group_name',
        'name',
        'enabled',
        'auth_epoch',
        'era',
        'mime_type',
        'extension',
        'byte_size',
        'width',
        'height',
        'date_precision',
        'date_confidence',
        'date_source',
        'suggested_date',
        'archive_date',
        'event_label',
        'image_id',
        'piwigo_image_id',
        'submission_id',
        'album_id',
        'official',
        'result',
        'operation_type',
        'operation_state',
        'identity_state',
        'seat_state',
        'account_state',
        'principal_state',
        'current_marker',
        'reason_code',
        'count',
        'from',
        'to',
        'fields',
        'changes',
        'class_photo_id',
        'class_person_id',
        'class_album_id',
        'canonical_class_photo_id',
        'cover_class_photo_id',
        'duplicate_id',
        'merge_id',
        'spotlight_id',
        'batch_id',
        'display_name',
        'classmate_identity_id',
        'owner_principal_id',
        'piwigo_category_id',
        'visibility',
        'album_type',
        'relation_kind',
        'rule',
        'similarity',
        'source_kind',
        'provenance_code',
        'canonicalized',
        'item_count',
        'applied_count',
        'failed_count',
        'high_risk_confirmed',
    ];

    /** @var list<string> */
    private const HIGH_RISK_ACTIONS = [
        'IDENTITY_FREEZE',
        'IDENTITY_RETIRE',
        'ACCOUNT_FREEZE',
        'FAMILY_SEAT_RELEASE',
        'CLAIM_REISSUE',
        'FAMILY_INVITATION_REISSUE',
        'INVITATION_REVOKE',
        'PASSWORD_RESET_INITIATED',
        'FORCE_LOGOUT',
        'ANONYMOUS_RESOLVE',
        'ANONYMOUS_DISABLE',
        'SUBMISSION_REJECT',
        'SUBMISSION_APPROVE',
        'SUBMISSION_APPROVE_ABORT',
        'REJECTED_BINARY_CLEANUP',
        'ARCHIVE_METADATA_UPDATE',
        'ANONYMOUS_ENABLE',
        'ERA_CHANGE',
        'PERMISSION_CHANGE',
        'PRINCIPAL_SECURITY_CHANGE',
        'MANUAL_COMPENSATION_ATTEMPT',
        'MANUAL_COMPENSATION',
        'PERSON_CREATE_MANUAL',
        'PERSON_UPDATE',
        'PERSON_MERGE',
        'PERSON_MERGE_REVERT',
        'PERSON_PHOTO_RULE_SET',
        'PERSON_PHOTO_RULE_CLEAR',
        'PERSON_PHOTO_MOVE',
        'ALBUM_MAPPING_CREATE',
        'ALBUM_MAPPING_UPDATE',
        'SPOTLIGHT_CANCEL',
        'PHOTO_SOURCE_RECORD',
        'PHOTO_DUPLICATE_REVIEW',
        'PHOTO_DUPLICATE_CONSOLIDATE',
        'PHOTO_DUPLICATE_REVERT',
        'BULK_ARCHIVE_UPDATE',
    ];

    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * @param array<string, mixed> $event
     * @return int inserted event id
     */
    public function append(array $event): int
    {
        $this->assertTopLevelFields($event);

        $actorKind = $this->boundedCode($event['actor_kind'] ?? null, 'actor_kind', 24);
        $action = $this->boundedCode($event['action'] ?? null, 'action', 64);
        $targetType = $this->boundedCode($event['target_type'] ?? null, 'target_type', 32);
        $result = $this->boundedCode($event['result'] ?? null, 'result', 16);
        if (!in_array($result, ['SUCCESS', 'DENIED', 'FAILED'], true)) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_result');
        }

        // This is deliberately repeated at the persistence boundary. HTTP and
        // service validation improve ergonomics, but no caller is trusted to
        // decide that operator-supplied text is safe to persist in Audit.
        $reason = self::validateReason($event['reason'] ?? null);
        if (in_array($action, self::HIGH_RISK_ACTIONS, true) && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('class_identity_audit_reason_required');
        }

        $targetId = $this->nullableIdentifier($event['target_id'] ?? null, 190, 'target_id');
        $errorCode = $this->nullableCode($event['error_code'] ?? null, 64, 'error_code');
        $requestId = $this->normalizeRequestId($event['request_id'] ?? null);
        $sourceIpHash = $this->normalizeBinaryHash($event['source_ip_hash'] ?? null, 'source_ip_hash');
        $oldJson = $this->encodeValue($event['old_value'] ?? null, 'old_value');
        $newJson = $this->encodeValue($event['new_value'] ?? null, 'new_value');

        $ids = [];
        foreach (
            [
                'actor_principal_id',
                'actor_user_id',
                'target_identity_id',
                'target_seat_id',
                'target_account_id',
                'target_principal_id',
            ] as $field
        ) {
            $ids[$field] = $this->nullablePositiveInt($event[$field] ?? null, $field);
        }

        $sql = 'INSERT INTO `' . $this->repository->table('audit_event') . '` ('
            . '`request_id`, `actor_principal_id`, `actor_user_id`, `actor_kind`, `action`, '
            . '`target_type`, `target_id`, `target_identity_id`, `target_seat_id`, '
            . '`target_account_id`, `target_principal_id`, `old_value`, `new_value`, '
            . '`reason`, `source_ip_hash`, `result`, `error_code`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $this->repository->execute($sql, [
            $requestId,
            $ids['actor_principal_id'],
            $ids['actor_user_id'],
            $actorKind,
            $action,
            $targetType,
            $targetId,
            $ids['target_identity_id'],
            $ids['target_seat_id'],
            $ids['target_account_id'],
            $ids['target_principal_id'],
            $oldJson,
            $newJson,
            $reason,
            $sourceIpHash,
            $result,
            $errorCode,
        ]);

        return $this->repository->lastInsertId();
    }

    /**
     * Validate and normalize a business reason without ever reflecting its
     * value in an exception. Sensitive-looking text is rejected rather than
     * partially redacted: a redaction could make a high-risk audit event
     * misleading while still leaving an overlooked fragment behind.
     */
    public static function validateReason(mixed $value, bool $required = false): ?string
    {
        if ($value === null) {
            if ($required) {
                throw new \InvalidArgumentException('class_identity_audit_reason_required');
            }

            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_reason');
        }

        $reason = trim($value);
        if ($reason === '') {
            if ($required) {
                throw new \InvalidArgumentException('class_identity_audit_reason_required');
            }

            return null;
        }
        if (preg_match('//u', $reason) !== 1
            || self::characterLength($reason) > self::MAX_REASON_CHARACTERS
            || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1
            || preg_match('/\A[\p{L}\p{M}\p{N}\p{P}\p{Zs}]+\z/uD', $reason) !== 1
        ) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_reason');
        }

        // Claim and Invitation credentials are selector.validator values.
        // The bounds intentionally err on the side of rejecting any similar
        // high-entropy credential, even if a future token format changes.
        if (preg_match('/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{24,}(?![A-Za-z0-9_-])/D', $reason) === 1) {
            throw new \InvalidArgumentException('class_identity_sensitive_audit_reason_rejected');
        }

        $credentialSyntax = str_replace(
            ['：', '＝', '%3A', '%3a', '%3D', '%3d'],
            [':', '=', ':', ':', '=', '='],
            $reason,
        );
        if (preg_match(
            '/(?:\b(?:password|passwd|pwd|authorization|cookie|set-cookie|session(?:id)?|php(?:sessid)?|api[ _-]?key|access[ _-]?token|refresh[ _-]?token|claim[ _-]?(?:code|token)|invite|invitation|secret|credential)\b\s*[:=]\s*\S+|(?:密码|口令|通行码|验证码|凭据|密钥|令牌)\s*[:=]\s*\S+|\b(?:bearer|basic)\s+[A-Za-z0-9+\/_=.-]{8,}|\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,})/iu',
            $credentialSyntax,
        ) === 1) {
            throw new \InvalidArgumentException('class_identity_sensitive_audit_reason_rejected');
        }

        // A raw password may arrive without a helpful field label. Reject
        // uninterrupted printable-ASCII tokens that look credential-like, and
        // long base64/hex-style blobs. Business reasons are expected to be
        // natural-language text; identifiers belong in structured fields.
        if (preg_match('/(?=[!-~]{12,})(?=[!-~]*[A-Za-z])(?=[!-~]*[0-9])[!-~]{12,}/D', $reason) === 1
            || preg_match('/(?<![A-Za-z0-9+\/_=-])[A-Za-z0-9+\/_=-]{24,}(?![A-Za-z0-9+\/_=-])/D', $reason) === 1
        ) {
            throw new \InvalidArgumentException('class_identity_sensitive_audit_reason_rejected');
        }

        return $reason;
    }

    public static function hashSourceIp(string $canonicalIp, string $hmacKey): string
    {
        if ($canonicalIp === '' || $hmacKey === '') {
            throw new \InvalidArgumentException('class_identity_invalid_audit_ip_hash_input');
        }

        return hash_hmac('sha256', "class-identity/audit-ip/v1\0" . $canonicalIp, $hmacKey, true);
    }

    /** @param array<string, mixed> $event */
    private function assertTopLevelFields(array $event): void
    {
        foreach (array_keys($event) as $field) {
            if (!is_string($field) || !in_array($field, self::TOP_LEVEL_FIELDS, true)) {
                throw new \InvalidArgumentException('class_identity_audit_field_not_allowed');
            }
        }
    }

    private function encodeValue(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('class_identity_audit_' . $field . '_must_be_object');
        }

        $this->assertSafeValue($value, 0, null);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('class_identity_audit_value_too_large');
        }

        return $json;
    }

    private function assertSafeValue(mixed $value, int $depth, ?string $field): void
    {
        if ($depth > self::MAX_VALUE_DEPTH) {
            throw new \InvalidArgumentException('class_identity_audit_value_too_deep');
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return;
        }
        if (is_string($value)) {
            if (strlen($value) > 1000) {
                throw new \InvalidArgumentException('class_identity_audit_string_too_long');
            }
            if ($field === 'roster_code') {
                // A roster identifier is intentionally high-entropy-looking,
                // but is already a bounded domain field rather than a secret.
                // Keep the exception narrow: all other structured strings use
                // the credential-sensitive reason validator below.
                if (preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/D', $value) !== 1) {
                    throw new \InvalidArgumentException('class_identity_audit_invalid_roster_code');
                }
                return;
            }
            if ($field === 'provenance_code') {
                // This is a bounded, non-secret enum-like local source label.
                // Filesystem paths and filenames are intentionally forbidden.
                if (preg_match('/\A[A-Z0-9][A-Z0-9._:-]{1,63}\z/D', $value) !== 1) {
                    throw new \InvalidArgumentException('class_identity_audit_invalid_provenance_code');
                }
                return;
            }
            if (in_array($field, [
                'class_photo_id',
                'class_person_id',
                'class_album_id',
                'canonical_class_photo_id',
                'cover_class_photo_id',
                'duplicate_id',
                'merge_id',
                'spotlight_id',
                'batch_id',
            ], true)) {
                if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) !== 1) {
                    throw new \InvalidArgumentException('class_identity_audit_invalid_opaque_id');
                }
                return;
            }
            if (in_array($field, ['from', 'to'], true)
                && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1
            ) {
                return;
            }
            // Structured old/new values use an allowlisted key vocabulary, but
            // the value itself can still accidentally contain a credential.
            // Apply the same non-reflecting final defense before JSON encoding.
            self::validateReason($value);
            return;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('class_identity_audit_value_type_not_allowed');
        }

        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            if (!$isList) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException('class_identity_audit_invalid_value_key');
                }
                $this->assertNotSensitiveKey($key);
                if (!in_array($key, self::VALUE_FIELDS, true)) {
                    throw new \InvalidArgumentException('class_identity_audit_value_field_not_allowed');
                }
            }
            $this->assertSafeValue($child, $depth + 1, $isList ? $field : $key);
        }
    }

    private function assertNotSensitiveKey(string $key): void
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? $key);
        foreach (
            [
                'password',
                'passwd',
                'credential',
                'secret',
                'token',
                'validator',
                'selector',
                'session',
                'cookie',
                'authorization',
                'api_key',
                'auth_key',
                'private_key',
                'hmac',
                'pepper',
                'hash',
            ] as $forbidden
        ) {
            if (str_contains($normalized, $forbidden)) {
                throw new \InvalidArgumentException('class_identity_audit_sensitive_field_rejected');
            }
        }
    }

    private function boundedCode(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
        }
        if (!preg_match('/^[A-Z][A-Z0-9_.:-]*$/D', $value)) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
        }

        return $value;
    }

    private function nullableCode(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->boundedCode($value, $field, $maxLength);
    }

    private function nullableIdentifier(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $value)) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
        }

        return $value;
    }

    private static function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? PHP_INT_MAX : $count;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
        }

        return $value;
    }

    private function normalizeRequestId(mixed $requestId): string
    {
        if ($requestId === null) {
            return random_bytes(16);
        }
        if (!is_string($requestId)) {
            throw new \InvalidArgumentException('class_identity_invalid_request_id');
        }
        if (strlen($requestId) === 16) {
            return $requestId;
        }

        $hex = str_replace('-', '', $requestId);
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new \InvalidArgumentException('class_identity_invalid_request_id');
        }
        $binary = hex2bin($hex);
        if ($binary === false) {
            throw new \InvalidArgumentException('class_identity_invalid_request_id');
        }

        return $binary;
    }

    private function normalizeBinaryHash(mixed $hash, string $field): ?string
    {
        if ($hash === null) {
            return null;
        }
        if (!is_string($hash)) {
            throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
        }
        if (strlen($hash) === 32) {
            return $hash;
        }
        if (strlen($hash) === 64 && ctype_xdigit($hash)) {
            $binary = hex2bin($hash);
            if ($binary !== false) {
                return $binary;
            }
        }

        throw new \InvalidArgumentException('class_identity_invalid_audit_' . $field);
    }
}
