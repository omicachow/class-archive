#!/usr/bin/env sh
set -eu

# This is deliberately a restore-drill tool, not a general production restore
# button. It requires two exact synthetic-only acknowledgements, verifies a
# complete bundle before touching an exact list of mounted volumes, and never
# mounts or modifies the backup volume.

umask 077

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

# Format 4 binds the backup contract to the complete ClassIdentity v8 table
# set. An older bundle may still contain a valid SQL dump, but it is not valid
# evidence for the Phase 3.2 product domain and is therefore rejected before
# any target is cleared.
schema_contract='"class_identity_schema":{"version":8,"tables":["migration","identity","seat","account","principal","token","operation","audit_event","role_group","rate_limit_bucket","submission","archive_image","photo","person","person_merge","person_photo_rule","album","spotlight","photo_source","photo_duplicate","batch_operation","batch_operation_item"]}'
grep -Eq '^\{"format":4,"created_at":"[0-9]{8}T[0-9]{6}Z",' "$bundle/MANIFEST.json" || fail restore_business_manifest_invalid
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

# Re-check the manifest's v8 claim against the restored database before any
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
[ "$ci_version" = 8 ] || fail restore_class_identity_schema_invalid
for suffix in migration identity seat account principal token operation audit_event role_group rate_limit_bucket submission archive_image photo person person_merge person_photo_rule album spotlight photo_source photo_duplicate batch_operation batch_operation_item; do
  ci_table_count=$(mariadb --batch --skip-column-names --host=db --user=root --password="$DB_ROOT_PASSWORD" "$DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$ci_base$suffix'")
  [ "$ci_table_count" = 1 ] || fail restore_class_identity_schema_invalid
done
tar -C /target/piwigo --no-same-owner -xzf "$bundle/piwigo-data.tar.gz" || fail restore_piwigo_data_failed
tar -C /target/uploads --no-same-owner -xzf "$bundle/uploads.tar.gz" || fail restore_uploads_failed
tar -C /target/galleries --no-same-owner -xzf "$bundle/galleries.tar.gz" || fail restore_galleries_failed
tar -C /target/scripts --no-same-owner -xzf "$bundle/scripts.tar.gz" || fail restore_scripts_failed
printf '%s\n' "BACKUP_RESTORE=PASS bundle=$CLASS_ARCHIVE_RESTORE_BUNDLE"
