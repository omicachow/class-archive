<?php

declare(strict_types=1);

const CLASS_ARCHIVE_PLUGIN_SOURCE_ROOT = '/workspace/plugins';
const CLASS_ARCHIVE_PIWIGO_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_PLUGIN_MARKER = '.class-archive-source.json';
const CLASS_ARCHIVE_MAINTENANCE_MARKER = CLASS_ARCHIVE_PIWIGO_ROOT . '/_data/.class-archive-maintenance';
const CLASS_ARCHIVE_MAINTENANCE_CONTENT = "class-archive-identity-bootstrap\n";

/** @var array<string, array{version: string, required: list<string>}> */
const CLASS_ARCHIVE_PLUGIN_MANIFEST = [
    'ClassArchivePolicy' => [
        'version' => '0.1.0',
        'required' => [
            'main.inc.php', 'media-gateway.php', 'derivative-generator.php',
            'identity-derivative-fallback.php', 'src/MediaGuard.php',
        ],
    ],
    'ClassIdentity' => [
        'version' => '0.1.0',
        'required' => [
            'main.inc.php', 'maintain.class.php', 'admin.php',
            'src/Schema.php', 'src/Repository.php', 'src/Audit.php',
            'src/Access.php', 'src/CoreAdapter.php', 'src/CapabilityGuard.php',
            'src/RateLimiter.php', 'src/ProvisioningService.php',
            'src/AdminService.php', 'src/AnonymousPresenter.php',
            'src/AnonymousResolutionService.php', 'src/Http.php', 'public.php',
            'src/SubmissionService.php', 'src/ArchiveService.php',
            'src/AnonymousGovernanceService.php', 'src/MediaAttestation.php',
            'src/ReconciliationService.php', 'src/MaintenanceStatus.php',
            'src/BackupRestoreEvidence.php',
        ],
    ],
];

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

function assertTrustedMaintenanceGate(): void
{
    $path = CLASS_ARCHIVE_MAINTENANCE_MARKER;
    $root = realpath(CLASS_ARCHIVE_PIWIGO_ROOT);
    $resolvedDirectory = realpath(CLASS_ARCHIVE_PIWIGO_ROOT . '/_data');
    if (
        $root !== CLASS_ARCHIVE_PIWIGO_ROOT
        || $resolvedDirectory !== CLASS_ARCHIVE_PIWIGO_ROOT . '/_data'
        || !is_dir($resolvedDirectory)
        || is_link(CLASS_ARCHIVE_PIWIGO_ROOT)
        || is_link(CLASS_ARCHIVE_PIWIGO_ROOT . '/_data')
    ) {
        fail('The persistent maintenance-gate directory is unsafe or unavailable.');
    }

    clearstatcache(true, $path);
    $metadata = @lstat($path);
    if (
        !is_array($metadata)
        || is_link($path)
        || (($metadata['mode'] ?? 0) & 0170000) !== 0100000
        || realpath($path) !== $path
    ) {
        fail('An exact regular maintenance gate is required.');
    }
    if (
        (int) ($metadata['uid'] ?? -1) !== posix_geteuid()
        || (($metadata['mode'] ?? 0) & 0777) !== 0600
        || (int) ($metadata['nlink'] ?? 0) !== 1
    ) {
        fail('The maintenance gate owner or permissions are untrusted.');
    }
    $existing = file_get_contents($path);
    if (!is_string($existing) || !hash_equals(CLASS_ARCHIVE_MAINTENANCE_CONTENT, $existing)) {
        fail('The maintenance gate content is untrusted.');
    }
}

function openMaintenanceGate(): void
{
    $path = CLASS_ARCHIVE_MAINTENANCE_MARKER;
    clearstatcache(true, $path);
    if (file_exists($path) || is_link($path)) {
        // A retry may resume only a gate prepared by the controlled root helper
        // and already owned by nginx with exact 0600/content/path invariants.
        assertTrustedMaintenanceGate();
        return;
    }

    $handle = @fopen($path, 'x+b');
    if (!is_resource($handle)) {
        fail('Cannot create the persistent maintenance gate.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            fail('Cannot lock the persistent maintenance gate.');
        }
        $written = fwrite($handle, CLASS_ARCHIVE_MAINTENANCE_CONTENT);
        if (
            $written !== strlen(CLASS_ARCHIVE_MAINTENANCE_CONTENT)
            || !fflush($handle)
            || !chmod($path, 0600)
        ) {
            fail('Cannot publish the persistent maintenance gate.');
        }
    } finally {
        fclose($handle);
    }
    // On every failure above the file is deliberately retained: its mere
    // presence keeps nginx fail closed and supports an explicit recovery run.
    assertTrustedMaintenanceGate();
}

