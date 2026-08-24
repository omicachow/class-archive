<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Bounded, write-side derivative preparation for one exact canonical photo.
 *
 * This service is called only after an administrator/CLI write has committed.
 * It never participates in member GET/HEAD/Range delivery. The caller first
 * persists a path-free warmup marker; successful completion removes that
 * marker, while any failure leaves it for the maintenance runner.
 */
final class ClassArchiveDerivativeCacheWarmer
{
    private const MAX_RUNTIME_SECONDS = 30.0;
    private const MAX_STDERR_BYTES = 8192;

    /** @var array<string,string> */
    private const PROFILES = [
        'thumbnail' => 'IMG_THUMB',
        'xsmall' => 'IMG_XSMALL',
        'small' => 'IMG_SMALL',
        'medium' => 'IMG_MEDIUM',
        'large' => 'IMG_LARGE',
        'preview' => 'IMG_XLARGE',
    ];

    public static function warmBestEffort(string $classPhotoId, int $imageId): bool
    {
        try {
            self::warm($classPhotoId, $imageId);
            return true;
        } catch (Throwable $error) {
            // The durable approval/import remains the business truth and its
            // marker remains available for maintenance recovery. Do not log a
            // UUID, Piwigo id, source name or filesystem path here.
            error_log('Class Archive derivative immediate warmup failed: ' . get_class($error));
            return false;
        }
    }

    /** @return array{checked:int,cached:int,generated:int} */
    public static function warm(string $classPhotoId, int $imageId): array
    {
        self::assertIdentity($classPhotoId, $imageId);
        self::ensurePiwigoRuntime();
        $lock = self::lock();
        $started = microtime(true);
        try {
            $row = self::mappingRow($classPhotoId, $imageId);
            $sourceReference = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) $row['path']);
            $source = self::source($sourceReference);
            $normalized = self::normalizeMetadata($row, $source);
            $row = $normalized['row'];
            $metadataChanged = $normalized['changed'];
            $sourceImage = new SrcImage($row);
            $result = ['checked' => 0, 'cached' => 0, 'generated' => 0];

            foreach (self::PROFILES as $constantName) {
                if ((microtime(true) - $started) >= self::MAX_RUNTIME_SECONDS) {
                    throw new RuntimeException('derivative_immediate_warmup_timeout');
                }
                if (!defined($constantName)) {
                    throw new RuntimeException('derivative_immediate_profile_unavailable');
                }
                $type = (string) constant($constantName);
                $derivative = DerivativeImage::get_one($type, $sourceImage);
                if (!$derivative instanceof DerivativeImage) {
                    throw new RuntimeException('derivative_immediate_profile_unavailable');
                }
                ++$result['checked'];
                $identity = $derivative->same_as_source();
                if ($identity) {
                    $effectiveType = $type;
                    $target = self::canonicalDerivativePath($sourceReference, $type);
                } else {
                    $effectiveType = $derivative->get_type();
                    if (!is_string($effectiveType) || $effectiveType === '' || $effectiveType === 'Original') {
                        throw new RuntimeException('derivative_immediate_profile_unavailable');
                    }
                    $target = self::derivativePath($derivative);
                }

                if (self::fresh($target['absolute'], $source, $effectiveType)) {
                    self::normalizeMode($target['absolute']);
                    ++$result['cached'];
                    continue;
                }
                if (is_link($target['absolute'])
                    || (file_exists($target['absolute']) && !is_file($target['absolute']))) {
                    throw new RuntimeException('derivative_immediate_target_untrusted');
                }
                if ($identity) {
                    self::generateIdentity($target['relative'], $source);
                } else {
                    self::generate($target['relative'], $started);
                }
                if (!self::fresh($target['absolute'], $source, $effectiveType)) {
                    throw new RuntimeException('derivative_immediate_generation_unverified');
                }
                self::normalizeMode($target['absolute']);
                ++$result['generated'];
            }

