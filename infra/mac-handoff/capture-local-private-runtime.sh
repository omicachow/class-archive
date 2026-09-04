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

[ "$#" -ge 1 ] && [ "$#" -le 2 ] || {
  printf 'Usage: capture-local-private-runtime.sh PACKAGE_ROOT [--preflight-only]\n' >&2
  exit 64
}
mode=capture
if [ "$#" -eq 2 ]; then
  [ "$2" = --preflight-only ] || fail unknown_mode
  mode=preflight
fi

command -v realpath >/dev/null 2>&1 || fail missing_command_realpath
root_lexical=$(realpath -sm -- "$1")
root_physical=$(realpath -e -- "$1")
[ "$root_lexical" = "$root_physical" ] || fail package_root_symlink_forbidden
root=$root_physical
parent=$(dirname -- "$root")
staging_name=$(basename -- "$parent")
package_name=$(basename -- "$root")
approved_staging_input=${CLASS_ARCHIVE_HANDOFF_STAGING_ROOT:-}
[ -n "$approved_staging_input" ] || fail approved_staging_root_missing
approved_staging_lexical=$(realpath -sm -- "$approved_staging_input")
approved_staging_root=$(realpath -e -- "$approved_staging_input")
[ "$approved_staging_lexical" = "$approved_staging_root" ] || fail approved_staging_root_symlink_forbidden
[ -d "$approved_staging_root" ] && [ ! -L "$approved_staging_root" ] || fail approved_staging_root_invalid
[ "$(dirname -- "$parent")" = "$approved_staging_root" ] || fail package_root_outside_approved_staging
case "$staging_name" in .staging-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) fail staging_name_invalid ;; esac
case "$package_name" in ClassArchive-Complete-Mac-Handoff-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) fail package_name_invalid ;; esac
[ "${staging_name#.staging-}" = "${package_name#ClassArchive-Complete-Mac-Handoff-}" ] || fail staging_package_timestamp_mismatch
[ -d "$root/payloads/owner" ] || fail owner_payload_directory_missing
[ -d "$root/payloads/synthetic" ] || fail synthetic_payload_directory_missing
[ -d "$root/payloads/private-metadata" ] || fail private_metadata_directory_missing

for command_name in docker flock gzip python3 sha256sum; do
  command -v "$command_name" >/dev/null 2>&1 || fail "missing_command_$command_name"
done

# One stable host-local lock covers every staging timestamp.  Without it, two
# publishers could stop/start the same writer set and produce mutually
# inconsistent payloads even though their output directories differ.
exec 9>/tmp/classarchive-local-private-runtime-capture.lock
flock -n 9 || fail concurrent_capture_in_progress

owner_db=class_archive_private_full_v3_piwigo-db-1
owner_piwigo=class_archive_private_full_v3_piwigo-piwigo-1
owner_pg=class_archive_private_full_v3_immich-database-1
owner_redis=class_archive_private_full_v3_immich-redis-1
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

owner_expected=("$owner_db" "$owner_pg" "$owner_redis" "${owner_writers[@]}")
synthetic_expected=("$synthetic_db" "${synthetic_writers[@]}")

container_is_ready() {
  container=$1
  running=$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || true)
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$container" 2>/dev/null || true)
  [ "$running" = true ] && { [ -z "$health" ] || [ "$health" = healthy ]; }
}

is_expected_container() {
  candidate=$1
  shift
  for expected_container in "$@"; do
    [ "$candidate" != "$expected_container" ] || return 0
  done
  return 1
}

assert_project_running_set() {
  project=$1
  shift
  local running_output
  running_output=$(docker ps --filter "label=com.docker.compose.project=$project" --format '{{.Names}}') \
    || fail "project_container_inventory_failed_$project"
  while IFS= read -r running_container; do
    [ -z "$running_container" ] || is_expected_container "$running_container" "$@" \
      || fail "unexpected_running_project_container_$running_container"
  done <<<"$running_output"
}

assert_network_running_set() {
  local scope=$1
  shift
  local -A networks=()
  local expected_container network connected_container running network_output connected_output
  for expected_container in "$@"; do
    network_output=$(docker inspect --format '{{range $networkName, $network := .NetworkSettings.Networks}}{{println $networkName}}{{end}}' "$expected_container") \
      || fail "container_network_inventory_failed_$expected_container"
    while IFS= read -r network; do
      [ -z "$network" ] || networks["$network"]=1
    done <<<"$network_output"
  done
  for network in "${!networks[@]}"; do
    connected_output=$(docker network inspect --format '{{range $id, $container := .Containers}}{{println $container.Name}}{{end}}' "$network") \
      || fail "network_inventory_failed_$network"
    while IFS= read -r connected_container; do
      [ -n "$connected_container" ] || continue
      if ! is_expected_container "$connected_container" "$@"; then
        running=$(docker inspect --format '{{.State.Running}}' "$connected_container" 2>/dev/null || true)
        [ "$running" != true ] || fail "unexpected_running_${scope}_network_container_$connected_container"
      fi
    done <<<"$connected_output"
  done
}

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

