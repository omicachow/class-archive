<?php

declare(strict_types=1);

const PIWIGO_ROOT_PATH = '/var/www/html/piwigo';
const LOCK_FILE_PATH = __DIR__ . '/../piwigo-extensions.lock.json';
const INSTALLER_LOCK_FILENAME = '.class-archive-extension-installer.lock';
const TREE_MARKER_FILENAME = '.class-archive-extension-lock.json';
const TREE_DIGEST_ALGORITHM = 'sha256-tree-v1';
// An optional, read-only cache is useful when the official upstream is slow
// or intentionally unavailable during a controlled install. It is never
// trusted: an archive is accepted only after its lock-file SHA-256 matches.
const OPTIONAL_ARCHIVE_CACHE_PATH = '/class-archive-extension-cache';
const MAX_ARCHIVE_BYTES = 134_217_728;
const MAX_ARCHIVE_ENTRIES = 25_000;
const MAX_ENTRY_BYTES = 134_217_728;
const MAX_UNCOMPRESSED_BYTES = 536_870_912;
const MAX_COMPRESSION_RATIO = 250.0;

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function readJsonObject(string $path, string $description): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        fail("Cannot read {$description}: {$path}");
    }

    try {
        $value = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Invalid JSON in {$description}: {$exception->getMessage()}");
    }

    if (!is_array($value) || array_is_list($value)) {
        fail("{$description} must contain a JSON object.");
    }

    return $value;
}

function requireString(array $object, string $key, string $context): string
{
    $value = $object[$key] ?? null;
    if (!is_string($value) || $value === '') {
        fail("{$context}.{$key} must be a non-empty string.");
    }

    return $value;
}

function requireBoolean(array $object, string $key, string $context): bool
{
    $value = $object[$key] ?? null;
    if (!is_bool($value)) {
        fail("{$context}.{$key} must be a boolean.");
    }

    return $value;
}

function validateSingleDirectoryName(string $value, string $context): void
{
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $value)) {
        fail("{$context} must be a single safe directory name.");
    }

    if ($value === '.' || $value === '..' || str_ends_with($value, '.') || str_ends_with($value, ' ')) {
        fail("{$context} is not a portable directory name.");
    }
}

function readInstalledPiwigoVersion(): string
{
    $root = realpath(PIWIGO_ROOT_PATH);
    if ($root === false || !is_dir($root) || is_link(PIWIGO_ROOT_PATH)) {
        fail('The expected Piwigo root is missing or unsafe: ' . PIWIGO_ROOT_PATH);
    }

    $constantsPath = $root . DIRECTORY_SEPARATOR . 'include' . DIRECTORY_SEPARATOR . 'constants.php';
    if (is_link($constantsPath) || !is_file($constantsPath)) {
        fail('Cannot find a safe Piwigo include/constants.php file.');
    }

    $contents = @file_get_contents($constantsPath);
    if ($contents === false || strlen($contents) > 1_048_576) {
        fail('Cannot safely read the installed Piwigo version.');
    }

    $count = preg_match_all(
        '/^[ \t]*define\([ \t]*[\'\"]PHPWG_VERSION[\'\"][ \t]*,[ \t]*[\'\"]([^\'\"]+)[\'\"][ \t]*\)[ \t]*;/m',
        $contents,
        $matches,
    );
    if ($count !== 1 || !preg_match('/\A[0-9]+(?:\.[0-9]+)+(?:[A-Za-z0-9._-]*)\z/D', $matches[1][0])) {
        fail('Piwigo include/constants.php must declare exactly one safe PHPWG_VERSION value.');
    }

    return $matches[1][0];
}

