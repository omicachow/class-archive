#!/usr/bin/env bash
# Capture the current Windows/WSL Class Archive runtimes into portable,
# plaintext local-media payloads. This is intentionally a one-host publisher;
# restore on macOS uses fresh volumes and freshly generated runtime secrets.

set -euo pipefail
umask 077

fail() {
  printf 'LOCAL_HANDOFF_CAPTURE=FAIL reason=%s\n' "$1" >&2
  exit 1
}

[ "$#" -eq 1 ] || {
  printf 'Usage: capture-local-private-runtime.sh PACKAGE_ROOT\n' >&2
  exit 64
}

root=$1
case "$root" in
  /mnt/m/ClassArchive-Mac-Handoff-Private/.staging-*/ClassArchive-Complete-Mac-Handoff-*) ;;
  *) fail package_root_outside_approved_m_staging ;;
esac
[ -d "$root/payloads/owner" ] || fail owner_payload_directory_missing
[ -d "$root/payloads/synthetic" ] || fail synthetic_payload_directory_missing
[ -d "$root/payloads/private-metadata" ] || fail private_metadata_directory_missing

for command_name in docker gzip sha256sum; do
  command -v "$command_name" >/dev/null 2>&1 || fail "missing_command_$command_name"
done

owner_db=class_archive_private_full_v3_piwigo-db-1
owner_piwigo=class_archive_private_full_v3_piwigo-piwigo-1
owner_pg=class_archive_private_full_v3_immich-database-1
owner_writers=(
  class_archive_private_full_v3_piwigo-piwigo-1
  class_archive_private_full_v3_immich-immich-web-compat-1
  class_archive_private_full_v3_immich-immich-gateway-1
  class_archive_private_full_v3_immich-immich-server-1
  class_archive_private_full_v3_immich-immich-machine-learning-1
)
synthetic_db=class_archive_piwigo-db-1
synthetic_piwigo=class_archive_piwigo-piwigo-1
synthetic_writers=(class_archive_piwigo-piwigo-1)

owner_piwigo_data=class_archive_private_full_v3_control_piwigo_data
owner_piwigo_scripts=class_archive_private_full_v3_control_piwigo_scripts
owner_uploads=class_archive_private_full_v3_piwigo_uploads
owner_galleries=class_archive_private_full_v3_piwigo_galleries
owner_derivatives=class_archive_private_full_v3_piwigo_derivatives
owner_immich_upload=class_archive_private_full_v3_immich_upload
synthetic_piwigo_data=class_archive_piwigo_data
synthetic_piwigo_scripts=class_archive_piwigo_scripts
synthetic_uploads=class_archive_piwigo_uploads
synthetic_galleries=class_archive_piwigo_galleries
synthetic_derivatives=class_archive_piwigo_derivatives

all_core_containers=("$owner_db" "$owner_piwigo" "$owner_pg" "$synthetic_db" "$synthetic_piwigo")
for container in "${all_core_containers[@]}"; do
  [ "$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null)" = true ] || fail "core_container_not_running_$container"
done

all_volumes=(
  "$owner_piwigo_data" "$owner_piwigo_scripts" "$owner_uploads" "$owner_galleries"
  "$owner_derivatives" "$owner_immich_upload" "$synthetic_piwigo_data"
  "$synthetic_piwigo_scripts" "$synthetic_uploads" "$synthetic_galleries"
  "$synthetic_derivatives"
)
for volume in "${all_volumes[@]}"; do
  docker volume inspect "$volume" >/dev/null 2>&1 || fail "required_volume_missing_$volume"
done

helper_image=$(docker inspect --format '{{.Image}}' "$owner_db")
case "$helper_image" in sha256:[0-9a-f][0-9a-f]*) ;; *) fail helper_image_digest_invalid ;; esac

started_owner=()
started_synthetic=()
clone_name=''
clone_volume=''