function closeMaintenanceGate(): void
{
    $path = CLASS_ARCHIVE_MAINTENANCE_MARKER;
    // Only the independent finalizer may call this, after repeating all tree,
    // plugin, schema and principal assertions. Revalidate immediately before
    // unlink so a changed or operator-owned marker is never removed.
    assertTrustedMaintenanceGate();
    if (!unlink($path)) {
        fail('Cannot close the persistent maintenance gate.');
    }
    clearstatcache(true, $path);
    if (file_exists($path) || is_link($path)) {
        fail('The persistent maintenance gate remained open.');
    }
}

function assertMaintenanceGateClosed(): void
{
    clearstatcache(true, CLASS_ARCHIVE_MAINTENANCE_MARKER);
    if (file_exists(CLASS_ARCHIVE_MAINTENANCE_MARKER) || is_link(CLASS_ARCHIVE_MAINTENANCE_MARKER)) {
        fail('Class Archive is still in fail-closed maintenance mode.');
    }
}

/** @return array{version: string, required: list<string>} */
function pluginDefinition(string $pluginId): array
{
    $definition = CLASS_ARCHIVE_PLUGIN_MANIFEST[$pluginId] ?? null;
    if (!is_array($definition)) {
        fail('Refusing to operate on a plugin outside the Class Archive allowlist.');
    }
    return $definition;
}

/** @return array<string, array{path: string, size: int, sha256: string}> */
function scanTree(
    string $root,
    string $pluginId,
    bool $allowMarker = false,
    bool $validateRequired = true,
): array
{
    $definition = pluginDefinition($pluginId);
    $resolved = realpath($root);
    if ($resolved === false || !is_dir($resolved) || is_link($root)) {
        fail("Unsafe or missing {$pluginId} plugin tree.");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            fail("{$pluginId} plugin trees may not contain symbolic links.");
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($resolved) + 1));
        if (
            $relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
            || preg_match('~(?:^|/)\.\.(?:/|$)~D', $relative) === 1
        ) {
            fail("{$pluginId} plugin tree contains an unsafe relative path.");
        }
        if ($entry->isDir()) {
            continue;
        }
        if (!$entry->isFile()) {
            fail("{$pluginId} plugin tree contains a special entry: {$relative}");
        }
        if ($relative === CLASS_ARCHIVE_PLUGIN_MARKER) {
            if (!$allowMarker) {
                fail("The {$pluginId} source tree may not provide the installation marker.");
            }
            continue;
        }
        $size = $entry->getSize();
        if ($size < 0 || $size > 16_777_216) {
            fail("{$pluginId} plugin file has an unsafe size: {$relative}");
        }
        $digest = hash_file('sha256', $entry->getPathname());
        if ($digest === false) {
            fail("Cannot hash {$pluginId} plugin file: {$relative}");
        }
        $files[$relative] = ['path' => $entry->getPathname(), 'size' => $size, 'sha256' => $digest];
    }
    ksort($files, SORT_STRING);

    if ($validateRequired) {
        foreach ($definition['required'] as $requiredFile) {
            if (!isset($files[$requiredFile])) {
                fail("{$pluginId} plugin tree is incomplete; missing {$requiredFile}.");
            }
        }
    }
    $main = file_get_contents($files['main.inc.php']['path']);
    if (
        !is_string($main)
        || preg_match('/^Version:\s*([^\r\n]+)$/mi', $main, $matches) !== 1
        || trim($matches[1]) !== $definition['version']
    ) {
        fail("{$pluginId} plugin header does not match the locked version.");
    }
    return $files;
}

/** @param array<string, array{path: string, size: int, sha256: string}> $files */
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