function validateLockedPiwigo(array $lock): array
{
    $piwigo = $lock['piwigo'] ?? null;
    if (!is_array($piwigo) || array_is_list($piwigo)) {
        fail('The Piwigo extension lock must contain a piwigo object.');
    }

    $lockedVersion = requireString($piwigo, 'version', 'piwigo');
    if (!preg_match('/\A[0-9]+(?:\.[0-9]+)+(?:[A-Za-z0-9._-]*)\z/D', $lockedVersion)) {
        fail('piwigo.version contains unsafe characters.');
    }

    $installedVersion = readInstalledPiwigoVersion();
    if (!hash_equals($lockedVersion, $installedVersion)) {
        fail(
            "Installed Piwigo {$installedVersion} does not match locked Piwigo {$lockedVersion}; "
            . 'refusing to install or verify extensions.'
        );
    }

    $majorVersion = strstr($installedVersion, '.', true);
    if ($majorVersion === false || !preg_match('/\A[0-9]+\z/D', $majorVersion)) {
        fail("Cannot derive a safe major version from installed Piwigo {$installedVersion}.");
    }

    return [$installedVersion, $majorVersion];
}

function validateExtension(array $extension, int $index, string $installedMajorVersion): array
{
    $context = "extensions[{$index}]";
    $id = requireString($extension, 'id', $context);
    $name = requireString($extension, 'name', $context);
    $type = requireString($extension, 'type', $context);
    $archiveRoot = requireString($extension, 'archiveRoot', $context);
    $destinationDirectory = requireString($extension, 'destinationDirectory', $context);
    $version = requireString($extension, 'version', $context);
    $install = requireBoolean($extension, 'install', $context);
    $compatiblePiwigo = $extension['compatiblePiwigo'] ?? null;

    if (!in_array($type, ['plugin', 'theme'], true)) {
        fail("{$context}.type must be plugin or theme.");
    }

    validateSingleDirectoryName($id, "{$context}.id");
    validateSingleDirectoryName($archiveRoot, "{$context}.archiveRoot");
    validateSingleDirectoryName($destinationDirectory, "{$context}.destinationDirectory");
    if ($id !== $destinationDirectory) {
        fail("{$context}.id must equal destinationDirectory because Piwigo uses that directory as the extension id.");
    }

    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $version)) {
        fail("{$context}.version contains unsafe characters.");
    }

    if (!is_array($compatiblePiwigo) || !array_is_list($compatiblePiwigo) || $compatiblePiwigo === []) {
        fail("{$context}.compatiblePiwigo must be a non-empty array of Piwigo major versions.");
    }
    foreach ($compatiblePiwigo as $compatibleVersion) {
        if (!is_string($compatibleVersion) || !preg_match('/\A[0-9]+\z/D', $compatibleVersion)) {
            fail("{$context}.compatiblePiwigo contains an invalid major version.");
        }
    }
    if (!in_array($installedMajorVersion, $compatiblePiwigo, true)) {
        fail(
            "{$context} is not declared compatible with installed Piwigo major version "
            . "{$installedMajorVersion}."
        );
    }

    $normalized = [
        'id' => $id,
        'name' => $name,
        'type' => $type,
        'archiveRoot' => $archiveRoot,
        'destinationDirectory' => $destinationDirectory,
        'version' => $version,
        'compatiblePiwigo' => $compatiblePiwigo,
        'install' => $install,
    ];

    if (!$install) {
        return $normalized;
    }

    $downloadUrl = requireString($extension, 'downloadUrl', $context);
    $urlParts = parse_url($downloadUrl);
    if (
        $urlParts === false
        || ($urlParts['scheme'] ?? null) !== 'https'
        || !isset($urlParts['host'])
        || isset($urlParts['user'])
        || isset($urlParts['pass'])
    ) {
        fail("{$context}.downloadUrl must be an HTTPS URL without embedded credentials.");
    }

    $sha256 = requireString($extension, 'sha256', $context);
    if (!preg_match('/\A[0-9a-f]{64}\z/D', $sha256)) {
        fail("{$context}.sha256 must be a lowercase SHA-256 digest.");
    }

    $normalized['downloadUrl'] = $downloadUrl;
    $normalized['sha256'] = $sha256;

    return $normalized;
}

function assertNginxCliUser(): void
{
    if (PHP_SAPI !== 'cli') {
        fail('This installer may only run from the PHP CLI.');
    }

    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        fail('The POSIX extension is required to verify the effective runtime user.');
    }

    $effectiveUserId = posix_geteuid();
    if ($effectiveUserId === 0) {
        fail('Refusing to run as root. Execute this installer as the nginx user.');
    }

    $account = posix_getpwuid($effectiveUserId);
    if (!is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        fail('Refusing to run as a user other than nginx.');
    }
}

