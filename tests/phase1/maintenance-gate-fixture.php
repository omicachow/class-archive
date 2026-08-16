<?php

declare(strict_types=1);

/**
 * Namespace-bounded filesystem fixture for the tracked maintenance HTTP gate.
 *
 * The nginx marker content and path intentionally match the ClassIdentity
 * installer. A separate run-owned sidecar prevents cleanup from removing a
 * marker created by an installer, operator or another test process.
 */

const CI_MAINTENANCE_ROOT = '/var/www/html/piwigo';
const CI_MAINTENANCE_MARKER = CI_MAINTENANCE_ROOT . '/_data/.class-archive-maintenance';
const CI_MAINTENANCE_OWNER = CI_MAINTENANCE_ROOT . '/_data/.class-archive-maintenance-phase1-owner';
const CI_MAINTENANCE_CONTENT = "class-archive-identity-bootstrap\n";

function ciMaintenanceFail(string $message): never
{
    fwrite(STDERR, "MAINTENANCE_FIXTURE=ERROR {$message}\n");
    exit(2);
}

/** @param array<string, mixed> $payload */
function ciMaintenanceJson(array $payload): never
{
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

function ciMaintenanceAssertRuntime(): void
{
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
        ciMaintenanceFail('CLI POSIX runtime required.');
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
        ciMaintenanceFail('Fixture must run as the non-root nginx account.');
    }

    $data = realpath(CI_MAINTENANCE_ROOT . '/_data');
    if ($data !== CI_MAINTENANCE_ROOT . '/_data' || !is_dir($data) || is_link($data)) {
        ciMaintenanceFail('Persistent maintenance directory is unsafe.');
    }
}

function ciMaintenanceOwnerContent(string $runId): string
{
    return "CITEST:{$runId}\n";
}

function ciMaintenancePathState(string $path, ?string $expected = null): string
{
    clearstatcache(true, $path);
    if (is_link($path)) {
        return 'SYMLINK';
    }
    if (!file_exists($path)) {
        return 'ABSENT';
    }
    if (!is_file($path)) {
        return 'OTHER';
    }
    if ($expected === null) {
        return 'FILE';
    }
    $contents = file_get_contents($path);
    return is_string($contents) && hash_equals($expected, $contents) ? 'EXACT' : 'UNKNOWN';
}

function ciMaintenanceWriteExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('maintenance_fixture_create_failed');
    }
    $complete = false;
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('maintenance_fixture_lock_failed');
        }
        $written = fwrite($handle, $contents);
        if ($written !== strlen($contents) || !fflush($handle) || !chmod($path, 0600)) {
            throw new RuntimeException('maintenance_fixture_write_failed');
        }
        $complete = true;
    } finally {
        fclose($handle);
        if (!$complete) {
            @unlink($path);
        }
    }
}

function ciMaintenanceOpen(string $runId): never
{
    $owner = ciMaintenanceOwnerContent($runId);
    if (ciMaintenancePathState(CI_MAINTENANCE_MARKER) !== 'ABSENT'
        || ciMaintenancePathState(CI_MAINTENANCE_OWNER) !== 'ABSENT'
    ) {
        ciMaintenanceFail('Maintenance state is already owned or active.');
    }

    $ownerCreated = false;
    $markerCreated = false;
    try {
        ciMaintenanceWriteExclusive(CI_MAINTENANCE_OWNER, $owner);
        $ownerCreated = true;
        ciMaintenanceWriteExclusive(CI_MAINTENANCE_MARKER, CI_MAINTENANCE_CONTENT);
        $markerCreated = true;
        if (ciMaintenancePathState(CI_MAINTENANCE_OWNER, $owner) !== 'EXACT'
            || ciMaintenancePathState(CI_MAINTENANCE_MARKER, CI_MAINTENANCE_CONTENT) !== 'EXACT'
        ) {
            ciMaintenanceFail('Maintenance marker did not converge exactly.');
        }
    } catch (Throwable $error) {
        if ($markerCreated) {
            @unlink(CI_MAINTENANCE_MARKER);
        }
        if ($ownerCreated) {
            @unlink(CI_MAINTENANCE_OWNER);
        }
        throw $error;
    }

    ciMaintenanceJson(['state' => 'OPEN', 'owned' => true]);
}

