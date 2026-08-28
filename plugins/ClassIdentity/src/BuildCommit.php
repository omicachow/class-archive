<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Resolve the checked-out Git commit from the intentionally narrow read-only
 * metadata mounts provided by the local compose stack.
 *
 * No Git config, object database, reflog, remote or credential store is
 * available to the application. If the current ref cannot be resolved from
 * HEAD plus refs, release evidence fails closed.
 */
final class BuildCommit
{
    private const HEAD = '/workspace/git/HEAD';
    private const REFS = '/workspace/git/refs';

    public static function current(): string
    {
        $head = self::readSmallTrustedFile(self::HEAD, 256);
        $head = trim($head);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $head) === 1) {
            return $head;
        }
        if (preg_match('/\Aref: (refs\/(?:heads|tags)\/[A-Za-z0-9._\/-]+)\z/D', $head, $matches) !== 1
            || str_contains($matches[1], '..') || str_contains($matches[1], '//')) {
            throw new \RuntimeException('class_identity_build_commit_head_invalid');
        }

        $refsRoot = realpath(self::REFS);
        if ($refsRoot === false || !is_dir($refsRoot) || is_link(self::REFS)) {
            throw new \RuntimeException('class_identity_build_commit_refs_untrusted');
        }
        $relative = substr($matches[1], strlen('refs/'));
        $path = rtrim(str_replace('\\', '/', $refsRoot), '/') . '/' . $relative;
        $realPath = realpath($path);
        if ($realPath === false || is_link($path)
            || !str_starts_with(str_replace('\\', '/', $realPath), rtrim(str_replace('\\', '/', $refsRoot), '/') . '/')) {
            throw new \RuntimeException('class_identity_build_commit_ref_untrusted');
        }
        $commit = trim(self::readSmallTrustedFile($realPath, 128));
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new \RuntimeException('class_identity_build_commit_ref_invalid');
        }
        return $commit;
    }

    private static function readSmallTrustedFile(string $path, int $maximum): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('class_identity_build_commit_source_missing');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '' || strlen($contents) > $maximum || str_contains($contents, "\0")) {
            throw new \RuntimeException('class_identity_build_commit_source_invalid');
        }
        return $contents;
    }
}