function resolveExtensionBase(string $type): string
{
    $root = realpath(PIWIGO_ROOT_PATH);
    if ($root === false || !is_dir($root) || is_link(PIWIGO_ROOT_PATH)) {
        fail('The expected Piwigo root is missing or unsafe: ' . PIWIGO_ROOT_PATH);
    }

    $relativeDirectory = $type === 'plugin' ? 'plugins' : 'themes';
    $declaredBase = $root . DIRECTORY_SEPARATOR . $relativeDirectory;
    if (is_link($declaredBase)) {
        fail("The Piwigo {$relativeDirectory} directory must not be a symbolic link.");
    }

    $base = realpath($declaredBase);
    if ($base === false || !is_dir($base)) {
        fail("The Piwigo {$relativeDirectory} directory is missing.");
    }

    if (!str_starts_with($base . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
        fail("The Piwigo {$relativeDirectory} directory resolves outside the Piwigo root.");
    }

    if (!is_writable($base)) {
        fail("The Piwigo {$relativeDirectory} directory is not writable by nginx.");
    }

    return $base;
}

function createPrivateTemporaryDirectory(): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = $base . DIRECTORY_SEPARATOR . 'class-archive-piwigo-' . bin2hex(random_bytes(12));
        if (@mkdir($path, 0700)) {
            return $path;
        }
    }

    fail('Cannot create a private temporary directory.');
}

function downloadArchive(string $url, string $destination): void
{
    if (!function_exists('curl_init')) {
        fail('The cURL PHP extension is required.');
    }

    $output = @fopen($destination, 'xb');
    if ($output === false) {
        fail("Cannot create temporary archive: {$destination}");
    }

    $curl = curl_init($url);
    if ($curl === false) {
        fclose($output);
        fail('Cannot initialize cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_FILE => $output,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FAILONERROR => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Class-Archive-Piwigo-Locked-Extension-Installer/1',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => static function (
            CurlHandle $handle,
            float $downloadSize,
            float $downloaded,
            float $uploadSize,
            float $uploaded,
        ): int {
            unset($handle, $uploadSize, $uploaded);
            return $downloadSize > MAX_ARCHIVE_BYTES || $downloaded > MAX_ARCHIVE_BYTES ? 1 : 0;
        },
    ]);

    $succeeded = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($output);

    if ($succeeded !== true) {
        @unlink($destination);
        fail('Extension download failed: ' . ($error !== '' ? $error : 'unknown cURL error'));
    }

    $size = filesize($destination);
    if ($size === false || $size <= 0 || $size > MAX_ARCHIVE_BYTES) {
        @unlink($destination);
        fail('Downloaded archive has an invalid size.');
    }
}

/**
 * Return a locally cached archive only when it is a regular, non-symlink file
 * beneath the fixed cache mount. A cache hash mismatch is an integrity error,
 * not a reason to fall back to a network download.
 */
function resolveVerifiedCachedArchive(array $extension): ?string
{
    if (!file_exists(OPTIONAL_ARCHIVE_CACHE_PATH) && !is_link(OPTIONAL_ARCHIVE_CACHE_PATH)) {
        return null;
    }
    if (is_link(OPTIONAL_ARCHIVE_CACHE_PATH) || !is_dir(OPTIONAL_ARCHIVE_CACHE_PATH)) {
        fail('The optional extension archive cache path is unsafe.');
    }

    $filename = $extension['id'] . '-' . $extension['sha256'] . '.zip';
    $path = OPTIONAL_ARCHIVE_CACHE_PATH . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path) && !is_link($path)) {
        return null;
    }
    if (is_link($path) || !is_file($path)) {
        fail("Cached archive for {$extension['id']} is unsafe.");
    }

    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > MAX_ARCHIVE_BYTES) {
        fail("Cached archive for {$extension['id']} has an invalid size.");
    }
    $digest = hash_file('sha256', $path);
    if ($digest === false || !hash_equals($extension['sha256'], $digest)) {
        fail("Cached archive SHA-256 mismatch for {$extension['id']}.");
    }

    return $path;
}

