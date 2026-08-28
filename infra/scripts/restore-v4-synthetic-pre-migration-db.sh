#!/bin/sh
# Restore a *verified DB-only* v16 snapshot into the fixed synthetic v4
# migration laboratory.  This is intentionally not a generic restore helper:
# it has no media mount and rejects every source/target ambiguity.

set -eu
umask 077
LC_ALL=C
export LC_ALL

fail() {
  printf '%s\n' "V4_SYNTHETIC_DB_RESTORE=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_V4_SYNTHETIC_RESTORE:-}" = "1" ] || fail scope_confirmation_missing
[ "$(id -u)" = "0" ] || fail root_required

for value in "${DB_NAME:-}" "${DB_USER:-}" "${DB_PASSWORD:-}" "${DB_ROOT_PASSWORD:-}"; do
  [ -n "$value" ] || fail database_configuration_missing
done
case "${DB_NAME}:${DB_USER}:${PIWIGO_UID:-}:${PIWIGO_GID:-}" in
  *[!A-Za-z0-9_:\-]*|:*|*::*|*:-*|-:* ) fail database_configuration_invalid ;;
esac
for secret in "$DB_PASSWORD" "$DB_ROOT_PASSWORD"; do
  case "$secret" in
    *[!A-Za-z0-9_-]* ) fail database_secret_invalid ;;
  esac
done

snapshot=/snapshot
[ -d "$snapshot" ] && [ ! -L "$snapshot" ] || fail snapshot_root_untrusted
[ "$(realpath "$snapshot")" = "$snapshot" ] || fail snapshot_root_untrusted

