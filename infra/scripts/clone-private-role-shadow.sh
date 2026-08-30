#!/usr/bin/env bash
set -euo pipefail

# V18 private-role Shadow clone helper.  It is intentionally bound to one
# Owner source and one disposable Shadow target.  It never accepts a project,
# volume, container, port or host path from the caller.

umask 077
export LC_ALL=C

fail() {
  printf '%s\n' "PRIVATE_ROLE_SHADOW_CLONE=FAIL action=${action:-unknown} code=$1" >&2
  exit 2
}

action=${1:-}
case "$action" in preflight|clone|verify) ;; *) fail action_invalid ;; esac
[ "$#" -eq 1 ] || fail arguments_forbidden

repo=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P) || fail repo_root_invalid
runtime="$repo/.codex-work/private-role-e2e/shadow-v1"
piwigo_env="$runtime/piwigo.env"
immich_env="$runtime/immich.env"
clone_state="$runtime/clone-state.env"
clone_complete="$runtime/CLONE_COMPLETE"

owner_piwigo_project=class_archive_private_full_v3_piwigo
owner_immich_project=class_archive_private_full_v3_immich
shadow_piwigo_project=class_archive_private_role_shadow_v1_piwigo
shadow_immich_project=class_archive_private_role_shadow_v1_immich
scope=private-role-shadow

owner_mariadb=${owner_piwigo_project}-db-1
owner_postgres=${owner_immich_project}-database-1
shadow_mariadb=${shadow_piwigo_project}-db-1
shadow_postgres=${shadow_immich_project}-database-1

owner_piwigo_data=class_archive_private_full_v3_control_piwigo_data
owner_piwigo_scripts=class_archive_private_full_v3_control_piwigo_scripts
owner_piwigo_uploads=class_archive_private_full_v3_piwigo_uploads
owner_piwigo_galleries=class_archive_private_full_v3_piwigo_galleries
owner_immich_upload=class_archive_private_full_v3_immich_upload
owner_piwigo_db=class_archive_private_full_v3_control_piwigo_db
owner_immich_db=class_archive_private_full_v3_control_immich_db

shadow_piwigo_data=class_archive_private_role_shadow_v1_piwigo_data
shadow_piwigo_scripts=class_archive_private_role_shadow_v1_piwigo_scripts
shadow_piwigo_uploads=class_archive_private_role_shadow_v1_piwigo_uploads
shadow_piwigo_galleries=class_archive_private_role_shadow_v1_piwigo_galleries
shadow_piwigo_derivatives=class_archive_private_role_shadow_v1_piwigo_derivatives
shadow_piwigo_db=class_archive_private_role_shadow_v1_piwigo_db
shadow_piwigo_backups=class_archive_private_role_shadow_v1_piwigo_backups
shadow_recovery=class_archive_private_role_shadow_v1_private_e2e_recovery
shadow_immich_upload=class_archive_private_role_shadow_v1_immich_upload
shadow_model_cache=class_archive_private_role_shadow_v1_immich_model_cache
shadow_immich_db=class_archive_private_role_shadow_v1_immich_db
shadow_gateway_secret=class_archive_private_role_shadow_v1_immich_gateway_secret
seed_volume=class_archive_private_role_shadow_v1_clone_seed

command -v docker >/dev/null 2>&1 || fail docker_missing
command -v sha256sum >/dev/null 2>&1 || fail sha256sum_missing

assert_owner_container() {
  name=$1 project=$2 service=$3
  actual=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "com.classarchive.scope"}}|{{.State.Running}}' "$name" 2>/dev/null) \
    || fail owner_container_missing
  [ "$actual" = "$project|$service|private-real-full|true" ] || fail owner_container_identity_invalid
}

assert_owner_volume() {
  name=$1 project=$2 logical=$3
  actual=$(docker volume inspect --format '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}' "$name" 2>/dev/null) \
    || fail owner_volume_missing
  [ "$actual" = "$project|$logical|private-real-full" ] || fail owner_volume_identity_invalid
}

assert_shadow_container() {
  name=$1 project=$2 service=$3
  actual=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "com.classarchive.scope"}}|{{.State.Running}}' "$name" 2>/dev/null) \
    || fail shadow_container_missing
  [ "$actual" = "$project|$service|$scope|true" ] || fail shadow_container_identity_invalid
}

