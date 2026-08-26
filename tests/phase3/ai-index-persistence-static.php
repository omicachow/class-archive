<?php

declare(strict_types=1);

/**
 * Source-level contract for the checksum-bound AI index control plane.
 *
 * This intentionally uses no database, Immich endpoint, model cache or media
 * fixture. Runtime inference belongs to the separately isolated private
 * worker; this gate protects the public-safe control-plane contract.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'service' => $root . '/plugins/ClassIdentity/src/AiIndexService.php',
    'main' => $root . '/plugins/ClassIdentity/main.inc.php',
    'importer' => $root . '/infra/scripts/import-private-real-full.php',
    'maintenance' => $root . '/infra/scripts/run-maintenance.php',
    'reconciliation' => $root . '/plugins/ClassIdentity/src/ReconciliationService.php',
    'backup_evidence' => $root . '/plugins/ClassIdentity/src/BackupRestoreEvidence.php',
    'fixture' => $root . '/infra/scripts/capture-restore-fixture.php',
    'compose' => $root . '/infra/docker-compose.yml',
    'restore' => $root . '/infra/scripts/restore-backup.sh',
    'audit' => $root . '/infra/scripts/audit-backup.sh',
    'drill' => $root . '/infra/scripts/backup-restore-drill.ps1',
];

$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "AI_INDEX_PERSISTENCE=FAIL missing={$name}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};
$functionBody = static function (string $php, string $name): string {
    $start = strpos($php, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $brace = strpos($php, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $length = strlen($php);
    for ($i = $brace; $i < $length; ++$i) {
        if ($php[$i] === '{') {
            ++$depth;
        } elseif ($php[$i] === '}' && --$depth === 0) {
            return substr($php, $brace, $i - $brace + 1);
        }
    }
    return '';
};

$assert(str_contains($source['main'], "'src/AiIndexService.php'"), 'plugin bootstrap does not load AiIndexService');
foreach (['PENDING', 'INDEXED', 'UNAVAILABLE', 'FAILED', 'STALE', 'REMOVED'] as $state) {
    $assert(str_contains($source['service'], "'{$state}'"), "asset state {$state} missing");
}
foreach (['INDEX_ASSET', 'DELETE_ASSET', 'REINDEX_MODEL'] as $kind) {
    $assert(str_contains($source['service'], "'{$kind}'"), "job kind {$kind} missing");
}
foreach (['NEW_PHOTO', 'PIXEL_CHANGED', 'PHOTO_DELETED', 'MODEL_CHANGED', 'ADMIN_REINDEX', 'RECONCILIATION'] as $trigger) {
    $assert(str_contains($source['service'], "'{$trigger}'"), "trigger {$trigger} missing");
}
foreach (['PENDING', 'RUNNING', 'UNAVAILABLE', 'FAILED', 'COMPLETE', 'CANCELLED'] as $state) {
    $assert(str_contains($source['service'], "JOB_{$state}"), "job state {$state} missing");
}

$assert(str_contains($source['service'], 'public function enqueueNewPhoto'), 'new photo enqueue contract missing');
$assert(str_contains($source['service'], 'public function enqueuePixelChange'), 'pixel change enqueue contract missing');
$assert(str_contains($source['service'], 'public function enqueuePhotoDeletion'), 'photo deletion enqueue contract missing');
$assert(str_contains($source['service'], 'public function enqueueModelChange'), 'model change enqueue contract missing');
$assert(str_contains($source['service'], 'public function requestAdminReindex'), 'admin reindex contract missing');
$assert(str_contains($source['service'], 'public function enqueueReconciliation'), 'reconciliation enqueue contract missing');
$assert(str_contains($source['service'], 'AI_INDEX_REINDEX_REQUESTED'), 'admin reindex is not auditable');
$assert(str_contains($source['service'], 'uq_ci_ai_index_job_active_photo_kind') || str_contains($source['service'], 'ensureJob'), 'idempotent active job contract missing');
$assert(str_contains($source['service'], 'hash_equals($checksum, (string) $photo[\'media_checksum\'])'), 'completion does not verify checksum');
$assert(str_contains($source['service'], 'class_archive_ai_index_job_checksum_drift'), 'checksum drift is not fail-closed');

foreach (['status', 'maintenanceReport'] as $method) {
    $body = $functionBody($source['service'], $method);
    $assert($body !== '', "{$method} method missing");
    $assert(!str_contains($body, '$this->enqueue'), "{$method} must be read-only and never enqueue");
    $assert(!str_contains($body, 'claimNextJob'), "{$method} must not start a worker");
}
foreach (['curl_', 'file_get_contents(', 'fopen(', 'copy(', 'hash_file(', 'exec(', 'shell_exec('] as $forbidden) {
    $assert(!str_contains($source['service'], $forbidden), "AI service must not access runtime media/network: {$forbidden}");
}
$assert(str_contains($source['service'], 'CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER'), 'private worker must be explicit, not auto-discovered');
$assert(str_contains($source['service'], 'requirePrivateWorker'), 'job worker actions must reject an unconfigured runtime');
$assert(str_contains($source['service'], 'UNAVAILABLE'), 'unconfigured worker is not explicit');
$statusBody = $functionBody($source['service'], 'status');
$assert(str_contains($statusBody, "'DEGRADED'") && str_contains($statusBody, '$failedAssets')
    && str_contains($statusBody, '$failedJobs') && str_contains($statusBody, '$terminalAssetAnomalies'),
    'terminal AI failures may still be reported READY');

$importFinish = strpos($source['importer'], '$library->finishImport');
$importQueue = strpos($source['importer'], '$aiIndex->enqueueImportedActivePhotos()');
$assert($importFinish !== false && $importQueue !== false && $importQueue > $importFinish, 'AI import catch-up must occur after terminal import state');
$assert(str_contains($source['importer'], 'metadata-only, idempotent post-import hook'), 'import hook does not document safe behavior');
$assert(str_contains($source['maintenance'], 'AiIndexService::fromPiwigo()->maintenanceReport()'), 'maintenance does not report AI index state');
$assert(!str_contains($source['maintenance'], 'AiIndexService::fromPiwigo()->enqueue'), 'maintenance must not enqueue AI work automatically');

foreach (['AI_INDEX_MAPPING_MISSING', 'AI_INDEX_CHECKSUM_DRIFT', 'AI_INDEX_RETIRED_TARGET_DRIFT', 'AI_INDEX_JOB_TARGET_DRIFT'] as $code) {
    $assert(str_contains($source['reconciliation'], "'{$code}'"), "reconciliation finding {$code} missing");
}
$assert(!str_contains($source['reconciliation'], 'DELETE FROM'), 'reconciliation may not delete AI/media state');
$assert(!str_contains($source['reconciliation'], 'UPDATE `'), 'reconciliation may not repair AI/media state');

foreach (['photo_comment', 'auto_collection', 'auto_collection_photo', 'ai_asset_index', 'ai_index_job'] as $table) {
    $assert(str_contains($source['fixture'], "'{$table}' =>"), "restore fixture omits {$table}");
    $assert(str_contains($source['compose'], '"' . $table . '"'), "backup manifest omits {$table}");
    $assert(str_contains($source['restore'], '"' . $table . '"'), "restore verifier omits {$table}");
    $assert(str_contains($source['audit'], '"' . $table . '"'), "backup audit omits {$table}");
    $assert(str_contains($source['drill'], "'{$table}'"), "restore drill omits {$table}");
}
$assert(str_contains($source['fixture'], "'fixture_version' => 8"), 'restore fixture does not bind v8');
$assert(str_contains($source['fixture'], "'class_identity_schema_version' => 16"), 'restore fixture does not bind schema v16');
$assert(str_contains($source['backup_evidence'], 'public const VERSION = 8'), 'backup evidence does not invalidate old proof');
$assert(str_contains($source['backup_evidence'], 'public const BACKUP_MANIFEST_FORMAT = 8'), 'backup evidence does not bind manifest v8');
$assert(str_contains($source['backup_evidence'], '/workspace/plugins/ClassIdentity/src/AiIndexService.php'), 'backup evidence digest omits AI control plane');
$assert(str_contains($source['fixture'], 'body_sha256') && !str_contains($source['fixture'], '`body` FROM'), 'restore fixture must not export comment plaintext');
$windowsAbsolutePathPattern = '/[A-Za-z]:\\\\/';
foreach ($source as $name => $contents) {
    $assert(preg_match($windowsAbsolutePathPattern, $contents) !== 1, "{$name} contains a private source path");
    $assert(!str_contains($contents, '127.0.0.1:8191'), "{$name} couples AI control plane to the private UI port");
}

if ($failures !== []) {
    fwrite(STDERR, 'AI_INDEX_PERSISTENCE=FAIL assertions=' . $assertions . ' failures=' . implode('; ', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "AI_INDEX_PERSISTENCE=PASS assertions={$assertions}\n");
