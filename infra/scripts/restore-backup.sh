#!/usr/bin/env sh
set -eu

# This is deliberately a restore-drill tool, not a general production restore
# button. It requires two exact synthetic-only acknowledgements, verifies a
# complete bundle before touching an exact list of mounted volumes, and never
# mounts or modifies the backup volume.

umask 077
LC_ALL=C
export LC_ALL

fail() {
  printf '%s\n' "BACKUP_RESTORE=FAILED code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_RESTORE_DRILL:-}" = true ] || fail restore_drill_not_enabled
[ "${CLASS_ARCHIVE_RESTORE_CONFIRM:-}" = RESTORE_SYNTHETIC_FIXTURE_ONLY ] || fail restore_confirmation_missing
[ "$(id -u)" = 0 ] || fail restore_requires_root
case "${CLASS_ARCHIVE_RESTORE_BUNDLE:-}" in
  class-archive-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
  *) fail restore_bundle_invalid ;;
esac

bundle="/backup/$CLASS_ARCHIVE_RESTORE_BUNDLE"
[ -d "$bundle" ] && [ ! -L "$bundle" ] || fail restore_bundle_missing
for name in database.sql.gz piwigo-data.tar.gz uploads.tar.gz galleries.tar.gz scripts.tar.gz COMPLETE MANIFEST.json SHA256SUMS; do
  [ -f "$bundle/$name" ] && [ ! -L "$bundle/$name" ] || fail restore_bundle_incomplete
done

manifest="$bundle/SHA256SUMS"
expected_count=7
actual_count=$(wc -l < "$manifest" | tr -d '[:space:]')
valid_count=$(grep -Ec '^[0-9a-f]{64}  (database\.sql\.gz|piwigo-data\.tar\.gz|uploads\.tar\.gz|galleries\.tar\.gz|scripts\.tar\.gz|COMPLETE|MANIFEST\.json)$' "$manifest" || true)
[ "$actual_count" = "$expected_count" ] && [ "$valid_count" = "$expected_count" ] || fail restore_manifest_invalid
for name in database.sql.gz piwigo-data.tar.gz uploads.tar.gz galleries.tar.gz scripts.tar.gz COMPLETE MANIFEST.json; do
  grep -Eq "^[0-9a-f]{64}  $name$" "$manifest" || fail restore_manifest_invalid
