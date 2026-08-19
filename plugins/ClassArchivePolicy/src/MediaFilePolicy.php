<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Keeps files written by Piwigo Core's upload path private after Core applies
 * its upstream 0644 mode. This is deliberately a hook around Core, not a
 * Core patch: MediaGuard remains the authorization boundary and this class is
 * a defence-in-depth filesystem invariant.
 */
final class ClassArchiveMediaFilePolicy
{
    /** @param array<string, mixed> $image */
    public static function normalizeUploadedFile(array $image): void
    {
        $relative = $image['path'] ?? null;
        if (!is_string($relative)) {
            throw new RuntimeException('class_archive_uploaded_media_path_invalid');
        }

        self::normalizeUploadRelativePath($relative);
    }

    /** @param array<string, mixed> $format */
    public static function normalizeUploadedFormat(array $format): void
    {
        $imageId = $format['image_id'] ?? null;
        $extension = $format['ext'] ?? null;
        if (!is_numeric($imageId) || !is_string($extension) || preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1) {
            throw new RuntimeException('class_archive_uploaded_format_invalid');
        }

        $row = query2array(
            'SELECT `path` FROM `' . IMAGES_TABLE . '` WHERE `id`=' . (int) $imageId . ' LIMIT 1'
        );
        if (count($row) !== 1 || !isset($row[0]['path']) || !is_string($row[0]['path'])) {
            throw new RuntimeException('class_archive_uploaded_format_image_missing');
        }

        $base = self::normalizeUploadRelativePath((string) $row[0]['path'], false);
        $formatPath = original_to_format($base, $extension);
        self::normalizeAbsoluteUploadPath($formatPath);
    }

    private static function normalizeUploadRelativePath(string $relative, bool $mustExist = true): string
    {
        $relative = str_replace('\\', '/', $relative);
        if (!preg_match('#\A\.?/?upload/(?:[^/]+/)*[^/]+\z#D', $relative)) {
            throw new RuntimeException('class_archive_uploaded_media_path_invalid');
        }
        $absolute = PHPWG_ROOT_PATH . ltrim($relative, './');
        if ($mustExist) {
            self::normalizeAbsoluteUploadPath($absolute);
        }
        return $absolute;
    }

    private static function normalizeAbsoluteUploadPath(string $path): void
    {
        $uploadRoot = realpath(PHPWG_ROOT_PATH . 'upload');
        if ($uploadRoot === false || is_link(PHPWG_ROOT_PATH . 'upload') || !is_file($path) || is_link($path)) {
            throw new RuntimeException('class_archive_uploaded_media_path_untrusted');
        }
        $resolved = realpath($path);
        $prefix = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/';
        $normalized = $resolved === false ? '' : str_replace('\\', '/', $resolved);
        if ($normalized === '' || !str_starts_with($normalized, $prefix)) {
            throw new RuntimeException('class_archive_uploaded_media_path_untrusted');
        }
        if (!@chmod($resolved, 0660)) {
            throw new RuntimeException('class_archive_uploaded_media_mode_write_failed');
        }
        clearstatcache(true, $resolved);
        $mode = @fileperms($resolved);
        if (!is_int($mode) || ($mode & 0777) !== 0660) {
            throw new RuntimeException('class_archive_uploaded_media_mode_invalid');
        }
    }
}
