#!/usr/bin/env sh
set -eu

# Creates one immutable, DB-only synthetic V18 recovery bundle. The source is
# an attempt8-only MariaDB service; no media, private runtime or host path is
# mounted into this service.

fail() {
  printf '%s\n' "V18_SYNTHETIC_DB_BACKUP=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_V18_SYNTHETIC_RECOVERY:-}" = 1 ] || fail scope_confirmation_missing
[ "${CLASS_ARCHIVE_RUNTIME_SCOPE:-}" = SYNTHETIC_V4_MIGRATION ] || fail runtime_scope_invalid
[ "${DB_HOST:-}" = db ] || fail source_host_invalid
[ "${DB_NAME:-}" = piwigo ] || fail database_invalid
[ -n "${DB_ROOT_PASSWORD:-}" ] || fail root_password_missing
[ -d /snapshot ] && [ ! -L /snapshot ] || fail snapshot_root_invalid

schema=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -h "$DB_HOST" -uroot "$DB_NAME" -e "SELECT COALESCE(MAX(\`version\`),0) FROM \`piwigo_class_identity_migration\`" 2>/dev/null) || fail schema_query_failed
[ "$schema" = 18 ] || fail source_schema_not_v18
photos=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -h "$DB_HOST" -uroot "$DB_NAME" -e "SELECT COUNT(*) FROM \`piwigo_class_identity_photo\` WHERE \`state\`='ACTIVE'" 2>/dev/null) || fail photo_count_failed
case "$photos" in
  72) ;;
  *) fail synthetic_photo_count_invalid ;;
esac

lock=/snapshot/.class-archive-v18-synthetic-backup.lock
if ! mkdir "$lock" 2>/dev/null; then
  fail backup_lock_unavailable
fi
cleanup() { rmdir "$lock" 2>/dev/null || true; }
trap cleanup EXIT HUP INT TERM

stamp=$(date -u +%Y%m%dT%H%M%SZ)
bundle=/snapshot/class-archive-v18-synthetic-"$stamp"
[ ! -e "$bundle" ] || fail bundle_already_exists
mkdir -m 0700 "$bundle" || fail bundle_create_failed

if ! MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb-dump -h "$DB_HOST" -uroot --single-transaction --skip-lock-tables --routines --events --databases "$DB_NAME" | gzip -n > "$bundle/database.sql.gz"; then
  fail dump_failed
fi
[ -s "$bundle/database.sql.gz" ] || fail dump_empty
dump_sha=$(sha256sum "$bundle/database.sql.gz" | awk '{print $1}')
case "$dump_sha" in
  ????????*) ;;
  *) fail dump_hash_invalid ;;
esac

printf '{"format":10,"created_at":"%s","class_identity_schema":18,"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V18_RECOVERY","media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED","photos":72,"dump_sha256":"%s"}\n' "$stamp" "$dump_sha" > "$bundle/MANIFEST.json"
printf 'completed_at=%s\n' "$stamp" > "$bundle/COMPLETE"
(cd "$bundle" && sha256sum COMPLETE MANIFEST.json database.sql.gz > SHA256SUMS)
(cd "$bundle" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || fail checksum_invalid
chmod 0400 "$bundle/database.sql.gz" "$bundle/MANIFEST.json" "$bundle/COMPLETE" "$bundle/SHA256SUMS" || fail bundle_mode_invalid
printf '%s\n' "V18_SYNTHETIC_DB_BACKUP=PASS bundle=$(basename "$bundle") format=10 schema=18 scope=DB_ONLY media=NOT_MOUNTED media_guard=NOT_CLAIMED photos=72"
