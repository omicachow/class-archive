<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('PHPWG_ROOT_PATH', $root . '/');
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';

$sourcePath = $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
$sourceLines = file($sourcePath);
if (!is_array($sourceLines)) {
    throw new RuntimeException('presentation_epoch_source_unavailable');
}

$methodSource = static function (string $method) use ($sourceLines): string {
    $reflection = new ReflectionMethod(\ClassIdentity\Gateway\ReadProjectionStore::class, $method);
    return implode('', array_slice(
        $sourceLines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $label;
    }
};

$epoch = $methodSource('presentationEpoch');
$metadata = $methodSource('presentationAggregateBindings');
$aggregate = $methodSource('readAggregate');
$catalogState = $methodSource('activeCatalogState') . $methodSource('assertCatalogStateCurrent');
$aggregateState = $methodSource('assertAggregateStateCurrent');

$assert(str_contains($epoch, '$this->activeCatalogState()'), 'catalog_metadata_not_captured');
$assert(substr_count($epoch, '$this->presentationAggregateBindings(') === 2, 'aggregate_metadata_not_double_snapshotted');
$assert(!str_contains($epoch, '$this->readAggregate('), 'epoch_still_reads_aggregate_payload');
$assert(!str_contains($epoch, 'payload_json') && !str_contains($epoch, 'json_decode'), 'epoch_direct_payload_dependency_present');
$assert(str_contains($epoch, '$this->invokeReadValidationHook(\'AGGREGATE:\' . $kind)'), 'per_kind_race_checkpoint_missing');
$assert(str_contains($epoch, '$this->assertAggregateStateCurrent($kind, $aggregateBindings[$kind])'), 'per_kind_aggregate_recheck_missing');
$assert(str_contains($epoch, '$this->assertCatalogStateCurrent($catalog)'), 'per_kind_catalog_recheck_missing');
$assert(str_contains($epoch, '$this->assertCatalogBindingsEqual($catalog, $currentCatalog)'), 'catalog_final_recheck_missing');
$assert(str_contains($epoch, '$this->assertAggregateBindingsEqual($binding, $currentAggregateBindings[$kind])'), 'aggregate_final_recheck_missing');

$assert(!str_contains($metadata, 'payload_json') && !str_contains($metadata, 'json_decode'), 'metadata_reader_fetches_or_decodes_payload');
$assert(!str_contains($catalogState, 'payload_json') && !str_contains($catalogState, 'json_decode'), 'catalog_validation_fetches_or_decodes_payload');
$assert(!str_contains($aggregateState, 'payload_json') && !str_contains($aggregateState, 'json_decode'), 'aggregate_recheck_fetches_or_decodes_payload');
$assert(str_contains($metadata, '`projection_key`,`state`,`source_revision`,`generation`,`item_count`,`payload_digest`,`dependency_revision`'), 'metadata_select_contract_incomplete');
foreach (['TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'] as $kind) {
    $assert(str_contains($metadata, "'{$kind}'"), 'metadata_kind_missing_' . strtolower($kind));
}
$assert(str_contains($metadata, 'count($byKind) !== count(self::AGGREGATE_KINDS)'), 'complete_kind_set_validation_missing');
$assert(str_contains($metadata, '($row[\'state\'] ?? null) !== \'ACTIVE\''), 'active_state_validation_missing');
$assert(str_contains($metadata, 'self::aggregateDependencyRevision($kind'), 'dependency_revision_validation_missing');
$assert(str_contains($metadata, 'hash(\'sha256\', $expectedDependency . (string) $row[\'payload_digest\'], true)'), 'source_revision_validation_missing');
$assert(str_contains($metadata, 'hash_equals($expectedDependency'), 'dependency_digest_comparison_missing');
$assert(str_contains($metadata, 'hash_equals($sourceRevision'), 'source_digest_comparison_missing');

$assert(str_contains($epoch, "'version' => 1") && str_contains($epoch, "'scope' => \$scope"), 'epoch_version_or_scope_changed');
$assert(str_contains($epoch, '$this->catalogBindingForDigest($catalog)'), 'catalog_epoch_material_changed');
$assert(str_contains($epoch, '$this->aggregateBindingForDigest($aggregateBindings[$kind])'), 'aggregate_epoch_material_changed');
$assert(str_contains($epoch, 'return hash(') && str_contains($epoch, 'JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR'), 'epoch_hash_contract_changed');
$assert(str_contains($aggregate, '`payload_json`') && str_contains($aggregate, 'json_decode('), 'payload_read_integrity_path_removed');

if ($failures !== []) {
    fwrite(STDERR, 'PRESENTATION_EPOCH_STATIC=FAIL assertions=' . $assertions . ' failures=' . implode(',', $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'PRESENTATION_EPOCH_STATIC=PASS assertions=' . $assertions . "\n");
