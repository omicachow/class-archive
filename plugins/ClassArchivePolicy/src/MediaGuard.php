<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

final class ClassArchiveMediaDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?int $imageId = null,
        public readonly ?string $era = null,
        public readonly ?string $role = null,
    ) {
    }
}

final class ClassArchiveMediaRequest
{
    public function __construct(
        public readonly int $imageId,
        public readonly string $variant,
        public readonly string $sourcePath,
        public readonly string $internalUri,
        public readonly ?string $downloadName = null,
        public readonly ?string $derivativePath = null,
    ) {
    }
}

final class ClassArchiveMediaGuard
{
    private const ROLE_CLASSMATE = 'CLASSMATE';
    private const ROLE_TEACHER = 'TEACHER';
    private const ROLE_FAMILY = 'FAMILY';
    private const ROLE_ANONYMOUS = 'ANONYMOUS';
    private const ROLE_ADMIN = 'SYSTEM_ADMIN';

    private const ERA_HERITAGE = 'HERITAGE';
    private const ERA_LIVING = 'LIVING';

    /** @return array{request: ClassArchiveMediaRequest, image: array<string, mixed>} */
    public static function resolveRequest(string $kind): array
    {
        return match ($kind) {
            'source_path' => self::resolveSourcePath((string) ($_SERVER['CLASS_ARCHIVE_MEDIA_URI'] ?? '')),
            'derivative_path' => self::resolveDerivativePath(
                (string) ($_SERVER['CLASS_ARCHIVE_MEDIA_URI'] ?? ''),
                '/_data/i/'
            ),
            'derivative_script' => self::resolveDerivativeScript((string) ($_SERVER['QUERY_STRING'] ?? '')),
            'action' => self::resolveAction(self::parseActionQuery((string) ($_SERVER['QUERY_STRING'] ?? ''))),
            default => throw new DomainException('unsupported_request_kind'),
        };
    }

    public static function authorize(ClassArchiveMediaRequest $request, array $image): ClassArchiveMediaDecision
    {
        global $conf, $user;

        // An image id is not an authorization boundary. Piwigo does not place
        // a uniqueness constraint on images.path, so two rows can otherwise
        // point at the same original while carrying different album/Era
        // associations. Re-resolve the request's underlying original by its
        // canonical storage path immediately before authorization and require
        // that it still identifies this one row. This covers source,
        // derivative, action (e/r) and format delivery, and closes the race
        // between id-based request parsing and policy evaluation.
        self::assertUniqueOriginalBinding($request->imageId, $request->sourcePath);

        $role = self::resolveRole($user ?? []);
        if ($role === null) {
            return new ClassArchiveMediaDecision(false, 'actor_unresolved', $request->imageId);
        }

        $eras = self::resolveImageEras($request->imageId);
        if ($eras === []) {
            return new ClassArchiveMediaDecision(false, 'era_unresolved', $request->imageId, null, $role);
        }
        if (count($eras) !== 1) {
            return new ClassArchiveMediaDecision(false, 'cross_era_conflict', $request->imageId, 'CONFLICT', $role);
        }
        $era = array_key_first($eras);

        if ($role === self::ROLE_ADMIN) {
            return new ClassArchiveMediaDecision(true, 'admin', $request->imageId, $era, $role);
        }

        if (!self::hasPiwigoImageAccess($request->imageId)) {
            return new ClassArchiveMediaDecision(false, 'piwigo_acl_denied', $request->imageId, $era, $role);
        }

        $eraAllowed = match ($role) {
            self::ROLE_CLASSMATE, self::ROLE_TEACHER, self::ROLE_ANONYMOUS => true,
            self::ROLE_FAMILY => $era === self::ERA_HERITAGE,
            default => false,
        };
        if (!$eraAllowed) {
            return new ClassArchiveMediaDecision(false, 'era_denied', $request->imageId, $era, $role);
        }

        if ($request->variant === 'original' && !self::originalAllowed($role, $conf ?? [])) {
            return new ClassArchiveMediaDecision(false, 'original_denied', $request->imageId, $era, $role);
        }

        return new ClassArchiveMediaDecision(true, 'allowed', $request->imageId, $era, $role);
    }

