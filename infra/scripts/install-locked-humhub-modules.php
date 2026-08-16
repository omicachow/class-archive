<?php

declare(strict_types=1);

const MODULES_PATH_DEFAULT = '/data/modules';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function readJsonFile(string $path): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Cannot read {$path}");
    }

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException("Cannot remove {$path}");
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!unlink($itemPath)) {
                throw new RuntimeException("Cannot remove {$itemPath}");
            }
        } elseif (!rmdir($itemPath)) {
            throw new RuntimeException("Cannot remove {$itemPath}");
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException("Cannot remove {$path}");
    }
}

function validateArchivePath(string $path): void
{
    $normalized = str_replace('\\', '/', $path);
    if (
        $normalized === ''
        || str_contains($normalized, "\0")
        || str_starts_with($normalized, '/')
        || preg_match('/^[A-Za-z]:\//', $normalized) === 1
        || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1
    ) {
        throw new RuntimeException("Unsafe archive entry: {$path}");
    }
}

function downloadArchive(string $url, string $destination): void
{
    $output = fopen($destination, 'wb');
    if ($output === false) {
        throw new RuntimeException("Cannot create {$destination}");
    }

    $curl = curl_init($url);
    if ($curl === false) {
        fclose($output);
        throw new RuntimeException('Cannot initialize cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_FILE => $output,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'ClassArchive locked module installer/1',
    ]);

    try {
        if (!curl_exec($curl)) {
            throw new RuntimeException('Download failed: ' . curl_error($curl));
        }
    } finally {
        curl_close($curl);
        fclose($output);
    }
}

function installModule(array $module, string $modulesPath, bool $verifyOnly): void
{
    foreach (['id', 'version', 'downloadUrl', 'sha256'] as $requiredKey) {
        if (!isset($module[$requiredKey]) || !is_string($module[$requiredKey])) {
            throw new RuntimeException("Invalid lock entry: missing {$requiredKey}");
        }
    }

    $id = $module['id'];
    if (preg_match('/^[a-z0-9-]+$/', $id) !== 1) {
        throw new RuntimeException("Invalid module id: {$id}");
    }

    $destination = $modulesPath . '/' . $id;
    if (is_dir($destination)) {
        $manifest = readJsonFile($destination . '/module.json');
        if (($manifest['id'] ?? null) !== $id || ($manifest['version'] ?? null) !== $module['version']) {
            throw new RuntimeException(
                "Refusing to overwrite {$destination}; installed version does not match lock {$module['version']}",
            );
        }
        fwrite(STDOUT, "LOCKED {$id} {$module['version']} (already present)\n");
        return;
    }

    $temporaryRoot = $modulesPath . '/.locked-install-' . $id . '-' . bin2hex(random_bytes(6));
    $archivePath = $temporaryRoot . '/module.zip';
    $extractPath = $temporaryRoot . '/extract';

    if (!mkdir($extractPath, 0700, true)) {
        throw new RuntimeException("Cannot create {$extractPath}");
    }

    try {
        fwrite(STDOUT, "FETCH  {$id} {$module['version']}\n");
        downloadArchive($module['downloadUrl'], $archivePath);
        $actualHash = hash_file('sha256', $archivePath);
        if (!hash_equals(strtolower($module['sha256']), strtolower((string) $actualHash))) {
            throw new RuntimeException(
                "SHA-256 mismatch for {$id}: expected {$module['sha256']}, got {$actualHash}",
            );
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            throw new RuntimeException("Cannot open archive for {$id}; ZipArchive code {$openResult}");
        }

        $moduleRootRelative = null;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                if ($entry === false) {
                    throw new RuntimeException("Cannot inspect archive entry {$index} for {$id}");
                }
                validateArchivePath($entry);

                $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes);
                $unixType = ($attributes >> 16) & 0xF000;
                if ($unixType === 0xA000) {
                    throw new RuntimeException("Symbolic links are not accepted in {$id}: {$entry}");
                }

                if (basename($entry) !== 'module.json') {
                    continue;
                }

                $candidateJson = $zip->getFromIndex($index);
                if ($candidateJson === false) {
                    continue;
                }
                $candidate = json_decode($candidateJson, true);
                if (
                    is_array($candidate)
                    && ($candidate['id'] ?? null) === $id
                    && ($candidate['version'] ?? null) === $module['version']
                ) {
                    if ($moduleRootRelative !== null) {
                        throw new RuntimeException("Archive for {$id} has multiple matching manifests.");
                    }
                    $moduleRootRelative = dirname(str_replace('\\', '/', $entry));
                }
            }

            if ($moduleRootRelative === null) {
                throw new RuntimeException("Archive for {$id} has no matching module.json.");
            }
            if (!$zip->extractTo($extractPath)) {
                throw new RuntimeException("Cannot extract archive for {$id}");
            }
        } finally {
            $zip->close();
        }

        $moduleRoot = $moduleRootRelative === '.'
            ? $extractPath
            : $extractPath . '/' . $moduleRootRelative;
        $manifest = readJsonFile($moduleRoot . '/module.json');
        if (($manifest['id'] ?? null) !== $id || ($manifest['version'] ?? null) !== $module['version']) {
            throw new RuntimeException("Extracted manifest mismatch for {$id}");
        }

        if (!rename($moduleRoot, $destination)) {
            throw new RuntimeException("Cannot atomically install {$id} into {$destination}");
        }
        fwrite(STDOUT, "LOCKED {$id} {$module['version']} (installed)\n");
    } finally {
        removeTree($temporaryRoot);
    }
}