all_expected_containers=("${owner_expected[@]}" "${synthetic_expected[@]}")
for container in "${all_expected_containers[@]}"; do
  container_is_ready "$container" || fail "expected_container_not_ready_$container"
done
assert_project_running_set class_archive_private_full_v3_piwigo "$owner_db" "$owner_piwigo"
assert_project_running_set class_archive_private_full_v3_immich "$owner_pg" "$owner_redis" \
  class_archive_private_full_v3_immich-immich-web-compat-1 \
  class_archive_private_full_v3_immich-immich-gateway-1 \
  class_archive_private_full_v3_immich-immich-server-1 \
  class_archive_private_full_v3_immich-immich-machine-learning-1
assert_project_running_set class_archive_piwigo "$synthetic_db" "$synthetic_piwigo"
assert_network_running_set owner "${owner_expected[@]}"
assert_network_running_set synthetic "${synthetic_expected[@]}"

all_volumes=(
  "$owner_piwigo_data" "$owner_piwigo_scripts" "$owner_uploads" "$owner_galleries"
  "$owner_derivatives" "$owner_immich_upload" "$synthetic_piwigo_data"
  "$synthetic_piwigo_scripts" "$synthetic_uploads" "$synthetic_galleries"
  "$synthetic_derivatives"
)
for volume in "${all_volumes[@]}"; do
  docker volume inspect "$volume" >/dev/null 2>&1 || fail "required_volume_missing_$volume"
  attached_output=$(docker ps --filter "volume=$volume" --format '{{.Names}}') \
    || fail "volume_attachment_inventory_failed_$volume"
  while IFS= read -r attached_container; do
    [ -z "$attached_container" ] || is_expected_container "$attached_container" "${all_expected_containers[@]}" \
      || fail "unexpected_container_on_protected_volume_$volume"
  done <<<"$attached_output"
done

helper_image=$(docker inspect --format '{{.Image}}' "$owner_db")
case "$helper_image" in sha256:[0-9a-f][0-9a-f]*) ;; *) fail helper_image_digest_invalid ;; esac
docker run --rm --log-driver none --network none --read-only --entrypoint sh "$helper_image" -eu -c '
  tar --version | grep -q "GNU tar"
  find --version >/dev/null
  command -v gzip sha256sum find tar xargs >/dev/null
' || fail helper_capability_preflight_failed
docker exec "$owner_db" sh -eu -c 'command -v mariadb mariadb-dump mariadb-admin >/dev/null' \
  || fail mariadb_tool_preflight_failed
docker exec --user postgres "$owner_pg" sh -eu -c 'command -v psql pg_dump pg_restore >/dev/null' \
  || fail postgres_tool_preflight_failed

for volume in "${all_volumes[@]}"; do
  docker run --rm --log-driver none --network none --read-only \
    --mount "type=volume,source=$volume,target=/source,readonly" \
    --entrypoint sh "$helper_image" -eu -c '
      test -z "$(find /source -xdev \( -type l -o \( ! -type f ! -type d \) -o \( -type f -links +1 \) \) -print -quit)"
    ' || fail "nonportable_node_in_volume_$volume"
done

