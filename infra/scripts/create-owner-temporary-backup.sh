#!/usr/bin/env bash
set -euo pipefail

# Linux-side volume/dump helper for owner-temporary-backup.ps1. It is hard
# bound to the private-full v3 Docker scope and emits only numeric/version/image
# evidence. The owner runtime stays online: MariaDB briefly holds a global read
# lock for its logical dump, PostgreSQL uses pg_dump's consistent snapshot, and
# before/after guards reject publication if business/media or Immich state moves.
# Private filenames, SQL, tar listings and credentials never reach stdout or
# stderr.

umask 077
export LC_ALL=C

fail() {
  printf '%s\n' "OWNER_TEMP_BACKUP_HELPER=FAIL code=$1" >&2
  exit 1
}

mode=${1:-}
shift || true
case "$mode" in preflight|backup|verify|verify-pending) ;; *) fail action_invalid ;; esac

bundle=
passphrase_file=
expected_backup_id=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --bundle) [ "$#" -ge 2 ] || fail argument_missing; bundle=$2; shift 2 ;;
    --passphrase-file) [ "$#" -ge 2 ] || fail argument_missing; passphrase_file=$2; shift 2 ;;
    --expected-backup-id) [ "$#" -ge 2 ] || fail argument_missing; expected_backup_id=$2; shift 2 ;;
    *) fail argument_invalid ;;
  esac
done

assert_backup_id() {
  case "$1" in
    owner-full-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
    owner-full-v2-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
    *) fail bundle_path_invalid ;;
  esac
}

