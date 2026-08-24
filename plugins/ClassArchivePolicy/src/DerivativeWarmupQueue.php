<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Durable, path-free handoff from an approved canonical photo mapping to the
 * explicit derivative maintenance runner. Queue entries contain only the
 * canonical ClassArchivePhoto UUID and its current Piwigo image id; source and
 * derivative paths are always re-resolved from trusted database state.
 */
final class ClassArchiveDerivativeWarmupQueue
{
    private const VERSION = 1;
    private const DIRECTORY = '_data/class-archive/derivative-warmup';
    private const LOCK_FILE = '_data/class-archive/derivative-warmup.lock';
    private const QUARANTINE_DIRECTORY = '_data/class-archive/derivative-warmup-quarantine';
    private const MAX_TEMP_BYTES = 512;

    public static function enqueueBestEffort(string $classPhotoId, int $imageId): bool
    {
        try {
            self::enqueue($classPhotoId, $imageId);
            return true;
        } catch (Throwable $error) {
            // Approval/import is already durable at this point. Do not report
            // it as failed because a disposable cache handoff failed; the
            // bounded/all maintenance scan can still recover it.
            error_log('Class Archive derivative warmup enqueue failed: ' . get_class($error));
            return false;
        }
    }

    public static function enqueue(string $classPhotoId, int $imageId): void
    {
        $entry = self::entry($classPhotoId, $imageId);
        self::synchronized(static function (string $directory) use ($entry, $imageId): void {
            // Recover or isolate only our own strictly named crash remnants
            // before publishing more work. An arbitrary directory entry still
            // blocks the queue rather than being ignored or rewritten.
            self::scanLocked($directory);

            $path = $directory . DIRECTORY_SEPARATOR . self::filename($entry['class_photo_id'], $imageId);
            $payload = self::payload($entry);
            if (file_exists($path) || is_link($path)) {
                self::assertFile($path, $payload);
                return;
            }

            $temporary = $directory . DIRECTORY_SEPARATOR . '.pending-' . bin2hex(random_bytes(12));
            $handle = @fopen($temporary, 'x+b');
            if (!is_resource($handle)) {
                throw new RuntimeException('derivative_warmup_queue_create_failed');
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new RuntimeException('derivative_warmup_queue_write_failed');
                }
                $written = fwrite($handle, $payload);
                if ($written !== strlen($payload)
                    || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))
                    || !chmod($temporary, 0660)) {
                    throw new RuntimeException('derivative_warmup_queue_write_failed');
                }
            } finally {
                fclose($handle);
            }