/** @param array<string, array{path: string, size: int, sha256: string}> $files */
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
        if ($child !== '.' && $child !== '..') {
            removeTree($path . '/' . $child);
        }
    }
    if (!rmdir($path)) {
        fail("Cannot remove staging directory: {$path}");
    }
}

/** @return array<string, mixed> */
function readMarker(string $destination, string $pluginId): array
{
    $path = $destination . '/' . CLASS_ARCHIVE_PLUGIN_MARKER;
    $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        fail("Installed {$pluginId} has no trusted source marker; refusing to overwrite it.");
    }
    $marker = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    if (
        !is_array($marker) || array_is_list($marker) || ($marker['format'] ?? null) !== 1
        || ($marker['id'] ?? null) !== $pluginId || !is_string($marker['treeDigest'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $marker['treeDigest']) !== 1
    ) {
        fail("Installed {$pluginId} source marker is invalid.");
    }
    $markerVersion = $marker['version'] ?? null;
    if ($markerVersion !== null && $markerVersion !== pluginDefinition($pluginId)['version']) {
        fail("Installed {$pluginId} source marker has an unexpected version.");
    }
    return $marker;
}

function writeMarker(string $destination, string $pluginId, string $digest): void
{
    $payload = json_encode(
        [
            'format' => 1,
            'id' => $pluginId,
            'version' => pluginDefinition($pluginId)['version'],
            'treeDigest' => $digest,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n";
    $path = $destination . '/' . CLASS_ARCHIVE_PLUGIN_MARKER;
    if (file_put_contents($path, $payload, LOCK_EX) !== strlen($payload) || !chmod($path, 0644)) {
        fail("Cannot write the {$pluginId} source marker.");
    }
}

function activatePlugin(string $pluginId): void
{
    $definition = pluginDefinition($pluginId);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '/workspace/infra/scripts/activate-class-archive-policy.php', $pluginId],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail("Cannot start isolated activation for {$pluginId}.");
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("{$pluginId} activation failed: " . trim((string) $stderr));
    }
    $expected = "ACTIVATED {$pluginId} {$definition['version']}";
    if (!str_contains((string) $stdout, $expected)) {
        fail("{$pluginId} activation returned an unexpected result.");
    }
}

/** Direct SQL verification cannot activate, migrate, repair, or write state. */
function verifyPluginState(string $pluginId): void
{
    $definition = pluginDefinition($pluginId);
    $verificationCode = <<<'PHP'
$pluginId = $argv[1] ?? '';
$expectedVersion = $argv[2] ?? '';
if (!preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/D', $pluginId) || $expectedVersion === '') {
    fwrite(STDERR, "Invalid verification input.\n");
    exit(2);
}
$conf = [];
$prefixeTable = null;
require '/var/www/html/piwigo/local/config/database.inc.php';
if (!is_string($prefixeTable) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) || ($conf['dblayer'] ?? null) !== 'mysqli') {
    fwrite(STDERR, "Plugin database configuration is unavailable.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$database = @new mysqli((string) ($conf['db_host'] ?? ''), (string) ($conf['db_user'] ?? ''), (string) ($conf['db_password'] ?? ''), (string) ($conf['db_base'] ?? ''));
if ($database->connect_errno !== 0 || !$database->set_charset('utf8mb4')) {
    fwrite(STDERR, "Plugin database connection is unavailable.\n");
    exit(2);
}
$statement = $database->prepare("SELECT id, version, state FROM `{$prefixeTable}plugins` WHERE id = ?");
if ($statement === false) {
    fwrite(STDERR, "Plugin runtime state query is unavailable.\n");
    exit(2);
}
$statement->bind_param('s', $pluginId);
if (!$statement->execute()) {
    fwrite(STDERR, "Plugin runtime state query failed.\n");
    exit(2);
}
$result = $statement->get_result();
$rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
$statement->close();
$database->close();
if (count($rows) !== 1 || $rows[0]['state'] !== 'active' || $rows[0]['version'] !== $expectedVersion) {
    fwrite(STDERR, "Plugin runtime state is not active at the expected version.\n");
    exit(1);
}
fwrite(STDOUT, "VERIFIED_STATE {$pluginId} {$expectedVersion}\n");
PHP;

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $verificationCode, '--', $pluginId, $definition['version']],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail("Cannot start isolated state verification for {$pluginId}.");
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("{$pluginId} runtime state verification failed: " . trim((string) $stderr));
    }
    if (!str_contains((string) $stdout, "VERIFIED_STATE {$pluginId} {$definition['version']}")) {
        fail("{$pluginId} runtime state verification returned an unexpected result.");
    }
}

