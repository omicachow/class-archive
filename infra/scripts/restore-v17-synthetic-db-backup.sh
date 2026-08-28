#!/usr/bin/env sh
# Restore a format-9 database-only fixture into the second, empty DB in the
# V4 synthetic laboratory. It never clears a target and never mounts media.

set -eu
umask 077
LC_ALL=C
export LC_ALL

fail() {
  printf '%s\n' "V17_SYNTHETIC_DB_RESTORE=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_V17_SYNTHETIC_RECOVERY:-}" = 1 ] || fail scope_confirmation_missing
[ "${CLASS_ARCHIVE_RUNTIME_SCOPE:-}" = SYNTHETIC_V4_MIGRATION ] || fail runtime_scope_invalid
[ "$(id -u)" = 0 ] || fail root_required
[ "${DB_HOST:-}" = v17-synthetic-recovery-db ] || fail target_host_invalid
case "${DB_NAME:-}" in ''|*[!A-Za-z0-9_]*) fail database_configuration_invalid ;; esac
case "${DB_ROOT_PASSWORD:-}" in ''|*[!A-Za-z0-9_-]*) fail database_configuration_invalid ;; esac
case "$CLASS_ARCHIVE_V17_SYNTHETIC_RESTORE_BUNDLE" in
  class-archive-v17-synthetic-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
  *) fail restore_bundle_invalid ;;
esac

