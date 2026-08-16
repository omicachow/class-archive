<?php

declare(strict_types=1);

// Root-only synthetic fixture for runtime-surface-http.ps1. The caller may
// supply only an action and one validated run id. Paths, filenames and marker
// bytes are derived here below the exact Piwigo `_data` root.

const CI_RUNTIME_DATA_ROOT = '/var/www/html/piwigo/_data';
const CI_RUNTIME_PRIVATE_DIRS = ['logs', 'tmp', 'cache', 'templates_c', 'maintenance'];

function fixture_error(string $message): void
{
    throw new RuntimeException($message);
}

function path_exists_exact(string $path): bool
{
    return file_exists($path) || is_link($path);
}

/** @return array{run_id:string,canary_name:string,dot_name:string,manifest_name:string,marker:string} */
function derive_fixture(string $runId): array
{
    if (preg_match('/\A[a-f0-9]{16}\z/D', $runId) !== 1) {
        fixture_error('run id must be exactly 16 lowercase hexadecimal characters');
    }
    return [
        'run_id' => $runId,
        'canary_name' => "class-archive-surface-{$runId}.canary",
        'dot_name' => ".class-archive-maintenance-{$runId}.canary",
        'manifest_name' => ".class-archive-runtime-surface-{$runId}.manifest",
        'marker' => 'CLASS_ARCHIVE_RUNTIME_SURFACE_' . strtoupper($runId),
    ];
}

function assert_runtime_root(): void
{
    if (PHP_SAPI !== 'cli') {
        fixture_error('CLI execution is required');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        fixture_error('root execution is required');
    }
    if (is_link(CI_RUNTIME_DATA_ROOT) || !is_dir(CI_RUNTIME_DATA_ROOT)) {
        fixture_error('the exact data root is unavailable or unsafe');
    }
    if (realpath(CI_RUNTIME_DATA_ROOT) !== CI_RUNTIME_DATA_ROOT) {
        fixture_error('the exact data root did not resolve to itself');
    }
}

function write_exclusive(string $path, string $content, int $mode): void
{
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        fixture_error('exclusive fixture creation failed');
    }
    $ok = false;
    try {
        $ok = fwrite($handle, $content) === strlen($content) && fflush($handle);
    } finally {
        fclose($handle);
    }
    if (!$ok || !chmod($path, $mode)) {
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
        fixture_error('fixture write or mode verification failed');
    }
}

/** @param array<string,mixed> $payload */
function emit_json(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fixture_error('JSON serialization failed');
    }
    fwrite(STDOUT, $json . "\n");
}

/** @return list<string> */
function target_paths(array $fixture): array
{
    $paths = [];
    foreach (CI_RUNTIME_PRIVATE_DIRS as $leaf) {
        $paths[] = CI_RUNTIME_DATA_ROOT . '/' . $leaf . '/' . $fixture['canary_name'];
    }
    $paths[] = CI_RUNTIME_DATA_ROOT . '/' . $fixture['canary_name'];
    $paths[] = CI_RUNTIME_DATA_ROOT . '/' . $fixture['dot_name'];
    return $paths;
}

function fixture_status(array $fixture): string
{
    if (path_exists_exact(CI_RUNTIME_DATA_ROOT . '/' . $fixture['manifest_name'])) {
        return 'ACTIVE_OR_UNKNOWN';
    }
    foreach (target_paths($fixture) as $path) {
        if (path_exists_exact($path)) {
            return 'RESIDUE';
        }
    }
    return 'ABSENT';
}