    public static function assertDeliveryTarget(ClassArchiveMediaRequest $request): void
    {
        $sourcePrefix = '/_class_archive_internal/source/';
        $derivativePrefix = '/_class_archive_internal/derivative/';
        $generationPrefix = '/_class_archive_internal/generate/';
        if (str_starts_with($request->internalUri, $sourcePrefix)) {
            self::assertExistingFileWithinRoot(
                rawurldecode(substr($request->internalUri, strlen($sourcePrefix))),
                ['upload', 'galleries'],
                PHPWG_ROOT_PATH,
            );
            return;
        }
        if (str_starts_with($request->internalUri, $derivativePrefix)) {
            self::assertExistingFileWithinRoot(
                rawurldecode(substr($request->internalUri, strlen($derivativePrefix))),
                ['upload', 'galleries'],
                PHPWG_ROOT_PATH . '_data/i/',
            );
            return;
        }
        if (str_starts_with($request->internalUri, $generationPrefix)) {
            // The source was already resolved by exact DB path and checked
            // below. Piwigo owns creation under its dedicated derivative root.
            self::assertExistingFileWithinRoot(
                $request->sourcePath,
                ['upload', 'galleries'],
                PHPWG_ROOT_PATH,
            );
            return;
        }

        throw new DomainException('invalid_internal_target');
    }

    /** @return array{request: ClassArchiveMediaRequest, image: array<string, mixed>} */
    private static function resolveSourcePath(string $uri): array
    {
        $relative = self::normalizeRelativePath($uri, ['/upload/', '/galleries/']);
        $image = self::findImageBySourcePath($relative);

        return [
            'request' => new ClassArchiveMediaRequest(
                (int) $image['id'],
                'original',
                $relative,
                '/_class_archive_internal/source/' . self::encodePath($relative),
                (string) $image['file'],
            ),
            'image' => $image,
        ];
    }

    /** @return array{request: ClassArchiveMediaRequest, image: array<string, mixed>} */
    private static function resolveDerivativePath(string $value, string $prefix = ''): array
    {
        $relativeDerivative = self::normalizeDerivativePath($value, $prefix);
        $sourcePath = self::sourcePathFromDerivative($relativeDerivative);
        $image = self::findImageBySourcePath($sourcePath);
        $derivativeFile = PHPWG_ROOT_PATH . '_data/i/' . $relativeDerivative;
        $internalPrefix = is_file($derivativeFile)
            ? '/_class_archive_internal/derivative/'
            : '/_class_archive_internal/generate/';

        return [
            'request' => new ClassArchiveMediaRequest(
                (int) $image['id'],
                'derivative',
                $sourcePath,
                $internalPrefix . self::encodePath($relativeDerivative),
                null,
                $relativeDerivative,
            ),
            'image' => $image,
        ];
    }

    /** @return array{request: ClassArchiveMediaRequest, image: array<string, mixed>} */
    private static function resolveDerivativeScript(string $queryString): array
    {
        if ($queryString === '' || str_contains($queryString, '&') || str_contains($queryString, '=')) {
            throw new DomainException('invalid_derivative_query');
        }

        return self::resolveDerivativePath(rawurldecode($queryString));
    }

    /** @param array<string, mixed> $query
     *  @return array{request: ClassArchiveMediaRequest, image: array<string, mixed>}
     */
    private static function resolveAction(array $query): array
    {
        if (isset($query['format'])) {
            if (
                isset($query['id'])
                || isset($query['part'])
                || !is_scalar($query['format'])
                || !preg_match('/\A[1-9][0-9]*\z/D', (string) $query['format'])
            ) {
                throw new DomainException('invalid_format_id');
            }
            $formatId = (int) $query['format'];
            $rows = query2array(
                'SELECT f.image_id, f.ext, i.* FROM ' . IMAGE_FORMAT_TABLE . ' f '
                . 'JOIN ' . IMAGES_TABLE . ' i ON i.id = f.image_id WHERE f.format_id = ' . $formatId
            );
            if (count($rows) !== 1) {
                throw new DomainException('format_not_found');
            }
            $image = $rows[0];
            $source = self::databasePathToRelative((string) $image['path']);
            self::assertUniqueOriginalBinding((int) $image['id'], $source);
            $formatPath = self::databasePathToRelative(
                original_to_format('./' . $source, (string) $image['ext'])
            );

            return [
                'request' => new ClassArchiveMediaRequest(
                    (int) $image['id'],
                    'original',
                    $source,
                    '/_class_archive_internal/source/' . self::encodePath($formatPath),
                    pathinfo((string) $image['file'], PATHINFO_FILENAME) . '.' . (string) $image['ext'],
                ),
                'image' => $image,
            ];
        }

        if (
            !isset($query['id'], $query['part'])
            || !is_scalar($query['id'])
            || !preg_match('/\A[1-9][0-9]*\z/D', (string) $query['id'])
            || !is_scalar($query['part'])
            || !in_array((string) $query['part'], ['e', 'r'], true)
        ) {
            throw new DomainException('invalid_action');
        }

        $image = self::findImageById((int) $query['id']);
        $source = self::databasePathToRelative((string) $image['path']);

        // Piwigo deliberately returns action.php?id=N&part=e when a requested
        // derivative would be identical in dimensions to a small source. In
        // Class Archive, a non-download action is a web preview, never an
        // implicit grant to the archived original bytes. Explicit `download`
        // remains the only original-delivery action.
        if ((string) $query['part'] === 'e' && !array_key_exists('download', $query)) {
            return self::resolveSafePreview($image, $source);
        }

        if ((string) $query['part'] === 'r') {
            $representative = $image['representative_ext'] ?? null;
            if (!is_string($representative) || !preg_match('/\A[A-Za-z0-9]+\z/D', $representative)) {
                throw new DomainException('representative_not_available');
            }
            $source = self::databasePathToRelative(
                original_to_representative('./' . $source, $representative)
            );
        }

        return [
            'request' => new ClassArchiveMediaRequest(
                (int) $image['id'],
                'original',
                self::databasePathToRelative((string) $image['path']),
                '/_class_archive_internal/source/' . self::encodePath($source),
                (string) $image['file'],
            ),
            'image' => $image,
        ];
    }

