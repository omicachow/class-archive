<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Canonical, opaque photo identity helpers.
 *
 * A ClassArchivePhoto UUID is intentionally independent from both Piwigo's
 * numeric image id and Immich's future asset id.  Neither backend identifier
 * is a public API identity or an authorization credential.
 */
final class ClassArchivePhoto
{
    public const STATE_PENDING = 'PENDING';
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_STALE = 'STALE';
    public const STATE_RETIRED = 'RETIRED';

    /** @return list<string> */
    public static function states(): array
    {
        return [self::STATE_PENDING, self::STATE_ACTIVE, self::STATE_STALE, self::STATE_RETIRED];
    }

    /** Generate an RFC 4122 version-4 UUID without using a backend id. */
    public static function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::binaryToId($bytes);
    }

    /** Convert the public canonical UUID to its fixed-width storage value. */
    public static function idToBinary(string $id): string
    {
        $normalized = strtolower(trim($id));
        if (
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $normalized,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('class_archive_photo_id_invalid');
        }
        $binary = hex2bin(str_replace('-', '', $normalized));
        if (!is_string($binary) || strlen($binary) !== 16) {
            throw new \InvalidArgumentException('class_archive_photo_id_invalid');
        }

        return $binary;
    }

    /** Convert a fixed-width storage value to the public canonical UUID. */
    public static function binaryToId(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new \InvalidArgumentException('class_archive_photo_id_binary_invalid');
        }
        $hex = bin2hex($binary);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /**
     * Normalizes a SHA-256 digest to its binary database representation.
     *
     * Callers may provide the conventional lower/upper case hex form only;
     * accepting arbitrary binary strings here would make audit/reconciliation
     * callers much easier to misuse.
     */
    public static function checksumToBinary(string $checksum): string
    {
        $checksum = strtolower(trim($checksum));
        if (preg_match('/\A[0-9a-f]{64}\z/D', $checksum) !== 1) {
            throw new \InvalidArgumentException('class_archive_photo_checksum_invalid');
        }
        $binary = hex2bin($checksum);
        if (!is_string($binary) || strlen($binary) !== 32) {
            throw new \InvalidArgumentException('class_archive_photo_checksum_invalid');
        }

        return $binary;
    }

    public static function checksumToHex(string $checksum): string
    {
        if (strlen($checksum) !== 32) {
            throw new \InvalidArgumentException('class_archive_photo_checksum_binary_invalid');
        }

        return bin2hex($checksum);
    }

    /**
     * Normalize only the Piwigo database's media path form.
     *
     * This is a storage reference, never a request URL. Percent encoding,
     * Windows separators, dot traversal and absolute paths are rejected so
     * this value cannot later be repurposed as a delivery target.
     */
    public static function normalizeMediaReference(string $reference): string
    {
        $reference = trim($reference);
        if (str_starts_with($reference, './')) {
            $reference = substr($reference, 2);
        }
        if (
            $reference === ''
            || strlen($reference) > 512
            || str_contains($reference, "\0")
            || str_contains($reference, '\\')
            || str_contains($reference, '%')
            || str_starts_with($reference, '/')
            || preg_match('~(?:^|/)\.(?:/|$)|(?:^|/)\.\.(?:/|$)~D', $reference) === 1
            || !preg_match('~\A(?:upload|galleries)/[^/].*\z~Du', $reference)
        ) {
            throw new \InvalidArgumentException('class_archive_photo_media_reference_invalid');
        }

        return $reference;
    }

    /**
     * Pending submissions live in a separate private store. This accepted form
     * is intentionally narrower than Piwigo's published upload/galleries
     * references and is valid only while a mapping is PENDING.
     */
    public static function normalizePendingMediaReference(string $reference): string
    {
        $reference = trim($reference);
        if (
            !preg_match(
                '#\Aclass_identity_pending/[a-f0-9]{48}\.(?:jpg|jpeg|png|webp)\z#D',
                $reference,
            )
        ) {
            throw new \InvalidArgumentException('class_archive_photo_pending_media_reference_invalid');
        }

        return $reference;
    }

    /**
     * Immich asset ids are stored only as a future internal linkage.  The
     * canonical ClassArchivePhoto id stays authoritative and public.
     */
    public static function normalizeImmichAssetId(?string $assetId): ?string
    {
        if ($assetId === null) {
            return null;
        }
        $assetId = strtolower(trim($assetId));
        if ($assetId === '') {
            return null;
        }
        if (
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $assetId,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('class_archive_photo_immich_asset_id_invalid');
        }

        return $assetId;
    }
}
