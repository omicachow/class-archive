<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Opaque Class Archive identity for an AI face cluster.
 *
 * This intentionally has no semantic relationship to a ClassIdentity roster
 * record.  A future, explicit archival curation action may optionally bind a
 * cluster to an Identity, but neither the Immich person UUID nor that optional
 * relationship is ever a public photo-app identifier.
 */
final class ClassArchivePerson
{
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_STALE = 'STALE';
    public const STATE_RETIRED = 'RETIRED';

    /** @return list<string> */
    public static function states(): array
    {
        return [self::STATE_ACTIVE, self::STATE_STALE, self::STATE_RETIRED];
    }

    /** Generate an opaque UUID that is unrelated to Immich and roster IDs. */
    public static function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return self::binaryToId($bytes);
    }

    public static function idToBinary(string $id): string
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $id)) {
            throw new \InvalidArgumentException('class_archive_person_id_invalid');
        }
        $binary = hex2bin(str_replace('-', '', strtolower($id)));
        if (!is_string($binary) || strlen($binary) !== 16) {
            throw new \InvalidArgumentException('class_archive_person_id_invalid');
        }
        return $binary;
    }

    public static function binaryToId(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new \InvalidArgumentException('class_archive_person_binary_id_invalid');
        }
        $hex = bin2hex($binary);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** Validate an internal Immich UUID without exposing it to callers. */
    public static function normalizeImmichPersonId(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id)) {
            throw new \InvalidArgumentException('class_archive_person_immich_id_invalid');
        }
        return $id;
    }
}