function installPlugin(string $pluginId, bool $verifyOnly): string
{
    $definition = pluginDefinition($pluginId);
    $source = CLASS_ARCHIVE_PLUGIN_SOURCE_ROOT . '/' . $pluginId;
    $destinationBase = CLASS_ARCHIVE_PIWIGO_ROOT . '/plugins';
    $destination = $destinationBase . '/' . $pluginId;
    $sourceFiles = scanTree($source, $pluginId);
    $sourceDigest = treeDigest($sourceFiles);

    if (is_dir($destination) && !is_link($destination)) {
        $marker = readMarker($destination, $pluginId);
        // An older valid release may naturally lack a file newly required by
        // the incoming manifest. Hash it as installed state, then converge via
        // atomic replacement; current-required validation applies to source,
        // staging and the final verification pass.
        $installedDigest = treeDigest(scanTree($destination, $pluginId, true, false));
        if (!hash_equals((string) $marker['treeDigest'], $installedDigest)) {
            if ($verifyOnly || !hash_equals($sourceDigest, $installedDigest)) {
                fail("Installed {$pluginId} tree drifted from its trusted marker.");
            }
            writeMarker($destination, $pluginId, $sourceDigest);
        }
        if (hash_equals($sourceDigest, $installedDigest)) {
            if ($verifyOnly) {
                verifyPluginState($pluginId);
            } else {
                activatePlugin($pluginId);
            }
            return "VERIFIED {$pluginId} {$definition['version']}";
        }
        if ($verifyOnly) {
            fail("Installed {$pluginId} differs from the tracked source.");
        }
    } elseif (file_exists($destination) || is_link($destination)) {
        fail("{$pluginId} destination is not a safe directory.");
    } elseif ($verifyOnly) {
        fail("{$pluginId} is not installed.");
    }

    $baseResolved = realpath($destinationBase);
    if ($baseResolved === false || !is_dir($baseResolved) || is_link($destinationBase)) {
        fail('Piwigo plugin destination root is unsafe or missing.');
    }
    $suffix = bin2hex(random_bytes(8));
    $staging = $destinationBase . '/.class-archive-' . strtolower($pluginId) . '-stage-' . $suffix;
    $backup = $destinationBase . '/.class-archive-' . strtolower($pluginId) . '-backup-' . $suffix;
    $hadDestination = is_dir($destination) && !is_link($destination);
    try {
        copyTree($sourceFiles, $staging);
        writeMarker($staging, $pluginId, $sourceDigest);
        if (!hash_equals($sourceDigest, treeDigest(scanTree($staging, $pluginId, true)))) {
            fail("Staged {$pluginId} digest mismatch.");
        }
        if ($pluginId === 'ClassIdentity') {
            // Presenter safety is an attestation over the exact installed
            // rendering bytes. Reset only when a different ClassIdentity tree
            // is actually about to replace the current one; same-digest
            // activation/restart deliberately preserves a prior true result.
            resetAnonymousPresenterReadiness();
        }
        if ($hadDestination && !rename($destination, $backup)) {
            fail("Cannot stage the prior {$pluginId} for rollback.");
        }
        if (!rename($staging, $destination)) {
            if ($hadDestination) {
                rename($backup, $destination);
            }
            fail("Cannot atomically publish {$pluginId}.");
        }
        try {
            activatePlugin($pluginId);
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
        return "INSTALLED {$pluginId} {$definition['version']}";
    } finally {
        if (file_exists($staging) || is_link($staging)) {
            removeTree($staging);
        }
    }
}

/** Change one allowlisted boolean without loading plugin hooks or secrets. */
function setClassIdentityBooleanConfig(string $parameter, bool $enabled): void
{
    if (!in_array($parameter, ['class_identity_enforcement', 'class_identity_anon_presenter_ready'], true)) {
        fail('Refusing a ClassIdentity configuration key outside the installer allowlist.');
    }
    $state = $enabled ? 'true' : 'false';
    $code = <<<'PHP'
$parameter = $argv[1] ?? '';
$state = $argv[2] ?? '';
if (!in_array($parameter, ['class_identity_enforcement', 'class_identity_anon_presenter_ready'], true)
    || !in_array($state, ['true', 'false'], true)
) {
    fwrite(STDERR, "Invalid ClassIdentity configuration request.\n");
    exit(2);
}
$conf = [];
$prefixeTable = null;
require '/var/www/html/piwigo/local/config/database.inc.php';
if (!is_string($prefixeTable) || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) || ($conf['dblayer'] ?? null) !== 'mysqli') {
    fwrite(STDERR, "ClassIdentity database configuration is unavailable.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$database = @new mysqli((string) ($conf['db_host'] ?? ''), (string) ($conf['db_user'] ?? ''), (string) ($conf['db_password'] ?? ''), (string) ($conf['db_base'] ?? ''));
if ($database->connect_errno !== 0 || !$database->set_charset('utf8mb4')) {
    fwrite(STDERR, "ClassIdentity database connection is unavailable.\n");
    exit(2);
}
$table = $prefixeTable . 'config';
$statement = $database->prepare("INSERT INTO `{$table}` (`param`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
if ($statement === false) {
    fwrite(STDERR, "ClassIdentity state update is unavailable.\n");
    exit(2);
}
$statement->bind_param('ss', $parameter, $state);
if (!$statement->execute() || $statement->affected_rows < 0) {
    fwrite(STDERR, "ClassIdentity state update failed.\n");
    exit(2);
}
$statement->close();
$verify = $database->prepare("SELECT `value` FROM `{$table}` WHERE `param` = ?");
if ($verify === false) {
    fwrite(STDERR, "ClassIdentity state verification is unavailable.\n");
    exit(2);
}
$verify->bind_param('s', $parameter);
if (!$verify->execute()) {
    fwrite(STDERR, "ClassIdentity state verification failed.\n");
    exit(2);
}
$rows = $verify->get_result()->fetch_all(MYSQLI_ASSOC);
$verify->close();
$database->close();
if (count($rows) !== 1 || ($rows[0]['value'] ?? null) !== $state) {
    fwrite(STDERR, "ClassIdentity state did not converge.\n");
    exit(1);
}
fwrite(STDOUT, "CONFIG {$parameter} {$state}\n");
PHP;

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $code, '--', $parameter, $state],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail('Cannot start the isolated ClassIdentity configuration helper.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !str_contains((string) $stdout, "CONFIG {$parameter} {$state}")) {
        fail('Cannot set the allowlisted ClassIdentity configuration: ' . trim((string) $stderr));
    }
}

function setClassIdentityEnforcement(bool $enabled): void
{
    setClassIdentityBooleanConfig('class_identity_enforcement', $enabled);
}

function resetAnonymousPresenterReadiness(): void
{
    assertTrustedMaintenanceGate();
    setClassIdentityBooleanConfig('class_identity_anon_presenter_ready', false);
}

function runClassIdentityBootstrap(array $arguments, string $expectedOutput, string $operation): void
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '/workspace/infra/scripts/bootstrap-class-identity.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail("Cannot start isolated ClassIdentity {$operation}.");
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("ClassIdentity {$operation} failed: " . trim((string) $stderr));
    }
    if (!str_contains((string) $stdout, $expectedOutput)) {
        fail("ClassIdentity {$operation} returned an unexpected result.");
    }
}