assert_shadow_volume() {
  name=$1 project=$2 logical=$3
  actual=$(docker volume inspect --format '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}|{{index .Labels "com.classarchive.shadow-version"}}' "$name" 2>/dev/null) \
    || fail shadow_volume_missing
  [ "$actual" = "$project|$logical|$scope|1" ] || fail shadow_volume_identity_invalid
}

assert_source_scope() {
  assert_owner_container "$owner_mariadb" "$owner_piwigo_project" db
  assert_owner_container "$owner_postgres" "$owner_immich_project" database
  assert_owner_volume "$owner_piwigo_data" "$owner_piwigo_project" piwigo_data
  assert_owner_volume "$owner_piwigo_scripts" "$owner_piwigo_project" piwigo_scripts
  assert_owner_volume "$owner_piwigo_uploads" "$owner_piwigo_project" piwigo_uploads
  assert_owner_volume "$owner_piwigo_galleries" "$owner_piwigo_project" piwigo_galleries
  assert_owner_volume "$owner_piwigo_db" "$owner_piwigo_project" piwigo_db
  assert_owner_volume "$owner_immich_upload" "$owner_immich_project" immich_upload
  assert_owner_volume "$owner_immich_db" "$owner_immich_project" immich_db
}

read_env_value() {
  file=$1 key=$2
  [ -f "$file" ] && [ ! -L "$file" ] || fail generated_env_missing
  case "$key" in COMPOSE_PROJECT_NAME|IMMICH_COMPOSE_PROJECT_NAME|DB_NAME|DB_USER|DB_PASSWORD|DB_DATABASE_NAME) ;; *) fail generated_env_key_invalid ;; esac
  count=$(grep -c "^${key}=" "$file" 2>/dev/null || true)
  [ "$count" = 1 ] || fail generated_env_key_missing_or_duplicate
  value=$(grep "^${key}=" "$file" | sed -n '1s/^[^=]*=//p')
  case "$value" in ''|*[!A-Za-z0-9_:@.+/-]*) fail generated_env_value_invalid ;; esac
  printf '%s' "$value"
}

helper_image=$(docker inspect --format '{{.Image}}' "$owner_mariadb" 2>/dev/null) || fail helper_image_missing
case "$helper_image" in sha256:[0-9a-f][0-9a-f]*) ;; *) fail helper_image_invalid ;; esac
docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
  --cap-drop ALL --security-opt no-new-privileges:true --entrypoint tar "$helper_image" --version 2>/dev/null \
  | grep -F 'GNU tar' >/dev/null || fail gnu_tar_required

mariadb_state_digest() {
  container=${1:-$owner_mariadb}
  docker exec "$container" sh -eu -c '
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    exec mariadb-dump --quick --lock-all-tables --routines --events --triggers \
      --hex-blob --default-character-set=utf8mb4 --skip-comments --compact \
      --host=127.0.0.1 --user=root "$MARIADB_DATABASE"
  ' 2>/dev/null | sha256sum | awk '{print $1}'
}

postgres_state_digest() {
  docker exec --user postgres "$owner_postgres" sh -eu -c '
    exec pg_dump --format=plain --no-owner --no-acl --no-comments --dbname="$POSTGRES_DB"
  ' 2>/dev/null | sha256sum | awk '{print $1}'
}

volume_state_digest() {
  volume=$1 mode=${2:-all}
  if [ "$mode" = piwigo-data ]; then
    excludes='--exclude=./local/config/database.inc.php --exclude=./_data/.class-archive-immich-bridge.json --exclude=./_data/sessions --exclude=./_data/templates_c --exclude=./_data/combined'
  else
    excludes=
  fi
  docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH --security-opt no-new-privileges:true \
    --entrypoint /bin/sh -v "$volume:/source:ro" "$helper_image" -eu -c \
    "exec tar --sort=name --format=posix --pax-option=delete=atime,delete=ctime --numeric-owner --acls --xattrs --xattrs-include='*' $excludes -C /source -cf - ." \
    2>/dev/null | sha256sum | awk '{print $1}'
}

assert_digest() {
  case "$1" in ''|*[!0-9a-f]*) return 1 ;; *) [ "${#1}" -eq 64 ] ;; esac
}