. /workspace/infra/scripts/class-archive-recovery-contracts.sh
snapshot_root=/snapshot
[ -d "$snapshot_root" ] && [ ! -L "$snapshot_root" ] && [ "$(realpath "$snapshot_root")" = "$snapshot_root" ] || fail snapshot_root_untrusted
bundle="$snapshot_root/$CLASS_ARCHIVE_V17_SYNTHETIC_RESTORE_BUNDLE"
[ -d "$bundle" ] && [ ! -L "$bundle" ] && [ "$(realpath "$bundle")" = "$bundle" ] || fail restore_bundle_missing
expected_files='COMPLETE MANIFEST.json SHA256SUMS database.sql.gz'
actual_files=$(find "$bundle" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//')
[ "$actual_files" = "$expected_files" ] || fail restore_file_set_invalid
for name in COMPLETE MANIFEST.json SHA256SUMS database.sql.gz; do
  [ -f "$bundle/$name" ] && [ ! -L "$bundle/$name" ] || fail restore_file_untrusted
done

# Every manifest/schema check happens before a target query, import, or write.
# This restore intentionally requires an empty fresh DB rather than clearing
# anything, so an unknown contract cannot destroy a target by construction.
ca_recovery_select_v17_synthetic_manifest "$bundle/MANIFEST.json" || fail restore_business_manifest_invalid
expected_count=3
actual_count=$(wc -l < "$bundle/SHA256SUMS" | tr -d '[:space:]')
valid_count=$(grep -Ec '^[0-9a-f]{64}  (database\.sql\.gz|MANIFEST\.json|COMPLETE)$' "$bundle/SHA256SUMS" || true)
[ "$actual_count" = "$expected_count" ] && [ "$valid_count" = "$expected_count" ] || fail restore_manifest_invalid
(cd "$bundle" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || fail restore_checksum_failed
gzip -t "$bundle/database.sql.gz" || fail restore_dump_unreadable
if gzip -dc "$bundle/database.sql.gz" | grep -Eq '^INSERT INTO `[^`]+class_identity_(read_projection|read_photo)`'; then
  fail restore_projection_cache_present
fi
if ! gzip -dc "$bundle/database.sql.gz" \
     | grep -Eq '^CREATE TABLE `[^`]+class_identity_collection_snapshot`'; then
  fail restore_collection_domain_missing
fi

export MYSQL_PWD="$DB_ROOT_PASSWORD"
cleanup() { unset MYSQL_PWD || true; }
trap cleanup EXIT HUP INT TERM
existing_tables=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" -e 'SHOW TABLES' | wc -l | tr -d '[:space:]')
[ "$existing_tables" = 0 ] || fail target_database_not_empty
gzip -dc "$bundle/database.sql.gz" | mariadb --host="$DB_HOST" --user=root "$DB_NAME" || fail database_import_failed

set -- $(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ] || fail restored_migration_table_ambiguous
ci_migration=$2
case "$ci_migration" in ''|*[!A-Za-z0-9_]*) fail restored_migration_table_invalid ;; esac
ci_base=${ci_migration%migration}
ci_version=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COALESCE(MAX(version),0) FROM \`$ci_migration\`")
[ "$ci_version" = "$CA_RECOVERY_SCHEMA_VERSION" ] || fail restored_schema_not_v17
for suffix in $CA_RECOVERY_ALL_TABLES; do
  count=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$ci_base$suffix'")
  [ "$count" = 1 ] || fail restored_required_table_missing
done

pwg_base=${ci_base%class_identity_}
[ "$pwg_base" != "$ci_base" ] || fail restored_piwigo_prefix_invalid
epoch_table="${ci_base}native_source_epoch"
epoch_engine=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COALESCE(MAX(ENGINE),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$epoch_table' AND TABLE_TYPE='BASE TABLE'")
[ "$epoch_engine" = MyISAM ] || fail restored_native_epoch_invalid
set -- $(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(SUM(source_key='PIWIGO_NATIVE' AND OCTET_LENGTH(generation)=16),0) FROM \`$epoch_table\`")
[ "$#" = 2 ] && [ "$1" = 1 ] && [ "$2" = 1 ] || fail restored_native_epoch_invalid
native_guard_count=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('${pwg_base}ci_projection_images_bi','${pwg_base}ci_projection_images_bu','${pwg_base}ci_projection_images_bd','${pwg_base}ci_projection_image_category_bi','${pwg_base}ci_projection_image_category_bu','${pwg_base}ci_projection_image_category_bd','${pwg_base}ci_projection_categories_bi','${pwg_base}ci_projection_categories_bu','${pwg_base}ci_projection_categories_bd','${pwg_base}ci_source_epoch_images_bi','${pwg_base}ci_source_epoch_images_bu','${pwg_base}ci_source_epoch_images_bd','${pwg_base}ci_source_epoch_image_category_bi','${pwg_base}ci_source_epoch_image_category_bu','${pwg_base}ci_source_epoch_image_category_bd','${pwg_base}ci_source_epoch_categories_bi','${pwg_base}ci_source_epoch_categories_bu','${pwg_base}ci_source_epoch_categories_bd') AND ACTION_TIMING='BEFORE'")
[ "$native_guard_count" = 18 ] || fail restored_native_projection_guard_invalid

# The v17 snapshot domain is business truth and remains in the dump. Only
# cached read projections are seeded stale for a later application rebuild.
projection_meta="${ci_base}read_projection"
projection_photos="${ci_base}read_photo"
projection_rows=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*) FROM \`$projection_meta\`")
photo_rows=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*) FROM \`$projection_photos\`")
[ "$projection_rows" = 0 ] && [ "$photo_rows" = 0 ] || fail restored_projection_cache_present
mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "INSERT INTO \`$projection_meta\` (projection_key,state,generation) VALUES ('PHOTO_CATALOG','STALE',RANDOM_BYTES(16)),('TIMELINE','STALE',RANDOM_BYTES(16)),('ALBUMS','STALE',RANDOM_BYTES(16)),('PEOPLE','STALE',RANDOM_BYTES(16)),('MEMORIES','STALE',RANDOM_BYTES(16)),('SPOTLIGHT','STALE',RANDOM_BYTES(16))" \
  || fail projection_seed_failed
projection_seed_rows=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*) FROM \`$projection_meta\` WHERE state='STALE' AND source_revision IS NULL AND OCTET_LENGTH(generation)=16 AND native_source_generation IS NULL AND item_count=0 AND payload_json IS NULL AND payload_digest IS NULL AND dependency_revision IS NULL")
[ "$projection_seed_rows" = 6 ] || fail projection_seed_invalid

printf '%s\n' "V17_SYNTHETIC_DB_RESTORE=PASS format=9 schema=17 scope=DB_ONLY target=SECOND_EMPTY_DB projection_cache=STALE media=NOT_MOUNTED media_guard=NOT_CLAIMED"