function copyVerifiedCachedArchive(string $source, string $destination): void
{
    $input = @fopen($source, 'rb');
    $output = @fopen($destination, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        fail('Cannot prepare the verified cached extension archive.');
    }

    try {
        $copied = stream_copy_to_stream($input, $output, MAX_ARCHIVE_BYTES + 1);
        if ($copied === false || $copied <= 0 || $copied > MAX_ARCHIVE_BYTES || !fflush($output)) {
            fail('Cannot safely copy the verified cached extension archive.');
        }
    } finally {
        fclose($input);
        fclose($output);
    }
}

function normalizeArchiveEntry(string $name, string $archiveRoot): array
{
    if ($name === '' || strlen($name) > 4096 || str_contains($name, "\0")) {
        fail('Archive contains an empty, overlong, or NUL-containing path.');
    }

    if (
        str_contains($name, '\\')
        || str_starts_with($name, '/')
        || str_starts_with($name, '~')
        || preg_match('/\A[A-Za-z]:/', $name)
        || preg_match('/[\x00-\x1F\x7F]/', $name)
    ) {
        fail("Archive contains an unsafe path: {$name}");
    }

    $isDirectory = str_ends_with($name, '/');
    $trimmed = $isDirectory ? substr($name, 0, -1) : $name;
    if ($trimmed === '') {
        fail('Archive contains an invalid root entry.');
    }

    $segments = explode('/', $trimmed);
    foreach ($segments as $segment) {
        if (
            $segment === ''
            || $segment === '.'
            || $segment === '..'
            || strlen($segment) > 255
            || str_contains($segment, ':')
            || str_ends_with($segment, '.')
            || str_ends_with($segment, ' ')
        ) {
            fail("Archive contains an unsafe path segment in: {$name}");
        }
    }

    if ($segments[0] !== $archiveRoot) {
        fail("Archive entry is outside the locked archiveRoot {$archiveRoot}: {$name}");
    }

    array_shift($segments);
    $relativePath = implode('/', $segments);
    if ($relativePath === '' && !$isDirectory) {
        fail('The archive root must be a directory, not a file.');
    }

    if ($relativePath === TREE_MARKER_FILENAME) {
        fail('Archive attempts to supply the installer tree marker.');
    }

    return [$relativePath, $isDirectory];
}