source_schema() {
  docker exec "$owner_mariadb" sh -eu -c '
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    table=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" \
      -e "SELECT COALESCE(MIN(TABLE_NAME), '\''\'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '\''^[A-Za-z0-9_]+class_identity_migration$'\'';")
    case "$table" in ""|*[!A-Za-z0-9_]*) exit 83 ;; esac
    exec mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" \
      -e "SELECT COALESCE(MAX(version),0) FROM $table;"
  ' 2>/dev/null | tr -d '[:space:]'
}

volume_bytes() {
  docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --cap-add DAC_READ_SEARCH --security-opt no-new-privileges:true \
    --entrypoint /bin/sh -v "$1:/source:ro" "$helper_image" -eu -c 'du -sb /source | cut -f1' 2>/dev/null
}

assert_preflight() {
  assert_source_scope
  [ "$(source_schema)" = 18 ] || fail owner_schema_not_v18
  docker_root=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null) || fail docker_root_unknown
  free=$(df -PB1 "$docker_root" | awk 'NR==2 {print $4}')
  case "$free" in ''|*[!0-9]*) fail docker_free_space_invalid ;; esac
  control=$(( $(volume_bytes "$owner_piwigo_data") + $(volume_bytes "$owner_piwigo_scripts") ))
  database_state=$(( $(volume_bytes "$owner_piwigo_db") + $(volume_bytes "$owner_immich_db") ))
  required=$(( (control + database_state) * 3 + 1073741824 ))
  [ "$required" -ge 4294967296 ] || required=4294967296
  [ "$free" -ge "$required" ] || fail docker_free_space_insufficient
  printf '%s\n' "PRIVATE_ROLE_SHADOW_PREFLIGHT=PASS schema=18 docker_free_bytes=$free required_bytes=$required control_copy_bytes=$control database_state_bytes=$database_state media_mode=EMPTY_INDEPENDENT_FIXTURE_ONLY owner_mutation=NONE"
}

create_seed_volume() {
  if docker volume inspect "$seed_volume" >/dev/null 2>&1; then
    actual=$(docker volume inspect --format '{{index .Labels "com.classarchive.scope"}}|{{index .Labels "com.classarchive.shadow-version"}}|{{index .Labels "com.classarchive.shadow-seed"}}' "$seed_volume")
    [ "$actual" = "$scope|1|true" ] || fail seed_volume_identity_invalid
    empty=$(docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
      -v "$seed_volume:/seed:ro" --entrypoint /bin/sh "$helper_image" -eu -c 'find /seed -mindepth 1 -print -quit')
    [ -z "$empty" ] || fail seed_volume_not_empty
  else
    docker volume create --label "com.classarchive.scope=$scope" --label com.classarchive.shadow-version=1 \
      --label com.classarchive.shadow-seed=true "$seed_volume" >/dev/null || fail seed_volume_create_failed
  fi
}

remove_seed_volume() {
  if docker volume inspect "$seed_volume" >/dev/null 2>&1; then
    actual=$(docker volume inspect --format '{{index .Labels "com.classarchive.scope"}}|{{index .Labels "com.classarchive.shadow-version"}}|{{index .Labels "com.classarchive.shadow-seed"}}' "$seed_volume")
    [ "$actual" = "$scope|1|true" ] || fail seed_volume_cleanup_identity_invalid
    docker volume rm "$seed_volume" >/dev/null || fail seed_volume_cleanup_failed
  fi
}

store_stream() {
  destination=$1
  docker run --rm -i --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128 \
    --cap-drop ALL --security-opt no-new-privileges:true --entrypoint /bin/sh \
    -v "$seed_volume:/seed" "$helper_image" -eu -c "umask 077; cat > /seed/$destination" \
    || fail dump_store_failed
}

copy_volume() {
  source=$1 target=$2 mode=${3:-all}
  if [ "$mode" = piwigo-data ]; then
    excludes='--exclude=./local/config/database.inc.php --exclude=./_data/.class-archive-immich-bridge.json --exclude=./_data/sessions --exclude=./_data/templates_c --exclude=./_data/combined'
  else
    excludes=
  fi
  docker run --rm --log-driver none --network none --read-only --memory 512m --memory-swap 512m --pids-limit 128 \
    --cap-drop ALL --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --cap-add DAC_READ_SEARCH \
    --security-opt no-new-privileges:true --entrypoint /bin/sh \
    -v "$source:/source:ro" -v "$target:/target" "$helper_image" -eu -c \
    "test -z \"\$(find /target -mindepth 1 -print -quit)\"; tar --sort=name --format=posix --numeric-owner --acls --xattrs --xattrs-include='*' $excludes -C /source -cf - . | tar --numeric-owner --acls --xattrs --xattrs-include='*' -C /target -xf -" \
    || fail control_volume_copy_failed
}

