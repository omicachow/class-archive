<?php

declare(strict_types=1);

/**
 * Static boundary for the additive v17 -> v18 Spotlight rotation checkpoint.
 * Historical format-9/schema-17 recovery evidence stays immutable; v18 has a
 * separate semantic runtime fixture and never changes that contract in place.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'schema' => $root . '/plugins/ClassIdentity/src/Schema.php',
    'repository' => $root . '/plugins/ClassIdentity/src/Repository.php',
    'support' => $root . '/plugins/ClassIdentity/src/DomainSupport.php',
    'semantic' => $root . '/tests/phase3/spotlight-rotation-schema-semantics.php',
    'v17_contract' => $root . '/infra/scripts/class-archive-recovery-contracts.sh',
    'v17_runtime_contract' => $root . '/tests/phase3/v17-backup-restore-contract.php',
];
$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "SPOTLIGHT_ROTATION_MIGRATION_CONTRACT=FAIL missing={$name}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$assertions = 0;
$failures = [];
$privateSourcePathMarker = 'M' . chr(58) . chr(92) . 'private-media-root';
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};
$methodBody = static function (string $source, string $name): string {
    $start = strpos($source, 'private function ' . $name . '()');
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\n    private function ", $start + 1);
    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
};

$migration18 = $methodBody($source['schema'], 'migrationPhotosAppV4SpotlightRotationState');
$migration17 = $methodBody($source['schema'], 'migrationPhotosAppV4CollectionSnapshots');
$assert(str_contains($source['schema'], 'public const CURRENT_VERSION = 18;'), 'current_schema_version_not_v18');
$assert(str_contains($source['schema'], "17 => [") && str_contains($source['schema'], "'name' => '0017_photos_app_v4_collection_snapshots'")
    && str_contains($source['schema'], "'signature' => 'v1:versioned-role-scoped-collection-snapshots:atomic-active-pointer:retained-superseded-history:principal-pins-feedback:maintenance-watermark:innodb:utf8mb4'"), 'historical_v17_migration_mutated');
$assert(str_contains($source['schema'], "18 => [") && str_contains($source['schema'], "'name' => '0018_photos_app_v4_spotlight_rotation_state'")
    && str_contains($source['schema'], "'method' => 'migrationPhotosAppV4SpotlightRotationState'"), 'v18_migration_ledger_missing');
$assert($migration18 !== '' && str_contains($migration18, 'CREATE TABLE IF NOT EXISTS {$rotation}')
    && !preg_match('/\b(?:ALTER\s+TABLE|DROP\s+TABLE|DELETE\s+FROM|UPDATE\s+[^\n]+\s+SET|INSERT\s+INTO)\b/i', $migration18), 'v18_migration_not_additive_only');
$assert($migration17 !== '' && !str_contains($migration17, 'spotlight_rotation_state'), 'v17_snapshot_migration_rewritten');
$assert(str_contains($migration18, '`scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL')
    && str_contains($migration18, 'PRIMARY KEY (`scope`)')
    && str_contains($migration18, "`scope` IN ('FULL','HERITAGE')"), 'v18_scope_bound_missing');
$assert(str_contains($migration18, '`hero_spotlight_id` BINARY(16) NULL')
    && str_contains($migration18, 'FOREIGN KEY (`hero_spotlight_id`) REFERENCES {$spotlight} (`spotlight_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'), 'v18_hero_fk_fail_closed_missing');
$assert(str_contains($migration18, '`candidate_digest` BINARY(32) NOT NULL')
    && str_contains($migration18, '`revision` BINARY(32) NOT NULL')
    && str_contains($migration18, '`display_count` BIGINT UNSIGNED NOT NULL DEFAULT 0'), 'v18_digest_revision_count_missing');
$assert(str_contains($migration18, '`last_rotated_at` DATETIME(6) NULL')
    && str_contains($migration18, '`next_rotation_at` DATETIME(6) NOT NULL')
    && str_contains($migration18, "`next_rotation_at` > `last_rotated_at`"), 'v18_rotation_schedule_integrity_missing');
$assert(str_contains($migration18, 'idx_ci_spotlight_rotation_due')
    && str_contains($migration18, 'idx_ci_spotlight_rotation_hero')
    && str_contains($migration18, 'chk_ci_spotlight_rotation_display_count'), 'v18_bounded_lookup_constraints_missing');
$assert(str_contains($source['schema'], "'spotlight_rotation_state' => '7a5a1f7857e3a2678f8752d02d3e77f1e08d8323849276816726e3f6905f3b8b'"), 'v18_semantic_digest_missing');
$assert(str_contains($source['schema'], "'spotlight_rotation_state',")
    && str_contains($source['repository'], "'spotlight_rotation_state',")
    && str_contains($source['support'], "'spotlight_rotation_state',"), 'v18_table_boundary_not_registered');
$assert(str_contains($source['semantic'], 'SPOTLIGHT_ROTATION_V17_SUFFIXES')
    && str_contains($source['semantic'], 'migrationPhotosAppV4CollectionSnapshots')
    && str_contains($source['semantic'], 'migrationPhotosAppV4SpotlightRotationState')
    && str_contains($source['semantic'], 'spotlight_rotation_v17_semantics_mutated_'), 'v17_to_v18_semantic_fixture_missing');
$assert(str_contains($source['semantic'], 'spotlight_rotation_migration_not_idempotent')
    && str_contains($source['semantic'], 'hero_delete_restrict')
    && str_contains($source['semantic'], 'scope_primary_key')
    && str_contains($source['semantic'], 'schedule'), 'v18_constraint_fixture_missing');
$assert(str_contains($source['v17_contract'], 'CA_RECOVERY_FORMAT=9')
    && str_contains($source['v17_contract'], 'CA_RECOVERY_SCHEMA_VERSION=17')
    && !str_contains($source['v17_contract'], 'spotlight_rotation_state'), 'historical_v17_recovery_contract_mutated');
$assert(str_contains($source['v17_runtime_contract'], 'format9_schema17_contract_missing')
    && !str_contains($source['v17_runtime_contract'], 'spotlight_rotation_state'), 'historical_v17_runtime_evidence_mutated');

foreach ($source as $name => $contents) {
    if ($name === 'v17_runtime_contract') {
        continue;
    }
    $assert(preg_match('/[A-Za-z]:\\\\/', $contents) !== 1, "{$name}_contains_windows_path");
    $assert(!str_contains($contents, $privateSourcePathMarker), "{$name}_contains_private_source_path");
}

if ($failures !== []) {
    fwrite(STDERR, 'SPOTLIGHT_ROTATION_MIGRATION_CONTRACT=FAIL assertions=' . $assertions
        . ' failures=' . implode('; ', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "SPOTLIGHT_ROTATION_MIGRATION_CONTRACT=PASS assertions={$assertions}\n");
