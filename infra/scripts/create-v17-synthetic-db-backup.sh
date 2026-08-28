#!/usr/bin/env sh
# Create a database-only format-9 snapshot inside the isolated V4 synthetic
# migration laboratory. It deliberately has no media mount and is not a
# substitute for a complete MediaGuard or owner-recovery drill.

set -eu
umask 077
LC_ALL=C
export LC_ALL

fail() {
  printf '%s\n' "V17_SYNTHETIC_DB_BACKUP=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_V17_SYNTHETIC_RECOVERY:-}" = 1 ] || fail scope_confirmation_missing
[ "${CLASS_ARCHIVE_RUNTIME_SCOPE:-}" = SYNTHETIC_V4_MIGRATION ] || fail runtime_scope_invalid
[ "$(id -u)" = 0 ] || fail root_required
case "${DB_NAME:-}" in ''|*[!A-Za-z0-9_]*) fail database_configuration_invalid ;; esac
case "${DB_ROOT_PASSWORD:-}" in ''|*[!A-Za-z0-9_-]*) fail database_configuration_invalid ;; esac

. /workspace/infra/scripts/class-archive-recovery-contracts.sh
ca_recovery_select_by_format 9 || fail recovery_contract_unavailable

backup_root=/backup
[ -d "$backup_root" ] && [ ! -L "$backup_root" ] && [ "$(realpath "$backup_root")" = "$backup_root" ] || fail backup_root_untrusted
lock_dir="$backup_root/.class-archive-v17-synthetic-db-backup.lock"
partial_bundle=
cleanup() {
  unset MYSQL_PWD || true
  if [ -n "${partial_bundle:-}" ] && [ -d "$partial_bundle" ]; then
    rm -f -- "$partial_bundle/database.sql" "$partial_bundle/database.sql.gz" \
      "$partial_bundle/MANIFEST.json" "$partial_bundle/SHA256SUMS" "$partial_bundle/COMPLETE"
    rmdir -- "$partial_bundle" 2>/dev/null || true
  fi
  if [ -d "$lock_dir" ]; then
    rmdir -- "$lock_dir" 2>/dev/null || true
  fi
}
trap cleanup 0 1 2 15
mkdir "$lock_dir" 2>/dev/null || fail overlapping_backup

# MYSQL_PWD avoids passing the secret in a process argument; no command below
# prints it or puts it in the snapshot manifest.
export MYSQL_PWD="$DB_ROOT_PASSWORD"
set -- $(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ] || fail migration_table_ambiguous
ci_migration=$2
case "$ci_migration" in ''|*[!A-Za-z0-9_]*) fail migration_table_invalid ;; esac
ci_base=${ci_migration%migration}
ci_version=$(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" \
  -e "SELECT COALESCE(MAX(version),0) FROM \`$ci_migration\`")
[ "$ci_version" = "$CA_RECOVERY_SCHEMA_VERSION" ] || fail source_schema_not_v17

for suffix in $CA_RECOVERY_ALL_TABLES; do
  count=$(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$ci_base$suffix'")
  [ "$count" = 1 ] || fail required_table_missing
done

pwg_base=${ci_base%class_identity_}
[ "$pwg_base" != "$ci_base" ] || fail piwigo_prefix_invalid
epoch_table="${ci_base}native_source_epoch"
epoch_engine=$(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" \
  -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$epoch_table' AND TABLE_TYPE='BASE TABLE' AND ENGINE='MyISAM'")
[ "$epoch_engine" = 1 ] || fail native_epoch_engine_invalid
set -- $(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(SUM(source_key='PIWIGO_NATIVE' AND OCTET_LENGTH(generation)=16),0) FROM \`$epoch_table\`")
[ "$#" = 2 ] && [ "$1" = 1 ] && [ "$2" = 1 ] || fail native_epoch_singleton_invalid

stamp=$(date -u +%Y%m%dT%H%M%SZ)
bundle="$backup_root/class-archive-v17-synthetic-$stamp"
partial_bundle="${bundle}.partial.$$"
[ ! -e "$bundle" ] && [ ! -e "$partial_bundle" ] || fail bundle_name_collision
mkdir "$partial_bundle" || fail bundle_create_failed

# The v17 data domain is included. Only the authorization-neutral materialized
# read cache is deliberately omitted and will be rebuilt after a full restore.
mariadb-dump --quick --lock-all-tables --skip-triggers \
  --ignore-table-data="$DB_NAME.${ci_base}read_projection" \
  --ignore-table-data="$DB_NAME.${ci_base}read_photo" \
  --host=db --user=root "$DB_NAME" > "$partial_bundle/database.sql" || fail database_dump_failed
mariadb-dump --no-data --no-create-info --triggers --host=db --user=root "$DB_NAME" \
  "${pwg_base}images" "${pwg_base}image_category" "${pwg_base}categories" >> "$partial_bundle/database.sql" || fail native_trigger_dump_failed
[ -s "$partial_bundle/database.sql" ] || fail database_dump_empty
if grep -Eq '^INSERT INTO `[^`]+class_identity_(read_projection|read_photo)`' "$partial_bundle/database.sql"; then
  fail projection_cache_present
fi
native_guard_count=$(grep -Eo 'TRIGGER `?[A-Za-z0-9_]+ci_(projection|source_epoch)_(images|image_category|categories)_b(i|u|d)`?' "$partial_bundle/database.sql" \
  | sort -u | wc -l | tr -d '[:space:]')
[ "$native_guard_count" = 18 ] || fail native_projection_guard_invalid
gzip -9 "$partial_bundle/database.sql" || fail database_compress_failed

printf '{"format":9,"created_at":"%s","class_identity_schema":%s,"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V17_RECOVERY","media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED"}\n' \
  "$stamp" "$CA_RECOVERY_SCHEMA_JSON" > "$partial_bundle/MANIFEST.json" || fail manifest_write_failed
printf 'completed_at=%s\n' "$stamp" > "$partial_bundle/COMPLETE" || fail complete_write_failed
(
  cd "$partial_bundle"
  sha256sum database.sql.gz MANIFEST.json COMPLETE > SHA256SUMS
  sha256sum -c SHA256SUMS >/dev/null
) || fail checksum_verify_failed
mv "$partial_bundle" "$bundle" || fail bundle_publish_failed
partial_bundle=
rmdir "$lock_dir" || fail lock_release_failed
trap - 0 1 2 15
unset MYSQL_PWD

printf '%s\n' "V17_SYNTHETIC_DB_BACKUP=PASS bundle=$(basename "$bundle") format=9 schema=17 scope=DB_ONLY media=NOT_MOUNTED media_guard=NOT_CLAIMED"
