#!/usr/bin/env bash
set -euo pipefail

# Stream-only restore helper. It uses the local Docker control plane only for
# containers carrying the exact owner-restore labels. Every target volume is a
# fresh named volume bind-backed by the M:-resident ext4 restore filesystem.
# It never writes decrypted payloads directly onto exFAT.

umask 077
export LC_ALL=C

fail() {
  printf '%s\n' "OWNER_RESTORE_STREAM=FAIL code=$1" >&2
  exit 1
}

action=${1:-}
shift || true
case "$action" in
  verify|restore-mariadb|restore-immich-postgres|restore-piwigo-data|restore-piwigo-scripts|restore-piwigo-uploads|restore-piwigo-galleries|restore-immich-upload|write-piwigo-config) ;;
  *) fail action_invalid ;;
esac

bundle= passphrase_file= piwigo_env=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --bundle) [ "$#" -ge 2 ] || fail argument_missing; bundle=$2; shift 2 ;;
    --passphrase-file) [ "$#" -ge 2 ] || fail argument_missing; passphrase_file=$2; shift 2 ;;
    --piwigo-env) [ "$#" -ge 2 ] || fail argument_missing; piwigo_env=$2; shift 2 ;;
    *) fail argument_invalid ;;
  esac
done

case "$bundle" in /mnt/m/ClassArchive-Temporary-Recovery/bundles/owner-full-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) fail bundle_path_invalid ;; esac
case "$passphrase_file" in /mnt/c/*/.codex-work/owner-restore/runtime/owner-full-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z/gpg-passphrase.txt) ;; *) fail passphrase_path_invalid ;; esac
[ -d "$bundle" ] && [ ! -L "$bundle" ] || fail bundle_untrusted
[ -f "$passphrase_file" ] && [ ! -L "$passphrase_file" ] || fail passphrase_untrusted
[ "$(basename "$(dirname "$passphrase_file")")" = "$(basename "$bundle")" ] || fail passphrase_bundle_mismatch
# DrvFs projects Windows ACL-protected files as mode 0777 on this host.  The
# PowerShell launcher enforces the owner-only ACL and ignored path before WSL;
# this side independently pins the path, type, single-line shape and size.
[ "$(wc -l < "$passphrase_file" | tr -d '[:space:]')" = 1 ] || fail passphrase_shape_invalid
passphrase_bytes=$(wc -c < "$passphrase_file" | tr -d '[:space:]')
case "$passphrase_bytes" in ''|*[!0-9]*) fail passphrase_shape_invalid ;; esac
[ "$passphrase_bytes" -ge 64 ] && [ "$passphrase_bytes" -le 256 ] || fail passphrase_shape_invalid

socket=/var/run/docker.sock
[ -S "$socket" ] || fail restore_socket_missing
docker_host=(docker --host "unix://$socket")
root_dir=$("${docker_host[@]}" info --format '{{.DockerRootDir}}' 2>/dev/null) || fail restore_daemon_unavailable
[ "$root_dir" = /var/lib/docker ] || fail restore_control_plane_root_invalid

gpg_home=$(mktemp -d /tmp/class-archive-owner-restore-gpg.XXXXXXXX) || fail gpg_temp_failed
chmod 0700 "$gpg_home"
export GNUPGHOME="$gpg_home"
cleanup() { rm -rf -- "$gpg_home"; }
trap cleanup EXIT HUP INT TERM

decrypt() {
  [ -f "$1" ] && [ ! -L "$1" ] || fail encrypted_payload_untrusted
  gpg --batch --yes --no-tty --quiet --pinentry-mode loopback \
    --passphrase-file "$passphrase_file" --decrypt "$1" 2>/dev/null
}

assert_tar_safe() {
  decrypt "$1" | tar -tf - | awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    END { exit bad ? 1 : 0 }
  ' >/dev/null || fail encrypted_tar_invalid
}

piwigo_project=class_archive_owner_restore_v1_piwigo
immich_project=class_archive_owner_restore_v1_immich
mariadb_container=${piwigo_project}-db-1
postgres_container=${immich_project}-database-1
piwigo_data=class_archive_owner_restore_v1_piwigo_data
piwigo_scripts=class_archive_owner_restore_v1_piwigo_scripts
piwigo_uploads=class_archive_owner_restore_v1_piwigo_uploads
piwigo_galleries=class_archive_owner_restore_v1_piwigo_galleries
immich_upload=class_archive_owner_restore_v1_immich_upload

assert_container() {
  expected=$1 project=$2 service=$3
  identity=$("${docker_host[@]}" inspect --format '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "com.classarchive.scope"}}|{{.State.Running}}' "$expected" 2>/dev/null) || fail restore_container_missing
  [ "$identity" = "$project|$service|owner-restore-drill|true" ] || fail restore_container_identity_invalid
}

assert_volume() {
  expected=$1 project=$2 logical=$3
  expected_device=/mnt/classarchive-owner-restore-v1/volumes/$expected
  identity=$("${docker_host[@]}" volume inspect --format '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}|{{index .Labels "com.classarchive.storage"}}|{{index .Options "device"}}' "$expected" 2>/dev/null) || fail restore_volume_missing
  [ "$identity" = "$project|$logical|owner-restore-drill|m-ext4-bind|$expected_device" ] || fail restore_volume_identity_invalid
  [ -d "$expected_device" ] && [ ! -L "$expected_device" ] || fail restore_volume_backing_untrusted
}

helper_image() {
  image=$("${docker_host[@]}" inspect --format '{{.Image}}' "$mariadb_container" 2>/dev/null) || fail helper_image_missing
  case "$image" in sha256:[0-9a-f][0-9a-f]*) ;; *) fail helper_image_invalid ;; esac
  printf '%s' "$image"
}

restore_tar() {
  archive=$1 volume=$2 project=$3 logical=$4
  assert_tar_safe "$archive"
  assert_volume "$volume" "$project" "$logical"
  image=$(helper_image)
  empty=$("${docker_host[@]}" run --rm --network none --read-only --cap-drop ALL \
    --security-opt no-new-privileges:true --entrypoint sh -v "$volume:/target" "$image" \
    -eu -c 'find /target -mindepth 1 -print -quit' 2>/dev/null) || fail volume_empty_check_failed
  [ -z "$empty" ] || fail restore_volume_not_empty
  decrypt "$archive" | "${docker_host[@]}" run --rm -i --network none --read-only --cap-drop ALL \
    --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true \
    --entrypoint sh -v "$volume:/target" "$image" -eu -c \
    'exec tar --numeric-owner --same-owner --same-permissions --acls --xattrs --xattrs-include="*" -C /target -xf -' \
    >/dev/null 2>&1 || fail volume_restore_failed
}

case "$action" in
  verify)
    decrypt "$bundle/databases/mariadb.sql.gz.gpg" | gzip -t || fail mariadb_dump_invalid
    assert_container "$postgres_container" "$immich_project" database
    # First consume the complete encrypted stream so GPG verifies its MDC.
    # pg_restore --list may intentionally stop before GPG reaches EOF, which
    # otherwise turns a valid large dump into a pipefail/SIGPIPE false alarm.
    decrypt "$bundle/databases/immich-postgres.dump.gpg" >/dev/null || fail postgres_gpg_integrity_invalid
    set +e
    decrypt "$bundle/databases/immich-postgres.dump.gpg" | \
      "${docker_host[@]}" exec -i --user postgres "$postgres_container" sh -eu -c 'exec pg_restore --list' \
      >/dev/null 2>&1
    postgres_list_status=("${PIPESTATUS[@]}")
    set -e
    [ "${postgres_list_status[1]:-1}" = 0 ] || fail postgres_dump_invalid
    case "${postgres_list_status[0]:-1}" in 0|141) ;; *) fail postgres_dump_invalid ;; esac
    for archive in \
      "$bundle/business-state/piwigo-data.tar.gpg" \
      "$bundle/business-state/piwigo-scripts.tar.gpg" \
      "$bundle/media-archives/piwigo-uploads.tar.gpg" \
      "$bundle/media-archives/piwigo-galleries.tar.gpg" \
      "$bundle/immich-state/immich-upload.tar.gpg"; do assert_tar_safe "$archive"; done
    ;;
  restore-mariadb)
    assert_container "$mariadb_container" "$piwigo_project" db
    tables=$("${docker_host[@]}" exec "$mariadb_container" sh -eu -c \
      'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();"' 2>/dev/null) || fail mariadb_preflight_failed
    [ "$tables" = 0 ] || fail mariadb_not_empty
    decrypt "$bundle/databases/mariadb.sql.gz.gpg" | gzip -dc | \
      "${docker_host[@]}" exec -i "$mariadb_container" sh -eu -c \
      'exec mariadb --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
      >/dev/null 2>&1 || fail mariadb_restore_failed
    ;;
  restore-immich-postgres)
    assert_container "$postgres_container" "$immich_project" database
    decrypt "$bundle/databases/immich-postgres.dump.gpg" | \
      "${docker_host[@]}" exec -i --user postgres "$postgres_container" sh -eu -c \
      'exec pg_restore --exit-on-error --clean --if-exists --no-owner --no-privileges --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"' \
      >/dev/null 2>&1 || fail postgres_restore_failed
    ;;
  restore-piwigo-data) restore_tar "$bundle/business-state/piwigo-data.tar.gpg" "$piwigo_data" "$piwigo_project" piwigo_data ;;
  restore-piwigo-scripts) restore_tar "$bundle/business-state/piwigo-scripts.tar.gpg" "$piwigo_scripts" "$piwigo_project" piwigo_scripts ;;
  restore-piwigo-uploads) restore_tar "$bundle/media-archives/piwigo-uploads.tar.gpg" "$piwigo_uploads" "$piwigo_project" piwigo_uploads ;;
  restore-piwigo-galleries) restore_tar "$bundle/media-archives/piwigo-galleries.tar.gpg" "$piwigo_galleries" "$piwigo_project" piwigo_galleries ;;
  restore-immich-upload) restore_tar "$bundle/immich-state/immich-upload.tar.gpg" "$immich_upload" "$immich_project" immich_upload ;;
  write-piwigo-config)
    case "$piwigo_env" in /mnt/c/*/infra/owner-restore/.env.piwigo) ;; *) fail piwigo_env_path_invalid ;; esac
    [ -f "$piwigo_env" ] && [ ! -L "$piwigo_env" ] || fail piwigo_env_untrusted
    assert_volume "$piwigo_data" "$piwigo_project" piwigo_data
    image=$(helper_image)
    "${docker_host[@]}" run --rm --network none --read-only --cap-drop ALL --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE \
      --security-opt no-new-privileges:true --env-file "$piwigo_env" --entrypoint sh -v "$piwigo_data:/target" "$image" -eu -c '
        case "$DB_NAME|$DB_USER|$DB_PASSWORD" in *[!A-Za-z0-9_\|-]*) exit 71 ;; esac
        [ "$DB_NAME" = piwigo ] && [ "$DB_USER" = piwigo ] || exit 72
        mkdir -p /target/local/config
        target=/target/local/config/database.inc.php
        [ ! -e "$target" ] && [ ! -L "$target" ] || exit 73
        tmp="$target.restore.$$"
        trap '\''rm -f -- "$tmp"'\'' EXIT HUP INT TERM
        printf "%s\n" "<?php" "\$conf['\''dblayer'\''] = '\''mysqli'\'';" "\$conf['\''db_base'\''] = '\''$DB_NAME'\'';" "\$conf['\''db_user'\''] = '\''$DB_USER'\'';" "\$conf['\''db_password'\''] = '\''$DB_PASSWORD'\'';" "\$conf['\''db_host'\''] = '\''db'\'';" "" "\$prefixeTable = '\''piwigo_'\'';" "" "define('\''PHPWG_INSTALLED'\'', true);" "define('\''PWG_CHARSET'\'', '\''utf-8'\'');" "define('\''DB_CHARSET'\'', '\''utf8'\'');" "define('\''DB_COLLATE'\'', '\'''\'');" "" "?>" > "$tmp"
        chown 1000:1000 "$tmp" && chmod 0660 "$tmp" && mv -n "$tmp" "$target"
        trap - EXIT HUP INT TERM
      ' >/dev/null 2>&1 || fail piwigo_config_restore_failed
    ;;
esac

printf '%s\n' "OWNER_RESTORE_STREAM=PASS action=$action"
