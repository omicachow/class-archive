<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Internal validation helpers shared by the Phase 3.2 domain overlays. */
final class DomainSupport
{
    /** @var list<string> */
    private const TABLES = [
        'person_merge', 'person_photo_rule', 'album', 'spotlight', 'photo_source',
        'photo_duplicate', 'batch_operation', 'batch_operation_item',
        'private_library_collection', 'private_library_folder',
        'private_library_import', 'private_library_import_item',
    ];

    public static function table(Repository $repository, string $suffix): string
    {
        if (!in_array($suffix, self::TABLES, true)) {
            throw new \InvalidArgumentException('class_identity_domain_table_invalid');
        }
        return $repository->table($suffix);
    }

    public static function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return self::binaryToId($bytes);
    }

    public static function idToBinary(string $id): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $id) !== 1) {
            throw new \InvalidArgumentException('class_identity_opaque_id_invalid');
        }
        $binary = hex2bin(str_replace('-', '', strtolower($id)));
        if (!is_string($binary) || strlen($binary) !== 16) {
            throw new \InvalidArgumentException('class_identity_opaque_id_invalid');
        }
        return $binary;
    }

    public static function binaryToId(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new \InvalidArgumentException('class_identity_opaque_binary_id_invalid');
        }
        $hex = bin2hex($binary);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** @return array<string,mixed> */
    public static function requireSystemAdmin(int $userId): array
    {
        if ($userId <= 0) {
            throw new \RuntimeException('class_identity_system_admin_required');
        }
        $context = Access::resolveAuthorizationContext($userId);
        if ($context === null || ($context['role'] ?? null) !== Access::ROLE_SYSTEM_ADMIN) {
            throw new \RuntimeException('class_identity_system_admin_required');
        }
        if ((int) ($context['principal_id'] ?? 0) <= 0) {
            throw new \RuntimeException('class_identity_system_admin_context_invalid');
        }
        return $context;
    }

    /** @return array<string,mixed> */
    public static function requireMemberRole(int $userId, array $roles): array
    {
        if ($userId <= 0) {
            throw new \RuntimeException('class_identity_member_role_required');
        }
        $context = Access::resolveAuthorizationContext($userId);
        if ($context === null || !in_array((string) ($context['role'] ?? ''), $roles, true)) {
            throw new \RuntimeException('class_identity_member_role_required');
        }
        if ((int) ($context['principal_id'] ?? 0) <= 0) {
            throw new \RuntimeException('class_identity_member_context_invalid');
        }
        return $context;
    }

    public static function boundedText(mixed $value, int $max, bool $required = false): ?string
    {
        if ($value === null) {
            if ($required) {
                throw new \InvalidArgumentException('class_identity_text_required');
            }
            return null;
        }
        if (!is_string($value) || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('class_identity_text_invalid');
        }
        $value = trim($value);
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException('class_identity_text_required');
            }
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('class_identity_text_invalid');
        }
        return $value;
    }

    public static function normalizeHexDigest(?string $value, bool $required = false): ?string
    {
        if ($value === null || trim($value) === '') {
            if ($required) {
                throw new \InvalidArgumentException('class_identity_digest_required');
            }
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('class_identity_digest_invalid');
        }
        $binary = hex2bin($value);
        if (!is_string($binary) || strlen($binary) !== 32) {
            throw new \InvalidArgumentException('class_identity_digest_invalid');
        }
        return $binary;
    }

    /** @return array<string,mixed> */
    public static function requireActivePhoto(Repository $repository, string $classPhotoId, bool $forUpdate = false): array
    {
        $row = $repository->fetchOne(
            'SELECT `class_photo_id`,`piwigo_image_id`,`media_checksum`,`state` FROM `'
                . $repository->table('photo') . '` WHERE `class_photo_id` = ?'
                . ($forUpdate ? ' FOR UPDATE' : ' LIMIT 1'),
            [self::idToBinary($classPhotoId)],
        );
        if ($row === null || ($row['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE || (int) ($row['piwigo_image_id'] ?? 0) <= 0) {
            throw new \RuntimeException('class_archive_photo_not_active');
        }
        return $row;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function auditActor(array $context): array
    {
        return [
            'actor_principal_id' => (int) ($context['principal_id'] ?? 0),
            'actor_user_id' => (int) ($context['piwigo_user_id'] ?? 0),
            'actor_kind' => (string) ($context['role'] ?? 'SYSTEM'),
        ];
    }
}