            // Never unlink a temporary marker. A SIGKILL or an exceptional
            // publish path is recovered or quarantined by the next locked
            // scan, preserving the original bytes for operator inspection.
            if (!@rename($temporary, $path)) {
                if (file_exists($path) && !is_link($path)) {
                    self::assertFile($path, $payload);
                    $temp = self::inspectTemp($temporary, basename($temporary));
                    self::quarantineTempLocked($temp, 'duplicate');
                    return;
                }
                throw new RuntimeException('derivative_warmup_queue_publish_failed');
            }
            self::assertFile($path, $payload);
        });
    }

    /** @return list<array{class_photo_id:string,piwigo_image_id:int}> */
    public static function pending(): array
    {
        return self::synchronized(static function (string $directory): array {
            return self::scanLocked($directory);
        });
    }

    public static function complete(string $classPhotoId, int $imageId): void
    {
        $entry = self::entry($classPhotoId, $imageId);
        self::synchronized(static function (string $directory) use ($entry, $imageId): void {
            self::scanLocked($directory);
            $path = $directory . DIRECTORY_SEPARATOR . self::filename($entry['class_photo_id'], $imageId);
            self::assertFile($path, self::payload($entry));
            $handle = @fopen($path, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException('derivative_warmup_queue_open_failed');
            }
            try {
                if (!flock($handle, LOCK_EX) || !@unlink($path)) {
                    throw new RuntimeException('derivative_warmup_queue_complete_failed');
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * Preserve a fully verified marker whose canonical mapping and Piwigo row
     * were both proven absent by maintenance. This is an explicit isolation,
     * never a delete and never a fallback for database/query uncertainty.
     */
    public static function quarantineOrphan(string $classPhotoId, int $imageId): void
    {
        $entry = self::entry($classPhotoId, $imageId);
        self::synchronized(static function (string $directory) use ($entry, $imageId): void {
            self::scanLocked($directory);
            $source = $directory . DIRECTORY_SEPARATOR . self::filename($entry['class_photo_id'], $imageId);
            $payload = self::payload($entry);
            self::assertFile($source, $payload);
            $quarantine = self::quarantineDirectory();
            $target = $quarantine . DIRECTORY_SEPARATOR . self::filename($entry['class_photo_id'], $imageId) . '.orphaned';
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('derivative_warmup_quarantine_conflict');
            }
            $handle = @fopen($source, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException('derivative_warmup_queue_open_failed');
            }
            try {
                if (!flock($handle, LOCK_EX) || !@rename($source, $target)) {
                    throw new RuntimeException('derivative_warmup_quarantine_failed');
                }
                $stat = @lstat($target);
                $actual = is_array($stat) && !is_link($target) ? @file_get_contents($target) : false;
                if (!is_array($stat)
                    || (($stat['mode'] ?? 0) & 0170000) !== 0100000
                    || (($stat['mode'] ?? 0) & 0777) !== 0660
                    || (int) ($stat['nlink'] ?? 0) !== 1
                    || !is_string($actual)
                    || !hash_equals($payload, $actual)) {
                    @rename($target, $source);
                    throw new RuntimeException('derivative_warmup_quarantine_verification_failed');
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /** @return list<array{class_photo_id:string,piwigo_image_id:int}> */
    private static function scanLocked(string $directory): array
    {
        $names = scandir($directory);
        if (!is_array($names)) {
            throw new RuntimeException('derivative_warmup_queue_read_failed');
        }

        $temps = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match('/\A(?<uuid>[0-9a-f]{32})-(?<image>[1-9][0-9]{0,9})\.pending\z/D', $name, $match) === 1) {
                $entry = self::entry(self::hyphenate((string) $match['uuid']), (int) $match['image']);
                self::assertFile($directory . DIRECTORY_SEPARATOR . $name, self::payload($entry));
                continue;
            }
            if (preg_match('/\A\.pending-[0-9a-f]{24}\z/D', $name) === 1) {
                $temps[] = self::inspectTemp($directory . DIRECTORY_SEPARATOR . $name, $name);
                continue;
            }
            throw new RuntimeException('derivative_warmup_queue_unknown_entry');
        }

        foreach ($temps as $temp) {
            self::recoverTempLocked($directory, $temp);
        }

        $names = scandir($directory);
        if (!is_array($names)) {
            throw new RuntimeException('derivative_warmup_queue_read_failed');
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match('/\A(?<uuid>[0-9a-f]{32})-(?<image>[1-9][0-9]{0,9})\.pending\z/D', $name, $match) !== 1) {
                // A recognized temp must have been atomically recovered or
                // isolated. Anything else means the scan did not reach a
                // trustworthy steady state.
                throw new RuntimeException('derivative_warmup_queue_unknown_entry');
            }
            $entry = self::entry(self::hyphenate((string) $match['uuid']), (int) $match['image']);
            self::assertFile($directory . DIRECTORY_SEPARATOR . $name, self::payload($entry));
            $entries[] = $entry;
        }
        usort($entries, static fn (array $left, array $right): int => $left['piwigo_image_id'] <=> $right['piwigo_image_id']);
        return $entries;
    }

    /**
     * @return array{name:string,path:string,contents:string,sha256:string,size:int,uid:int,entry:?array{class_photo_id:string,piwigo_image_id:int}}
     */
    private static function inspectTemp(string $path, string $name): array
    {
        if (preg_match('/\A\.pending-[0-9a-f]{24}\z/D', $name) !== 1 || basename($path) !== $name) {
            throw new RuntimeException('derivative_warmup_queue_temp_name_untrusted');
        }
        $before = @lstat($path);
        $directoryStat = @lstat(dirname($path));
        if (!is_array($before)
            || !is_array($directoryStat)
            || is_link($path)
            || (($before['mode'] ?? 0) & 0170000) !== 0100000
            || !in_array((int) (($before['mode'] ?? 0) & 0777), [0600, 0660], true)
            || (int) ($before['nlink'] ?? 0) !== 1
            || (int) ($before['size'] ?? -1) < 0
            || (int) ($before['size'] ?? -1) > self::MAX_TEMP_BYTES
            || !self::trustedOwner($before, $directoryStat)) {
            throw new RuntimeException('derivative_warmup_queue_temp_untrusted');
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('derivative_warmup_queue_temp_open_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('derivative_warmup_queue_temp_lock_failed');
            }
            $opened = fstat($handle);
            $current = @lstat($path);
            if (!is_array($opened)
                || !is_array($current)
                || !self::sameFile($before, $opened)
                || !self::sameFile($opened, $current)
                || (int) ($opened['size'] ?? -1) > self::MAX_TEMP_BYTES) {
                throw new RuntimeException('derivative_warmup_queue_temp_changed');
            }
            $contents = stream_get_contents($handle, self::MAX_TEMP_BYTES + 1);
            if (!is_string($contents)
                || strlen($contents) !== (int) ($opened['size'] ?? -1)
                || strlen($contents) > self::MAX_TEMP_BYTES) {
                throw new RuntimeException('derivative_warmup_queue_temp_read_failed');
            }
        } finally {
            fclose($handle);
        }

        $entry = null;
        try {
            $decoded = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
            if (is_array($decoded)
                && array_keys($decoded) === ['version', 'class_photo_id', 'piwigo_image_id']
                && ($decoded['version'] ?? null) === self::VERSION
                && is_string($decoded['class_photo_id'] ?? null)
                && is_int($decoded['piwigo_image_id'] ?? null)) {
                $candidate = self::entry($decoded['class_photo_id'], $decoded['piwigo_image_id']);
                if (hash_equals(self::payload($candidate), $contents)) {
                    $entry = $candidate;
                }
            }
        } catch (Throwable) {
            // An interrupted or malformed internal temp is evidence, not a
            // queue marker. It is moved byte-for-byte to private quarantine.
        }

        return [
            'name' => $name,
            'path' => $path,
            'contents' => $contents,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
            'uid' => (int) ($before['uid'] ?? -1),
            'entry' => $entry,
        ];
    }

    /** @param array{name:string,path:string,contents:string,sha256:string,size:int,uid:int,entry:?array{class_photo_id:string,piwigo_image_id:int}} $temp */
    private static function recoverTempLocked(string $directory, array $temp): void
    {
        if ($temp['entry'] === null) {
            self::quarantineTempLocked($temp, 'invalid');
            return;
        }
        $entry = $temp['entry'];
        $target = $directory . DIRECTORY_SEPARATOR . self::filename(
            $entry['class_photo_id'],
            $entry['piwigo_image_id'],
        );
        $payload = self::payload($entry);
        if (file_exists($target) || is_link($target)) {
            self::assertFile($target, $payload);
            self::quarantineTempLocked($temp, 'duplicate');
            return;
        }
        $tempStat = @lstat($temp['path']);
        $mode = is_array($tempStat) ? (int) (($tempStat['mode'] ?? 0) & 0777) : 0;
        if (($mode !== 0660 && !@chmod($temp['path'], 0660)) || !@rename($temp['path'], $target)) {
            throw new RuntimeException('derivative_warmup_queue_temp_recovery_failed');
        }
        try {
            self::assertFile($target, $payload);
        } catch (Throwable $error) {
            @rename($target, $temp['path']);
            throw $error;
        }
    }

    /** @param array{name:string,path:string,contents:string,sha256:string,size:int,uid:int,entry:?array{class_photo_id:string,piwigo_image_id:int}} $temp */
    private static function quarantineTempLocked(array $temp, string $reason): void
    {
        if ($reason !== 'invalid' && $reason !== 'duplicate') {
            throw new InvalidArgumentException('derivative_warmup_quarantine_reason_invalid');
        }
        $quarantine = self::quarantineDirectory();
        $token = substr($temp['name'], strlen('.pending-'));
        $target = $quarantine . DIRECTORY_SEPARATOR . 'stale-temp-' . $token . '-'
            . $reason . '-' . bin2hex(random_bytes(6)) . '.quarantined';
        if (file_exists($target) || is_link($target) || !@rename($temp['path'], $target)) {
            throw new RuntimeException('derivative_warmup_quarantine_failed');
        }
        $stat = @lstat($target);
        $actual = is_array($stat) && !is_link($target) ? @file_get_contents($target) : false;
        if (!is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || !in_array((int) (($stat['mode'] ?? 0) & 0777), [0600, 0660], true)
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (int) ($stat['uid'] ?? -1) !== $temp['uid']
            || !is_string($actual)
            || strlen($actual) !== $temp['size']
            || !hash_equals($temp['sha256'], hash('sha256', $actual))) {
            @rename($target, $temp['path']);
            throw new RuntimeException('derivative_warmup_quarantine_verification_failed');
        }
    }

    private static function synchronized(callable $callback): mixed
    {
        $directory = self::directory(true);
        if (!is_string($directory)) {
            throw new RuntimeException('derivative_warmup_queue_directory_failed');
        }
        $lock = self::openQueueLock($directory);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('derivative_warmup_queue_lock_failed');
            }
            self::assertQueueLock($lock, $directory);
            return $callback($directory);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return resource */
    private static function openQueueLock(string $directory)
    {
        $parent = realpath(dirname($directory));
        $expectedParent = realpath(PHPWG_ROOT_PATH . dirname(self::LOCK_FILE));
        if ($parent === false || $expectedParent === false || $parent !== $expectedParent
            || is_link(PHPWG_ROOT_PATH . dirname(self::LOCK_FILE))) {
            throw new RuntimeException('derivative_warmup_queue_lock_parent_untrusted');
        }
        $path = PHPWG_ROOT_PATH . self::LOCK_FILE;
        $created = false;
        $handle = @fopen($path, 'x+b');
        if (is_resource($handle)) {
            $created = true;
            if (!@chmod($path, 0660)) {
                fclose($handle);
                throw new RuntimeException('derivative_warmup_queue_lock_mode_failed');
            }
        } else {
            // A concurrent creator may be between open(O_EXCL) and chmod.
            // Retry validation briefly; never normalize an existing unknown
            // filesystem object.
            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $stat = @lstat($path);
                if (self::validLockStat($stat, @lstat($parent))) {
                    break;
                }
                usleep(2500);
            }
            $stat = @lstat($path);
            if (!self::validLockStat($stat, @lstat($parent))) {
                throw new RuntimeException('derivative_warmup_queue_lock_untrusted');
            }
            $handle = @fopen($path, 'r+b');
            if (!is_resource($handle)) {
                throw new RuntimeException('derivative_warmup_queue_lock_open_failed');
            }
        }
        if ($created || is_resource($handle)) {
            return $handle;
        }
        throw new RuntimeException('derivative_warmup_queue_lock_open_failed');
    }

    /** @param resource $handle */
    private static function assertQueueLock($handle, string $directory): void
    {
        $path = PHPWG_ROOT_PATH . self::LOCK_FILE;
        $opened = fstat($handle);
        $current = @lstat($path);
        $parent = @lstat(dirname($directory));
        if (!self::validLockStat($opened, $parent)
            || !self::validLockStat($current, $parent)
            || !self::sameFile($opened, $current)) {
            throw new RuntimeException('derivative_warmup_queue_lock_changed');
        }
    }

    private static function validLockStat(mixed $stat, mixed $directoryStat): bool
    {
        return is_array($stat)
            && is_array($directoryStat)
            && (($stat['mode'] ?? 0) & 0170000) === 0100000
            && (($stat['mode'] ?? 0) & 0777) === 0660
            && (int) ($stat['nlink'] ?? 0) === 1
            && (int) ($stat['size'] ?? -1) === 0
            && self::trustedOwner($stat, $directoryStat);
    }

    private static function trustedOwner(array $stat, array $directoryStat): bool
    {
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $ownerUid = (int) ($stat['uid'] ?? -1);
        $directoryOwner = (int) ($directoryStat['uid'] ?? -2);
        $configuredUid = getenv('PIWIGO_UID');
        $configuredUid = is_string($configuredUid) && preg_match('/\A[0-9]{1,10}\z/D', $configuredUid) === 1
            ? (int) $configuredUid
            : null;
        return $ownerUid === $directoryOwner
            || ($effectiveUid !== null && $ownerUid === $effectiveUid)
            || ($configuredUid !== null && $ownerUid === $configuredUid);
    }

    private static function sameFile(array $left, array $right): bool
    {
        return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2)
            && (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2);
    }

    /** @return array{class_photo_id:string,piwigo_image_id:int} */
    private static function entry(string $classPhotoId, int $imageId): array
    {
        $classPhotoId = strtolower($classPhotoId);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $classPhotoId) !== 1) {
            throw new InvalidArgumentException('derivative_warmup_class_photo_id_invalid');
        }
        if ($imageId <= 0 || $imageId > 4294967295) {
            throw new InvalidArgumentException('derivative_warmup_image_id_invalid');
        }
        return ['class_photo_id' => $classPhotoId, 'piwigo_image_id' => $imageId];
    }

    /** @param array{class_photo_id:string,piwigo_image_id:int} $entry */
    private static function payload(array $entry): string
    {
        return json_encode(
            ['version' => self::VERSION] + $entry,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private static function filename(string $classPhotoId, int $imageId): string
    {
        return str_replace('-', '', $classPhotoId) . '-' . $imageId . '.pending';
    }

    private static function hyphenate(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private static function directory(bool $create): ?string
    {
        $root = realpath(PHPWG_ROOT_PATH . '_data');
        if ($root === false || is_link(PHPWG_ROOT_PATH . '_data')) {
            throw new RuntimeException('derivative_warmup_queue_root_untrusted');
        }
        $path = PHPWG_ROOT_PATH . self::DIRECTORY;
        if (!file_exists($path)) {
            if (!$create) {
                return null;
            }
            $oldUmask = umask(0007);
            try {
                if (!@mkdir($path, 0770, true) && !is_dir($path)) {
                    throw new RuntimeException('derivative_warmup_queue_directory_failed');
                }
            } finally {
                umask($oldUmask);
            }
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($path)
            || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('derivative_warmup_queue_directory_untrusted');
        }
        return $resolved;
    }

    private static function quarantineDirectory(): string
    {
        $root = realpath(PHPWG_ROOT_PATH . '_data');
        if ($root === false || is_link(PHPWG_ROOT_PATH . '_data')) {
            throw new RuntimeException('derivative_warmup_queue_root_untrusted');
        }
        $path = PHPWG_ROOT_PATH . self::QUARANTINE_DIRECTORY;
        if (!file_exists($path)) {
            $oldUmask = umask(0007);
            try {
                if (!@mkdir($path, 0770, true) && !is_dir($path)) {
                    throw new RuntimeException('derivative_warmup_quarantine_directory_failed');
                }
            } finally {
                umask($oldUmask);
            }
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($path)
            || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('derivative_warmup_quarantine_directory_untrusted');
        }
        return $resolved;
    }

    private static function assertFile(string $path, string $payload): void
    {
        $stat = @lstat($path);
        $directoryStat = @lstat(dirname($path));
        $actual = is_array($stat) && !is_link($path) ? @file_get_contents($path) : false;
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $ownerUid = is_array($stat) ? (int) ($stat['uid'] ?? -1) : -1;
        $directoryOwner = is_array($directoryStat) ? (int) ($directoryStat['uid'] ?? -2) : -2;
        if (!is_array($stat)
            || !is_array($directoryStat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($stat['mode'] ?? 0) & 0777) !== 0660
            || (int) ($stat['nlink'] ?? 0) !== 1
            // The persistent Piwigo volume owner can intentionally differ
            // from the image's runtime nginx uid. Accept only the active uid
            // or the already-trusted queue directory owner; the startup hook
            // strictly validates and migrates older entries to that owner.
            || ($ownerUid !== $directoryOwner && ($effectiveUid === null || $ownerUid !== $effectiveUid))
            || !is_string($actual)
            || !hash_equals($payload, $actual)) {
            throw new RuntimeException('derivative_warmup_queue_entry_untrusted');
        }
    }
}