assert_target_scope() {
  assert_shadow_container "$shadow_mariadb" "$shadow_piwigo_project" db
  assert_shadow_container "$shadow_postgres" "$shadow_immich_project" database
  assert_shadow_volume "$shadow_piwigo_db" "$shadow_piwigo_project" piwigo_db
  assert_shadow_volume "$shadow_immich_db" "$shadow_immich_project" immich_db
  assert_shadow_volume "$shadow_piwigo_data" "$shadow_piwigo_project" piwigo_data
  assert_shadow_volume "$shadow_piwigo_scripts" "$shadow_piwigo_project" piwigo_scripts
  assert_shadow_volume "$shadow_piwigo_uploads" "$shadow_piwigo_project" piwigo_uploads
  assert_shadow_volume "$shadow_piwigo_galleries" "$shadow_piwigo_project" piwigo_galleries
  assert_shadow_volume "$shadow_piwigo_derivatives" "$shadow_piwigo_project" piwigo_derivatives
  assert_shadow_volume "$shadow_piwigo_backups" "$shadow_piwigo_project" backups
  assert_shadow_volume "$shadow_recovery" "$shadow_piwigo_project" private_e2e_recovery
  assert_shadow_volume "$shadow_immich_upload" "$shadow_immich_project" immich_upload
  assert_shadow_volume "$shadow_model_cache" "$shadow_immich_project" immich_model_cache
  assert_shadow_volume "$shadow_gateway_secret" "$shadow_immich_project" immich_gateway_secret
}

write_shadow_database_config() {
  config=$(printf '%s\n' '<?php' \
    "\$conf['dblayer'] = 'mysqli';" \
    "\$conf['db_base'] = '$shadow_piwigo_db_name';" \
    "\$conf['db_user'] = '$shadow_piwigo_db_user';" \
    "\$conf['db_password'] = '$shadow_piwigo_db_password';" \
    "\$conf['db_host'] = 'db';" '' \
    "\$prefixeTable = 'piwigo_';" '' \
    "define('PHPWG_INSTALLED', true);" \
    "define('PWG_CHARSET', 'utf-8');" \
    "define('DB_CHARSET', 'utf8');" \
    "define('DB_COLLATE', '');" '' '?>')
  printf '%s\n' "$config" | docker run --rm -i --log-driver none --network none --read-only --memory 128m --memory-swap 128m --pids-limit 64 \
    --cap-drop ALL --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE \
    --security-opt no-new-privileges:true --entrypoint /bin/sh -v "$shadow_piwigo_data:/target" "$helper_image" \
    -eu -c 'umask 077; mkdir -p /target/local/config; cat > /target/local/config/database.inc.php; chown 1000:1000 /target/local/config/database.inc.php; chmod 0600 /target/local/config/database.inc.php' \
    || fail shadow_database_config_failed
}