done
(cd "$bundle" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || fail restore_checksum_failed

# Format 6 binds the backup contract to ClassIdentity v12 while explicitly
# separating business truth from all rebuildable read projections. An older
# bundle may contain a valid SQL dump, but it cannot prove this recovery policy
# and is therefore rejected before any target is cleared.
schema_contract='"class_identity_schema":{"version":12,"business_tables":["migration","identity","seat","account","principal","token","operation","audit_event","role_group","rate_limit_bucket","submission","archive_image","photo","person","person_merge","person_photo_rule","album","spotlight","photo_source","photo_duplicate","batch_operation","batch_operation_item","native_source_epoch"],"rebuildable_projection_tables":["read_projection","read_photo"],"projection_rebuild":"ALL"}'
grep -Eq '^\{"format":6,"created_at":"[0-9]{8}T[0-9]{6}Z",' "$bundle/MANIFEST.json" || fail restore_business_manifest_invalid
grep -Fq "$schema_contract" "$bundle/MANIFEST.json" || fail restore_business_manifest_invalid

assert_archive_safe() {
  archive=$1
  if tar -tzf "$archive" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    fail restore_archive_path_unsafe
  fi
  tar -tzf "$archive" >/dev/null 2>&1 || fail restore_archive_unreadable
}
assert_archive_safe "$bundle/piwigo-data.tar.gz"
assert_archive_safe "$bundle/uploads.tar.gz"
assert_archive_safe "$bundle/galleries.tar.gz"
assert_archive_safe "$bundle/scripts.tar.gz"

# Reject a falsely-labelled cache-free bundle before any target volume is
# cleared. The post-import row-count assertion below remains a second line of
# defence against dump-format edge cases.
if gzip -dc "$bundle/database.sql.gz" | grep -Eq '^INSERT INTO `[^`]+class_identity_(read_projection|read_photo)`'; then
  fail restore_projection_cache_present
fi
epoch_insert_stats=$(gzip -dc "$bundle/database.sql.gz" | awk '
  function tuple_count(row, i, char, quoted, escaped, depth, tuples) {
    quoted=0; escaped=0; depth=0; tuples=0
    for (i=1; i<=length(row); i++) {
      char=substr(row,i,1)
      if (quoted) {
        if (escaped) escaped=0
        else if (char == "\\") escaped=1
        else if (char == "\047") quoted=0
      } else if (char == "\047") quoted=1
      else if (char == "(") { if (depth == 0) tuples++; depth++ }
      else if (char == ")") { depth--; if (depth < 0) return -1 }
    }
    return quoted || depth != 0 ? -1 : tuples
  }
  function inspect(row) {
    rows++
    if (row ~ /^\(\047PIWIGO_NATIVE\047,/ && row ~ /\);$/ && tuple_count(row) == 1) valid++
  }
  /^INSERT INTO `[^`]+class_identity_native_source_epoch` VALUES[[:space:]]*\(/ {
    headers++
    row=$0
    sub(/^.* VALUES[[:space:]]*/, "", row)
    inspect(row)
    next
  }
  /^INSERT INTO `[^`]+class_identity_native_source_epoch` VALUES[[:space:]]*$/ {
    headers++
    expect=1
    next
  }
  expect { inspect($0); expect=0 }
  END { print headers+0, rows+0, valid+0 }
')
if ! gzip -dc "$bundle/database.sql.gz" \
     | awk '/^CREATE TABLE `[^`]+class_identity_native_source_epoch` \(/ { capture=1 } capture { print } capture && /^\) ENGINE=/ { exit }' \
     | grep -Eq '^\) ENGINE=MyISAM ' \
   || [ "$epoch_insert_stats" != '1 1 1' ]; then
  fail restore_native_source_epoch_invalid
fi
native_guard_count=$(gzip -dc "$bundle/database.sql.gz" \
  | grep -Eo 'TRIGGER `?[A-Za-z0-9_]+ci_(projection|source_epoch)_(images|image_category|categories)_b(i|u|d)`?' \
  | sort -u | wc -l | tr -d '[:space:]')
[ "$native_guard_count" = 18 ] || fail restore_native_projection_guard_invalid

if [ "${CLASS_ARCHIVE_RESTORE_PRECHECK:-}" = true ]; then
  printf '%s\n' "BACKUP_RESTORE_PRECHECK=PASS bundle=$CLASS_ARCHIVE_RESTORE_BUNDLE"
  exit 0
fi

clear_target() {
  target=$1
  [ -d "$target" ] && [ ! -L "$target" ] || fail restore_target_untrusted
  [ "$(realpath "$target")" = "$target" ] || fail restore_target_untrusted
  find "$target" -mindepth 1 -maxdepth 1 -xdev -exec rm -rf -- {} +
}

for target in /target/piwigo /target/uploads /target/galleries /target/derivatives /target/scripts; do
  clear_target "$target"
done

existing_tables=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" -e 'SHOW TABLES' | wc -l | tr -d '[:space:]')
[ "$existing_tables" = 0 ] || fail restore_database_not_empty
gzip -dc "$bundle/database.sql.gz" | mariadb --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" || fail restore_database_failed

# Re-check the manifest's v12 claim against the restored database before any
# application volume is repopulated. Identifiers are accepted only from the
# server's own alphanumeric table inventory and are never caller supplied.
set -- $(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ] || fail restore_class_identity_schema_ambiguous
ci_migration=$2
case "$ci_migration" in ''|*[!A-Za-z0-9_]*) fail restore_class_identity_schema_invalid ;; esac
ci_base=${ci_migration%migration}
ci_version=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COALESCE(MAX(version),0) FROM $ci_migration")
[ "$ci_version" = 12 ] || fail restore_class_identity_schema_invalid
for suffix in migration identity seat account principal token operation audit_event role_group rate_limit_bucket submission archive_image photo person person_merge person_photo_rule album spotlight photo_source photo_duplicate batch_operation batch_operation_item native_source_epoch read_projection read_photo; do
  ci_table_count=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$ci_base$suffix'")
  [ "$ci_table_count" = 1 ] || fail restore_class_identity_schema_invalid
done
pwg_base=${ci_base%class_identity_}
[ "$pwg_base" != "$ci_base" ] || fail restore_class_identity_schema_invalid
epoch_table="${ci_base}native_source_epoch"
epoch_engine=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COALESCE(MAX(ENGINE),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$epoch_table' AND TABLE_TYPE='BASE TABLE'")
[ "$epoch_engine" = MyISAM ] || fail restore_native_source_epoch_invalid
set -- $(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(SUM(source_key='PIWIGO_NATIVE' AND OCTET_LENGTH(generation)=16),0) FROM $epoch_table")
[ "$#" = 2 ] && [ "$1" = 1 ] && [ "$2" = 1 ] || fail restore_native_source_epoch_invalid
ci_trigger_count=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('${pwg_base}ci_projection_images_bi','${pwg_base}ci_projection_images_bu','${pwg_base}ci_projection_images_bd','${pwg_base}ci_projection_image_category_bi','${pwg_base}ci_projection_image_category_bu','${pwg_base}ci_projection_image_category_bd','${pwg_base}ci_projection_categories_bi','${pwg_base}ci_projection_categories_bu','${pwg_base}ci_projection_categories_bd','${pwg_base}ci_source_epoch_images_bi','${pwg_base}ci_source_epoch_images_bu','${pwg_base}ci_source_epoch_images_bd','${pwg_base}ci_source_epoch_image_category_bi','${pwg_base}ci_source_epoch_image_category_bu','${pwg_base}ci_source_epoch_image_category_bd','${pwg_base}ci_source_epoch_categories_bi','${pwg_base}ci_source_epoch_categories_bu','${pwg_base}ci_source_epoch_categories_bd') AND ACTION_TIMING='BEFORE'")
[ "$ci_trigger_count" = 18 ] || fail restore_native_projection_guard_invalid

# Projection rows are cache, not recovery truth. The v12 dump must retain their
# DDL but omit all data. Seed only the six schema-defined STALE control rows;
# the running application must rebuild PHOTO_CATALOG plus all materialized
# aggregate projections from Piwigo/Class Archive business tables after startup.
projection_meta="${ci_base}read_projection"
projection_photos="${ci_base}read_photo"
projection_meta_rows=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COUNT(*) FROM $projection_meta")
projection_photo_rows=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COUNT(*) FROM $projection_photos")
[ "$projection_meta_rows" = 0 ] && [ "$projection_photo_rows" = 0 ] || fail restore_projection_cache_present
mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "INSERT INTO $projection_meta (projection_key,state,generation) VALUES ('PHOTO_CATALOG','STALE',RANDOM_BYTES(16)),('TIMELINE','STALE',RANDOM_BYTES(16)),('ALBUMS','STALE',RANDOM_BYTES(16)),('PEOPLE','STALE',RANDOM_BYTES(16)),('MEMORIES','STALE',RANDOM_BYTES(16)),('SPOTLIGHT','STALE',RANDOM_BYTES(16))" \
  || fail restore_projection_seed_failed
projection_seed_rows=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e "SELECT COUNT(*) FROM $projection_meta WHERE state='STALE' AND source_revision IS NULL AND OCTET_LENGTH(generation)=16 AND native_source_generation IS NULL AND item_count=0 AND payload_json IS NULL AND payload_digest IS NULL AND dependency_revision IS NULL")
[ "$projection_seed_rows" = 6 ] || fail restore_projection_seed_invalid
tar -C /target/piwigo --no-same-owner -xzf "$bundle/piwigo-data.tar.gz" || fail restore_piwigo_data_failed
tar -C /target/uploads --no-same-owner -xzf "$bundle/uploads.tar.gz" || fail restore_uploads_failed
tar -C /target/galleries --no-same-owner -xzf "$bundle/galleries.tar.gz" || fail restore_galleries_failed
tar -C /target/scripts --no-same-owner -xzf "$bundle/scripts.tar.gz" || fail restore_scripts_failed
printf '%s\n' "BACKUP_RESTORE=PASS bundle=$CLASS_ARCHIVE_RESTORE_BUNDLE"