cleanup() {
  status=$?
  if [ -n "$clone_name" ] && docker container inspect "$clone_name" >/dev/null 2>&1; then
    docker rm -f "$clone_name" >/dev/null 2>&1 || true
  fi
  if [ -n "$clone_volume" ] && docker volume inspect "$clone_volume" >/dev/null 2>&1; then
    label=$(docker volume inspect --format '{{index .Labels "org.classarchive.disposable"}}' "$clone_volume" 2>/dev/null || true)
    [ "$label" = handoff-sanitizer ] && docker volume rm "$clone_volume" >/dev/null 2>&1 || true
  fi
  if [ "${#started_owner[@]}" -gt 0 ]; then
    docker start "${started_owner[@]}" >/dev/null 2>&1 || true
  fi
  if [ "${#started_synthetic[@]}" -gt 0 ]; then
    docker start "${started_synthetic[@]}" >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT HUP INT TERM

stop_writers() {
  group=$1
  shift
  for container in "$@"; do
    if [ "$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || true)" = true ]; then
      docker stop --time 60 "$container" >/dev/null
      if [ "$group" = owner ]; then
        started_owner+=("$container")
      else
        started_synthetic+=("$container")
      fi
    fi
  done
}

assert_new_output() {
  [ ! -e "$1" ] && [ ! -e "$1.partial" ] || fail output_already_exists
}

archive_volume() {
  volume=$1
  output=$2
  shift 2
  assert_new_output "$output"
  docker run --rm --log-driver none --network none --read-only \
    --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/source,readonly" \
    --entrypoint /bin/sh "$helper_image" -eu -c '
      exec tar --sort=name --format=posix --pax-option=delete=atime,delete=ctime \
        --numeric-owner --acls --xattrs --xattrs-include="*" -C /source -cf - "$@"
    ' _ "$@" >"$output.partial"
  [ -s "$output.partial" ] || fail volume_archive_empty
  mv -- "$output.partial" "$output"
}

archive_piwigo_data() {
  volume=$1
  output=$2
  assert_new_output "$output"
  docker run --rm --log-driver none --network none --read-only \
    --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/source,readonly" \
    --entrypoint /bin/sh "$helper_image" -eu -c '
      exec tar --sort=name --format=posix --pax-option=delete=atime,delete=ctime \
        --numeric-owner --acls --xattrs --xattrs-include="*" \
        --exclude=./local/config/database.inc.php \
        --exclude=./_data/.class-archive-immich-bridge.json \
        --exclude=./_data/sessions --exclude=./_data/templates_c \
        --exclude=./_data/combined --exclude=./_data/logs --exclude=./_data/i \
        --exclude=./upload --exclude=./galleries --exclude=./backups \
        --exclude=./.env --exclude=./.env.* \
        -C /source -cf - .
    ' >"$output.partial"
  [ -s "$output.partial" ] || fail piwigo_data_archive_empty
  mv -- "$output.partial" "$output"
}

sanitized_mariadb_dump() {
  source_container=$1
  output=$2
  scope=$3
  assert_new_output "$output"
  database=$(docker exec "$source_container" printenv MARIADB_DATABASE | tr -d '\r\n')
  case "$database" in ''|*[!A-Za-z0-9_]*) fail mariadb_database_name_invalid ;; esac
  prefix_count=$(docker exec "$source_container" sh -eu -c \
    'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=0x70697769676f5f636c6173735f6964656e746974795f6d6967726174696f6e;"' | tr -d '[:space:]')
  [ "$prefix_count" = 1 ] || fail mariadb_expected_prefix_missing

  stamp=$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM
  clone_name="classarchive-handoff-sanitize-${scope}-${stamp}"
  clone_volume="classarchive_handoff_sanitize_${scope}_${stamp}"
  docker volume create --label org.classarchive.disposable=handoff-sanitizer "$clone_volume" >/dev/null
  docker run -d --name "$clone_name" --network none --log-driver none \
    --mount "type=volume,source=$clone_volume,target=/var/lib/mysql" \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 "$helper_image" >/dev/null
  ready=0
  for _ in $(seq 1 90); do
    if docker exec "$clone_name" mariadb-admin --protocol=socket --user=root ping --silent >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 1
  done
  [ "$ready" = 1 ] || fail mariadb_sanitizer_not_ready
  docker exec "$clone_name" mariadb --protocol=socket --user=root --execute="CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  docker exec "$source_container" sh -eu -c \
    'exec mariadb-dump --quick --lock-all-tables --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --skip-comments --host=127.0.0.1 --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
    | docker exec -i "$clone_name" mariadb --protocol=socket --user=root "$database"

  for table in piwigo_sessions piwigo_user_auth_keys; do
    count=$(docker exec "$clone_name" mariadb --batch --skip-column-names --protocol=socket --user=root "$database" --execute="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table';" | tr -d '[:space:]')
    [ "$count" = 1 ] || fail "required_sensitive_table_missing_$table"
    docker exec "$clone_name" mariadb --protocol=socket --user=root "$database" --execute="TRUNCATE TABLE \`$table\`;"
  done
  lease_exists=$(docker exec "$clone_name" mariadb --batch --skip-column-names --protocol=socket --user=root "$database" --execute="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='piwigo_class_identity_private_e2e_fixture_lease';" | tr -d '[:space:]')
  [ "$lease_exists" = 0 ] || docker exec "$clone_name" mariadb --protocol=socket --user=root "$database" --execute='TRUNCATE TABLE piwigo_class_identity_private_e2e_fixture_lease;'
  docker exec "$clone_name" mariadb --protocol=socket --user=root "$database" --execute="UPDATE piwigo_user_infos SET activation_key=NULL,activation_key_expire=NULL; UPDATE piwigo_user_mail_notification SET check_key=''; DELETE FROM piwigo_config WHERE param='secret_key';"

  unsafe=$(docker exec "$clone_name" mariadb --batch --skip-column-names --protocol=socket --user=root "$database" --execute="SELECT (SELECT COUNT(*) FROM piwigo_sessions)+(SELECT COUNT(*) FROM piwigo_user_auth_keys)+(SELECT COUNT(*) FROM piwigo_user_infos WHERE activation_key IS NOT NULL OR activation_key_expire IS NOT NULL)+(SELECT COUNT(*) FROM piwigo_user_mail_notification WHERE check_key <> '')+(SELECT COUNT(*) FROM piwigo_config WHERE param='secret_key');" | tr -d '[:space:]')
  [ "$unsafe" = 0 ] || fail mariadb_sanitization_incomplete
  docker exec "$clone_name" mariadb-dump --quick --lock-all-tables --routines --events --triggers \
    --hex-blob --default-character-set=utf8mb4 --skip-comments --protocol=socket --user=root "$database" \
    | gzip -1 -n >"$output.partial"
  gzip -t "$output.partial"
  [ -s "$output.partial" ] || fail mariadb_dump_empty
  mv -- "$output.partial" "$output"
  docker rm -f "$clone_name" >/dev/null
  docker volume rm "$clone_volume" >/dev/null
  clone_name=''
  clone_volume=''
}

