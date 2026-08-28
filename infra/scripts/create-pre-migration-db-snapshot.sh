#!/bin/sh
# Narrow local rollback snapshot for a forward-only ClassIdentity migration.
#
# This script deliberately never mounts or reads originals, derivatives, or
# Piwigo application state. It is run only by the owner-aware deployment
# helper after the exact maintenance gate is live and the Piwigo writer has
# stopped. `--lock-all-tables` is still required because Piwigo/Core retains
# MyISAM tables that cannot share InnoDB's transaction snapshot semantics.

set -eu
umask 077

fail() {
  printf '%s\n' "PRE_MIGRATION_DB_SNAPSHOT=FAILED code=$1" >&2
  exit 1
}

[ "$(id -u)" = "0" ] || fail root_required

# The deployment runner first invokes this script in `probe` mode while the
# maintenance gate is active.  That proves the exact source schema without
# writing a backup or stopping the writer.  Only `snapshot` can create a
# rollback bundle, and it still requires an explicit confirmation value.
mode="${CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE:-snapshot}"
case "$mode" in
  probe|snapshot) ;;
  *) fail snapshot_mode_invalid ;;
esac

from_version="${CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION:-}"
to_version="${CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION:-}"
case "$from_version:$to_version" in
  # Each pair is deliberately explicit.  Do not turn this into a broad
  # numeric range: a DB-only rollback bundle is valid only at the exact
  # ledger boundary that the owner deployment adapter has reviewed.
  14:15|15:16|16:17|17:18) ;;
  *) fail migration_version_invalid ;;
esac

db_name="${DB_NAME:-}"
case "$db_name" in
  ''|*[!A-Za-z0-9_]* ) fail database_name_invalid ;;
esac
[ -n "${DB_ROOT_PASSWORD:-}" ] || fail database_secret_missing

# MYSQL_PWD avoids putting the credential in a child process argument. It is
# never echoed, copied into the manifest, or included in the PASS record.
export MYSQL_PWD="$DB_ROOT_PASSWORD"

backup_root=/backup
lock_dir="$backup_root/.class-archive-pre-migration-db.lock"
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

[ -d "$backup_root" ] && [ ! -L "$backup_root" ] || fail backup_root_untrusted
mkdir "$lock_dir" 2>/dev/null || fail overlapping_snapshot

# Resolve exactly one ClassIdentity migration ledger without assuming Piwigo's
# table prefix. A snapshot at the target version cannot stand in for the
# preceding rollback point.
set -- $(mariadb --batch --skip-column-names --host=db --user=root "$db_name" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ] || fail migration_table_ambiguous
migration_table="$2"
case "$migration_table" in
  ''|*[!A-Za-z0-9_]* ) fail migration_table_invalid ;;
esac

set -- $(mariadb --batch --skip-column-names --host=db --user=root "$db_name" \
  -e "SELECT COUNT(*),COALESCE(MIN(version),0),COALESCE(MAX(version),0),COUNT(DISTINCT version) FROM \`$migration_table\`")
[ "$#" = 4 ] || fail migration_ledger_shape_invalid
migration_count="$1"
migration_min="$2"
current_version="$3"
migration_distinct="$4"
case "$migration_count:$migration_min:$current_version:$migration_distinct" in
  *[!0-9:]*) fail migration_ledger_shape_invalid ;;
esac
[ "$migration_count" = "$current_version" ] \
  && [ "$migration_min" = 1 ] \
  && [ "$migration_distinct" = "$migration_count" ] \
  || fail migration_ledger_not_contiguous

# A rerun at the exact target is legitimate and must not create a misleading
# second rollback bundle. Any other state is unknown and blocks deployment
# before plugin migration can change it.
case "$current_version" in
  "$from_version")
    if [ "$mode" = "probe" ]; then
      unset MYSQL_PWD
      rmdir "$lock_dir" || fail lock_release_failed
      trap - 0 1 2 15
      printf '%s\n' "PRE_MIGRATION_DB_SNAPSHOT=REQUIRED_CURRENT_V${from_version} schema_current=$current_version schema_from=$from_version schema_to=$to_version scope=DB_ONLY media=NOT_INCLUDED"
      exit 0
    fi
    [ "${CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM:-}" = "true" ] || fail confirmation_required
    ;;
  "$to_version")
    unset MYSQL_PWD
    rmdir "$lock_dir" || fail lock_release_failed
    trap - 0 1 2 15
    printf '%s\n' "PRE_MIGRATION_DB_SNAPSHOT=NOT_REQUIRED_CURRENT_V${to_version} schema_current=$current_version schema_from=$current_version schema_to=$current_version scope=NONE media=NOT_INCLUDED"
    exit 0
    ;;
  *) fail source_schema_not_transition_boundary ;;
esac

stamp=$(date -u +%Y%m%dT%H%M%SZ)
bundle="$backup_root/pre-migration-db-v${from_version}-to-v${to_version}-${stamp}"
partial_bundle="${bundle}.partial.$$"
[ ! -e "$bundle" ] && [ ! -e "$partial_bundle" ] || fail bundle_name_collision
mkdir "$partial_bundle" || fail bundle_create_failed

# `--lock-all-tables` makes the dump coherent even for MyISAM. The Piwigo
# writer is stopped by the owner helper; the lock is a second independent
# consistency boundary rather than a substitute for stopping writes.
mariadb-dump --quick --lock-all-tables --triggers --routines --events --add-drop-table \
  --host=db --user=root "$db_name" > "$partial_bundle/database.sql" || fail database_dump_failed
[ -s "$partial_bundle/database.sql" ] || fail database_dump_empty
gzip -9 "$partial_bundle/database.sql" || fail database_compress_failed
[ -s "$partial_bundle/database.sql.gz" ] || fail database_compress_empty

dump_bytes=$(wc -c < "$partial_bundle/database.sql.gz" | tr -d ' ')
dump_sha256=$(sha256sum "$partial_bundle/database.sql.gz" | awk '{print $1}')
script_sha256=$(sha256sum "$0" | awk '{print $1}')
case "$dump_bytes:$dump_sha256:$script_sha256" in
  *[!0-9a-f:]*|:*|*::*) fail snapshot_digest_invalid ;;
esac

printf '{"format":1,"scope":"DB_ONLY_PRE_MIGRATION_ROLLBACK","created_at":"%s","schema_current":%s,"schema_from":%s,"schema_to":%s,"migration_ledger_count":%s,"migration_ledger_min":%s,"migration_ledger_max":%s,"migration_ledger_distinct":%s,"lock_strategy":"MARIADB_DUMP_LOCK_ALL_TABLES","media":"NOT_INCLUDED","dump_file":"database.sql.gz","dump_bytes":%s,"dump_sha256":"%s","snapshot_script_sha256":"%s"}\n' \
  "$stamp" "$current_version" "$from_version" "$to_version" "$migration_count" "$migration_min" "$current_version" "$migration_distinct" "$dump_bytes" "$dump_sha256" "$script_sha256" > "$partial_bundle/MANIFEST.json" || fail manifest_write_failed
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

printf '%s\n' "PRE_MIGRATION_DB_SNAPSHOT=PASS bundle=$(basename "$bundle") schema_from=$from_version schema_to=$to_version scope=DB_ONLY media=NOT_INCLUDED"