function bootstrapClassIdentity(bool $withSyntheticFixtures): void
{
    $arguments = $withSyntheticFixtures ? ['--with-synthetic-fixtures'] : [];
    runClassIdentityBootstrap($arguments, 'CLASS_IDENTITY_BOOTSTRAPPED', 'bootstrap');
}

function verifyClassIdentityRuntime(bool $withSyntheticFixtures, bool $requireMaintenanceMarker): void
{
    $arguments = ['--verify-only'];
    if ($withSyntheticFixtures) {
        $arguments[] = '--with-synthetic-fixtures';
    }
    if ($requireMaintenanceMarker) {
        $arguments[] = '--require-maintenance-marker';
    }
    runClassIdentityBootstrap($arguments, 'CLASS_IDENTITY_RUNTIME_VERIFIED', 'runtime verification');
}

/** @return array{mode: string, with_synthetic_fixtures: bool} */
function parseInstallerArguments(array $arguments): array
{
    $mode = 'install';
    $withSyntheticFixtures = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--verify-only' && $mode === 'install') {
            $mode = 'verify';
            continue;
        }
        if ($argument === '--verify-runtime' && $mode === 'install') {
            $mode = 'verify-runtime';
            continue;
        }
        if ($argument === '--finalize-maintenance' && $mode === 'install') {
            $mode = 'finalize';
            continue;
        }
        if ($argument === '--with-synthetic-fixtures' && !$withSyntheticFixtures) {
            $withSyntheticFixtures = true;
            continue;
        }
        fail("Unknown, duplicate or conflicting installer argument: {$argument}");
    }

    return ['mode' => $mode, 'with_synthetic_fixtures' => $withSyntheticFixtures];
}