open_ai_jobs=$(docker exec "$owner_db" sh -eu -c \
  'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job WHERE state <> '\''COMPLETE'\'';"' \
  | tr -d '[:space:]')
[ "$open_ai_jobs" = 0 ] || fail owner_ai_jobs_open
if [ "$mode" = preflight ]; then
  printf 'LOCAL_HANDOFF_CAPTURE_PREFLIGHT=PASS\n'
  exit 0
fi

started_owner=()
started_synthetic=()
clone_name=''
clone_volume=''
comparison_nonce="$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
owner_capture_compare="$root/payloads/private-metadata/.owner-capture-compare-$comparison_nonce.json"
owner_postgres_compare="$root/payloads/private-metadata/.owner-postgres-compare-$comparison_nonce.json"
synthetic_capture_compare="$root/payloads/synthetic/.synthetic-capture-compare-$comparison_nonce.json"

remove_comparison_files() {
  local path
  for path in "$owner_capture_compare" "$owner_postgres_compare" "$synthetic_capture_compare"; do
    rm -f -- "$path" "$path.partial" || return 1
  done
}

cleanup() {
  status=$?
  if [ -n "$clone_name" ] && docker container inspect "$clone_name" >/dev/null 2>&1; then
    docker rm -f "$clone_name" >/dev/null 2>&1 || true
  fi
  if [ -n "$clone_volume" ] && docker volume inspect "$clone_volume" >/dev/null 2>&1; then
    label=$(docker volume inspect --format '{{index .Labels "org.classarchive.disposable"}}' "$clone_volume" 2>/dev/null || true)
    [ "$label" = handoff-sanitizer ] && docker volume rm "$clone_volume" >/dev/null 2>&1 || true
  fi
  if ! remove_comparison_files; then
    printf 'LOCAL_HANDOFF_CAPTURE=FAIL reason=comparison_file_cleanup_failed\n' >&2
    status=1
  fi
  trap - EXIT HUP INT TERM
  if [ "${#started_owner[@]}" -gt 0 ] && ! restart_group "${started_owner[@]}"; then
    printf 'LOCAL_HANDOFF_CAPTURE=FAIL reason=owner_container_restart_failed\n' >&2
    status=1
  fi
  if [ "${#started_synthetic[@]}" -gt 0 ] && ! restart_group "${started_synthetic[@]}"; then
    printf 'LOCAL_HANDOFF_CAPTURE=FAIL reason=synthetic_container_restart_failed\n' >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

wait_container_ready() {
  container=$1
  for _ in $(seq 1 180); do
    running=$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || true)
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$container" 2>/dev/null || true)
    if [ "$running" = true ] && { [ -z "$health" ] || [ "$health" = healthy ]; }; then
      return 0
    fi
    [ "$health" != unhealthy ] || return 1
    sleep 1
  done
  return 1
}

restart_group() {
  [ "$#" -gt 0 ] || return 0
  docker start "$@" >/dev/null || return 1
  for container in "$@"; do
    wait_container_ready "$container" || return 1
  done
}

stop_writers() {
  group=$1
  shift
  for container in "$@"; do
    if [ "$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || true)" = true ]; then
      # Record the originally-running writer before issuing stop.  Cleanup may
      # safely call `docker start` on a still-running container, whereas
      # recording after stop leaves a signal/error window in which a stopped
      # service is absent from the restart set.
      if [ "$group" = owner ]; then
        started_owner+=("$container")
      else
        started_synthetic+=("$container")
      fi
      docker stop -t 60 "$container" >/dev/null
    fi
  done
}

assert_new_output() {
  [ ! -e "$1" ] && [ ! -e "$1.partial" ] || fail output_already_exists
}

mariadb_scalar() {
  local container=$1
  local query=$2
  local value
  value=$(docker exec "$container" sh -eu -c \
    'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="$1"' \
    _ "$query" | tr -d '[:space:]')
  case "$value" in ''|*[!0-9]*) fail mariadb_count_invalid ;; esac
  printf '%s' "$value"
}

postgres_scalar() {
  local query=$1
  local database value
  database=$(docker exec "$owner_pg" printenv POSTGRES_DB | tr -d '\r\n')
  case "$database" in ''|*[!A-Za-z0-9_]*) fail postgres_database_name_invalid ;; esac
  value=$(docker exec --user postgres "$owner_pg" psql --dbname="$database" --tuples-only --no-align \
    --command="$query" | tr -d '[:space:]')
  case "$value" in ''|*[!0-9]*) fail postgres_count_invalid ;; esac
  printf '%s' "$value"
}

volume_file_count() {
  local volume=$1
  docker run --rm --log-driver none --network none --read-only \
    --mount "type=volume,source=$volume,target=/source,readonly" \
    --entrypoint sh "$helper_image" -eu -c 'find /source -xdev -type f -printf ".\n" | wc -l' \
    | tr -d '[:space:]'
}