    /** @param array<string, mixed> $image
     *  @return array{request: ClassArchiveMediaRequest, image: array<string, mixed>}
     */
    private static function resolveSafePreview(array $image, string $source): array
    {
        global $conf;

        $type = (string) ($conf['class_archive_safe_preview_type'] ?? IMG_XLARGE);
        $definedTypes = ImageStdParams::get_defined_type_map();
        if (!isset($definedTypes[$type])) {
            throw new DomainException('safe_preview_type_unavailable');
        }
        $token = derivative_to_url($type);
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $token)) {
            throw new DomainException('safe_preview_token_invalid');
        }

        $relativeDerivative = self::derivativePathFromSource($source, $token);
        $derivativeFile = PHPWG_ROOT_PATH . '_data/i/' . $relativeDerivative;
        $internalPrefix = is_file($derivativeFile)
            ? '/_class_archive_internal/derivative/'
            : '/_class_archive_internal/generate/';

        return [
            'request' => new ClassArchiveMediaRequest(
                (int) $image['id'],
                'derivative',
                $source,
                $internalPrefix . self::encodePath($relativeDerivative),
                null,
                $relativeDerivative,
            ),
            'image' => $image,
        ];
    }

    private static function derivativePathFromSource(string $source, string $token): string
    {
        $dot = strrpos($source, '.');
        if ($dot === false || $dot === 0 || str_contains(substr($source, $dot + 1), '/')) {
            throw new DomainException('safe_preview_source_extension_invalid');
        }

        return substr($source, 0, $dot) . '-' . $token . substr($source, $dot);
    }

    private static function resolveRole(array $user): ?string
    {
        global $conf;

        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0 || $userId === (int) ($conf['guest_id'] ?? 0) || ($user['status'] ?? null) === 'guest') {
            return null;
        }

        $statusRows = query2array(
            'SELECT status FROM ' . USER_INFOS_TABLE . ' WHERE user_id = ' . $userId
        );
        if (count($statusRows) !== 1) {
            return null;
        }
        $currentStatus = (string) $statusRows[0]['status'];
        if (in_array($currentStatus, ['webmaster', 'admin'], true)) {
            return self::ROLE_ADMIN;
        }
        if ($currentStatus !== 'normal') {
            return null;
        }

        $rows = query2array(
            'SELECT g.name FROM ' . GROUPS_TABLE . ' g JOIN ' . USER_GROUP_TABLE . ' ug ON ug.group_id = g.id '
            . 'WHERE ug.user_id = ' . $userId . " AND g.name IN ('CLASSMATE','TEACHER','FAMILY','ANONYMOUS')"
        );
        $roles = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['name'], $rows)));
        if (count($roles) !== 1) {
            return null;
        }

        return $roles[0];
    }

    /** @return array<string, string> */
    private static function parseActionQuery(string $rawQuery): array
    {
        if ($rawQuery === '' || strlen($rawQuery) > 2048) {
            throw new DomainException('invalid_action_query');
        }

        $allowed = ['id' => true, 'part' => true, 'format' => true, 'download' => true, 'pwg_token' => true];
        $parsed = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                throw new DomainException('invalid_action_query');
            }
            $separator = strpos($pair, '=');
            $rawKey = $separator === false ? $pair : substr($pair, 0, $separator);
            $rawValue = $separator === false ? '' : substr($pair, $separator + 1);
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $value = rawurldecode(str_replace('+', ' ', $rawValue));
            if (
                !isset($allowed[$key])
                || array_key_exists($key, $parsed)
                || str_contains($key, "\0")
                || str_contains($value, "\0")
            ) {
                throw new DomainException('invalid_action_query');
            }
            $parsed[$key] = $value;
        }

        return $parsed;
    }

    /** @return array<string, true> */
    private static function resolveImageEras(int $imageId): array
    {
        $rows = query2array(
            'SELECT c.uppercats, root.permalink AS root_permalink FROM ' . IMAGE_CATEGORY_TABLE . ' ic '
            . 'JOIN ' . CATEGORIES_TABLE . ' c ON c.id = ic.category_id '
            . 'LEFT JOIN ' . CATEGORIES_TABLE . " root ON root.id = CAST(SUBSTRING_INDEX(c.uppercats, ',', 1) AS UNSIGNED) "
            . 'WHERE ic.image_id = ' . $imageId
        );
        $eras = [];
        foreach ($rows as $row) {
            $era = match ((string) ($row['root_permalink'] ?? '')) {
                'class-archive-heritage' => self::ERA_HERITAGE,
                'class-archive-living' => self::ERA_LIVING,
                default => null,
            };
            if ($era === null) {
                return [];
            }
            $eras[$era] = true;
        }

        return $eras;
    }

    private static function hasPiwigoImageAccess(int $imageId): bool
    {
        global $user;

        $userId = (int) ($user['id'] ?? 0);
        $rows = query2array(
            'SELECT status, level FROM ' . USER_INFOS_TABLE . ' WHERE user_id = ' . $userId
        );
        if (count($rows) !== 1 || (string) $rows[0]['status'] !== 'normal') {
            return false;
        }

        // Piwigo caches forbidden_categories in the PHP session/user cache.
        // Media delivery cannot trust that snapshot after an administrator
        // revokes album/group access, so recompute from the current Core ACL
        // tables for every protected request. The query retains Piwigo's
        // native union rule: one currently authorized same-era album is
        // enough, while cross-era association is rejected earlier.
        $forbiddenCategories = calculate_permissions($userId, (string) $rows[0]['status']);
        if (!preg_match('/\A[0-9]+(?:,[0-9]+)*\z/D', $forbiddenCategories)) {
            return false;
        }
        $level = max(0, (int) $rows[0]['level']);
        $query = 'SELECT 1 FROM ' . IMAGE_CATEGORY_TABLE . ' ic JOIN ' . IMAGES_TABLE . ' i ON i.id = ic.image_id '
            . 'WHERE ic.image_id = ' . $imageId
            . ' AND ic.category_id NOT IN (' . $forbiddenCategories . ')'
            . ' AND i.level <= ' . $level
            . ' LIMIT 1';

        return pwg_db_num_rows(pwg_query($query)) === 1;
    }

    private static function originalAllowed(string $role, array $conf): bool
    {
        return match ($role) {
            self::ROLE_CLASSMATE => (bool) ($conf['class_archive_classmate_original_download'] ?? true),
            self::ROLE_TEACHER => (bool) ($conf['class_archive_teacher_original_download'] ?? true),
            self::ROLE_FAMILY => (bool) ($conf['class_archive_family_original_download'] ?? false),
            self::ROLE_ANONYMOUS => (bool) ($conf['class_archive_anonymous_original_download'] ?? false),
            self::ROLE_ADMIN => true,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private static function findImageBySourcePath(string $relative): array
    {
        $relative = self::databasePathToRelative($relative);
        $rows = self::findImagesByCanonicalSourcePath($relative);
        if (count($rows) !== 1) {
            throw new DomainException('source_not_found_or_ambiguous');
        }
        self::assertExistingFileWithinRoot($relative, ['upload', 'galleries'], PHPWG_ROOT_PATH);

        return $rows[0];
    }

    /** @return array<string, mixed> */
    private static function findImageById(int $imageId): array
    {
        $rows = query2array('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id = ' . $imageId);
        if (count($rows) !== 1) {
            throw new DomainException('image_not_found');
        }
        $relative = self::databasePathToRelative((string) $rows[0]['path']);
        self::assertUniqueOriginalBinding($imageId, $relative);

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private static function findImagesByCanonicalSourcePath(string $relative): array
    {
        $relative = self::databasePathToRelative($relative);
        $withPrefix = './' . $relative;
        $escaped = pwg_db_real_escape_string($withPrefix);
        $escapedWithoutPrefix = pwg_db_real_escape_string($relative);

        return query2array(
            'SELECT * FROM ' . IMAGES_TABLE . " WHERE path = '{$escaped}' OR path = '{$escapedWithoutPrefix}'"
        );
    }

    private static function assertUniqueOriginalBinding(int $imageId, string $relative): void
    {
        $relative = self::databasePathToRelative($relative);
        $rows = self::findImagesByCanonicalSourcePath($relative);
        if (count($rows) !== 1 || (int) $rows[0]['id'] !== $imageId) {
            throw new DomainException('source_not_found_or_ambiguous');
        }
        self::assertExistingFileWithinRoot(
            $relative,
            ['upload', 'galleries'],
            PHPWG_ROOT_PATH,
        );
    }

    private static function normalizeDerivativePath(string $value, string $prefix = ''): string
    {
        if ($prefix !== '') {
            if (!str_starts_with($value, $prefix)) {
                throw new DomainException('invalid_derivative_prefix');
            }
            $value = substr($value, strlen($prefix));
        }

        return self::normalizeRelativePath('/' . ltrim($value, '/'), ['/upload/', '/galleries/']);
    }

    private static function sourcePathFromDerivative(string $derivative): string
    {
        $lastSlash = strrpos($derivative, '/');
        $directory = $lastSlash === false ? '' : substr($derivative, 0, $lastSlash + 1);
        $filename = $lastSlash === false ? $derivative : substr($derivative, $lastSlash + 1);
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === 0) {
            throw new DomainException('invalid_derivative_extension');
        }
        $stem = substr($filename, 0, $dot);
        $extension = substr($filename, $dot);
        $separator = strrpos($stem, '-');
        if ($separator === false || $separator === 0) {
            throw new DomainException('invalid_derivative_token');
        }
        $token = substr($stem, $separator + 1);
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $token)) {
            throw new DomainException('invalid_derivative_token');
        }
        self::assertDerivativeTokenAllowed($token);

        return $directory . substr($stem, 0, $separator) . $extension;
    }

    private static function assertDerivativeTokenAllowed(string $token): void
    {
        if (
            !class_exists('ImageStdParams')
            || !function_exists('derivative_to_url')
            || !defined('IMG_CUSTOM')
        ) {
            throw new DomainException('derivative_policy_unavailable');
        }

        foreach (array_keys(ImageStdParams::get_defined_type_map()) as $type) {
            if (derivative_to_url($type) === $token) {
                return;
            }
        }

        $customPrefix = derivative_to_url(IMG_CUSTOM) . '_';
        if (str_starts_with($token, $customPrefix)) {
            $customKey = substr($token, strlen($customPrefix));
            if ($customKey !== '' && array_key_exists($customKey, ImageStdParams::$custom)) {
                return;
            }
        }

        throw new DomainException('derivative_token_not_enabled');
    }

    /** @param list<string> $allowedPrefixes */
    private static function normalizeRelativePath(string $value, array $allowedPrefixes): string
    {
        if (
            $value === ''
            || strlen($value) > 4096
            || str_contains($value, "\0")
            || str_contains($value, '\\')
            || str_contains($value, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            throw new DomainException('invalid_path');
        }

        $matchesPrefix = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $matchesPrefix = true;
                break;
            }
        }
        if (!$matchesPrefix) {
            throw new DomainException('path_outside_media_roots');
        }

        $segments = explode('/', ltrim($value, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new DomainException('invalid_path_segment');
            }
        }

        return implode('/', $segments);
    }

    private static function databasePathToRelative(string $path): string
    {
        $path = str_starts_with($path, './') ? substr($path, 2) : $path;
        return self::normalizeRelativePath('/' . $path, ['/upload/', '/galleries/']);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @param list<string> $allowedTopLevels */
    private static function assertExistingFileWithinRoot(
        string $relative,
        array $allowedTopLevels,
        string $base,
    ): void {
        if (str_contains($relative, "\0") || str_contains($relative, '\\')) {
            throw new DomainException('unsafe_filesystem_path');
        }
        $segments = explode('/', $relative);
        if ($segments === [] || !in_array($segments[0], $allowedTopLevels, true)) {
            throw new DomainException('filesystem_root_denied');
        }

        $allowedRoot = realpath(rtrim($base, '/') . '/' . $segments[0]);
        $candidate = realpath(rtrim($base, '/') . '/' . $relative);
        if (
            $allowedRoot === false
            || $candidate === false
            || !is_file($candidate)
            || is_link(rtrim($base, '/') . '/' . $relative)
            || !str_starts_with($candidate, rtrim($allowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            throw new DomainException('media_file_outside_root');
        }
    }
}