capture_clone() {
  [ "${CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED:-0}" = 1 ] || fail shadow_disabled
  [ "${CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_CONFIRM:-}" = CLONE_V18_OWNER_TO_ISOLATED_SHADOW ] || fail explicit_confirmation_required
  [ ! -e "$clone_complete" ] || fail clone_already_complete
  [ "$(read_env_value "$piwigo_env" COMPOSE_PROJECT_NAME)" = "$shadow_piwigo_project" ] || fail piwigo_env_identity_invalid
  [ "$(read_env_value "$immich_env" IMMICH_COMPOSE_PROJECT_NAME)" = "$shadow_immich_project" ] || fail immich_env_identity_invalid
  shadow_piwigo_db_name=$(read_env_value "$piwigo_env" DB_NAME)
  shadow_piwigo_db_user=$(read_env_value "$piwigo_env" DB_USER)
  shadow_piwigo_db_password=$(read_env_value "$piwigo_env" DB_PASSWORD)
  [ "$(read_env_value "$immich_env" DB_DATABASE_NAME)" = immich ] || fail immich_env_database_invalid
  assert_preflight >/dev/null
  assert_target_scope
  create_seed_volume
  trap remove_seed_volume EXIT HUP INT TERM

  maria_before=$(mariadb_state_digest); assert_digest "$maria_before" || fail mariadb_before_digest_invalid
  pg_before=$(postgres_state_digest); assert_digest "$pg_before" || fail postgres_before_digest_invalid
  data_before=$(volume_state_digest "$owner_piwigo_data" piwigo-data); assert_digest "$data_before" || fail piwigo_data_before_digest_invalid
  scripts_before=$(volume_state_digest "$owner_piwigo_scripts"); assert_digest "$scripts_before" || fail piwigo_scripts_before_digest_invalid

  docker exec "$owner_mariadb" sh -eu -c '
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    exec mariadb-dump --quick --lock-all-tables --routines --events --triggers --hex-blob \
      --default-character-set=utf8mb4 --host=127.0.0.1 --user=root "$MARIADB_DATABASE"
  ' 2>/dev/null | store_stream mariadb.sql
  docker exec --user postgres "$owner_postgres" sh -eu -c '
    exec pg_dump --format=custom --compress=1 --no-owner --no-acl --serializable-deferrable --dbname="$POSTGRES_DB"
  ' 2>/dev/null | store_stream immich.dump

  copy_volume "$owner_piwigo_data" "$shadow_piwigo_data" piwigo-data
  copy_volume "$owner_piwigo_scripts" "$shadow_piwigo_scripts"

  maria_after=$(mariadb_state_digest); assert_digest "$maria_after" || fail mariadb_after_digest_invalid
  pg_after=$(postgres_state_digest); assert_digest "$pg_after" || fail postgres_after_digest_invalid
  data_after=$(volume_state_digest "$owner_piwigo_data" piwigo-data); assert_digest "$data_after" || fail piwigo_data_after_digest_invalid
  scripts_after=$(volume_state_digest "$owner_piwigo_scripts"); assert_digest "$scripts_after" || fail piwigo_scripts_after_digest_invalid
  [ "$maria_before" = "$maria_after" ] || fail owner_mariadb_drift
  [ "$pg_before" = "$pg_after" ] || fail owner_postgres_drift
  [ "$data_before" = "$data_after" ] || fail owner_piwigo_data_drift
  [ "$scripts_before" = "$scripts_after" ] || fail owner_piwigo_scripts_drift
  [ "$(volume_state_digest "$shadow_piwigo_data" piwigo-data)" = "$data_before" ] || fail shadow_piwigo_data_copy_mismatch
  [ "$(volume_state_digest "$shadow_piwigo_scripts")" = "$scripts_before" ] || fail shadow_piwigo_scripts_copy_mismatch

  docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$seed_volume:/seed:ro" --entrypoint cat "$helper_image" /seed/mariadb.sql \
    | docker exec -i "$shadow_mariadb" sh -eu -c '
        export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
        exec mariadb --host=127.0.0.1 --user=root "$MARIADB_DATABASE"
      ' >/dev/null 2>&1 || fail shadow_mariadb_restore_failed
  target_maria=$(mariadb_state_digest "$shadow_mariadb"); assert_digest "$target_maria" || fail shadow_mariadb_restore_digest_invalid
  [ "$target_maria" = "$maria_before" ] || fail shadow_mariadb_restore_digest_mismatch
  docker exec "$shadow_mariadb" sh -eu -c '
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    exists=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='\''piwigo_sessions'\'';")
    [ "$exists" = 0 ] || mariadb --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "DELETE FROM piwigo_sessions;"
    keys=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='\''piwigo_user_auth_keys'\'';")
    [ "$keys" = 0 ] || mariadb --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "UPDATE piwigo_user_auth_keys SET revoked_on=COALESCE(revoked_on,UTC_TIMESTAMP()) WHERE revoked_on IS NULL;"
  ' >/dev/null 2>&1 || fail shadow_session_revoke_failed

  docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    -v "$seed_volume:/seed:ro" --entrypoint cat "$helper_image" /seed/immich.dump \
    | docker exec -i --user postgres "$shadow_postgres" sh -eu -c '
        tmp=$(mktemp /tmp/class-archive-shadow-pg.XXXXXX); trap "rm -f -- \"$tmp\"" EXIT HUP INT TERM
        cat > "$tmp"
        pg_restore --clean --if-exists --no-owner --no-acl --dbname="$POSTGRES_DB" "$tmp"
        rm -f -- "$tmp"
        trap - EXIT HUP INT TERM
      ' >/dev/null 2>&1 || fail shadow_postgres_restore_failed

  target_pg=$(docker exec --user postgres "$shadow_postgres" sh -eu -c 'exec pg_dump --format=plain --no-owner --no-acl --no-comments --dbname="$POSTGRES_DB"' 2>/dev/null | sha256sum | awk '{print $1}')
  [ "$target_pg" = "$pg_before" ] || fail shadow_postgres_restore_digest_mismatch
  # The persisted AI/search/person state is retained, while every cloned
  # browser session and API key is invalidated before any Shadow service can
  # be exposed. A future bridge must provision a new Shadow-only key.
  docker exec --user postgres "$shadow_postgres" psql --no-psqlrc --set=ON_ERROR_STOP=1 --dbname=immich \
    --command 'DELETE FROM "sessions"; DELETE FROM "api_keys";' \
    >/dev/null 2>&1 || fail shadow_immich_credential_revoke_failed
  target_schema=$(docker exec "$shadow_mariadb" sh -eu -c '
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    table=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "SELECT COALESCE(MIN(TABLE_NAME), '\''\'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '\''^[A-Za-z0-9_]+class_identity_migration$'\'';")
    case "$table" in ""|*[!A-Za-z0-9_]*) exit 83 ;; esac
    exec mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "SELECT COALESCE(MAX(version),0) FROM $table;"
  ' 2>/dev/null | tr -d '[:space:]')
  [ "$target_schema" = 18 ] || fail shadow_schema_not_v18
  write_shadow_database_config

  head=$(git -C "$repo" rev-parse HEAD 2>/dev/null) || fail git_head_unavailable
  case "$head" in [0-9a-f][0-9a-f]*) [ "${#head}" -eq 40 ] ;; *) fail git_head_invalid ;; esac
  tmp_state="$clone_state.partial.$$"
  cat > "$tmp_state" <<EOF