managed_original_file_count() {
  local database_container=$1
  local uploads_volume=$2
  local galleries_volume=$3
  local expected

  # The upload/gallery volumes also contain web-server guard files and may
  # retain unreferenced QA or pending binaries.  They are storage files, not
  # published Piwigo originals.  Count only image paths referenced by
  # piwigo_images and prove every referenced path resolves to a regular file in
  # one of the two read-only volumes.  SQL emits HEX(path), so embedded newline
  # or control bytes cannot alter record boundaries.  Python validates the
  # record count, UTF-8, path grammar, and macOS-portable normalized uniqueness
  # before emitting NUL-delimited relative paths to the isolated file checker.
  # Any malformed, traversal-like, missing, symlink, or duplicate path makes
  # capture fail closed.
  expected=$(mariadb_scalar "$database_container" 'SELECT COUNT(*) FROM piwigo_images;')
  if ! docker exec "$database_container" sh -eu -c \
    'exec mariadb --batch --skip-column-names --raw --default-character-set=utf8mb4 --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT id,HEX(path) FROM piwigo_images ORDER BY id;"' \
    | python3 -I -c '
import re
import sys
import unicodedata

expected = int(sys.argv[1])
seen_ids = set()
seen_paths = set()
count = 0
for raw in sys.stdin.buffer:
    line = raw[:-1] if raw.endswith(b"\n") else raw
    if line.endswith(b"\r"):
        line = line[:-1]
    fields = line.split(b"\t")
    if len(fields) != 2 or not re.fullmatch(rb"[1-9][0-9]*", fields[0]):
        raise SystemExit("managed_original_sql_record_invalid")
    image_id = int(fields[0])
    if image_id in seen_ids:
        raise SystemExit("managed_original_image_id_duplicate")
    seen_ids.add(image_id)
    encoded = fields[1]
    if len(encoded) == 0 or len(encoded) % 2 or not re.fullmatch(rb"[0-9A-F]+", encoded):
        raise SystemExit("managed_original_path_hex_invalid")
    try:
        path = bytes.fromhex(encoded.decode("ascii")).decode("utf-8", "strict")
    except (UnicodeDecodeError, ValueError):
        raise SystemExit("managed_original_path_utf8_invalid")
    if any(ord(character) < 32 or ord(character) == 127 for character in path) or "\\" in path:
        raise SystemExit("managed_original_path_control_invalid")
    if path.startswith("./upload/"):
        root = "upload"
        relative = path[len("./upload/"):]
    elif path.startswith("./galleries/"):
        root = "galleries"
        relative = path[len("./galleries/"):]
    else:
        raise SystemExit("managed_original_path_root_invalid")
    segments = relative.split("/")
    if any(segment in {"", ".", ".."} for segment in segments):
        raise SystemExit("managed_original_path_segment_invalid")
    portable = unicodedata.normalize("NFC", root + "/" + relative).casefold()
    if portable in seen_paths:
        raise SystemExit("managed_original_path_duplicate")
    seen_paths.add(portable)
    sys.stdout.buffer.write((root + "/" + relative).encode("utf-8") + b"\0")
    count += 1
if count != expected or len(seen_ids) != expected or len(seen_paths) != expected:
    raise SystemExit("managed_original_count_mismatch")
' "$expected" \
    | docker run --rm -i --log-driver none --network none --read-only \
      --mount "type=volume,source=$uploads_volume,target=/source/upload,readonly" \
      --mount "type=volume,source=$galleries_volume,target=/source/galleries,readonly" \
      --entrypoint xargs "$helper_image" -0 -r -n 64 sh -eu -c '
        for relative do
          case "$relative" in upload/*|galleries/*) ;; *) exit 71 ;; esac
          target=/source/$relative
          [ -f "$target" ] && [ ! -L "$target" ] || exit 73
        done
      ' _; then
    fail managed_original_verification_failed
  fi
  printf '%s' "$expected"
}

write_owner_capture_counts() {
  local output=$1
  assert_new_output "$output"
  local schema source_records canonical images relationships albums comments replies visible_people ai_rows ai_jobs ai_complete ai_open originals
  local piwigo_data_files piwigo_script_files raw_upload_files raw_gallery_files derivative_files immich_upload_files managed_uploads managed_galleries
  schema=$(mariadb_scalar "$owner_db" 'SELECT COALESCE(MAX(version),0) FROM piwigo_class_identity_migration;')
  source_records=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_photo_source;')
  canonical=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_photo;')
  images=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_images;')
  relationships=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_image_category;')
  albums=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_album;')
  comments=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_photo_comment;')
  replies=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_photo_comment WHERE parent_comment_id IS NOT NULL;')
  visible_people=$(mariadb_scalar "$owner_db" "SELECT COUNT(*) FROM piwigo_class_identity_person WHERE state='ACTIVE' AND visibility='VISIBLE';")
  ai_rows=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_ai_asset_index;')
  ai_jobs=$(mariadb_scalar "$owner_db" 'SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job;')
  ai_complete=$(mariadb_scalar "$owner_db" "SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job WHERE state='COMPLETE';")
  ai_open=$(mariadb_scalar "$owner_db" "SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job WHERE state<>'COMPLETE';")
  piwigo_data_files=$(volume_file_count "$owner_piwigo_data")
  piwigo_script_files=$(volume_file_count "$owner_piwigo_scripts")
  raw_upload_files=$(volume_file_count "$owner_uploads")
  raw_gallery_files=$(volume_file_count "$owner_galleries")
  derivative_files=$(volume_file_count "$owner_derivatives")
  immich_upload_files=$(volume_file_count "$owner_immich_upload")
  originals=$(managed_original_file_count "$owner_db" "$owner_uploads" "$owner_galleries")
  managed_uploads=$(mariadb_scalar "$owner_db" "SELECT COUNT(*) FROM piwigo_images WHERE path LIKE './upload/%';")
  managed_galleries=$(mariadb_scalar "$owner_db" "SELECT COUNT(*) FROM piwigo_images WHERE path LIKE './galleries/%';")
  [ $(( managed_uploads + managed_galleries )) -eq "$originals" ] || fail owner_managed_original_root_count_mismatch
  printf '{"format":"class-archive-owner-capture-counts-v2","captured_at":"%s","schema_version":%s,"source_records":%s,"canonical_photos":%s,"piwigo_images":%s,"physical_originals":%s,"album_relationships":%s,"albums":%s,"comments_and_replies":%s,"replies":%s,"visible_people":%s,"ai_index_rows":%s,"ai_jobs":%s,"ai_jobs_complete":%s,"ai_jobs_open":%s,"piwigo_data_files":%s,"piwigo_script_files":%s,"managed_upload_originals":%s,"managed_gallery_originals":%s,"raw_upload_files":%s,"raw_gallery_files":%s,"piwigo_derivative_files":%s,"immich_upload_files":%s}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$schema" "$source_records" "$canonical" "$images" "$originals" "$relationships" "$albums" "$comments" "$replies" "$visible_people" "$ai_rows" "$ai_jobs" "$ai_complete" "$ai_open" "$piwigo_data_files" "$piwigo_script_files" "$managed_uploads" "$managed_galleries" "$raw_upload_files" "$raw_gallery_files" "$derivative_files" "$immich_upload_files" >"$output.partial"
  mv -- "$output.partial" "$output"
}

write_owner_postgres_capture_counts() {
  local output=$1
  assert_new_output "$output"
  local assets faces people search_indexed
  assets=$(postgres_scalar 'SELECT COUNT(*) FROM asset;')
  faces=$(postgres_scalar 'SELECT COUNT(*) FROM asset_face;')
  people=$(postgres_scalar 'SELECT COUNT(*) FROM person;')
  search_indexed=$(postgres_scalar 'SELECT COUNT(*) FROM smart_search;')
  printf '{"format":"class-archive-owner-postgres-capture-counts-v1","captured_at":"%s","assets":%s,"faces":%s,"raw_people":%s,"search_indexed":%s}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$assets" "$faces" "$people" "$search_indexed" >"$output.partial"
  mv -- "$output.partial" "$output"
}

write_synthetic_capture_counts() {
  local output=$1
  assert_new_output "$output"
  local schema images originals multi_album piwigo_data_files piwigo_script_files raw_upload_files raw_gallery_files derivative_files managed_uploads managed_galleries
  schema=$(mariadb_scalar "$synthetic_db" 'SELECT COALESCE(MAX(version),0) FROM piwigo_class_identity_migration;')
  images=$(mariadb_scalar "$synthetic_db" 'SELECT COUNT(*) FROM piwigo_images;')
  piwigo_data_files=$(volume_file_count "$synthetic_piwigo_data")
  piwigo_script_files=$(volume_file_count "$synthetic_piwigo_scripts")
  raw_upload_files=$(volume_file_count "$synthetic_uploads")
  raw_gallery_files=$(volume_file_count "$synthetic_galleries")
  derivative_files=$(volume_file_count "$synthetic_derivatives")
  originals=$(managed_original_file_count "$synthetic_db" "$synthetic_uploads" "$synthetic_galleries")
  managed_uploads=$(mariadb_scalar "$synthetic_db" "SELECT COUNT(*) FROM piwigo_images WHERE path LIKE './upload/%';")
  managed_galleries=$(mariadb_scalar "$synthetic_db" "SELECT COUNT(*) FROM piwigo_images WHERE path LIKE './galleries/%';")
  [ $(( managed_uploads + managed_galleries )) -eq "$originals" ] || fail synthetic_managed_original_root_count_mismatch
  multi_album=$(mariadb_scalar "$synthetic_db" 'SELECT COUNT(*) FROM (SELECT image_id FROM piwigo_image_category GROUP BY image_id HAVING COUNT(*)>1) AS grouped_images;')
  printf '{"format":"class-archive-synthetic-capture-counts-v2","captured_at":"%s","schema_version":%s,"images":%s,"physical_originals":%s,"multi_album_images":%s,"piwigo_data_files":%s,"piwigo_script_files":%s,"managed_upload_originals":%s,"managed_gallery_originals":%s,"raw_upload_files":%s,"raw_gallery_files":%s,"derivative_files":%s}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$schema" "$images" "$originals" "$multi_album" "$piwigo_data_files" "$piwigo_script_files" "$managed_uploads" "$managed_galleries" "$raw_upload_files" "$raw_gallery_files" "$derivative_files" >"$output.partial"
  mv -- "$output.partial" "$output"
}

compare_capture_counts() {
  local initial=$1
  local final=$2
  local expected_format=$3
  python3 -I - "$initial" "$final" "$expected_format" <<'PY'
import json
import pathlib
import sys

def load(path_value: str, expected_format: str) -> dict:
    path = pathlib.Path(path_value)
    with path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict) or value.get("format") != expected_format:
        raise SystemExit("capture_count_format_invalid")
    captured_at = value.pop("captured_at", None)
    if not isinstance(captured_at, str) or not captured_at.endswith("Z"):
        raise SystemExit("capture_count_timestamp_invalid")
    return value

before = load(sys.argv[1], sys.argv[3])
after = load(sys.argv[2], sys.argv[3])
if before != after:
    raise SystemExit("capture_snapshot_drift")
PY
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
        --exclude=./_data/cache --exclude=./_data/tmp \
        --exclude=./_data/sessions --exclude=./_data/templates_c \
        --exclude=./_data/combined --exclude=./_data/logs --exclude=./_data/i \
        --exclude=./_data/class-archive/derivative-warmup \
        --exclude=./_data/class-archive/*.lock \
        --exclude=./_data/class-archive/*.log \
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

  # Piwigo still has a small set of MyISAM tables.  A previously interrupted
  # web process can leave their "not closed cleanly" flag set even though the
  # table contents pass a full check; mariadb-dump then refuses SHOW CREATE.
  # With every application writer stopped, run CHECK TABLE (never REPAIR) and
  # require a final OK status.  Real corruption remains a hard stop.
  myisam_tables=$(docker exec "$source_container" sh -eu -c \
    'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE=0x4d794953414d ORDER BY TABLE_NAME;"')
  while IFS= read -r myisam_table; do
    [ -n "$myisam_table" ] || continue
    case "$myisam_table" in *[!A-Za-z0-9_]*) fail mariadb_myisam_table_name_invalid ;; esac
    check_result=$(docker exec "$source_container" sh -eu -c \
      'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="$1"' \
      _ "CHECK TABLE \`$myisam_table\`;" 2>/dev/null) || fail mariadb_myisam_check_failed
    printf '%s\n' "$check_result" | awk -F '\t' 'END { exit !($3 == "status" && $4 == "OK") }' \
      || fail mariadb_myisam_check_not_ok
  done <<<"$myisam_tables"

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
  for _ in $(seq 1 120); do
    # mariadb-admin ping may exit successfully as soon as the daemon answers,
    # before a normal SQL connection through the socket is usable.  Require an
    # actual query so a slow first initialization cannot create a false-ready
    # sanitizer and abort an otherwise valid capture.
    if docker exec "$clone_name" mariadb --skip-ssl --batch --skip-column-names \
      --protocol=socket --user=root --execute='SELECT 1;' >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 1
  done
  [ "$ready" = 1 ] || fail mariadb_sanitizer_not_ready
  database_ready=0
  for _ in $(seq 1 30); do
    if docker exec "$clone_name" mariadb --skip-ssl --protocol=socket --user=root \
      --execute="CREATE DATABASE IF NOT EXISTS \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
      >/dev/null 2>&1; then
      database_ready=1
      break
    fi
    sleep 1
  done
  [ "$database_ready" = 1 ] || fail mariadb_sanitizer_database_create_failed
  # First finish the source dump into the disposable clone container, then
  # import it as a separate step.  A failed producer must never leave the SQL
  # client consuming a truncated stream and echoing a large INSERT (which can
  # expose private metadata in terminal logs).  The unsanitized intermediate
  # exists only in this throw-away container layer and is removed immediately.
  if ! docker exec "$source_container" sh -eu -c \
    'exec mariadb-dump --skip-ssl --quick --lock-all-tables --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --skip-comments --host=127.0.0.1 --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
    2>/dev/null | docker exec -i "$clone_name" sh -eu -c \
    'umask 077; target=/tmp/classarchive-source.sql; test ! -e "$target"; cat >"$target"; test -s "$target"' \
    >/dev/null 2>&1; then
    fail mariadb_source_dump_stage_failed
  fi
  docker exec "$clone_name" sh -eu -c \
    'exec mariadb --skip-ssl --protocol=socket --user=root "$1" </tmp/classarchive-source.sql' _ "$database" \
    >/dev/null 2>&1 || fail mariadb_sanitizer_import_failed
  docker exec "$clone_name" sh -eu -c 'rm -f -- /tmp/classarchive-source.sql' \
    >/dev/null 2>&1 || fail mariadb_unsanitized_intermediate_cleanup_failed

  for table in piwigo_sessions piwigo_user_auth_keys; do
    count=$(docker exec "$clone_name" mariadb --skip-ssl --batch --skip-column-names --protocol=socket --user=root "$database" --execute="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table';" | tr -d '[:space:]')
    [ "$count" = 1 ] || fail "required_sensitive_table_missing_$table"
    docker exec "$clone_name" mariadb --skip-ssl --protocol=socket --user=root "$database" --execute="TRUNCATE TABLE \`$table\`;"
  done
  lease_exists=$(docker exec "$clone_name" mariadb --skip-ssl --batch --skip-column-names --protocol=socket --user=root "$database" --execute="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='piwigo_class_identity_private_e2e_fixture_lease';" | tr -d '[:space:]')
  [ "$lease_exists" = 0 ] || docker exec "$clone_name" mariadb --skip-ssl --protocol=socket --user=root "$database" --execute='TRUNCATE TABLE piwigo_class_identity_private_e2e_fixture_lease;'
  docker exec "$clone_name" mariadb --skip-ssl --protocol=socket --user=root "$database" --execute="UPDATE piwigo_class_identity_token SET state='REVOKED', revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6)), reserved_by_operation_id=NULL, reserved_at=NULL WHERE state IN ('ISSUED','RESERVED'); UPDATE piwigo_class_identity_seat SET state='AVAILABLE', updated_at=UTC_TIMESTAMP(6), lock_version=lock_version+1 WHERE state='INVITED'; UPDATE piwigo_user_infos SET activation_key=NULL,activation_key_expire=NULL; UPDATE piwigo_user_mail_notification SET check_key=''; DELETE FROM piwigo_config WHERE param='secret_key';"

  unsafe=$(docker exec "$clone_name" mariadb --skip-ssl --batch --skip-column-names --protocol=socket --user=root "$database" --execute="SELECT (SELECT COUNT(*) FROM piwigo_sessions)+(SELECT COUNT(*) FROM piwigo_user_auth_keys)+(SELECT COUNT(*) FROM piwigo_user_infos WHERE activation_key IS NOT NULL OR activation_key_expire IS NOT NULL)+(SELECT COUNT(*) FROM piwigo_user_mail_notification WHERE check_key <> '')+(SELECT COUNT(*) FROM piwigo_config WHERE LOWER(param) REGEXP '(secret|token|password|passphrase|oauth|smtp|credential)')+(SELECT COUNT(*) FROM piwigo_class_identity_token WHERE state IN ('ISSUED','RESERVED'))+(SELECT COUNT(*) FROM piwigo_class_identity_seat WHERE state='INVITED')+(SELECT COUNT(*) FROM piwigo_class_identity_audit_event WHERE CONCAT_WS(' ',old_value,new_value,reason) REGEXP 'Bearer[[:space:]]+[A-Za-z0-9._~-]{20,}|eyJ[A-Za-z0-9_-]{8,}\\.[A-Za-z0-9_-]{8,}\\.[A-Za-z0-9_-]{8,}');" | tr -d '[:space:]')
  [ "$unsafe" = 0 ] || fail mariadb_sanitization_incomplete
  docker exec "$clone_name" mariadb-dump --skip-ssl --quick --lock-all-tables --routines --events --triggers \
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
  database=$(docker exec "$owner_pg" printenv POSTGRES_DB | tr -d '\r\n')
  case "$database" in ''|*[!A-Za-z0-9_]*) fail postgres_database_name_invalid ;; esac
  sensitive_table_count=$(docker exec --user postgres "$owner_pg" psql --dbname="$database" --tuples-only --no-align \
    --command="SELECT COUNT(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('session','session_sync_checkpoint','api_key','shared_link','shared_link_asset','video_stream_session','video_stream_variant','video_stream_segment','system_metadata','user_metadata');" \
    | tr -d '[:space:]')
  [ "$sensitive_table_count" = 10 ] || fail postgres_sensitive_schema_drift
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
    --dbname="$database" >"$output.partial"
  docker exec -i --user postgres "$owner_pg" pg_restore --list >/dev/null <"$output.partial"
  [ -s "$output.partial" ] || fail postgres_dump_empty
  mv -- "$output.partial" "$output"
}

stop_writers owner "${owner_writers[@]}"
assert_network_running_set owner "${owner_expected[@]}"

write_owner_capture_counts "$root/payloads/private-metadata/owner-capture-counts.json"
write_owner_postgres_capture_counts "$root/payloads/private-metadata/owner-postgres-capture-counts.json"
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

write_owner_capture_counts "$owner_capture_compare"
write_owner_postgres_capture_counts "$owner_postgres_compare"
compare_capture_counts "$root/payloads/private-metadata/owner-capture-counts.json" "$owner_capture_compare" class-archive-owner-capture-counts-v2 \
  || fail owner_snapshot_drift_during_capture
compare_capture_counts "$root/payloads/private-metadata/owner-postgres-capture-counts.json" "$owner_postgres_compare" class-archive-owner-postgres-capture-counts-v1 \
  || fail owner_postgres_snapshot_drift_during_capture
rm -f -- "$owner_capture_compare" "$owner_capture_compare.partial" "$owner_postgres_compare" "$owner_postgres_compare.partial" \
  || fail owner_comparison_file_cleanup_failed

restart_group "${started_owner[@]}" || fail owner_container_restart_failed
started_owner=()

stop_writers synthetic "${synthetic_writers[@]}"
assert_network_running_set synthetic "${synthetic_expected[@]}"
write_synthetic_capture_counts "$root/payloads/synthetic/synthetic-capture-counts.json"
sanitized_mariadb_dump "$synthetic_db" "$root/payloads/synthetic/synthetic-mariadb.sql.gz" synthetic
archive_piwigo_data "$synthetic_piwigo_data" "$root/payloads/synthetic/synthetic-piwigo-data.tar"
archive_volume "$synthetic_piwigo_scripts" "$root/payloads/synthetic/synthetic-piwigo-scripts.tar" .
archive_volume "$synthetic_uploads" "$root/payloads/synthetic/synthetic-uploads.tar" .
archive_volume "$synthetic_galleries" "$root/payloads/synthetic/synthetic-galleries.tar" .
archive_volume "$synthetic_derivatives" "$root/payloads/synthetic/synthetic-derivatives.tar" .
write_synthetic_capture_counts "$synthetic_capture_compare"
compare_capture_counts "$root/payloads/synthetic/synthetic-capture-counts.json" "$synthetic_capture_compare" class-archive-synthetic-capture-counts-v2 \
  || fail synthetic_snapshot_drift_during_capture
rm -f -- "$synthetic_capture_compare" "$synthetic_capture_compare.partial" \
  || fail synthetic_comparison_file_cleanup_failed
restart_group "${started_synthetic[@]}" || fail synthetic_container_restart_failed
started_synthetic=()

sanitization_output="$root/payloads/private-metadata/runtime-sanitization.json"
assert_new_output "$sanitization_output"
printf '{"format":"class-archive-runtime-sanitization-v2","owner_mariadb_sessions":0,"owner_mariadb_auth_keys":0,"synthetic_mariadb_sessions":0,"synthetic_mariadb_auth_keys":0,"mariadb_activation_keys":0,"outstanding_identity_tokens":0,"invited_seats":0,"piwigo_secret_config_candidates":0,"audit_raw_token_candidates":0,"postgres_sessions":"excluded","postgres_api_keys":"excluded","postgres_shared_links":"excluded","postgres_stream_sessions":"excluded","postgres_system_metadata":"excluded_all","postgres_user_metadata":"excluded_all","runtime_secrets_included":false}\n' >"$sanitization_output.partial"
mv -- "$sanitization_output.partial" "$sanitization_output"
printf 'LOCAL_HANDOFF_CAPTURE=PASS\n'