function inspectArchive(ZipArchive $archive, string $archiveRoot): array
{
    if ($archive->numFiles <= 0 || $archive->numFiles > MAX_ARCHIVE_ENTRIES) {
        fail('Archive contains an unsafe number of entries.');
    }

    $entries = [];
    $pathKinds = [];
    $caseFoldedPaths = [];
    $totalUncompressedBytes = 0;
    $sawArchiveRoot = false;

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $statistics = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
        if (!is_array($statistics) || !isset($statistics['name'], $statistics['size'], $statistics['comp_size'])) {
            fail("Cannot inspect ZIP entry {$index}.");
        }

        $name = $statistics['name'];
        $uncompressedBytes = $statistics['size'];
        $compressedBytes = $statistics['comp_size'];
        if (!is_string($name) || !is_int($uncompressedBytes) || !is_int($compressedBytes)) {
            fail("ZIP entry {$index} has invalid metadata.");
        }

        [$relativePath, $isDirectory] = normalizeArchiveEntry($name, $archiveRoot);
        if ($relativePath === '') {
            $sawArchiveRoot = true;
        }

        if ($uncompressedBytes < 0 || $compressedBytes < 0 || $uncompressedBytes > MAX_ENTRY_BYTES) {
            fail("Archive entry has an unsafe size: {$name}");
        }
        $totalUncompressedBytes += $uncompressedBytes;
        if ($totalUncompressedBytes > MAX_UNCOMPRESSED_BYTES) {
            fail('Archive expands beyond the allowed total size.');
        }
        if (
            $uncompressedBytes > 1_048_576
            && $compressedBytes > 0
            && ($uncompressedBytes / $compressedBytes) > MAX_COMPRESSION_RATIO
        ) {
            fail("Archive entry has a suspicious compression ratio: {$name}");
        }
        if (($statistics['encryption_method'] ?? 0) !== 0) {
            fail("Encrypted ZIP entries are not allowed: {$name}");
        }

        $operatingSystem = 0;
        $externalAttributes = 0;
        if ($archive->getExternalAttributesIndex($index, $operatingSystem, $externalAttributes, ZipArchive::FL_UNCHANGED)) {
            $unixMode = ($externalAttributes >> 16) & 0xFFFF;
            $fileType = $unixMode & 0170000;
            if ($fileType === 0120000) {
                fail("Symbolic links are not allowed in extension archives: {$name}");
            }
            if ($fileType !== 0 && $fileType !== 0040000 && $fileType !== 0100000) {
                fail("Special filesystem entries are not allowed in extension archives: {$name}");
            }
            if ($fileType === 0040000 && !$isDirectory) {
                fail("ZIP metadata/path type mismatch for directory: {$name}");
            }
            if ($fileType === 0100000 && $isDirectory) {
                fail("ZIP metadata/path type mismatch for file: {$name}");
            }
        }

        if ($relativePath !== '') {
            if (isset($pathKinds[$relativePath])) {
                fail("Archive contains a duplicate normalized path: {$relativePath}");
            }
            $foldedPath = function_exists('mb_strtolower')
                ? mb_strtolower($relativePath, 'UTF-8')
                : strtolower($relativePath);
            if (isset($caseFoldedPaths[$foldedPath])) {
                fail("Archive contains a case-colliding path: {$relativePath}");
            }
            $pathKinds[$relativePath] = $isDirectory ? 'directory' : 'file';
            $caseFoldedPaths[$foldedPath] = true;
        }

        $entries[] = [
            'index' => $index,
            'name' => $name,
            'relativePath' => $relativePath,
            'isDirectory' => $isDirectory,
            'size' => $uncompressedBytes,
        ];
    }

    if (!$sawArchiveRoot) {
        fail("Archive does not contain the locked root directory {$archiveRoot}/.");
    }

    foreach ($pathKinds as $path => $kind) {
        $segments = explode('/', $path);
        array_pop($segments);
        while ($segments !== []) {
            $parent = implode('/', $segments);
            if (($pathKinds[$parent] ?? null) === 'file') {
                fail("Archive path is nested below a file: {$path}");
            }
            array_pop($segments);
        }
        if ($kind === 'directory' && isset($pathKinds[rtrim($path, '/')]) && $pathKinds[rtrim($path, '/')] === 'file') {
            fail("Archive path is both a file and directory: {$path}");
        }
    }

    return $entries;
}

function ensureDirectory(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        return;
    }
    if (file_exists($path) || is_link($path)) {
        fail("Cannot create directory over an existing filesystem entry: {$path}");
    }
    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        fail("Cannot create directory: {$path}");
    }
}

function extractArchive(ZipArchive $archive, array $entries, string $stagingDirectory): void
{
    foreach ($entries as $entry) {
        if ($entry['relativePath'] === '') {
            continue;
        }

        $target = $stagingDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['relativePath']);
        if ($entry['isDirectory']) {
            ensureDirectory($target);
            continue;
        }

        ensureDirectory(dirname($target));
        $input = $archive->getStream($entry['name']);
        if ($input === false) {
            fail("Cannot read ZIP entry: {$entry['name']}");
        }
        $output = @fopen($target, 'xb');
        if ($output === false) {
            fclose($input);
            fail("Cannot create extracted file: {$entry['relativePath']}");
        }

        $copied = stream_copy_to_stream($input, $output, MAX_ENTRY_BYTES + 1);
        fclose($input);
        fclose($output);
        if ($copied === false || $copied !== $entry['size']) {
            fail("Extracted size mismatch for: {$entry['relativePath']}");
        }
        if (!@chmod($target, 0644)) {
            fail("Cannot set safe permissions on: {$entry['relativePath']}");
        }
    }
}