function runHumHubCommand(array $arguments): string
{
    $command = ['/app/yii', ...$arguments];
    $process = proc_open(
        $command,
        [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the HumHub CLI.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim((string) $stderr);
        throw new RuntimeException(
            'HumHub CLI failed for ' . implode(' ', $arguments)
            . "; exit code {$exitCode}" . ($detail === '' ? '' : ": {$detail}"),
        );
    }

    return (string) $stdout;
}

function enableModule(string $id): void
{
    $output = runHumHubCommand(['module/enable', $id]);
    if ($output !== '') {
        fwrite(STDOUT, $output);
    }
}

function configureTwoFaComparisonBaseline(): void
{
    // TwoFA 1.2.3 otherwise treats a missing setting as "all administrator
    // groups" and defaults to email delivery. The comparison runtime has no
    // SMTP recovery channel, so installation must fail closed without
    // enforcement until that recovery path is deliberately configured.
    runHumHubCommand(['settings/set', 'twofa', 'enforcedGroups', '', '--interactive=0']);
}

function verifyTwoFaComparisonBaseline(): void
{
    $output = runHumHubCommand(['settings/list-module', 'twofa']);
    if (preg_match('/enforcedGroups\s*[│|]\s*[│|]/u', $output) !== 1) {
        throw new RuntimeException(
            'TwoFA enforcedGroups must exist and be empty in the comparison runtime.',
        );
    }
}

function verifyHumHubVersion(array $lock): void
{
    $expectedVersion = $lock['humhub']['version'] ?? null;
    if (!is_string($expectedVersion) || $expectedVersion === '') {
        throw new RuntimeException('The lock file has no HumHub core version.');
    }

    $coreConfigPath = '/opt/humhub/protected/humhub/config/common.php';
    $coreConfig = file_get_contents($coreConfigPath);
    if (
        $coreConfig === false
        || preg_match("/'version'\\s*=>\\s*'([^']+)'/", $coreConfig, $matches) !== 1
    ) {
        throw new RuntimeException("Cannot determine HumHub version from {$coreConfigPath}");
    }
    if (!hash_equals($expectedVersion, $matches[1])) {
        throw new RuntimeException(
            "HumHub core {$matches[1]} does not match locked version {$expectedVersion}",
        );
    }
}

function verifyModuleRuntime(array $module, string $modulesPath): void
{
    $id = $module['id'];
    $version = $module['version'];
    $output = runHumHubCommand(['module/info', $id]);

    foreach (['Installed' => 'Yes', 'Enabled' => 'Yes', 'Version' => $version] as $label => $value) {
        if (preg_match('/^' . preg_quote($label, '/') . ':\\s*' . preg_quote($value, '/') . '\\s*$/m', $output) !== 1) {
            throw new RuntimeException("Runtime state for {$id} does not report {$label}: {$value}");
        }
    }

    if (preg_match('/^Path:\\s*(.+)\\s*$/m', $output, $pathMatches) !== 1) {
        throw new RuntimeException("Runtime path for {$id} was not reported.");
    }
    $expectedPath = realpath($modulesPath . '/' . $id);
    $actualPath = realpath(trim($pathMatches[1]));
    if ($expectedPath === false || $actualPath === false || $expectedPath !== $actualPath) {
        throw new RuntimeException("Runtime path for {$id} is outside the locked module directory.");
    }
}

try {
    $verifyOnly = in_array('--verify-only', $argv, true);
    $lockPath = dirname(__DIR__) . '/humhub-modules.lock.json';
    $lock = readJsonFile($lockPath);
    $modules = $lock['modules'] ?? null;
    if (!is_array($modules) || $modules === []) {
        throw new RuntimeException("No modules found in {$lockPath}");
    }
    verifyHumHubVersion($lock);

    $modulesPath = getenv('HUMHUB_CONFIG__MODULES__MARKETPLACE__MODULES_PATH') ?: MODULES_PATH_DEFAULT;
    if (!is_dir($modulesPath)) {
        throw new RuntimeException("Modules path does not exist: {$modulesPath}");
    }
    if ($verifyOnly ? !is_readable($modulesPath) : !is_writable($modulesPath)) {
        $requiredAccess = $verifyOnly ? 'readable' : 'writable';
        throw new RuntimeException("Modules path is not {$requiredAccess}: {$modulesPath}");
    }

    foreach ($modules as $module) {
        $destination = $modulesPath . '/' . ($module['id'] ?? '');
        if ($verifyOnly && !is_dir($destination)) {
            throw new RuntimeException("Locked module is missing: {$destination}");
        }
        installModule($module, $modulesPath, $verifyOnly);
    }

    if (!$verifyOnly) {
        foreach ($modules as $module) {
            if (($module['enabled'] ?? false) === true) {
                enableModule($module['id']);
            }
        }
        configureTwoFaComparisonBaseline();
    }

    foreach ($modules as $module) {
        if (($module['enabled'] ?? false) === true) {
            verifyModuleRuntime($module, $modulesPath);
        }
    }
    verifyTwoFaComparisonBaseline();

    fwrite(
        STDOUT,
        $verifyOnly
            ? "All locked module manifests and runtime states verified.\n"
            : "All locked modules installed, enabled, and runtime-verified.\n",
    );
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
