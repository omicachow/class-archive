<?php

declare(strict_types=1);

const CLASS_ARCHIVE_PLUGIN_SOURCE_ROOT = '/workspace/plugins';
const CLASS_ARCHIVE_PIWIGO_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_PLUGIN_ID = 'ClassArchivePolicy';
const CLASS_ARCHIVE_PLUGIN_VERSION = '0.1.0';
const CLASS_ARCHIVE_PLUGIN_MARKER = '.class-archive-source.json';

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function assertRuntimeUser(): void
{
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        fail('The custom plugin installer requires PHP CLI with POSIX support.');
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        fail('Run the custom plugin installer as the nginx user, never root.');
    }
}

/** @return array<string, array{path: string, size: int, sha256: string}> */
function scanTree(string $root, bool $allowMarker = false, bool $requireCurrentLayout = true): array
{
    $resolved = realpath($root);
    if ($resolved === false || !is_dir($resolved) || is_link($root)) {
        fail("Unsafe or missing plugin tree: {$root}");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            fail('Plugin trees may not contain symbolic links.');
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($resolved) + 1));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '../')) {
            fail('Plugin tree contains an unsafe relative path.');
        }
        if ($entry->isDir()) {
            continue;
        }
        if (!$entry->isFile()) {
            fail("Plugin tree contains a special entry: {$relative}");
        }
        if ($relative === CLASS_ARCHIVE_PLUGIN_MARKER) {
            if (!$allowMarker) {
                fail('The source tree may not provide the installation marker.');
            }
            continue;
        }
        $size = $entry->getSize();
        if ($size < 0 || $size > 16_777_216) {
            fail("Plugin file has an unsafe size: {$relative}");
        }
        $digest = hash_file('sha256', $entry->getPathname());
        if ($digest === false) {
            fail("Cannot hash plugin file: {$relative}");
        }
        $files[$relative] = ['path' => $entry->getPathname(), 'size' => $size, 'sha256' => $digest];
    }
    ksort($files, SORT_STRING);
    $requiredFiles = [
        'main.inc.php',
        'media-gateway.php',
        'src/MediaGuard.php',
    ];
    if ($requireCurrentLayout) {
        $requiredFiles[] = 'derivative-generator.php';
        $requiredFiles[] = 'identity-derivative-fallback.php';
    }
    foreach ($requiredFiles as $requiredFile) {
        if (isset($files[$requiredFile])) {
            continue;
        }
        fail('ClassArchivePolicy source tree is incomplete.');
    }

    return $files;
}

function treeDigest(array $files): string
{
    $context = hash_init('sha256');
    foreach ($files as $relative => $metadata) {
        hash_update($context, $relative . "\0" . $metadata['size'] . "\0" . $metadata['sha256'] . "\n");
    }
    return hash_final($context);
}

function ensureDirectory(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        return;
    }
    if (file_exists($path) || is_link($path) || !mkdir($path, 0755, true)) {
        fail("Cannot create safe directory: {$path}");
    }
}

function copyTree(array $files, string $destination): void
{
    ensureDirectory($destination);
    foreach ($files as $relative => $metadata) {
        $target = $destination . '/' . $relative;
        ensureDirectory(dirname($target));
        $input = fopen($metadata['path'], 'rb');
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            fail("Cannot copy plugin file: {$relative}");
        }
        $copied = stream_copy_to_stream($input, $output, 16_777_217);
        fclose($input);
        $flushed = fflush($output);
        fclose($output);
        if ($copied !== $metadata['size'] || !$flushed || !chmod($target, 0644)) {
            fail("Plugin file copy failed verification: {$relative}");
        }
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            fail("Cannot remove staging entry: {$path}");
        }
        return;
    }
    foreach (scandir($path) ?: [] as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }
        removeTree($path . '/' . $child);
    }
    if (!rmdir($path)) {
        fail("Cannot remove staging directory: {$path}");
    }
}

function readMarker(string $destination): array
{
    $path = $destination . '/' . CLASS_ARCHIVE_PLUGIN_MARKER;
    $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        fail('Installed custom plugin has no trusted source marker; refusing to overwrite it.');
    }
    $marker = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($marker) || array_is_list($marker) || ($marker['id'] ?? null) !== CLASS_ARCHIVE_PLUGIN_ID) {
        fail('Installed custom plugin marker is invalid.');
    }
    return $marker;
}