validate_bundle_passphrase_identity() {
  identity_mode=$1
  bundle_parent=${bundle%/*}
  bundle_name=${bundle##*/}
  [ "$bundle_parent" != "$bundle" ] && [ "${bundle_parent##*/}" = bundles ] || fail bundle_path_invalid
  target_path=${bundle_parent%/*}
  target_name=${target_path##*/}
  target_parent=${target_path%/*}
  case "$target_parent" in /mnt/[a-z]) ;; *) fail bundle_path_invalid ;; esac
  case "$target_name" in
    ClassArchive-Temporary-Recovery) ;;
    ClassArchive-Temporary-Recovery-*)
      target_suffix=${target_name#ClassArchive-Temporary-Recovery-}
      [ -n "$target_suffix" ] && [ "${#target_suffix}" -le 40 ] || fail bundle_path_invalid
      case "$target_suffix" in *[!A-Za-z0-9_-]*) fail bundle_path_invalid ;; esac
      ;;
    *) fail bundle_path_invalid ;;
  esac

  case "$identity_mode" in
    verify)
      backup_id=$bundle_name
      assert_backup_id "$backup_id"
      [ -z "$expected_backup_id" ] || fail unexpected_backup_id_argument
      expected_passphrase_parent=${backup_id}-verify
      ;;
    verify-pending|backup)
      case "$bundle_name" in .partial-owner-full-*) ;; *) fail pending_bundle_path_invalid ;; esac
      backup_id=${bundle_name#.partial-}
      assert_backup_id "$backup_id"
      assert_backup_id "$expected_backup_id"
      [ "$backup_id" = "$expected_backup_id" ] || fail pending_backup_id_mismatch
      expected_passphrase_parent=$backup_id
      ;;
    *) fail bundle_identity_mode_invalid ;;
  esac

  [ "${passphrase_file##*/}" = gpg-passphrase.txt ] || fail passphrase_path_invalid
  passphrase_parent=${passphrase_file%/*}
  [ "$passphrase_parent" != "$passphrase_file" ] || fail passphrase_path_invalid
  [ "${passphrase_parent##*/}" = "$expected_passphrase_parent" ] || fail passphrase_bundle_identity_mismatch
  passphrase_runtime_parent=${passphrase_parent%/*}
  [ "${passphrase_runtime_parent##*/}" = owner-temporary-backup ] || fail passphrase_path_invalid
  passphrase_runtime_root=${passphrase_runtime_parent%/*}
  [ "${passphrase_runtime_root##*/}" = runtime ] || fail passphrase_path_invalid
  passphrase_scope_root=${passphrase_runtime_root%/*}
  [ "${passphrase_scope_root##*/}" = private-real-full ] || fail passphrase_path_invalid
  passphrase_work_root=${passphrase_scope_root%/*}
  [ "${passphrase_work_root##*/}" = .codex-work ] || fail passphrase_path_invalid
  case "${passphrase_work_root%/*}" in /mnt/c/*) ;; *) fail passphrase_path_invalid ;; esac
}

# Verification is deliberately independent of the source 8191 runtime. It
# needs only the published bundle, the same Windows-user recovery key and
# standard local GNU/GPG tooling; semantic restore validation is repeated in
# the fresh restore runtime before import.
if [ "$mode" = verify ] || [ "$mode" = verify-pending ]; then
  validate_bundle_passphrase_identity "$mode"
  [ -d "$bundle" ] && [ ! -L "$bundle" ] || fail bundle_untrusted
  [ -f "$passphrase_file" ] && [ ! -L "$passphrase_file" ] || fail passphrase_file_untrusted
  [ "$(wc -l < "$passphrase_file" | tr -d '[:space:]')" = 1 ] || fail passphrase_file_invalid
  [ "$(wc -c < "$passphrase_file" | tr -d '[:space:]')" -ge 80 ] || fail passphrase_file_invalid
  command -v gpg >/dev/null 2>&1 || fail gpg_missing
  command -v tar >/dev/null 2>&1 || fail gnu_tar_required
  tar --version 2>/dev/null | grep -F 'GNU tar' >/dev/null || fail gnu_tar_required
  verify_gpg_home=$(mktemp -d /tmp/class-archive-owner-verify-gpg.XXXXXXXX) || fail gpg_temp_failed
  chmod 0700 "$verify_gpg_home"
  export GNUPGHOME="$verify_gpg_home"
  verify_cleanup() { rm -rf -- "$verify_gpg_home"; }
  trap verify_cleanup EXIT HUP INT TERM
  standalone_decrypt() {
    gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$passphrase_file" \
      --decrypt "$1" 2>/dev/null
  }
  standalone_assert_file() {
    [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %s "$1")" -gt 0 ] || fail backup_payload_invalid
  }
  standalone_assert_file "$bundle/databases/mariadb.sql.gz.gpg"
  standalone_decrypt "$bundle/databases/mariadb.sql.gz.gpg" | gzip -t || fail mariadb_dump_invalid
  standalone_assert_file "$bundle/databases/immich-postgres.dump.gpg"
  standalone_decrypt "$bundle/databases/immich-postgres.dump.gpg" >/dev/null || fail postgres_dump_authentication_failed
  postgres_magic=$(standalone_decrypt "$bundle/databases/immich-postgres.dump.gpg" | {
    IFS= read -r -N 5 prefix || exit 1
    cat >/dev/null || exit 1
    printf '%s' "$prefix"
  }) || fail postgres_dump_invalid
  [ "$postgres_magic" = PGDMP ] || fail postgres_dump_invalid
  for archive in \
    "$bundle/business-state/piwigo-data.tar.gpg" \
    "$bundle/business-state/piwigo-scripts.tar.gpg" \
    "$bundle/media-archives/piwigo-uploads.tar.gpg" \
    "$bundle/media-archives/piwigo-galleries.tar.gpg" \
    "$bundle/immich-state/immich-upload.tar.gpg"; do
    standalone_assert_file "$archive"
    standalone_decrypt "$archive" | tar -tf - | awk '
      /^\// { bad=1 }
      /(^|\/)\.\.($|\/)/ { bad=1 }
      END { exit bad ? 1 : 0 }
    ' >/dev/null || fail encrypted_tar_invalid
  done
  printf '%s\n' "OWNER_TEMP_BACKUP_HELPER=PASS action=$mode"
  exit 0
fi

piwigo=class_archive_private_full_v3_piwigo-piwigo-1
mariadb=class_archive_private_full_v3_piwigo-db-1
immich_server=class_archive_private_full_v3_immich-immich-server-1
immich_ml=class_archive_private_full_v3_immich-immich-machine-learning-1
postgres=class_archive_private_full_v3_immich-database-1

assert_container() {
  name=$1 project=$2 service=$3
  [ "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "com.classarchive.scope"}}' "$name" 2>/dev/null)" = "$project|$service|private-real-full" ] \
    || fail container_scope_invalid
}

assert_volume() {
  name=$1 project=$2 logical=$3
  [ "$(docker volume inspect --format '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}' "$name" 2>/dev/null)" = "$project|$logical|private-real-full" ] \
    || fail volume_scope_invalid
}

assert_running() {
  [ "$(docker inspect --format '{{.State.Running}}' "$1" 2>/dev/null)" = true ] || fail runtime_not_running
}

assert_container "$piwigo" class_archive_private_full_v3_piwigo piwigo
assert_container "$mariadb" class_archive_private_full_v3_piwigo db
assert_container "$immich_server" class_archive_private_full_v3_immich immich-server
assert_container "$immich_ml" class_archive_private_full_v3_immich immich-machine-learning
assert_container "$postgres" class_archive_private_full_v3_immich database
for name in "$piwigo" "$mariadb" "$immich_server" "$immich_ml" "$postgres"; do assert_running "$name"; done

piwigo_data=class_archive_private_full_v3_control_piwigo_data
piwigo_uploads=class_archive_private_full_v3_piwigo_uploads
piwigo_galleries=class_archive_private_full_v3_piwigo_galleries
piwigo_scripts=class_archive_private_full_v3_control_piwigo_scripts
immich_upload=class_archive_private_full_v3_immich_upload
piwigo_db=class_archive_private_full_v3_control_piwigo_db
immich_db=class_archive_private_full_v3_control_immich_db

assert_volume "$piwigo_data" class_archive_private_full_v3_piwigo piwigo_data
assert_volume "$piwigo_uploads" class_archive_private_full_v3_piwigo piwigo_uploads
assert_volume "$piwigo_galleries" class_archive_private_full_v3_piwigo piwigo_galleries
assert_volume "$piwigo_scripts" class_archive_private_full_v3_piwigo piwigo_scripts
assert_volume "$piwigo_db" class_archive_private_full_v3_piwigo piwigo_db
assert_volume "$immich_upload" class_archive_private_full_v3_immich immich_upload
assert_volume "$immich_db" class_archive_private_full_v3_immich immich_db

helper_image=$(docker inspect --format '{{.Image}}' "$mariadb" 2>/dev/null)
case "$helper_image" in sha256:[0-9a-f][0-9a-f]*) ;; *) fail helper_image_invalid ;; esac
docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
  --cap-drop ALL --security-opt no-new-privileges:true \
  --entrypoint tar "$helper_image" --version 2>/dev/null | grep -F 'GNU tar' >/dev/null || fail gnu_tar_required
command -v gpg >/dev/null 2>&1 || fail gpg_missing
gpg --version 2>/dev/null | grep -Eq '^gpg \(GnuPG\) 2\.[234]\.' || fail gpg_version_invalid
gpg --version 2>/dev/null | grep -Eq '^Cipher:.*AES256' || fail gpg_aes256_unavailable

volume_bytes() {
  value=$(docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true --entrypoint /bin/sh -v "$1:/source:ro" "$helper_image" \
    -eu -c 'du -sb /source | cut -f1' 2>/dev/null) || fail volume_size_failed
  case "$value" in ''|*[!0-9]*) fail volume_size_invalid ;; esac
  printf '%s' "$value"
}

postgres_query() {
  docker exec "$postgres" sh -eu -c 'exec psql --no-psqlrc --tuples-only --no-align --set ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --command "$1"' sh "$1" 2>/dev/null
}

original_bytes=$(( $(volume_bytes "$piwigo_uploads") + $(volume_bytes "$piwigo_galleries") ))
mariadb_bytes=$(volume_bytes "$piwigo_db")
postgres_bytes=$(volume_bytes "$immich_db")
config_bytes=$(( $(volume_bytes "$piwigo_data") + $(volume_bytes "$piwigo_scripts") ))
immich_upload_bytes=$(volume_bytes "$immich_upload")
ai_index_bytes=$(postgres_query "SELECT COALESCE(SUM(pg_total_relation_size(format('%I.%I',schemaname,tablename))),0)::bigint FROM pg_tables WHERE schemaname='public' AND tablename IN ('asset_face','face_search','person','smart_search');" | tr -d '[:space:]')
case "$ai_index_bytes" in ''|*[!0-9]*) fail ai_index_size_invalid ;; esac
raw_total=$(( original_bytes + mariadb_bytes + postgres_bytes + config_bytes + immich_upload_bytes ))
est_backup=$(( raw_total + (raw_total / 10) + 104857600 ))
est_restore=$(( raw_total + (raw_total / 10) + 104857600 ))
printf '%s\n' "OWNER_ORIGINAL_BYTES=$original_bytes"
printf '%s\n' "MARIADB_BYTES=$mariadb_bytes"
printf '%s\n' "IMMICH_POSTGRES_BYTES=$postgres_bytes"
printf '%s\n' "AI_INDEX_BYTES=$ai_index_bytes"
printf '%s\n' "CONFIG_STATE_BYTES=$config_bytes"
printf '%s\n' "IMMICH_UPLOAD_BYTES=$immich_upload_bytes"
printf '%s\n' "EST_BACKUP_BYTES=$est_backup"
printf '%s\n' "EST_RESTORE_BYTES=$est_restore"
if [ "$mode" = preflight ]; then
  printf '%s\n' 'OWNER_TEMP_BACKUP_HELPER=PASS action=preflight'
  exit 0
fi

validate_bundle_passphrase_identity backup
[ -d "$bundle" ] && [ ! -L "$bundle" ] || fail bundle_untrusted
[ -f "$passphrase_file" ] && [ ! -L "$passphrase_file" ] || fail passphrase_file_untrusted
[ "$(wc -l < "$passphrase_file" | tr -d '[:space:]')" = 1 ] || fail passphrase_file_invalid
[ "$(wc -c < "$passphrase_file" | tr -d '[:space:]')" -ge 80 ] || fail passphrase_file_invalid

gpg_home=$(mktemp -d /tmp/class-archive-owner-backup-gpg.XXXXXXXX) || fail gpg_temp_failed
chmod 0700 "$gpg_home"
export GNUPGHOME="$gpg_home"
cleanup() {
  rm -rf -- "$gpg_home"
}
trap cleanup EXIT HUP INT TERM

gpg_encrypt() {
  output=$1
  gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$passphrase_file" \
    --symmetric --cipher-algo AES256 --s2k-mode 3 --s2k-digest-algo SHA512 --s2k-count 65011712 \
    --compress-algo none --force-mdc --output "$output" 2>/dev/null
}

gpg_decrypt() {
  gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$passphrase_file" \
    --decrypt "$1" 2>/dev/null
}

assert_bundle_file() {
  [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %s "$1")" -gt 0 ] || fail backup_payload_invalid
}

verify_tar() {
  assert_bundle_file "$1"
  gpg_decrypt "$1" | tar -tf - | awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    END { exit bad ? 1 : 0 }
  ' >/dev/null || fail encrypted_tar_invalid
}

verify_payloads() {
  gpg_decrypt "$bundle/databases/mariadb.sql.gz.gpg" | gzip -t || fail mariadb_dump_invalid
  # First consume the entire encrypted stream so GPG's MDC/authentication is
  # checked. pg_restore may close stdin after reading the TOC and legitimately
  # SIGPIPE the producer, so its semantic check is a separate pass where only
  # the consumer status is authoritative.
  gpg_decrypt "$bundle/databases/immich-postgres.dump.gpg" >/dev/null || fail postgres_dump_authentication_failed
  set +o pipefail
  gpg_decrypt "$bundle/databases/immich-postgres.dump.gpg" \
    | docker exec -i "$postgres" sh -eu -c 'exec pg_restore --list' >/dev/null 2>&1
  restore_status=${PIPESTATUS[1]}
  set -o pipefail
  [ "$restore_status" -eq 0 ] || fail postgres_dump_invalid
  verify_tar "$bundle/business-state/piwigo-data.tar.gpg"
  verify_tar "$bundle/business-state/piwigo-scripts.tar.gpg"
  verify_tar "$bundle/media-archives/piwigo-uploads.tar.gpg"
  verify_tar "$bundle/media-archives/piwigo-galleries.tar.gpg"
  verify_tar "$bundle/immich-state/immich-upload.tar.gpg"
}

for path in \
  "$bundle/databases/mariadb.sql.gz.gpg" \
  "$bundle/databases/immich-postgres.dump.gpg" \
  "$bundle/business-state/piwigo-data.tar.gpg" \
  "$bundle/business-state/piwigo-scripts.tar.gpg" \
  "$bundle/media-archives/piwigo-uploads.tar.gpg" \
  "$bundle/media-archives/piwigo-galleries.tar.gpg" \
  "$bundle/immich-state/immich-upload.tar.gpg"; do
  [ ! -e "$path" ] || fail backup_payload_exists
done

archive_volume() {
  volume=$1 output=$2
  docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true --entrypoint /bin/sh -v "$volume:/source:ro" "$helper_image" \
    -eu -c 'exec tar --sort=name --format=posix --pax-option=delete=atime,delete=ctime \
      --numeric-owner --acls --xattrs --xattrs-include="*" -C /source -cf - .' \
    2>/dev/null | gpg_encrypt "$output" || fail volume_archive_failed
  assert_bundle_file "$output"
}

volume_state_digest() {
  volume=$1
  value=$(docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true --entrypoint /bin/sh -v "$volume:/source:ro" "$helper_image" \
    -eu -c 'exec tar --sort=name --format=posix --pax-option=delete=atime,delete=ctime \
      --numeric-owner --acls --xattrs --xattrs-include="*" -C /source -cf - .' 2>/dev/null \
    | sha256sum | awk '{print $1}') || fail volume_state_digest_failed
  case "$value" in ''|*[!0-9a-f]*) fail volume_state_digest_invalid ;; esac
  [ "${#value}" -eq 64 ] || fail volume_state_digest_invalid
  printf '%s' "$value"
}

encrypted_plaintext_digest() {
  value=$(gpg_decrypt "$1" | sha256sum | awk '{print $1}') || fail encrypted_plaintext_digest_failed
  case "$value" in ''|*[!0-9a-f]*) fail encrypted_plaintext_digest_invalid ;; esac
  [ "${#value}" -eq 64 ] || fail encrypted_plaintext_digest_invalid
  printf '%s' "$value"
}

archive_piwigo_data() {
  output=$1
  docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true --entrypoint /bin/sh -v "$piwigo_data:/source:ro" "$helper_image" \
    -eu -c 'exec tar --format=posix --numeric-owner --acls --xattrs --xattrs-include="*" \
      --exclude=./local/config/database.inc.php \
      --exclude=./_data/.class-archive-immich-bridge.json \
      --exclude=./_data/sessions --exclude=./_data/templates_c --exclude=./_data/combined \
      -C /source -cf - .' 2>/dev/null | gpg_encrypt "$output" || fail piwigo_data_archive_failed
  assert_bundle_file "$output"
}

mariadb_query() {
  docker exec "$mariadb" sh -eu -c 'exec mariadb --batch --skip-column-names --host=127.0.0.1 --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute "$1"' sh "$1" 2>/dev/null
}

owner_state_digest() {
  value=$(docker exec --user nginx "$piwigo" php /workspace/infra/scripts/capture-restore-fixture.php 2>/dev/null \
    | sha256sum | awk '{print $1}') || fail owner_state_capture_failed
  case "$value" in ''|*[!0-9a-f]*) fail owner_state_digest_invalid ;; esac
  [ "${#value}" -eq 64 ] || fail owner_state_digest_invalid
  printf '%s' "$value"
}

postgres_generation() {
  value=$(postgres_query 'SELECT pg_snapshot_xmax(pg_current_snapshot())::text;' | tr -d '[:space:]')
  case "$value" in ''|*[!0-9]*) fail postgres_generation_invalid ;; esac
  printf '%s' "$value"
}

postgres_state_digest() {
  # A full logical representation catches row-preserving UPDATEs and a
  # transaction that obtained its XID before this backup began. PostgreSQL 17
  # restrict keys are randomized, so only those two control lines are removed.
  value=$(docker exec --user postgres "$postgres" sh -eu -c \
    'exec pg_dump --format=plain --no-owner --no-acl --no-comments --dbname="$POSTGRES_DB"' 2>/dev/null \
    | sed -e '/^\\restrict /d' -e '/^\\unrestrict /d' \
    | sha256sum | awk '{print $1}') || fail postgres_state_digest_failed
  case "$value" in ''|*[!0-9a-f]*) fail postgres_state_digest_invalid ;; esac
  [ "${#value}" -eq 64 ] || fail postgres_state_digest_invalid
  printf '%s' "$value"
}

postgres_count() {
  value=$(postgres_query "SELECT COUNT(*) FROM $1;" | tr -d '[:space:]')
  case "$value" in ''|*[!0-9]*) fail postgres_count_invalid ;; esac
  printf '%s' "$value"
}

# Capture the immutable business/media state and the isolated Immich database
# generation before opening either logical snapshot. If any participating
# state moves before all payloads are complete, this run is discarded rather
# than publishing a cross-system point-in-time fiction.
owner_state_before=$(owner_state_digest)
postgres_generation_before=$(postgres_generation)
postgres_state_before=$(postgres_state_digest)
immich_upload_state_before=$(volume_state_digest "$immich_upload")
immich_assets_before=$(postgres_count asset)
immich_faces_before=$(postgres_count asset_face)
immich_people_before=$(postgres_count person)
immich_search_before=$(postgres_count smart_search)

docker exec "$mariadb" sh -eu -c 'exec mariadb-dump --quick --lock-all-tables --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --skip-comments --host=127.0.0.1 --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  2>/dev/null | gzip -1 | gpg_encrypt "$bundle/databases/mariadb.sql.gz.gpg" || fail mariadb_dump_failed
archive_piwigo_data "$bundle/business-state/piwigo-data.tar.gpg"
archive_volume "$piwigo_scripts" "$bundle/business-state/piwigo-scripts.tar.gpg"
archive_volume "$piwigo_uploads" "$bundle/media-archives/piwigo-uploads.tar.gpg"
archive_volume "$piwigo_galleries" "$bundle/media-archives/piwigo-galleries.tar.gpg"

ci_migration=$(mariadb_query "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';" | tr -d '[:space:]')
case "$ci_migration" in ''|*[!A-Za-z0-9_]*) fail class_identity_prefix_invalid ;; esac
ci_base=${ci_migration%migration}
pwg_base=${ci_base%class_identity_}
[ "$pwg_base" != "$ci_base" ] || fail piwigo_prefix_invalid
schema_version=$(mariadb_query "SELECT COALESCE(MAX(version),0) FROM ${ci_base}migration;" | tr -d '[:space:]')
case "$schema_version" in 15|16) ;; *) fail class_identity_schema_invalid ;; esac
source_records=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}photo_source;" | tr -d '[:space:]')
if [ "$schema_version" = 16 ]; then
  source_presentations=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}photo_source_presentation;" | tr -d '[:space:]')
else
  source_presentations=0
fi
canonical_photos=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}photo;" | tr -d '[:space:]')
piwigo_images=$(mariadb_query "SELECT COUNT(*) FROM ${pwg_base}images;" | tr -d '[:space:]')
album_relationships=$(mariadb_query "SELECT COUNT(*) FROM ${pwg_base}image_category;" | tr -d '[:space:]')
leaf_albums=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}album a WHERE a.state='ACTIVE' AND EXISTS (SELECT 1 FROM ${pwg_base}image_category ic WHERE ic.category_id=a.piwigo_category_id);" | tr -d '[:space:]')
comments=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}photo_comment;" | tr -d '[:space:]')
replies=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}photo_comment WHERE parent_comment_id IS NOT NULL;" | tr -d '[:space:]')
visible_people=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}person WHERE state='ACTIVE' AND visibility='VISIBLE';" | tr -d '[:space:]')
person_merges=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}person_merge;" | tr -d '[:space:]')
person_rules=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}person_photo_rule;" | tr -d '[:space:]')
spotlights=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}spotlight;" | tr -d '[:space:]')
memories=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}auto_collection;" | tr -d '[:space:]')
audit_events=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}audit_event;" | tr -d '[:space:]')
ai_assets=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_asset_index;" | tr -d '[:space:]')
ai_job_table_exists=$(mariadb_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${ci_base}ai_index_job';" | tr -d '[:space:]')
case "$ai_job_table_exists" in
  0) ai_jobs_total=0; ai_jobs_complete=0; ai_jobs_pending=0; ai_jobs_running=0; ai_jobs_unavailable=0; ai_jobs_failed=0; ai_jobs_cancelled=0 ;;
  1)
    ai_jobs_total=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job;" | tr -d '[:space:]')
    ai_jobs_complete=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job WHERE state='COMPLETE';" | tr -d '[:space:]')
    ai_jobs_pending=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job WHERE state='PENDING';" | tr -d '[:space:]')
    ai_jobs_running=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job WHERE state='RUNNING';" | tr -d '[:space:]')
    ai_jobs_unavailable=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job WHERE state='UNAVAILABLE';" | tr -d '[:space:]')
    ai_jobs_failed=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job WHERE state='FAILED';" | tr -d '[:space:]')
    ai_jobs_cancelled=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}ai_index_job WHERE state='CANCELLED';" | tr -d '[:space:]')
    ;;
  *) fail ai_job_table_shape_invalid ;;
esac
for value in "$source_records" "$source_presentations" "$canonical_photos" "$piwigo_images" "$album_relationships" "$leaf_albums" "$comments" "$replies" "$visible_people" "$person_merges" "$person_rules" "$spotlights" "$memories" "$audit_events" "$ai_assets" "$ai_jobs_total" "$ai_jobs_complete" "$ai_jobs_pending" "$ai_jobs_running" "$ai_jobs_unavailable" "$ai_jobs_failed" "$ai_jobs_cancelled"; do
  case "$value" in ''|*[!0-9]*) fail mariadb_count_invalid ;; esac
done

docker exec --user postgres "$postgres" sh -eu -c 'exec pg_dump --format=custom --compress=1 --no-owner --no-acl --dbname="$POSTGRES_DB"' \
  2>/dev/null | gpg_encrypt "$bundle/databases/immich-postgres.dump.gpg" || fail postgres_dump_failed
archive_volume "$immich_upload" "$bundle/immich-state/immich-upload.tar.gpg"

owner_state_after=$(owner_state_digest)
postgres_generation_after=$(postgres_generation)
postgres_state_after=$(postgres_state_digest)
immich_upload_state_after=$(volume_state_digest "$immich_upload")
immich_upload_archive_state=$(encrypted_plaintext_digest "$bundle/immich-state/immich-upload.tar.gpg")
immich_assets=$(postgres_count asset)
immich_faces=$(postgres_count asset_face)
immich_people=$(postgres_count person)
immich_search=$(postgres_count smart_search)
[ "$owner_state_before" = "$owner_state_after" ] || fail owner_state_changed_during_backup
[ "$postgres_generation_before" = "$postgres_generation_after" ] || fail postgres_state_changed_during_backup
[ "$postgres_state_before" = "$postgres_state_after" ] || fail postgres_state_changed_during_backup
[ "$immich_upload_state_before" = "$immich_upload_state_after" ] || fail immich_upload_changed_during_backup
[ "$immich_upload_state_before" = "$immich_upload_archive_state" ] || fail immich_upload_archive_snapshot_mismatch
[ "$immich_assets_before" = "$immich_assets" ] || fail postgres_state_changed_during_backup
[ "$immich_faces_before" = "$immich_faces" ] || fail postgres_state_changed_during_backup
[ "$immich_people_before" = "$immich_people" ] || fail postgres_state_changed_during_backup
[ "$immich_search_before" = "$immich_search" ] || fail postgres_state_changed_during_backup

verify_payloads

image_ref() {
  value=$(docker inspect --format '{{.Config.Image}}' "$1" 2>/dev/null)
  case "$value" in ''|*[!A-Za-z0-9@:/._+-]*) fail container_image_invalid ;; esac
  printf '%s' "$value"
}

printf '%s\n' "CLASS_IDENTITY_SCHEMA_VERSION=$schema_version"
printf '%s\n' 'PIWIGO_VERSION=16.4.0'
printf '%s\n' 'IMMICH_VERSION=3.1.0'
printf '%s\n' "SOURCE_RECORDS=$source_records"
printf '%s\n' "SOURCE_PRESENTATIONS=$source_presentations"
printf '%s\n' "CANONICAL_PHOTOS=$canonical_photos"
printf '%s\n' "PIWIGO_IMAGES=$piwigo_images"
printf '%s\n' "ALBUM_RELATIONSHIPS=$album_relationships"
printf '%s\n' "LEAF_ALBUMS=$leaf_albums"
printf '%s\n' "COMMENTS=$comments"
printf '%s\n' "REPLIES=$replies"
printf '%s\n' "VISIBLE_PEOPLE=$visible_people"
printf '%s\n' "PERSON_MERGES=$person_merges"
printf '%s\n' "PERSON_RULES=$person_rules"
printf '%s\n' "SPOTLIGHTS=$spotlights"
printf '%s\n' "MEMORIES=$memories"
printf '%s\n' "AUDIT_EVENTS=$audit_events"
printf '%s\n' "AI_ASSET_INDEX=$ai_assets"
printf '%s\n' "AI_JOBS_TOTAL=$ai_jobs_total"
printf '%s\n' "AI_JOBS_COMPLETE=$ai_jobs_complete"
printf '%s\n' "AI_JOBS_PENDING=$ai_jobs_pending"
printf '%s\n' "AI_JOBS_RUNNING=$ai_jobs_running"
printf '%s\n' "AI_JOBS_UNAVAILABLE=$ai_jobs_unavailable"
printf '%s\n' "AI_JOBS_FAILED=$ai_jobs_failed"
printf '%s\n' "AI_JOBS_CANCELLED=$ai_jobs_cancelled"
printf '%s\n' "IMMICH_ASSETS=$immich_assets"
printf '%s\n' "IMMICH_FACE_RECORDS=$immich_faces"
printf '%s\n' "IMMICH_RAW_PERSONS=$immich_people"
printf '%s\n' "IMMICH_SEARCH_INDEX=$immich_search"
printf '%s\n' "OWNER_STATE_SHA256=$owner_state_after"
printf '%s\n' "IMMICH_POSTGRES_STATE_SHA256=$postgres_state_after"
printf '%s\n' "IMMICH_UPLOAD_STATE_SHA256=$immich_upload_state_after"
printf '%s\n' "IMMICH_SNAPSHOT_XMAX=$postgres_generation_after"
printf '%s\n' "MARIADB_IMAGE=$(image_ref "$mariadb")"
printf '%s\n' "PIWIGO_IMAGE=$(image_ref "$piwigo")"
printf '%s\n' "IMMICH_SERVER_IMAGE=$(image_ref "$immich_server")"
printf '%s\n' "IMMICH_ML_IMAGE=$(image_ref "$immich_ml")"
printf '%s\n' "POSTGRES_IMAGE=$(image_ref "$postgres")"
printf '%s\n' 'OWNER_TEMP_BACKUP_HELPER=PASS action=backup'