function readUniqueHeaderValue(string $contents, string $header, string $metadataPath): string
{
    $pattern = '/^[ \t]*' . preg_quote($header, '/') . ':[ \t]*(.+?)[ \t]*$/mi';
    $count = preg_match_all($pattern, $contents, $matches);
    if ($count !== 1) {
        fail("{$metadataPath} must contain exactly one {$header} header.");
    }

    return trim($matches[1][0]);
}

function validateInstalledMetadata(
    string $directory,
    array $extension,
    bool $requireDestinationId = true,
): void
{
    $metadataFilename = $extension['type'] === 'plugin' ? 'main.inc.php' : 'themeconf.inc.php';
    $metadataPath = $directory . DIRECTORY_SEPARATOR . $metadataFilename;
    if (is_link($metadataPath) || !is_file($metadataPath)) {
        fail("{$extension['id']} is missing a safe {$metadataFilename} metadata file.");
    }

    $contents = @file_get_contents($metadataPath);
    if ($contents === false || strlen($contents) > 1_048_576) {
        fail("Cannot safely read metadata for {$extension['id']}.");
    }

    $nameHeader = $extension['type'] === 'plugin' ? 'Plugin Name' : 'Theme Name';
    $name = readUniqueHeaderValue($contents, $nameHeader, $metadataFilename);
    $version = readUniqueHeaderValue($contents, 'Version', $metadataFilename);
    if (!hash_equals($extension['name'], $name)) {
        fail("{$extension['id']} metadata name does not match the lock file.");
    }
    if (!hash_equals($extension['version'], $version)) {
        fail("{$extension['id']} metadata version does not match the lock file.");
    }

    if ($requireDestinationId && basename($directory) !== $extension['id']) {
        fail("{$extension['id']} destination directory does not match its Piwigo extension id.");
    }

    if ($extension['type'] === 'theme') {
        $count = preg_match_all(
            '/[\'\"]name[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/',
            $contents,
            $matches,
        );
        if ($count === false || !in_array($extension['id'], $matches[1], true)) {
            fail("{$extension['id']} themeconf does not declare the locked theme id.");
        }
    }
}

function calculateTreeDigest(string $root): string
{
    if (!is_dir($root) || is_link($root)) {
        fail("Cannot digest an unsafe extension directory: {$root}");
    }

    $records = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $prefixLength = strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1;

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, $prefixLength));
        if ($relativePath === TREE_MARKER_FILENAME) {
            continue;
        }
        if ($entry->isLink()) {
            fail("Installed extension contains a symbolic link: {$relativePath}");
        }
        if ($entry->isDir()) {
            $records[] = "D\0{$relativePath}\n";
            continue;
        }
        if (!$entry->isFile()) {
            fail("Installed extension contains a special filesystem entry: {$relativePath}");
        }

        $size = $entry->getSize();
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            fail("Cannot hash installed extension file: {$relativePath}");
        }
        $records[] = "F\0{$relativePath}\0{$size}\0{$digest}\n";
    }

    sort($records, SORT_STRING);
    $context = hash_init('sha256');
    foreach ($records as $record) {
        hash_update($context, $record);
    }

    return hash_final($context);
}

function expectedMarker(array $extension, string $treeDigest): array
{
    return [
        'markerFormat' => 1,
        'id' => $extension['id'],
        'name' => $extension['name'],
        'type' => $extension['type'],
        'version' => $extension['version'],
        'archiveRoot' => $extension['archiveRoot'],
        'destinationDirectory' => $extension['destinationDirectory'],
        'archiveSha256' => $extension['sha256'],
        'treeDigestAlgorithm' => TREE_DIGEST_ALGORITHM,
        'treeDigest' => $treeDigest,
    ];
}

