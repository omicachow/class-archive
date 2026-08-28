#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(process.cwd());
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const fail = (code) => {
  console.error(`COLLECTION_SNAPSHOT_STATIC=FAIL code=${code}`);
  process.exit(1);
};
const check = (value, code) => {
  if (!value) fail(code);
};

const schema = read('plugins/ClassIdentity/src/Schema.php');
const repository = read('plugins/ClassIdentity/src/Repository.php');
const support = read('plugins/ClassIdentity/src/DomainSupport.php');
const service = read('plugins/ClassIdentity/src/CollectionSnapshotService.php');
const gateway = read('plugins/ClassIdentity/src/Gateway/GatewayService.php');
const controller = read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php');
const bootstrap = read('plugins/ClassIdentity/main.inc.php');
const snapshot = read('infra/scripts/create-pre-migration-db-snapshot.sh');
const privateDeployment = read('infra/scripts/deploy-private-full-class-plugins.ps1');
const supplementalTarget = read('infra/scripts/verify-private-real-supplemental-target.php');
const legacyRestoreVerifier = read('infra/scripts/verify-owner-restore-post-migration.php');
const v17RestoreVerifier = read('infra/scripts/verify-owner-restore-post-migration-v17.php');

check(schema.includes("17 => [") && schema.includes("0017_photos_app_v4_collection_snapshots"), 'migration17_missing');
check(schema.includes('public const CURRENT_VERSION = 18;'), 'current_version18_missing');
check(schema.includes("18 => [") && schema.includes("0018_photos_app_v4_spotlight_rotation_state"), 'migration18_missing');
check(!schema.includes("15 => [\n                'name' => '0015_collections_first_comments_ai_index'\n                'signature' => 'v3"), 'old_migration_signature_mutated');
for (const table of [
  'collection_snapshot',
  'collection_snapshot_item',
  'collection_snapshot_pointer',
  'collection_pin',
  'collection_feedback',
  'collection_maintenance_state',
]) {
  check(schema.includes(`'${table}'`), `schema_table_${table}_missing`);
  check(repository.includes(`'${table}'`), `repository_table_${table}_missing`);
  check(support.includes(`'${table}'`), `domain_support_table_${table}_missing`);
}
check(schema.includes("'collection_snapshot' => '") && !schema.includes("'collection_snapshot' => '000000"), 'semantic_digest_placeholder');
check(snapshot.includes('14:15|15:16|16:17'), 'pre_migration_16_to_17_missing');
check(privateDeployment.includes('$migrationSourceVersion = 16') && privateDeployment.includes('$migrationTargetVersion = 17'), 'private_deployment_16_to_17_missing');
check(supplementalTarget.includes('Schema::CURRENT_VERSION !== 17') && supplementalTarget.includes("['schema_version'] ?? 0) !== 17"), 'supplemental_target_v17_missing');
check(legacyRestoreVerifier.includes('v16-only') && legacyRestoreVerifier.includes('CURRENT_VERSION !== 16'), 'legacy_restore_verifier_not_versioned');
check(v17RestoreVerifier.includes('CURRENT_VERSION !== 17') && v17RestoreVerifier.includes('CLASS_ARCHIVE_OWNER_RESTORE_V17_VERIFY'), 'v17_restore_verifier_missing');
check(bootstrap.includes("src/CollectionSnapshotService.php"), 'service_bootstrap_missing');

for (const needle of [
  'function publish(',
  'function publishBundle(',
  'function activeSnapshot(',
  'function pin(',
  'function unpin(',
  'function reorderPins(',
  'function setFeedback(',
  'function clearFeedback(',
  'function claimMaintenance(',
  'function completeMaintenance(',
  'class_archive_collection_snapshot_unavailable',
  'class_archive_collection_snapshot_digest_mismatch',
  'SUPERSEDED',
  'currentAclRecheck',
  'class_archive_collection_snapshot_bundle_invalid',
]) {
  check(service.includes(needle), `service_contract_${needle.replaceAll(/[^A-Za-z0-9]+/g, '_')}_missing`);
}
check(!service.includes('visiblePhotos('), 'service_must_not_evaluate_gateway_policy');
check(gateway.includes('function collectionsHome()') && gateway.includes('recheckCollectionSnapshotItem'), 'gateway_collection_acl_hook_missing');
check(gateway.includes("class_archive_collection_snapshot_stale") && gateway.includes('requireCurrentCollectionSnapshot'), 'gateway_collection_snapshot_freshness_missing');
check(gateway.includes('function collectionPins()') && gateway.includes('function setCollectionFeedback('), 'gateway_collection_mutations_missing');
check(service.includes("'projectionKind' => $projectionKind") && service.includes('reorder client'), 'collection_pin_public_projection_kind_missing');
for (const route of [
  "['collections', 'home']",
  "['collections', 'state']",
  "['collections', 'pins']",
  "'collections/pins/create'",
  "'collections/pins/remove'",
  "'collections/pins/reorder'",
  "'collections/feedback/set'",
  "'collections/feedback/clear'",
]) {
  check(controller.includes(route), `controller_route_${route.replaceAll(/[^A-Za-z0-9]+/g, '_')}_missing`);
}
check(!controller.includes('server.mjs'), 'controller_must_not_rewrite_bff');

console.log('COLLECTION_SNAPSHOT_STATIC=PASS assertions=43');