sanitized_postgres_dump() {
  output=$1
  assert_new_output "$output"
  docker exec --user postgres "$owner_pg" pg_dump --format=custom --compress=1 \
    --no-owner --no-acl --serializable-deferrable \
    --exclude-table-data=public.session \
    --exclude-table-data=public.session_sync_checkpoint \
    --exclude-table-data=public.api_key \
    --exclude-table-data=public.shared_link \
    --exclude-table-data=public.shared_link_asset \
    --exclude-table-data=public.video_stream_session \
    --exclude-table-data=public.video_stream_variant \
    --exclude-table-data=public.video_stream_segment \
    --exclude-table-data=public.system_metadata \
    --exclude-table-data=public.user_metadata \
    --dbname=immich >"$output.partial"
  docker exec --user postgres "$owner_pg" pg_restore --list >/dev/null <"$output.partial"
  [ -s "$output.partial" ] || fail postgres_dump_empty
  mv -- "$output.partial" "$output"
}

stop_writers owner "${owner_writers[@]}"

sanitized_mariadb_dump "$owner_db" "$root/payloads/owner/owner-mariadb.sql.gz" owner
sanitized_postgres_dump "$root/payloads/owner/owner-immich-postgres.dump"
archive_piwigo_data "$owner_piwigo_data" "$root/payloads/owner/owner-piwigo-data.tar"
archive_volume "$owner_piwigo_scripts" "$root/payloads/owner/owner-piwigo-scripts.tar" .
archive_volume "$owner_uploads" "$root/payloads/owner/owner-canonical-uploads.tar" .
archive_volume "$owner_galleries" "$root/payloads/owner/owner-canonical-galleries.tar" .
archive_volume "$owner_derivatives" "$root/payloads/owner/owner-piwigo-derivatives.tar" .

immich_roots=$(docker run --rm --log-driver none --network none --read-only \
  --mount "type=volume,source=$owner_immich_upload,target=/source,readonly" \
  --entrypoint /bin/sh "$helper_image" -eu -c 'find /source -mindepth 1 -maxdepth 1 -printf "%f\n" | sort')
[ "$immich_roots" = "$(printf 'backups\nencoded-video\nlibrary\nprofile\nthumbs\nupload')" ] || fail immich_upload_root_shape_unknown
archive_volume "$owner_immich_upload" "$root/payloads/owner/owner-immich-canonical.tar" ./library ./upload ./profile
archive_volume "$owner_immich_upload" "$root/payloads/owner/owner-immich-derivatives.tar" ./thumbs ./encoded-video

docker start "${started_owner[@]}" >/dev/null
started_owner=()

stop_writers synthetic "${synthetic_writers[@]}"
sanitized_mariadb_dump "$synthetic_db" "$root/payloads/synthetic/synthetic-mariadb.sql.gz" synthetic
archive_piwigo_data "$synthetic_piwigo_data" "$root/payloads/synthetic/synthetic-piwigo-data.tar"
archive_volume "$synthetic_piwigo_scripts" "$root/payloads/synthetic/synthetic-piwigo-scripts.tar" .
archive_volume "$synthetic_uploads" "$root/payloads/synthetic/synthetic-uploads.tar" .
archive_volume "$synthetic_galleries" "$root/payloads/synthetic/synthetic-galleries.tar" .
archive_volume "$synthetic_derivatives" "$root/payloads/synthetic/synthetic-derivatives.tar" .
docker start "${started_synthetic[@]}" >/dev/null
started_synthetic=()

printf '{"format":"class-archive-runtime-sanitization-v1","mariadb_sessions":0,"mariadb_auth_keys":0,"mariadb_activation_keys":0,"postgres_sessions":"excluded","postgres_api_keys":"excluded","runtime_secrets_included":false}\n' >"$root/payloads/private-metadata/runtime-sanitization.json"
printf 'LOCAL_HANDOFF_CAPTURE=PASS\n'