function writeMarker(string $destination, string $digest): void
{
    $payload = json_encode(
        ['format' => 1, 'id' => CLASS_ARCHIVE_PLUGIN_ID, 'treeDigest' => $digest],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n";
    $path = $destination . '/' . CLASS_ARCHIVE_PLUGIN_MARKER;
    if (file_put_contents($path, $payload, LOCK_EX) !== strlen($payload) || !chmod($path, 0644)) {
        fail('Cannot write the custom plugin source marker.');
    }
}

function activatePlugin(): void
{
    $command = [PHP_BINARY, '/workspace/infra/scripts/activate-class-archive-policy.php'];
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail('Cannot start the isolated Piwigo activation process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail('ClassArchivePolicy activation failed: ' . trim((string) $stderr));
    }
    if (!str_contains((string) $stdout, 'ACTIVATED ClassArchivePolicy 0.1.0')) {
        fail('ClassArchivePolicy activation returned an unexpected result.');
    }
}

function verifyPluginState(): void
{
    $verificationCode = <<<'PHP'
$conf = [];
$prefixeTable = null;
require '/var/www/html/piwigo/local/config/database.inc.php';
if (
    !is_string($prefixeTable)
    || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable)
    || ($conf['dblayer'] ?? null) !== 'mysqli'
) {
    fwrite(STDERR, "ClassArchivePolicy database configuration is unavailable.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$database = @new mysqli(
    (string) ($conf['db_host'] ?? ''),
    (string) ($conf['db_user'] ?? ''),
    (string) ($conf['db_password'] ?? ''),
    (string) ($conf['db_base'] ?? ''),
);
if ($database->connect_errno !== 0 || !$database->set_charset('utf8mb4')) {
    fwrite(STDERR, "ClassArchivePolicy database connection is unavailable.\n");
    exit(2);
}
$statement = $database->prepare(
    "SELECT id, version, state FROM `{$prefixeTable}plugins` WHERE id = ?"
);
if ($statement === false) {
    fwrite(STDERR, "ClassArchivePolicy runtime state query is unavailable.\n");
    exit(2);
}
$pluginId = 'ClassArchivePolicy';
$statement->bind_param('s', $pluginId);
if (!$statement->execute()) {
    fwrite(STDERR, "ClassArchivePolicy runtime state query failed.\n");
    exit(2);
}
$result = $statement->get_result();
$rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
$statement->close();
$database->close();
if (
    count($rows) !== 1
    || $rows[0]['state'] !== 'active'
    || $rows[0]['version'] !== '0.1.0'
) {
    fwrite(STDERR, "ClassArchivePolicy runtime state is not active at the expected version.\n");
    exit(1);
}
fwrite(STDOUT, "VERIFIED_STATE ClassArchivePolicy 0.1.0\n");
PHP;

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $verificationCode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail('Cannot start the isolated Piwigo state verification process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail('ClassArchivePolicy runtime state verification failed: ' . trim((string) $stderr));
    }
    if (!str_contains((string) $stdout, 'VERIFIED_STATE ClassArchivePolicy ' . CLASS_ARCHIVE_PLUGIN_VERSION)) {
        fail('ClassArchivePolicy runtime state verification returned an unexpected result.');
    }
}

function main(array $arguments): void
{
    assertRuntimeUser();
    $verifyOnly = $arguments === [$arguments[0] ?? '', '--verify-only'];
    if (!$verifyOnly && count($arguments) !== 1) {
        fail('Usage: install-class-archive-plugins.php [--verify-only]');
    }

    $source = CLASS_ARCHIVE_PLUGIN_SOURCE_ROOT . '/' . CLASS_ARCHIVE_PLUGIN_ID;
    $destinationBase = CLASS_ARCHIVE_PIWIGO_ROOT . '/plugins';
    $destination = $destinationBase . '/' . CLASS_ARCHIVE_PLUGIN_ID;
    $sourceFiles = scanTree($source);
    $sourceDigest = treeDigest($sourceFiles);

    if (is_dir($destination) && !is_link($destination)) {
        $marker = readMarker($destination);
        // Older trusted revisions can legitimately lack files introduced by
        // the current source tree. Verify their marker before replacing them,
        // but require the complete current layout for source and staging.
        $installedDigest = treeDigest(scanTree($destination, true, false));
        if (!hash_equals((string) ($marker['treeDigest'] ?? ''), $installedDigest)) {
            // A crashed/manual development sync can leave only the marker
            // stale. Repair it solely when every installed byte already
            // equals the current read-only tracked source; never bless an
            // unknown or partially copied tree.
            if ($verifyOnly || !hash_equals($sourceDigest, $installedDigest)) {
                fail('Installed ClassArchivePolicy tree drifted from its trusted marker.');
            }
            writeMarker($destination, $sourceDigest);
            $marker = readMarker($destination);
        }
        if (hash_equals($sourceDigest, $installedDigest)) {
            if ($verifyOnly) {
                verifyPluginState();
            } else {
                activatePlugin();
            }
            fwrite(STDOUT, "VERIFIED ClassArchivePolicy 0.1.0\n");
            return;
        }
        if ($verifyOnly) {
            fail('Installed ClassArchivePolicy differs from the tracked source.');
        }
    } elseif (file_exists($destination) || is_link($destination)) {
        fail('ClassArchivePolicy destination is not a safe directory.');
    } elseif ($verifyOnly) {
        fail('ClassArchivePolicy is not installed.');
    }

    $suffix = bin2hex(random_bytes(8));
    $staging = $destinationBase . '/.class-archive-policy-stage-' . $suffix;
    $backup = $destinationBase . '/.class-archive-policy-backup-' . $suffix;
    $hadDestination = is_dir($destination);
    try {
        copyTree($sourceFiles, $staging);
        writeMarker($staging, $sourceDigest);
        if (!hash_equals($sourceDigest, treeDigest(scanTree($staging, true)))) {
            fail('Staged ClassArchivePolicy digest mismatch.');
        }
        if ($hadDestination && !rename($destination, $backup)) {
            fail('Cannot stage the prior ClassArchivePolicy for rollback.');
        }
        if (!rename($staging, $destination)) {
            if ($hadDestination) {
                rename($backup, $destination);
            }
            fail('Cannot atomically publish ClassArchivePolicy.');
        }
        try {
            activatePlugin();
        } catch (Throwable $exception) {
            removeTree($destination);
            if ($hadDestination) {
                rename($backup, $destination);
            }
            throw $exception;
        }
        if ($hadDestination) {
            removeTree($backup);
        }
        fwrite(STDOUT, "INSTALLED ClassArchivePolicy 0.1.0\n");
    } finally {
        if (file_exists($staging) || is_link($staging)) {
            removeTree($staging);
        }
    }
}

try {
    main($_SERVER['argv'] ?? []);
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