            if ($metadataChanged) {
                if (!class_exists(\ClassIdentity\Gateway\ReadProjectionBuilder::class)) {
                    throw new RuntimeException('derivative_immediate_projection_rebuilder_unavailable');
                }
                // Metadata discovery is a native Piwigo write and therefore
                // invalidates the projection. Rebuild before consuming the
                // recovery marker so a failure remains safely retryable.
                \ClassIdentity\Gateway\ReadProjectionBuilder::rebuildChangedPhotos(
                    [strtolower($classPhotoId)],
                    \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
                );
            }
            ClassArchiveDerivativeWarmupQueue::complete($classPhotoId, $imageId);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function assertIdentity(string $classPhotoId, int $imageId): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $classPhotoId) !== 1) {
            throw new InvalidArgumentException('derivative_immediate_class_photo_id_invalid');
        }
        if ($imageId <= 0 || $imageId > 4294967295) {
            throw new InvalidArgumentException('derivative_immediate_image_id_invalid');
        }
    }

    private static function ensurePiwigoRuntime(): void
    {
        if (!class_exists(DerivativeImage::class, false)) {
            require_once PHPWG_ROOT_PATH . 'include/derivative.inc.php';
        }
        if (!class_exists(pwg_image::class, false)) {
            require_once PHPWG_ROOT_PATH . 'admin/include/image.class.php';
        }
        if (!class_exists(DerivativeImage::class, false)
            || !class_exists(SrcImage::class, false)
            || !class_exists(ImageStdParams::class, false)
            || !class_exists(pwg_image::class, false)) {
            throw new RuntimeException('derivative_immediate_runtime_unavailable');
        }
    }

    /** @return array<string,mixed> */
    private static function mappingRow(string $classPhotoId, int $imageId): array
    {
        global $prefixeTable;
        if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1
            || !class_exists(\ClassIdentity\Repository::class)
            || !class_exists(\ClassIdentity\ClassArchivePhoto::class)) {
            throw new RuntimeException('derivative_immediate_runtime_unavailable');
        }
        $repository = \ClassIdentity\Repository::fromPiwigo();
        $photo = $repository->table('photo');
        $rows = $repository->fetchAll(
            'SELECT i.`id`,i.`path`,i.`file`,i.`width`,i.`height`,i.`rotation`,i.`representative_ext`,'
            . 'pm.`media_reference`,pm.`state` AS `mapping_state` '
            . 'FROM `' . $prefixeTable . 'images` i '
            . 'JOIN `' . $photo . '` pm ON pm.`piwigo_image_id`=i.`id` '
            . 'WHERE pm.`class_photo_id`=UNHEX(REPLACE(?,\'-\',\'\')) AND pm.`piwigo_image_id`=? '
            . 'AND pm.`state`=\'ACTIVE\' LIMIT 2',
            [strtolower($classPhotoId), $imageId],
        );
        if (count($rows) !== 1 || ($rows[0]['mapping_state'] ?? null) !== \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE) {
            throw new RuntimeException('derivative_immediate_mapping_unresolved');
        }
        $path = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($rows[0]['path'] ?? ''));
        $mapped = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($rows[0]['media_reference'] ?? ''));
        if (!hash_equals($mapped, $path)) {
            throw new RuntimeException('derivative_immediate_mapping_reference_drift');
        }
        return $rows[0];
    }

    private static function root(): string
    {
        $root = realpath(PHPWG_ROOT_PATH);
        if ($root === false || !is_dir($root) || is_link(PHPWG_ROOT_PATH)) {
            throw new RuntimeException('derivative_immediate_root_untrusted');
        }
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private static function source(string $reference): string
    {
        $root = self::root();
        $topLevel = explode('/', $reference, 2)[0] ?? '';
        if (!in_array($topLevel, ['upload', 'galleries'], true)) {
            throw new RuntimeException('derivative_immediate_source_unavailable');
        }
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reference);
        return self::trustedFile($candidate, $root . DIRECTORY_SEPARATOR . $topLevel, true);
    }

    /** @param array<string,mixed> $row @return array{row:array<string,mixed>,changed:bool} */
    private static function normalizeMetadata(array $row, string $source): array
    {
        global $prefixeTable;
        $imageId = (int) ($row['id'] ?? 0);
        $updates = [];
        if ((int) ($row['width'] ?? 0) <= 0 || (int) ($row['height'] ?? 0) <= 0) {
            $size = @getimagesize($source);
            if (!is_array($size) || (int) ($size[0] ?? 0) <= 0 || (int) ($size[1] ?? 0) <= 0) {
                throw new RuntimeException('derivative_immediate_source_dimensions_unavailable');
            }
            $row['width'] = $updates['width'] = (int) $size[0];
            $row['height'] = $updates['height'] = (int) $size[1];
        }
        if (!array_key_exists('rotation', $row) || $row['rotation'] === null) {
            $rotation = pwg_image::get_rotation_code_from_angle(pwg_image::get_rotation_angle($source));
            if (!is_int($rotation) || $rotation < 0 || $rotation > 3) {
                throw new RuntimeException('derivative_immediate_source_rotation_invalid');
            }
            $row['rotation'] = $updates['rotation'] = $rotation;
        }
        if ($updates !== []) {
            if (!class_exists(\ClassIdentity\ProjectionMutationBoundary::class)) {
                throw new RuntimeException('derivative_immediate_projection_boundary_unavailable');
            }
            $repository = \ClassIdentity\Repository::fromPiwigo();
            \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
                $repository,
                \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
                'DERIVATIVE_METADATA_NORMALIZE',
            );
            $assignments = [];
            $parameters = [];
            foreach ($updates as $field => $value) {
                $assignments[] = '`' . $field . '`=?';
                $parameters[] = $value;
            }
            $parameters[] = $imageId;
            $changed = $repository->execute(
                'UPDATE `' . $prefixeTable . 'images` SET ' . implode(',', $assignments) . ' WHERE `id`=?',
                $parameters,
            );
            if ($changed !== 1) {
                throw new RuntimeException('derivative_immediate_source_metadata_update_failed');
            }
        }
        return ['row' => $row, 'changed' => $updates !== []];
    }

    /** @return resource */
    private static function lock()
    {
        $directory = self::root() . '/_data/class-archive';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('derivative_immediate_lock_directory_unavailable');
        }
        if (is_link($directory)) {
            throw new RuntimeException('derivative_immediate_lock_directory_untrusted');
        }
        $path = $directory . '/photo-cache-warmup.lock';
        if (is_link($path)) {
            throw new RuntimeException('derivative_immediate_lock_untrusted');
        }
        $handle = @fopen($path, 'c+b');
        $mode = (fileperms($path) ?: 0) & 0777;
        if (!is_resource($handle)
            || ($mode !== 0660 && (!@chmod($path, 0660) || ((fileperms($path) ?: 0) & 0777) !== 0660))
            || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('derivative_immediate_warmup_busy');
        }
        return $handle;
    }

    /** @return array{absolute:string,relative:string} */
    private static function derivativePath(DerivativeImage $derivative): array
    {
        $path = str_replace('\\', '/', $derivative->get_path());
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if (!preg_match('#\A_data/i/(?<relative>(?:upload|galleries)/(?:[^/]+/)*[^/]+)\z#D', $path, $match)) {
            throw new RuntimeException('derivative_immediate_path_invalid');
        }
        return self::target((string) $match['relative']);
    }

    /** @return array{absolute:string,relative:string} */
    private static function canonicalDerivativePath(string $sourceReference, string $type): array
    {
        $token = derivative_to_url($type);
        $dot = strrpos($sourceReference, '.');
        if (!is_string($token) || preg_match('/\A[A-Za-z0-9_]+\z/D', $token) !== 1
            || $dot === false || $dot <= 0 || str_contains(substr($sourceReference, $dot + 1), '/')) {
            throw new RuntimeException('derivative_immediate_path_invalid');
        }
        return self::target(substr($sourceReference, 0, $dot) . '-' . $token . substr($sourceReference, $dot));
    }

    /** @return array{absolute:string,relative:string} */
    private static function target(string $relative): array
    {
        if (strlen($relative) > 4096 || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_contains($relative, '//')
            || !preg_match('#\A(?:upload|galleries)/(?:[^/]+/)*[^/]+\z#D', $relative)) {
            throw new RuntimeException('derivative_immediate_path_invalid');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new RuntimeException('derivative_immediate_path_invalid');
            }
        }
        $absolute = self::root() . '/_data/i/' . $relative;
        self::assertDerivativeAncestors(dirname($absolute));
        return ['absolute' => $absolute, 'relative' => $relative];
    }

    private static function assertDerivativeAncestors(string $directory): void
    {
        $derivativeRootPath = self::root() . '/_data/i';
        $root = realpath($derivativeRootPath);
        if ($root === false || is_link($derivativeRootPath)) {
            throw new RuntimeException('derivative_immediate_cache_root_untrusted');
        }
        $cursor = $directory;
        while (!file_exists($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('derivative_immediate_parent_unavailable');
            }
            $cursor = $parent;
        }
        $resolved = !is_link($cursor) ? realpath($cursor) : false;
        if ($resolved === false
            || ($resolved !== $root && !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('derivative_immediate_parent_untrusted');
        }
    }

    private static function fresh(string $derivative, string $source, string $type): bool
    {
        if (!file_exists($derivative) && !is_link($derivative)) {
            return false;
        }
        self::trustedFile($derivative, self::root() . '/_data/i', false);
        $sourceMtime = filemtime($source);
        $derivativeMtime = filemtime($derivative);
        $params = ImageStdParams::get_by_type($type);
        if ($sourceMtime === false || $derivativeMtime === false || $params === null) {
            throw new RuntimeException('derivative_immediate_freshness_unavailable');
        }
        return $derivativeMtime >= max($sourceMtime, (int) $params->last_mod_time);
    }

    private static function normalizeMode(string $path): void
    {
        $mode = (fileperms($path) ?: 0) & 0777;
        if (!is_file($path) || is_link($path)
            || ($mode !== 0660 && (!@chmod($path, 0660) || ((fileperms($path) ?: 0) & 0777) !== 0660))) {
            throw new RuntimeException('derivative_immediate_mode_failed');
        }
        self::trustedFile($path, self::root() . '/_data/i', true);
    }

    private static function trustedFile(string $path, string $trustedRoot, bool $requireMode): string
    {
        $stat = @lstat($path);
        $rootStat = @lstat($trustedRoot);
        $resolved = realpath($path);
        $root = realpath($trustedRoot);
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $ownerUid = is_array($stat) ? (int) ($stat['uid'] ?? -1) : -1;
        $rootOwnerUid = is_array($rootStat) ? (int) ($rootStat['uid'] ?? -2) : -2;
        if (!is_array($stat) || !is_array($rootStat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($rootStat['mode'] ?? 0) & 0170000) !== 0040000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || ($requireMode && (($stat['mode'] ?? 0) & 0777) !== 0660)
            || ($ownerUid !== $rootOwnerUid && ($effectiveUid === null || $ownerUid !== $effectiveUid))
            || is_link($path) || is_link($trustedRoot)
            || $resolved === false || $root === false
            || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('derivative_immediate_file_untrusted');
        }
        return $resolved;
    }

    private static function generate(string $relative, float $warmStarted): void
    {
        $root = self::root();
        $generator = $root . '/plugins/ClassArchivePolicy/derivative-generator.php';
        $php = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        if (!is_file($generator) || is_link($generator) || !is_file($php) || !is_executable($php)) {
            throw new RuntimeException('derivative_immediate_generator_unavailable');
        }
        $environment = self::generatorEnvironment($relative);
        $pipes = [];
        $process = proc_open(
            [$php, $generator],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('derivative_immediate_generator_start_failed');
        }
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $exitCode = -1;
        try {
            while (true) {
                $chunk = (string) stream_get_contents($pipes[2]);
                if (strlen($stderr) + strlen($chunk) > self::MAX_STDERR_BYTES) {
                    self::terminateProcess($process);
                    throw new RuntimeException('derivative_immediate_generator_stderr_limit');
                }
                $stderr .= $chunk;
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    self::terminateProcess($process);
                    throw new RuntimeException('derivative_immediate_generator_status_failed');
                }
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if ((microtime(true) - $warmStarted) >= self::MAX_RUNTIME_SECONDS) {
                    self::terminateProcess($process);
                    throw new RuntimeException('derivative_immediate_generator_timeout');
                }
                usleep(20_000);
            }
            $chunk = (string) stream_get_contents($pipes[2]);
            if (strlen($stderr) + strlen($chunk) > self::MAX_STDERR_BYTES) {
                throw new RuntimeException('derivative_immediate_generator_stderr_limit');
            }
            $stderr .= $chunk;
        } finally {
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode < 0) {
                $exitCode = $closed;
            }
        }
        if ($exitCode !== 0 || trim($stderr) !== '') {
            throw new RuntimeException('derivative_immediate_generator_failed');
        }
    }

    /** @return array<string,string> */
    private static function generatorEnvironment(string $relative): array
    {
        $environment = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => '/tmp',
            'TMPDIR' => '/tmp',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'CLASS_ARCHIVE_DERIVATIVE_GENERATOR' => '1',
            'CLASS_ARCHIVE_DERIVATIVE_PATH' => $relative,
            'QUERY_STRING' => '/' . $relative,
            'REQUEST_URI' => '/i.php?/' . $relative,
            'SCRIPT_NAME' => '/i.php',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        $timezone = getenv('TZ');
        if (is_string($timezone) && preg_match('#\A[A-Za-z0-9_+./-]{1,64}\z#D', $timezone) === 1
            && !str_contains($timezone, '..')) {
            $environment['TZ'] = $timezone;
        }
        return $environment;
    }

    /** @param resource $process */
    private static function terminateProcess($process): void
    {
        @proc_terminate($process, 15);
        $deadline = microtime(true) + 0.5;
        do {
            $status = @proc_get_status($process);
            if (!is_array($status) || !$status['running']) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        @proc_terminate($process, 9);
    }

    private static function generateIdentity(string $relative, string $source): void
    {
        $target = self::root() . '/_data/i/' . $relative;
        self::assertDerivativeAncestors(dirname($target));
        $lockPath = sys_get_temp_dir() . '/class-archive-identity-derivative-' . hash('sha256', $relative) . '.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('derivative_immediate_identity_lock_untrusted');
        }
        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock) || !@chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('derivative_immediate_identity_lock_failed');
        }
        $temporary = null;
        try {
            clearstatcache(true, $target);
            if (is_file($target) && !is_link($target)) {
                return;
            }
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('derivative_immediate_identity_target_untrusted');
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new RuntimeException('derivative_immediate_identity_parent_failed');
            }
            self::assertDerivativeAncestors($parent);
            $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $filename = pathinfo($target, PATHINFO_FILENAME);
            if (preg_match('/\A[A-Za-z0-9]+\z/D', $extension) !== 1 || $filename === '') {
                throw new RuntimeException('derivative_immediate_identity_extension_invalid');
            }
            $temporary = $parent . '/.' . $filename . '.warm-' . bin2hex(random_bytes(8)) . '.' . $extension;
            $sourceSize = @getimagesize($source);
            $image = new pwg_image($source);
            try {
                $image->set_compression_quality(ImageStdParams::$quality);
                $image->strip();
                $image->write($temporary);
            } finally {
                $image->destroy();
            }
            $outputSize = is_file($temporary) && !is_link($temporary) ? @getimagesize($temporary) : false;
            if (!is_array($sourceSize) || !is_array($outputSize)
                || (int) $outputSize[0] !== (int) $sourceSize[0]
                || (int) $outputSize[1] !== (int) $sourceSize[1]
                || (int) @filesize($temporary) <= 0
                || !@chmod($temporary, 0660)
                || !@rename($temporary, $target)) {
                throw new RuntimeException('derivative_immediate_identity_generation_failed');
            }
            $temporary = null;
        } finally {
            if (is_string($temporary) && (is_file($temporary) || is_link($temporary))) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