function ciMaintenanceClose(string $runId): never
{
    $owner = ciMaintenanceOwnerContent($runId);
    $ownerState = ciMaintenancePathState(CI_MAINTENANCE_OWNER, $owner);
    if ($ownerState === 'ABSENT') {
        ciMaintenanceJson([
            'state' => ciMaintenancePathState(CI_MAINTENANCE_MARKER, CI_MAINTENANCE_CONTENT),
            'owned' => false,
            'closed' => false,
        ]);
    }
    if ($ownerState !== 'EXACT') {
        ciMaintenanceFail('Refusing cleanup without the exact run owner.');
    }
    if (ciMaintenancePathState(CI_MAINTENANCE_MARKER, CI_MAINTENANCE_CONTENT) !== 'EXACT') {
        ciMaintenanceFail('Refusing cleanup of a missing or changed maintenance marker.');
    }

    if (!unlink(CI_MAINTENANCE_MARKER)) {
        ciMaintenanceFail('Could not close the maintenance marker.');
    }
    clearstatcache(true, CI_MAINTENANCE_MARKER);
    if (file_exists(CI_MAINTENANCE_MARKER) || is_link(CI_MAINTENANCE_MARKER)) {
        ciMaintenanceFail('Maintenance marker remained after cleanup.');
    }
    if (!unlink(CI_MAINTENANCE_OWNER)) {
        ciMaintenanceFail('Maintenance owner sidecar remained after cleanup.');
    }

    ciMaintenanceJson(['state' => 'ABSENT', 'owned' => true, 'closed' => true]);
}

function ciMaintenanceStatus(string $runId): never
{
    ciMaintenanceJson([
        'marker' => ciMaintenancePathState(CI_MAINTENANCE_MARKER, CI_MAINTENANCE_CONTENT),
        'owner' => ciMaintenancePathState(CI_MAINTENANCE_OWNER, ciMaintenanceOwnerContent($runId)),
    ]);
}

function ciMaintenanceResolveMedia(): never
{
    if (!defined('PHPWG_VERSION') || PHPWG_VERSION !== '16.4.0') {
        ciMaintenanceFail('Locked Piwigo 16.4.0 runtime required.');
    }

    $rows = query2array(
        'SELECT DISTINCT i.id, i.path FROM ' . IMAGES_TABLE . ' i '
        . 'JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id = i.id '
        . 'JOIN ' . CATEGORIES_TABLE . ' c ON c.id = ic.category_id '
        . "WHERE c.permalink = 'fixture-living-reunion' ORDER BY i.id ASC LIMIT 1"
    );
    if (count($rows) !== 1) {
        ciMaintenanceFail('Synthetic unambiguous media fixture unavailable.');
    }
    $id = (int) ($rows[0]['id'] ?? 0);
    $path = preg_replace('#\A\./#', '', (string) ($rows[0]['path'] ?? ''));
    if ($id <= 0 || !is_string($path) || preg_match('#\Aupload/[A-Za-z0-9_./-]+\z#D', $path) !== 1
        || str_contains($path, '..') || str_contains($path, '//')
    ) {
        ciMaintenanceFail('Synthetic media reference is unsafe.');
    }
    $resolved = realpath(CI_MAINTENANCE_ROOT . '/' . $path);
    if (!is_string($resolved) || !str_starts_with($resolved, CI_MAINTENANCE_ROOT . '/upload/')) {
        ciMaintenanceFail('Synthetic media file is outside the upload root.');
    }

    ciMaintenanceJson(['image_id' => $id, 'original_path' => $path]);
}

$action = $argv[1] ?? '';
$runId = strtolower((string) ($argv[2] ?? ''));
if (!preg_match('/\A[a-f0-9]{12}\z/D', $runId)) {
    ciMaintenanceFail('A 12-hex run id is required.');
}
ciMaintenanceAssertRuntime();

// Piwigo's bootstrap creates global runtime state. Requiring common.inc.php
// inside a PHP function would incorrectly localize that state, so the one
// read-only resolve action is bootstrapped here at file scope.
if ($action === 'resolve') {
    if (!chdir(CI_MAINTENANCE_ROOT)) {
        ciMaintenanceFail('Cannot enter the Piwigo root.');
    }
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
}

try {
    match ($action) {
        'status' => ciMaintenanceStatus($runId),
        'open' => ciMaintenanceOpen($runId),
        'close' => ciMaintenanceClose($runId),
        'resolve' => ciMaintenanceResolveMedia(),
        default => ciMaintenanceFail('Unknown fixture action.'),
    };
} catch (Throwable $error) {
    ciMaintenanceFail('Fixture action failed [' . get_class($error) . '].');
}