function writeTreeMarker(string $directory, array $extension, string $treeDigest): void
{
    $markerPath = $directory . DIRECTORY_SEPARATOR . TREE_MARKER_FILENAME;
    if (file_exists($markerPath) || is_link($markerPath)) {
        fail("Archive supplied or collided with the tree marker for {$extension['id']}.");
    }

    $json = json_encode(
        expectedMarker($extension, $treeDigest),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n";
    $handle = @fopen($markerPath, 'xb');
    if ($handle === false) {
        fail("Cannot create tree marker for {$extension['id']}.");
    }
    $written = fwrite($handle, $json);
    $flushed = fflush($handle);
    fclose($handle);
    if ($written !== strlen($json) || !$flushed || !@chmod($markerPath, 0644)) {
        fail("Cannot safely write tree marker for {$extension['id']}.");
    }
}

function verifyInstalledExtension(
    string $destination,
    array $extension,
    bool $requireDestinationId = true,
): void
{
    if (is_link($destination) || !is_dir($destination)) {
        fail("Locked extension destination is missing or unsafe: {$destination}");
    }

    validateInstalledMetadata($destination, $extension, $requireDestinationId);
    $markerPath = $destination . DIRECTORY_SEPARATOR . TREE_MARKER_FILENAME;
    if (is_link($markerPath) || !is_file($markerPath)) {
        fail("{$extension['id']} has no trusted tree digest marker; refusing to accept or overwrite it.");
    }

    $marker = readJsonObject($markerPath, "{$extension['id']} tree marker");
    $treeDigest = requireString($marker, 'treeDigest', "{$extension['id']} tree marker");
    if (!preg_match('/\A[0-9a-f]{64}\z/D', $treeDigest)) {
        fail("{$extension['id']} tree marker contains an invalid digest.");
    }

    $expected = expectedMarker($extension, $treeDigest);
    if ($marker !== $expected) {
        fail("{$extension['id']} tree marker does not match the locked extension metadata.");
    }

    $actualDigest = calculateTreeDigest($destination);
    if (!hash_equals($treeDigest, $actualDigest)) {
        fail("{$extension['id']} installed tree has drifted from its digest marker.");
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) {
            fail("Cannot remove temporary filesystem entry: {$path}");
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $children = scandir($path);
    if ($children === false) {
        fail("Cannot inspect temporary directory during cleanup: {$path}");
    }
    foreach ($children as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $child);
    }
    if (!@rmdir($path)) {
        fail("Cannot remove temporary directory: {$path}");
    }
}

function createStagingDirectory(string $base, string $id): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = $base . DIRECTORY_SEPARATOR . '.class-archive-install-' . $id . '-' . bin2hex(random_bytes(8));
        if (@mkdir($path, 0700)) {
            return $path;
        }
    }

    fail("Cannot create staging directory for {$id}.");
}

function installExtension(array $extension): void
{
    $base = resolveExtensionBase($extension['type']);
    $destination = $base . DIRECTORY_SEPARATOR . $extension['destinationDirectory'];
    if (file_exists($destination) || is_link($destination)) {
        verifyInstalledExtension($destination, $extension);
        fwrite(STDOUT, "VERIFIED {$extension['type']} {$extension['id']} {$extension['version']} (already installed)\n");
        return;
    }

    $temporaryDirectory = createPrivateTemporaryDirectory();
    $archivePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'extension.zip';
    $stagingDirectory = null;
    $archive = null;

    try {
        $cachedArchive = resolveVerifiedCachedArchive($extension);
        if ($cachedArchive !== null) {
            copyVerifiedCachedArchive($cachedArchive, $archivePath);
            fwrite(STDOUT, "USING VERIFIED CACHE {$extension['type']} {$extension['id']} {$extension['version']}\n");
        } else {
            downloadArchive($extension['downloadUrl'], $archivePath);
        }
        $actualArchiveDigest = hash_file('sha256', $archivePath);
        if ($actualArchiveDigest === false || !hash_equals($extension['sha256'], $actualArchiveDigest)) {
            fail("SHA-256 mismatch for {$extension['id']}.");
        }

        if (!class_exists(ZipArchive::class)) {
            fail('The ZIP PHP extension is required.');
        }
        $archive = new ZipArchive();
        $openResult = $archive->open($archivePath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            fail("Cannot open locked ZIP for {$extension['id']}; ZipArchive error {$openResult}.");
        }

        $entries = inspectArchive($archive, $extension['archiveRoot']);
        $stagingDirectory = createStagingDirectory($base, $extension['id']);
        extractArchive($archive, $entries, $stagingDirectory);
        if (!@chmod($stagingDirectory, 0755)) {
            fail("Cannot set safe directory permissions for {$extension['id']}.");
        }
        $archive->close();
        $archive = null;

        validateInstalledMetadata($stagingDirectory, $extension, false);
        $treeDigest = calculateTreeDigest($stagingDirectory);
        writeTreeMarker($stagingDirectory, $extension, $treeDigest);
        verifyInstalledExtension($stagingDirectory, $extension, false);

        if (file_exists($destination) || is_link($destination)) {
            fail("Destination appeared while installing {$extension['id']}; refusing to overwrite it.");
        }
        if (!@rename($stagingDirectory, $destination)) {
            fail("Cannot atomically publish {$extension['id']} into its Piwigo destination.");
        }
        $stagingDirectory = null;
        verifyInstalledExtension($destination, $extension);
        fwrite(STDOUT, "INSTALLED {$extension['type']} {$extension['id']} {$extension['version']}\n");
    } finally {
        if ($archive instanceof ZipArchive) {
            $archive->close();
        }
        if ($stagingDirectory !== null && (file_exists($stagingDirectory) || is_link($stagingDirectory))) {
            removeTree($stagingDirectory);
        }
        if (file_exists($temporaryDirectory) || is_link($temporaryDirectory)) {
            removeTree($temporaryDirectory);
        }
    }
}