/**
 * @param list<string> $results
 */
function verifyInstalledRuntime(array &$results, bool $withSyntheticFixtures, bool $maintenanceOpen): void
{
    if ($maintenanceOpen) {
        assertTrustedMaintenanceGate();
    } else {
        assertMaintenanceGateClosed();
    }
    foreach (array_keys(CLASS_ARCHIVE_PLUGIN_MANIFEST) as $pluginId) {
        $results[] = installPlugin($pluginId, true);
    }
    verifyClassIdentityRuntime($withSyntheticFixtures, $maintenanceOpen);
    $results[] = 'VERIFIED ClassIdentity schema/principal runtime';
}

function main(array $arguments): void
{
    assertRuntimeUser();
    $options = parseInstallerArguments($arguments);
    $mode = $options['mode'];
    $withSyntheticFixtures = $options['with_synthetic_fixtures'];
    $results = [];
    if ($mode === 'verify') {
        verifyInstalledRuntime($results, $withSyntheticFixtures, false);
    } elseif ($mode === 'verify-runtime') {
        // This read-only gate is run only after the PHP-FPM container restart.
        // It deliberately leaves the exact maintenance marker in place.
        verifyInstalledRuntime($results, $withSyntheticFixtures, true);
        $results[] = 'MAINTENANCE READY_FOR_FINALIZE';
    } elseif ($mode === 'finalize') {
        // Independent process and repeated assertions: a prior successful
        // verifier result is never treated as an authorization token.
        verifyInstalledRuntime($results, $withSyntheticFixtures, true);
        closeMaintenanceGate();
        $results[] = 'MAINTENANCE FINALIZED';
    } else {
        // The durable web gate covers publication/restart. Enforcement remains
        // true while plugin bytes are replaced; only the marker-validated
        // ClassIdentity bootstrap process may briefly set it false.
        openMaintenanceGate();
        try {
            assertTrustedMaintenanceGate();
            setClassIdentityEnforcement(true);
            foreach (array_keys(CLASS_ARCHIVE_PLUGIN_MANIFEST) as $pluginId) {
                $results[] = installPlugin($pluginId, false);
            }
            bootstrapClassIdentity($withSyntheticFixtures);
            // Bootstrap already closes the window. Repeat the narrow setter so
            // this process independently verifies the durable state. The web
            // marker remains until a post-restart independent finalizer.
            setClassIdentityEnforcement(true);
            $results[] = 'MAINTENANCE PENDING_RESTART_VERIFICATION';
        } catch (Throwable $exception) {
            try {
                // Bootstrap owns the only bounded false window. Reassert true
                // after every install/bootstrap exception, even if this parent
                // process had previously observed true.
                setClassIdentityEnforcement(true);
            } catch (Throwable) {
                // Never remove the marker, even when enforcement restoration
                // fails. nginx remains the outer fail-closed boundary.
                throw new RuntimeException(
                    'Plugin installation failed; enforcement restoration also failed; maintenance remains active.',
                    0,
                    $exception,
                );
            }
            // Enforcement=true is defense in depth, never permission to remove
            // a marker after a failed install/bootstrap.
            throw $exception;
        }
    }
    foreach ($results as $result) {
        fwrite(STDOUT, $result . "\n");
    }
}

try {
    main($_SERVER['argv'] ?? []);
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