# The existing pre-migration helper writes exactly these four entries.  A
# snapshot with extra media or other files is not a DB-only migration input.
expected_files='COMPLETE MANIFEST.json SHA256SUMS database.sql.gz'
actual_files=$(find "$snapshot" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//')
[ "$actual_files" = "$expected_files" ] || fail snapshot_file_set_invalid
for name in COMPLETE MANIFEST.json SHA256SUMS database.sql.gz; do
  [ -f "$snapshot/$name" ] && [ ! -L "$snapshot/$name" ] || fail snapshot_file_untrusted
done
[ "$(cat "$snapshot/COMPLETE")" != "" ] || fail snapshot_complete_invalid

manifest="$snapshot/MANIFEST.json"
grep -Eq '^\{"format":1,"scope":"DB_ONLY_PRE_MIGRATION_ROLLBACK","created_at":"[0-9]{8}T[0-9]{6}Z","schema_current":16,"schema_from":16,"schema_to":17,' "$manifest" \
  || fail snapshot_manifest_transition_invalid
grep -Fq '"lock_strategy":"MARIADB_DUMP_LOCK_ALL_TABLES","media":"NOT_INCLUDED","dump_file":"database.sql.gz"' "$manifest" \
  || fail snapshot_manifest_scope_invalid
manifest_dump_sha=$(sed -n 's/.*"dump_sha256":"\([a-f0-9]\{64\}\)".*/\1/p' "$manifest")
manifest_script_sha=$(sed -n 's/.*"snapshot_script_sha256":"\([a-f0-9]\{64\}\)".*/\1/p' "$manifest")
[ "$(printf '%s' "$manifest_dump_sha" | wc -c | tr -d ' ')" = 64 ] || fail snapshot_manifest_digest_invalid
[ "$(printf '%s' "$manifest_script_sha" | wc -c | tr -d ' ')" = 64 ] || fail snapshot_manifest_digest_invalid
[ "$manifest_script_sha" = "$(sha256sum /workspace/infra/scripts/create-pre-migration-db-snapshot.sh | awk '{print $1}')" ] \
  || fail snapshot_not_created_by_current_mechanism

expected_sums='^[a-f0-9]{64}  (COMPLETE|MANIFEST\.json|database\.sql\.gz)$'
[ "$(wc -l < "$snapshot/SHA256SUMS" | tr -d '[:space:]')" = 3 ] \
  && [ "$(grep -Ec "$expected_sums" "$snapshot/SHA256SUMS" || true)" = 3 ] \
  || fail snapshot_checksums_invalid
(cd "$snapshot" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || fail snapshot_checksum_failed
[ "$manifest_dump_sha" = "$(sha256sum "$snapshot/database.sql.gz" | awk '{print $1}')" ] || fail snapshot_dump_digest_invalid
gzip -t "$snapshot/database.sql.gz" || fail snapshot_dump_unreadable

# A v16 source snapshot must not already contain the v17 snapshot domain.  It
# guards against a forged manifest or an accidental re-use of a target dump.
if gzip -dc "$snapshot/database.sql.gz" | grep -Eq '^CREATE TABLE `[^`]+class_identity_collection_snapshot`'; then
  fail snapshot_contains_v17_domain
fi

export MYSQL_PWD="$DB_ROOT_PASSWORD"
cleanup() { unset MYSQL_PWD || true; }
trap cleanup EXIT HUP INT TERM

table_count=$(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" -e 'SHOW TABLES' | wc -l | tr -d '[:space:]')
[ "$table_count" = 0 ] || fail target_database_not_empty
gzip -dc "$snapshot/database.sql.gz" | mariadb --host=db --user=root "$DB_NAME" || fail database_import_failed

set -- $(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ] || fail restored_migration_table_ambiguous
migration_table="$2"
case "$migration_table" in ''|*[!A-Za-z0-9_]*) fail restored_migration_table_invalid ;; esac
version=$(mariadb --batch --skip-column-names --host=db --user=root "$DB_NAME" -e "SELECT COALESCE(MAX(version),0) FROM \`$migration_table\`")
[ "$version" = 16 ] || fail restored_schema_not_v16

# The Piwigo image was started once before this one-shot restore so its code
# tree exists in the isolated named volume.  Only a newly written sandbox DB
# config is added; no source Piwigo data/config/media is ever imported.
target=/target-piwigo
[ -d "$target" ] && [ ! -L "$target" ] && [ "$(realpath "$target")" = "$target" ] || fail target_piwigo_root_untrusted
[ -d "$target/include" ] && [ -f "$target/index.php" ] || fail target_piwigo_core_not_initialized
config_dir="$target/local/config"
mkdir -p "$config_dir" || fail target_config_directory_failed
[ -d "$config_dir" ] && [ ! -L "$config_dir" ] && [ "$(realpath "$config_dir")" = "$config_dir" ] || fail target_config_directory_untrusted
config="$config_dir/database.inc.php"
[ ! -e "$config" ] && [ ! -L "$config" ] || fail target_database_config_already_exists
cat > "$config" <<EOF
<?php
\$conf['dblayer'] = 'mysqli';
\$conf['db_base'] = '${DB_NAME}';
\$conf['db_user'] = '${DB_USER}';
\$conf['db_password'] = '${DB_PASSWORD}';
\$conf['db_host'] = 'db';
\$prefixeTable = 'piwigo_';
define('PHPWG_INSTALLED', true);
EOF
chown "${PIWIGO_UID}:${PIWIGO_GID}" "$config" || fail target_database_config_owner_failed
chmod 0660 "$config" || fail target_database_config_mode_failed

# Never start the restored v16 database as a normal browser surface.  The
# current source tree has not yet been installed into its fresh Piwigo volume,
# so publication must stay fail-closed until the migration runner completes
# install -> migrate -> projection rebuild -> verification -> finalization.
data_dir="$target/_data"
[ -d "$data_dir" ] && [ ! -L "$data_dir" ] && [ "$(realpath "$data_dir")" = "$data_dir" ] || fail target_data_directory_untrusted
marker="$data_dir/.class-archive-maintenance"
[ ! -e "$marker" ] && [ ! -L "$marker" ] || fail target_maintenance_marker_already_exists
temporary_marker="$data_dir/.class-archive-maintenance-v4-restore-$$"
printf '%s\n' 'class-archive-identity-bootstrap' > "$temporary_marker" || fail target_maintenance_marker_write_failed
chown "${PIWIGO_UID}:${PIWIGO_GID}" "$temporary_marker" || fail target_maintenance_marker_owner_failed
# The pinned Windows/named-volume startup normalizer makes the persistent
# _data tree owned by PIWIGO_UID:GID.  The trusted maintenance lifecycle
# explicitly accepts this exact private service form at 0660/0670; use 0660
# so the restored marker remains recognizable after the first Piwigo restart.
chmod 0660 "$temporary_marker" || fail target_maintenance_marker_mode_failed
mv "$temporary_marker" "$marker" || fail target_maintenance_marker_publish_failed

printf '%s\n' "V4_SYNTHETIC_DB_RESTORE=PASS schema=16 scope=DB_ONLY media=NOT_MOUNTED target=ISOLATED maintenance=FAIL_CLOSED"