function verifyOnly(array $extension): void
{
    $base = resolveExtensionBase($extension['type']);
    $destination = $base . DIRECTORY_SEPARATOR . $extension['destinationDirectory'];
    verifyInstalledExtension($destination, $extension);
    fwrite(STDOUT, "VERIFIED {$extension['type']} {$extension['id']} {$extension['version']}\n");
}

function acquireInstallerLock(): mixed
{
    // The official image deliberately leaves the Piwigo root non-writable by
    // nginx, while its extension directories are writable. Keep the shared
    // installer lock in the plugin directory so the installer never needs root.
    $base = resolveExtensionBase('plugin');
    $path = $base . DIRECTORY_SEPARATOR . INSTALLER_LOCK_FILENAME;
    if (is_link($path)) {
        fail('The extension installer lock path is a symbolic link.');
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        fail('Another locked extension installer is already running.');
    }

    return $handle;
}

function main(array $arguments): void
{
    assertNginxCliUser();

    $verifyOnly = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--verify-only' && !$verifyOnly) {
            $verifyOnly = true;
            continue;
        }
        fail("Unknown or duplicate argument: {$argument}");
    }

    $lock = readJsonObject(LOCK_FILE_PATH, 'Piwigo extension lock file');
    if (($lock['lockFormat'] ?? null) !== 1) {
        fail('Unsupported Piwigo extension lock format.');
    }
    [, $installedMajorVersion] = validateLockedPiwigo($lock);
    $extensions = $lock['extensions'] ?? null;
    if (!is_array($extensions) || !array_is_list($extensions)) {
        fail('The Piwigo extension lock must contain an extensions array.');
    }

    $normalizedExtensions = [];
    $destinations = [];
    foreach ($extensions as $index => $extension) {
        if (!is_array($extension) || array_is_list($extension)) {
            fail("extensions[{$index}] must be an object.");
        }
        $normalized = validateExtension($extension, $index, $installedMajorVersion);
        $destinationKey = $normalized['type'] . ':' . $normalized['destinationDirectory'];
        if (isset($destinations[$destinationKey])) {
            fail("Duplicate locked extension destination: {$destinationKey}");
        }
        $destinations[$destinationKey] = true;
        $normalizedExtensions[] = $normalized;
    }

    $installerLock = acquireInstallerLock();
    try {
        foreach ($normalizedExtensions as $extension) {
            if (!$extension['install']) {
                fwrite(STDOUT, "SKIPPED {$extension['type']} {$extension['id']} (install=false; no download)\n");
                continue;
            }
            if ($verifyOnly) {
                verifyOnly($extension);
            } else {
                installExtension($extension);
            }
        }
    } finally {
        flock($installerLock, LOCK_UN);
        fclose($installerLock);
    }
}

try {
    main($_SERVER['argv'] ?? []);
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
