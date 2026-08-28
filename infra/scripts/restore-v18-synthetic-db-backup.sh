#!/usr/bin/env sh
set -eu

# Restores exactly one verified V18 DB-only synthetic bundle into the separate
# empty target database. It never clears or reuses a non-empty target.

fail() {
  printf '%s\n' "V18_SYNTHETIC_DB_RESTORE=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_V18_SYNTHETIC_RECOVERY:-}" = 1 ] || fail scope_confirmation_missing
[ "${CLASS_ARCHIVE_RUNTIME_SCOPE:-}" = SYNTHETIC_V4_MIGRATION ] || fail runtime_scope_invalid
[ "${DB_HOST:-}" = v18-synthetic-recovery-db ] || fail target_host_invalid
[ "${DB_NAME:-}" = piwigo ] || fail database_invalid
[ -n "${DB_ROOT_PASSWORD:-}" ] || fail root_password_missing
case "${CLASS_ARCHIVE_V18_SYNTHETIC_RESTORE_BUNDLE:-}" in
  class-archive-v18-synthetic-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
  *) fail bundle_name_invalid ;;
esac
[ -d /snapshot ] && [ ! -L /snapshot ] || fail snapshot_root_invalid
bundle=/snapshot/$CLASS_ARCHIVE_V18_SYNTHETIC_RESTORE_BUNDLE
[ -d "$bundle" ] && [ ! -L "$bundle" ] || fail bundle_invalid
[ -f "$bundle/MANIFEST.json" ] && [ ! -L "$bundle/MANIFEST.json" ] || fail manifest_invalid
[ -f "$bundle/SHA256SUMS" ] && [ ! -L "$bundle/SHA256SUMS" ] || fail checksum_file_invalid

stamp=${CLASS_ARCHIVE_V18_SYNTHETIC_RESTORE_BUNDLE#class-archive-v18-synthetic-}
dump_sha=$(sha256sum "$bundle/database.sql.gz" 2>/dev/null | awk '{print $1}')
expected=$(printf '{"format":10,"created_at":"%s","class_identity_schema":18,"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V18_RECOVERY","media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED","photos":72,"dump_sha256":"%s"}' "$stamp" "$dump_sha")
manifest=$(cat "$bundle/MANIFEST.json")
[ "$manifest" = "$expected" ] || fail manifest_invalid
[ "$(cat "$bundle/COMPLETE")" = "completed_at=$stamp" ] || fail complete_invalid
(cd "$bundle" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || fail checksum_invalid

tables=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -h "$DB_HOST" -uroot information_schema -e "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='${DB_NAME}'" 2>/dev/null) || fail target_table_count_failed
[ "$tables" = 0 ] || fail target_not_empty
if ! gzip -cd "$bundle/database.sql.gz" | MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -h "$DB_HOST" -uroot; then
  fail import_failed
fi
schema=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -h "$DB_HOST" -uroot "$DB_NAME" -e "SELECT COALESCE(MAX(\`version\`),0) FROM \`piwigo_class_identity_migration\`" 2>/dev/null) || fail restored_schema_query_failed
[ "$schema" = 18 ] || fail restored_schema_not_v18
photos=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -h "$DB_HOST" -uroot "$DB_NAME" -e "SELECT COUNT(*) FROM \`piwigo_class_identity_photo\` WHERE \`state\`='ACTIVE'" 2>/dev/null) || fail restored_photo_count_failed
[ "$photos" = 72 ] || fail restored_photo_count_invalid
printf '%s\n' "V18_SYNTHETIC_DB_RESTORE=PASS format=10 schema=18 scope=DB_ONLY target=SECOND_EMPTY_DB media=NOT_MOUNTED media_guard=NOT_CLAIMED photos=72"
