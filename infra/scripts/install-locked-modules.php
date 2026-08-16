<?php

declare(strict_types=1);

const MODULES_PATH_DEFAULT = '/data/modules';
const MODULE_OWNER = 'www-data';

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

function setOwnerRecursively(string $path): void
{
    $account = posix_getpwnam(MODULE_OWNER);
    if ($account === false) {
        throw new RuntimeException('The container has no www-data account.');
    }

    $apply = static function (string $target) use ($account): void {
        if (!chown($target, $account['uid']) || !chgrp($target, $account['gid'])) {
            throw new RuntimeException("Cannot set ownership on {$target}");
        }
    };

    $apply($path);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $apply($item->getPathname());
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
        if (!$verifyOnly) {
            setOwnerRecursively($destination);
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
        setOwnerRecursively($destination);
        fwrite(STDOUT, "LOCKED {$id} {$module['version']} (installed)\n");
    } finally {
        removeTree($temporaryRoot);
    }
}

function enableModule(string $id): void
{
    $command = ['/app/yii', 'module/enable', $id];
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Cannot start HumHub CLI for {$id}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("HumHub failed to enable {$id}; exit code {$exitCode}");
    }
}

try {
    $verifyOnly = in_array('--verify-only', $argv, true);
    $lockPath = dirname(__DIR__) . '/modules.lock.json';
    $lock = readJsonFile($lockPath);
    $modules = $lock['modules'] ?? null;
    if (!is_array($modules) || $modules === []) {
        throw new RuntimeException("No modules found in {$lockPath}");
    }

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
    }

    fwrite(STDOUT, $verifyOnly ? "All locked module manifests verified.\n" : "All locked modules installed and enabled.\n");
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
