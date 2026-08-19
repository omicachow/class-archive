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
tar -C /target/piwigo --no-same-owner -xzf "$bundle/piwigo-data.tar.gz" || fail restore_piwigo_data_failed
tar -C /target/uploads --no-same-owner -xzf "$bundle/uploads.tar.gz" || fail restore_uploads_failed
tar -C /target/galleries --no-same-owner -xzf "$bundle/galleries.tar.gz" || fail restore_galleries_failed
tar -C /target/scripts --no-same-owner -xzf "$bundle/scripts.tar.gz" || fail restore_scripts_failed
printf '%s\n' "BACKUP_RESTORE=PASS bundle=$CLASS_ARCHIVE_RESTORE_BUNDLE"