function setup_fixture(array $fixture): void
{
    if (fixture_status($fixture) !== 'ABSENT') {
        fixture_error('fixture state was not absent');
    }

    $createdDirectories = [];
    foreach (CI_RUNTIME_PRIVATE_DIRS as $leaf) {
        $directory = CI_RUNTIME_DATA_ROOT . '/' . $leaf;
        if (is_link($directory) || (path_exists_exact($directory) && !is_dir($directory))) {
            fixture_error('a private data directory has an unsafe type');
        }
        if (!is_dir($directory)) {
            $createdDirectories[] = $leaf;
        }
    }
    foreach (target_paths($fixture) as $path) {
        if (path_exists_exact($path)) {
            fixture_error('a derived canary target already exists');
        }
    }

    $manifestPath = CI_RUNTIME_DATA_ROOT . '/' . $fixture['manifest_name'];
    if (path_exists_exact($manifestPath)) {
        fixture_error('the derived manifest already exists');
    }
    $manifest = [
        'version' => 1,
        'run_id' => $fixture['run_id'],
        'canary_name' => $fixture['canary_name'],
        'dot_name' => $fixture['dot_name'],
        'created_dirs' => $createdDirectories,
    ];
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    if (!is_string($manifestJson)) {
        fixture_error('manifest serialization failed');
    }

    $createdFiles = [];
    $madeDirectories = [];
    try {
        write_exclusive($manifestPath, $manifestJson . "\n", 0600);
        $createdFiles[] = $manifestPath;
        foreach ($createdDirectories as $leaf) {
            $directory = CI_RUNTIME_DATA_ROOT . '/' . $leaf;
            if (!mkdir($directory, 0755) || is_link($directory) || !is_dir($directory)) {
                fixture_error('private fixture directory creation failed');
            }
            $madeDirectories[] = $directory;
        }
        foreach (target_paths($fixture) as $path) {
            write_exclusive($path, $fixture['marker'], 0644);
            $createdFiles[] = $path;
        }
    } catch (Throwable $error) {
        foreach (array_reverse($createdFiles) as $path) {
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        foreach (array_reverse($madeDirectories) as $directory) {
            if (is_dir($directory) && !is_link($directory)) {
                @rmdir($directory);
            }
        }
        throw $error;
    }
    emit_json(['state' => 'ACTIVE']);
}

/** @return array{version:int,run_id:string,canary_name:string,dot_name:string,created_dirs:list<string>} */
function read_manifest(array $fixture): array
{
    $manifestPath = CI_RUNTIME_DATA_ROOT . '/' . $fixture['manifest_name'];
    if (is_link($manifestPath) || !is_file($manifestPath)) {
        fixture_error('the exact manifest is missing or unsafe');
    }
    $raw = file_get_contents($manifestPath);
    if (!is_string($raw) || strlen($raw) > 2048) {
        fixture_error('the exact manifest is unreadable or oversized');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || array_keys($decoded) !== ['version', 'run_id', 'canary_name', 'dot_name', 'created_dirs']) {
        fixture_error('the exact manifest shape is invalid');
    }
    if (
        $decoded['version'] !== 1 ||
        $decoded['run_id'] !== $fixture['run_id'] ||
        $decoded['canary_name'] !== $fixture['canary_name'] ||
        $decoded['dot_name'] !== $fixture['dot_name'] ||
        !is_array($decoded['created_dirs'])
    ) {
        fixture_error('the exact manifest content is invalid');
    }
    $seen = [];
    foreach ($decoded['created_dirs'] as $leaf) {
        if (!is_string($leaf) || !in_array($leaf, CI_RUNTIME_PRIVATE_DIRS, true) || isset($seen[$leaf])) {
            fixture_error('the manifest directory set is invalid');
        }
        $seen[$leaf] = true;
    }
    return $decoded;
}

function cleanup_fixture(array $fixture): void
{
    $manifest = read_manifest($fixture);
    foreach (CI_RUNTIME_PRIVATE_DIRS as $leaf) {
        $directory = CI_RUNTIME_DATA_ROOT . '/' . $leaf;
        if (is_link($directory) || (path_exists_exact($directory) && !is_dir($directory))) {
            fixture_error('a cleanup directory has an unsafe type');
        }
    }
    foreach (target_paths($fixture) as $path) {
        if (!path_exists_exact($path)) {
            continue;
        }
        if (is_link($path) || !is_file($path)) {
            fixture_error('a cleanup target has an unsafe type');
        }
        $content = file_get_contents($path);
        if (!is_string($content) || !hash_equals($fixture['marker'], $content)) {
            fixture_error('a cleanup target has unknown content');
        }
    }

    foreach (target_paths($fixture) as $path) {
        if (path_exists_exact($path) && !unlink($path)) {
            fixture_error('exact canary removal failed');
        }
    }
    foreach (array_reverse($manifest['created_dirs']) as $leaf) {
        $directory = CI_RUNTIME_DATA_ROOT . '/' . $leaf;
        if (!path_exists_exact($directory)) {
            continue;
        }
        if (is_link($directory) || !is_dir($directory) || !rmdir($directory)) {
            fixture_error('a fixture-created directory was not empty or safe to remove');
        }
    }
    $manifestPath = CI_RUNTIME_DATA_ROOT . '/' . $fixture['manifest_name'];
    if (!unlink($manifestPath) || fixture_status($fixture) !== 'ABSENT') {
        fixture_error('fixture cleanup did not reach the absent state');
    }
    emit_json(['state' => 'ABSENT']);
}

function find_combined_assets(): void
{
    $combined = CI_RUNTIME_DATA_ROOT . '/combined';
    if (is_link($combined) || !is_dir($combined) || realpath($combined) !== $combined) {
        fixture_error('the combined-assets directory is unavailable or unsafe');
    }
    $assets = ['css' => [], 'js' => []];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($combined, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->isLink() || !$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if ($extension !== 'css' && $extension !== 'js') {
            continue;
        }
        $absolute = $file->getRealPath();
        if (!is_string($absolute) || substr($absolute, 0, strlen($combined) + 1) !== $combined . '/') {
            fixture_error('a combined asset escaped its public directory');
        }
        $relative = substr($absolute, strlen(CI_RUNTIME_DATA_ROOT) + 1);
        $public = '_data/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        if (preg_match('#\A_data/combined/[A-Za-z0-9._/-]+\.(css|js)\z#D', $public) !== 1) {
            fixture_error('a combined asset path was unsafe');
        }
        $assets[$extension][] = $public;
    }
    sort($assets['css'], SORT_STRING);
    sort($assets['js'], SORT_STRING);
    if ($assets['css'] === [] || $assets['js'] === []) {
        fixture_error('an existing combined CSS or JavaScript asset was missing');
    }
    emit_json(['css' => $assets['css'][0], 'js' => $assets['js'][0]]);
}

try {
    assert_runtime_root();
    if ($argc !== 3) {
        fixture_error('expected action and run id');
    }
    $action = (string) $argv[1];
    $fixture = derive_fixture((string) $argv[2]);
    if ($action === 'setup') {
        setup_fixture($fixture);
    } elseif ($action === 'cleanup') {
        cleanup_fixture($fixture);
    } elseif ($action === 'status') {
        emit_json(['state' => fixture_status($fixture)]);
    } elseif ($action === 'find-assets') {
        find_combined_assets();
    } else {
        fixture_error('unsupported action');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "RUNTIME_SURFACE_FIXTURE: operation failed closed\n");
    exit(1);
}