CLONE_FORMAT=1
SOURCE_HEAD=$head
SOURCE_SCHEMA=18
MARIADB_SOURCE_SHA256=$maria_before
POSTGRES_SOURCE_SHA256=$pg_before
PIWIGO_DATA_SOURCE_SHA256=$data_before
PIWIGO_SCRIPTS_SOURCE_SHA256=$scripts_before
MEDIA_MODE=EMPTY_INDEPENDENT_FIXTURE_ONLY
SOURCE_RUNTIME_MUTATION=NONE
CLONED_SESSIONS_AND_API_KEYS=REVOKED
EOF
  chmod 0600 "$tmp_state"
  mv "$tmp_state" "$clone_state"
  printf '%s\n' 'PRIVATE_ROLE_SHADOW_CLONE_COMPLETE=1' > "$clone_complete"
  chmod 0600 "$clone_complete"
  remove_seed_volume
  trap - EXIT HUP INT TERM
  printf '%s\n' 'PRIVATE_ROLE_SHADOW_CLONE=PASS schema=18 mariadb=LOGICAL_LOCK_ALL_TABLES postgres=CUSTOM_LOGICAL control_volumes=VERIFIED media=EMPTY_INDEPENDENT_FIXTURE_ONLY source_mutation=NONE'
}

verify_clone() {
  [ -f "$clone_state" ] && [ ! -L "$clone_state" ] || fail clone_state_missing
  [ -f "$clone_complete" ] && [ ! -L "$clone_complete" ] || fail clone_complete_missing
  grep -Fx 'SOURCE_SCHEMA=18' "$clone_state" >/dev/null || fail clone_state_schema_invalid
  grep -Fx 'MEDIA_MODE=EMPTY_INDEPENDENT_FIXTURE_ONLY' "$clone_state" >/dev/null || fail clone_state_media_invalid
  grep -Fx 'CLONED_SESSIONS_AND_API_KEYS=REVOKED' "$clone_state" >/dev/null || fail clone_state_credential_boundary_invalid
  assert_target_scope
  printf '%s\n' 'PRIVATE_ROLE_SHADOW_CLONE=PASS action=verify schema=18 volumes=INDEPENDENT media=EMPTY_INDEPENDENT_FIXTURE_ONLY source_mutation=NONE'
}

case "$action" in
  preflight) assert_preflight ;;
  clone) capture_clone ;;
  verify) verify_clone ;;
esac
