#!/usr/bin/env bash
# Restore the unencrypted v2 private handoff into a brand-new, isolated
# Docker Desktop for Mac namespace.  This script intentionally has no reset,
# delete, overwrite, or "reuse existing volume" path.
#
# A successful --restore proves the logical databases and POSIX volume
# payloads were imported and that the Piwigo core can start on loopback.  It
# does not claim that the excluded ML model cache, the rotated Immich
# technical credential, the Class Archive bridge secret, role browser E2E, or
# an authorized MediaGuard flow has been restored.  Those gates stay explicit
# and fail closed.

set -euo pipefail
umask 077

action=""
package_root=""
checkout_root=""
runtime_env=""
state_dir=""
requested_restore_id=""
requested_core_port="8490"
requested_compat_port="8491"
requested_gateway_subnet="10.254.90.0/24"
requested_gateway_bff_ip="10.254.90.10"
allow_amd64_emulation=0
temporary_paths=()

PIWIGO_IMAGE_LOCK='piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
MARIADB_IMAGE_LOCK='mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf'
IMMICH_SERVER_IMAGE_LOCK='ghcr.io/immich-app/immich-server:v3.1.0@sha256:079cc990b26a88d71f96027341c67329cb11829d4c341ce33b3718fe0f84cbfa'
IMMICH_ML_IMAGE_LOCK='ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
IMMICH_POSTGRES_IMAGE_LOCK='ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0@sha256:bcf63357191b76a916ae5eb93464d65c07511da41e3bf7a8416db519b40b1c23'
VALKEY_IMAGE_LOCK='docker.io/valkey/valkey:9@sha256:8e8d64b405ce18f41b8e5ee20aa4687a8ed0022d1298f2ce31cdcf3a76e09411'

usage() {
  cat <<'EOF'
Usage:
  restore-mac.sh --init-env PATH [environment options]
  restore-mac.sh --prepare-source --package-root PATH --checkout PATH
  restore-mac.sh (--preflight-only|--dry-run|--restore) \
    --package-root PATH --checkout PATH --runtime-env PATH --state-dir PATH \
    [--allow-amd64-emulation]
  restore-mac.sh --verify-data \
    --package-root PATH --checkout PATH --runtime-env PATH --state-dir PATH

Actions:
  --init-env PATH       Create an owner-only (0600) runtime env with new,
                        randomly generated secrets. The path must not exist.
  --prepare-source      Restore the pinned Immich source and verified Web
                        build from this package into a fresh source checkout.
  --preflight-only      Read-only package/checkout/host/Docker admission gate.
  --dry-run             Alias for --preflight-only; it creates no Docker object.
  --restore             Create fresh named volumes, import Owner data, start
                        MariaDB, PostgreSQL and the Piwigo core, then verify.
  --verify-data         Re-run read-only data/count/core checks for a completed
                        restore created by this script.

Environment options (only for --init-env):
  --restore-id NAME       Unique lowercase restore namespace.
  --core-port PORT        Piwigo loopback port (default 8490).
  --compat-port PORT      future BFF loopback port (default 8491).
  --gateway-subnet CIDR   dedicated /24 (default 10.254.90.0/24).
  --gateway-bff-ip IP     BFF address inside that /24 (default 10.254.90.10).

Architecture:
  The package is locked to linux/amd64 images. On Apple Silicon the default is
  to stop. --allow-amd64-emulation is an explicit experimental admission only;
  it never changes MAC_RUNTIME_TESTED=NO or proves performance/compatibility.

This tool never deletes Docker containers, networks, images, or volumes. A
partial restore is retained for inspection and a rerun with the same identity
is refused. Runtime secrets are never printed.
EOF
}

fail() {
  printf 'MAC_PRIVATE_RESTORE=FAIL code=%s\n' "$1" >&2
  exit 1
}

cleanup_temporaries() {
  local path
  for path in "${temporary_paths[@]:-}"; do
    if [ -n "$path" ] && [ -d "$path" ] && [ ! -L "$path" ]; then
      python3 -I - "$path" <<'PY' >/dev/null 2>&1 || true
import os, shutil, sys
p=os.path.realpath(sys.argv[1])
if os.path.basename(p).startswith((".classarchive-mac-restore.", ".classarchive-mac-source.")):
    shutil.rmtree(p)
PY
    fi
  done
}
trap cleanup_temporaries EXIT HUP INT TERM

set_action() {
  [ -z "$action" ] || fail multiple_actions
  action=$1
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --init-env)
      [ "$#" -ge 2 ] || fail init_env_path_missing
      set_action init-env
      runtime_env=$2
      shift 2
      ;;
    --prepare-source) set_action prepare-source; shift ;;
    --preflight-only) set_action preflight; shift ;;
    --dry-run) set_action preflight; shift ;;
    --restore) set_action restore; shift ;;
    --verify-data) set_action verify-data; shift ;;
    --package-root)
      [ "$#" -ge 2 ] || fail package_root_missing
      package_root=$2
      shift 2
      ;;
    --checkout)
      [ "$#" -ge 2 ] || fail checkout_missing
      checkout_root=$2
      shift 2
      ;;
    --runtime-env)
      [ "$#" -ge 2 ] || fail runtime_env_missing
      runtime_env=$2
      shift 2
      ;;
    --state-dir)
      [ "$#" -ge 2 ] || fail state_dir_missing
      state_dir=$2
      shift 2
      ;;
    --restore-id)
      [ "$#" -ge 2 ] || fail restore_id_missing
      requested_restore_id=$2
      shift 2
      ;;
    --core-port)
      [ "$#" -ge 2 ] || fail core_port_missing
      requested_core_port=$2
      shift 2
      ;;
    --compat-port)
      [ "$#" -ge 2 ] || fail compat_port_missing
      requested_compat_port=$2
      shift 2
      ;;
    --gateway-subnet)
      [ "$#" -ge 2 ] || fail gateway_subnet_missing
      requested_gateway_subnet=$2
      shift 2
      ;;
    --gateway-bff-ip)
      [ "$#" -ge 2 ] || fail gateway_bff_ip_missing
      requested_gateway_bff_ip=$2
      shift 2
      ;;
    --allow-amd64-emulation) allow_amd64_emulation=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail unknown_option ;;
  esac
done

[ -n "$action" ] || { usage >&2; fail action_missing; }

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "missing_command_$1"
}

canonical_directory() {
  [ -d "$1" ] && [ ! -L "$1" ] || fail "$2"
  (CDPATH= cd -- "$1" && pwd -P) || fail "$2"
}

canonical_file() {
  [ -f "$1" ] && [ ! -L "$1" ] || fail "$2"
  python3 -I - "$1" <<'PY'
import os, stat, sys
p=os.path.realpath(sys.argv[1])
s=os.lstat(p)
if not stat.S_ISREG(s.st_mode) or s.st_nlink != 1:
    raise SystemExit(1)
print(p)
PY
}

assert_outside_roots() {
  python3 -I - "$1" "$2" "$3" <<'PY' || fail private_path_inside_package_or_checkout
import os, sys
candidate=os.path.realpath(sys.argv[1])
for raw in sys.argv[2:]:
    root=os.path.realpath(raw)
    try:
        if os.path.commonpath((candidate,root)) == root:
            raise SystemExit(1)
    except ValueError:
        pass
PY
}

validate_restore_identity() {
  case "$1" in
    ''|*[!a-z0-9_-]*|[!a-z0-9]*) fail restore_id_invalid ;;
  esac
  [ "${#1}" -ge 12 ] && [ "${#1}" -le 46 ] || fail restore_id_invalid
  case "$1" in
    class_archive|classarchive|class_archive_private_full_v3|class_archive_owner_restore_v1|class_archive_owner_restore_v2)
      fail known_restore_identity_forbidden
      ;;
  esac
}

validate_network_values() {
  python3 -I - "$1" "$2" <<'PY' || fail gateway_network_values_invalid
import ipaddress, sys
network=ipaddress.ip_network(sys.argv[1], strict=True)
address=ipaddress.ip_address(sys.argv[2])
if network.version != 4 or network.prefixlen != 24 or address not in network:
    raise SystemExit(1)
if address in (network.network_address, network.broadcast_address):
    raise SystemExit(1)
if int(address) - int(network.network_address) != 10:
    raise SystemExit(1)
PY
}

validate_port_pair() {
  local first=$1 second=$2
  case "$first:$second" in *[!0-9:]*) fail port_invalid ;; esac
  [ "$first" -ge 1024 ] && [ "$first" -le 65535 ] || fail core_port_invalid
  [ "$second" -ge 1024 ] && [ "$second" -le 65535 ] || fail compat_port_invalid
  [ "$first" -ne "$second" ] || fail duplicate_ports
}

random_hex() {
  openssl rand -hex 32 | tr -d '\r\n'
}

initialize_runtime_env() {
  require_command openssl
  require_command python3
  [ "$(uname -s 2>/dev/null || true)" = Darwin ] || fail host_os_not_darwin
  [ -n "$runtime_env" ] || fail runtime_env_missing
  [ ! -e "$runtime_env" ] && [ ! -L "$runtime_env" ] || fail runtime_env_already_exists
  local parent
  parent=$(canonical_directory "$(dirname -- "$runtime_env")" runtime_env_parent_invalid)
  runtime_env="$parent/$(basename -- "$runtime_env")"
  case "$(basename -- "$runtime_env")" in .*|*/*|*\\*) fail runtime_env_name_invalid ;; esac

  local restore_id=$requested_restore_id
  if [ -z "$restore_id" ]; then
    restore_id="classarchive_mac_$(date -u +%Y%m%d%H%M%S)_$(openssl rand -hex 4)"
  fi
  validate_restore_identity "$restore_id"
  validate_port_pair "$requested_core_port" "$requested_compat_port"
  validate_network_values "$requested_gateway_subnet" "$requested_gateway_bff_ip"

  local piwigo_password root_password claim_pepper pseudonym_secret immich_password temporary
  piwigo_password=$(random_hex)
  root_password=$(random_hex)
  claim_pepper=$(random_hex)
  pseudonym_secret=$(random_hex)
  immich_password=$(random_hex)
  temporary=$(mktemp "$parent/.classarchive-mac-runtime-env.XXXXXXXX") || fail runtime_env_create_failed
  chmod 0600 "$temporary"
  {
    printf 'CLASS_ARCHIVE_ENV_FORMAT=class-archive-mac-restore-env-v1\n'
    printf 'CLASS_ARCHIVE_RESTORE_ID=%s\n' "$restore_id"
    printf 'CLASS_ARCHIVE_CORE_PORT=%s\n' "$requested_core_port"
    printf 'CLASS_ARCHIVE_COMPAT_PORT=%s\n' "$requested_compat_port"
    printf 'CLASS_ARCHIVE_GATEWAY_SUBNET=%s\n' "$requested_gateway_subnet"
    printf 'CLASS_ARCHIVE_GATEWAY_BFF_IP=%s\n' "$requested_gateway_bff_ip"
    printf 'PIWIGO_DB_PASSWORD=%s\n' "$piwigo_password"
    printf 'PIWIGO_DB_ROOT_PASSWORD=%s\n' "$root_password"
    printf 'CLASS_ARCHIVE_CLAIM_CODE_PEPPER=%s\n' "$claim_pepper"
    printf 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=%s\n' "$pseudonym_secret"
    printf 'IMMICH_DB_PASSWORD=%s\n' "$immich_password"
  } >"$temporary"
  chmod 0600 "$temporary"
  [ ! -e "$runtime_env" ] || fail runtime_env_race
  mv -n -- "$temporary" "$runtime_env" || fail runtime_env_publish_failed
  piwigo_password='' root_password='' claim_pepper='' pseudonym_secret='' immich_password=''
  printf 'MAC_RESTORE_RUNTIME_ENV=CREATED_OWNER_ONLY\n'
  printf 'RUNTIME_SECRETS=GENERATED_NOT_PRINTED\n'
  printf 'MAC_RUNTIME_TESTED=NO\n'
}

if [ "$action" = init-env ]; then
  initialize_runtime_env
  exit 0
fi

for command_name in bash docker git python3 tar gzip curl openssl shasum; do require_command "$command_name"; done

[ -n "$package_root" ] || fail package_root_missing
[ -n "$checkout_root" ] || fail checkout_missing
package_root=$(canonical_directory "$package_root" package_root_invalid)
checkout_root=$(canonical_directory "$checkout_root" checkout_root_invalid)

verify_script="$checkout_root/infra/mac-handoff/verify-handoff-package.sh"
[ -f "$verify_script" ] && [ ! -L "$verify_script" ] || fail package_verifier_missing
bash "$verify_script" "$package_root" >/dev/null || fail package_verification_failed

package_identity=$(python3 -I - "$package_root/manifest.json" <<'PY'
import json, re, sys
value=json.load(open(sys.argv[1],encoding="utf-8"))
head=value.get("git",{}).get("head","")
branch=value.get("git",{}).get("branch","")
if value.get("format") != "class-archive-mac-private-handoff-v2" or value.get("version") != 2:
    raise SystemExit(1)
if not re.fullmatch(r"[0-9a-f]{40}",head): raise SystemExit(1)
if not re.fullmatch(r"codex/[A-Za-z0-9._/-]{1,180}",branch) or "|" in branch: raise SystemExit(1)
print(head+"|"+branch)
PY
) || fail package_manifest_identity_invalid
IFS='|' read -r package_head package_branch package_extra <<EOF
$package_identity
EOF
[ -n "$package_head" ] && [ -n "$package_branch" ] && [ -z "${package_extra:-}" ] || fail package_manifest_identity_invalid

checkout_head=$(git -C "$checkout_root" rev-parse --verify HEAD 2>/dev/null) || fail checkout_not_git_repository
[ "$checkout_head" = "$package_head" ] || fail checkout_head_mismatch
[ -z "$(git -C "$checkout_root" status --porcelain=v1 --untracked-files=no)" ] || fail checkout_tracked_worktree_dirty
git -C "$checkout_root" cat-file -e "$package_head^{commit}" 2>/dev/null || fail checkout_commit_missing
for tracked in \
  infra/docker-compose.yml \
  infra/immich-spike/docker-compose.yml \
  infra/mac-handoff/restore-mac.sh \
  infra/mac-handoff/verify-handoff-package.sh \
  infra/piwigo-nginx/nginx.conf; do
  git -C "$checkout_root" ls-files --error-unmatch -- "$tracked" >/dev/null 2>&1 || fail tracked_restore_component_missing
done

assert_archive_safe() {
  python3 -I - "$1" <<'PY' || exit 1
import pathlib, stat, sys, tarfile, unicodedata
p=pathlib.Path(sys.argv[1])
if not p.is_file() or p.is_symlink() or p.stat().st_nlink != 1: raise SystemExit(1)
seen=set(); types={}
with tarfile.open(p,mode="r:*") as tf:
    members=tf.getmembers()
    if not members: raise SystemExit(1)
    for member in members:
        raw=member.name.replace("\\","/")
        pure=pathlib.PurePosixPath(raw)
        if raw.startswith("/") or "\\" in member.name or ".." in pure.parts: raise SystemExit(1)
        canonical="/".join(part for part in pure.parts if part not in ("","."))
        if not canonical: continue
        key=unicodedata.normalize("NFC",canonical).casefold()
        if key in seen: raise SystemExit(1)
        seen.add(key)
        if not (member.isfile() or member.isdir()): raise SystemExit(1)
        types[key]="file" if member.isfile() else "dir"
    for key,kind in types.items():
        parts=key.split("/")
        for index in range(1,len(parts)):
            if types.get("/".join(parts[:index])) == "file": raise SystemExit(1)
PY
}

prepare_source_checkout() {
  local pattern=("$package_root"/payloads/source/official-upstream-cache-*.tar.gz)
  [ "${#pattern[@]}" -eq 1 ] && [ -f "${pattern[0]}" ] || fail official_upstream_cache_ambiguous
  local cache=${pattern[0]}
  assert_archive_safe "$cache" || fail official_upstream_cache_unsafe
  local source_parent="$checkout_root/infra/immich-spike/source"
  local source_target="$source_parent/official-v3.1.0"
  [ ! -e "$source_target" ] && [ ! -L "$source_target" ] || fail official_source_target_not_fresh
  mkdir -p -- "$source_parent"
  local temp
  temp=$(mktemp -d "$source_parent/.classarchive-mac-source.XXXXXXXX") || fail source_temp_create_failed
  temporary_paths+=("$temp")
  tar -xzf "$cache" -C "$temp" --no-same-owner || fail upstream_cache_extract_failed

  local source_archives=() build_archives=()
  while IFS= read -r -d '' item; do source_archives+=("$item"); done < <(find "$temp" -type f -name 'immich-v3.1.0-official.tar.gz' -print0)
  while IFS= read -r -d '' item; do build_archives+=("$item"); done < <(find "$temp" -type f -name 'immich-v3.1.0-web-build.tar.gz' -print0)
  [ "${#source_archives[@]}" -eq 1 ] && [ "${#build_archives[@]}" -eq 1 ] || fail upstream_cache_contents_ambiguous
  assert_archive_safe "${source_archives[0]}" || fail official_source_archive_unsafe
  assert_archive_safe "${build_archives[0]}" || fail official_web_build_archive_unsafe

  local expected_source_sha actual_source_sha
  expected_source_sha=$(python3 -I - "$checkout_root/infra/immich-spike/immich-upstream.lock.json" <<'PY'
import json,re,sys
value=json.load(open(sys.argv[1],encoding="utf-8"))
if value.get("upstream",{}).get("version") != "v3.1.0" or value.get("upstream",{}).get("commit") != "8aa95c67470a02a8ddedf03c2e52963af33065ff": raise SystemExit(1)
digest=value.get("source_archive",{}).get("sha256","")
if not re.fullmatch(r"[0-9a-f]{64}",digest): raise SystemExit(1)
print(digest)
PY
) || fail upstream_lock_invalid
  actual_source_sha=$(shasum -a 256 -- "${source_archives[0]}" | awk '{print $1}')
  [ "$actual_source_sha" = "$expected_source_sha" ] || fail official_source_sha256_mismatch

  mkdir -- "$temp/extracted-source"
  tar -xzf "${source_archives[0]}" -C "$temp/extracted-source" --no-same-owner || fail official_source_extract_failed
  [ -d "$temp/extracted-source/immich-3.1.0" ] && [ ! -L "$temp/extracted-source/immich-3.1.0" ] || fail official_source_root_invalid
  mv -- "$temp/extracted-source/immich-3.1.0" "$source_target" || fail official_source_publish_failed
  tar -xzf "${build_archives[0]}" -C "$source_target/web" --no-same-owner || fail official_web_build_extract_failed
  [ -f "$source_target/web/build/index.html" ] && [ ! -L "$source_target/web/build/index.html" ] || fail official_web_build_missing
  chmod 0755 "$checkout_root/infra/s6/php-fpm-run"
  [ -z "$(git -C "$checkout_root" status --porcelain=v1 --untracked-files=no)" ] || fail prepare_source_changed_tracked_files
  printf 'MAC_HANDOFF_SOURCE_PREPARE=PASS\n'
  printf 'IMMICH_UPSTREAM=v3.1.0@8aa95c67470a02a8ddedf03c2e52963af33065ff\n'
  printf 'MAC_RUNTIME_TESTED=NO\n'
}

if [ "$action" = prepare-source ]; then
  prepare_source_checkout
  exit 0
fi

[ -n "$runtime_env" ] || fail runtime_env_missing
runtime_env=$(canonical_file "$runtime_env" runtime_env_invalid) || fail runtime_env_invalid
[ -n "$state_dir" ] || fail state_dir_missing

read_runtime_env() {
  local metadata
  metadata=$(python3 -I - "$runtime_env" <<'PY'
import os,stat,sys
p=sys.argv[1]; s=os.lstat(p)
if not stat.S_ISREG(s.st_mode) or s.st_nlink != 1 or s.st_uid != os.getuid() or stat.S_IMODE(s.st_mode) != 0o600 or s.st_size > 8192: raise SystemExit(1)
print("ok")
PY
) || fail runtime_env_permissions_invalid
  [ "$metadata" = ok ] || fail runtime_env_permissions_invalid

  local line key value count=0
  while IFS= read -r line || [ -n "$line" ]; do
    [ -n "$line" ] || fail runtime_env_blank_line_forbidden
    case "$line" in *$'\r'*|*' '*|*$'\t'*) fail runtime_env_line_invalid ;; esac
    key=${line%%=*}
    value=${line#*=}
    [ "$key" != "$line" ] || fail runtime_env_line_invalid
    count=$((count + 1))
    case "$key" in
      CLASS_ARCHIVE_ENV_FORMAT) [ -z "${env_format:-}" ] || fail runtime_env_duplicate; env_format=$value ;;
      CLASS_ARCHIVE_RESTORE_ID) [ -z "${restore_id:-}" ] || fail runtime_env_duplicate; restore_id=$value ;;
      CLASS_ARCHIVE_CORE_PORT) [ -z "${core_port:-}" ] || fail runtime_env_duplicate; core_port=$value ;;
      CLASS_ARCHIVE_COMPAT_PORT) [ -z "${compat_port:-}" ] || fail runtime_env_duplicate; compat_port=$value ;;
      CLASS_ARCHIVE_GATEWAY_SUBNET) [ -z "${gateway_subnet:-}" ] || fail runtime_env_duplicate; gateway_subnet=$value ;;
      CLASS_ARCHIVE_GATEWAY_BFF_IP) [ -z "${gateway_bff_ip:-}" ] || fail runtime_env_duplicate; gateway_bff_ip=$value ;;
      PIWIGO_DB_PASSWORD) [ -z "${piwigo_db_password:-}" ] || fail runtime_env_duplicate; piwigo_db_password=$value ;;
      PIWIGO_DB_ROOT_PASSWORD) [ -z "${piwigo_db_root_password:-}" ] || fail runtime_env_duplicate; piwigo_db_root_password=$value ;;
      CLASS_ARCHIVE_CLAIM_CODE_PEPPER) [ -z "${claim_code_pepper:-}" ] || fail runtime_env_duplicate; claim_code_pepper=$value ;;
      CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET) [ -z "${anonymous_secret:-}" ] || fail runtime_env_duplicate; anonymous_secret=$value ;;
      IMMICH_DB_PASSWORD) [ -z "${immich_db_password:-}" ] || fail runtime_env_duplicate; immich_db_password=$value ;;
      *) fail runtime_env_unknown_key ;;
    esac
  done <"$runtime_env"
  [ "$count" -eq 11 ] || fail runtime_env_key_count_invalid
  [ "${env_format:-}" = class-archive-mac-restore-env-v1 ] || fail runtime_env_format_invalid
  validate_restore_identity "${restore_id:-}"
  validate_port_pair "${core_port:-}" "${compat_port:-}"
  validate_network_values "${gateway_subnet:-}" "${gateway_bff_ip:-}"
  local secret
  for secret in "${piwigo_db_password:-}" "${piwigo_db_root_password:-}" "${claim_code_pepper:-}" "${anonymous_secret:-}" "${immich_db_password:-}"; do
    case "$secret" in
      [0-9a-f][0-9a-f][0-9a-f][0-9a-f]*) [ "${#secret}" -eq 64 ] || fail runtime_secret_shape_invalid ;;
      *) fail runtime_secret_shape_invalid ;;
    esac
  done
}
read_runtime_env

assert_outside_roots "$runtime_env" "$package_root" "$checkout_root"

piwigo_project="${restore_id}_piwigo"
immich_project="${restore_id}_immich"
gateway_network="${restore_id}_gateway"
piwigo_data="${restore_id}_piwigo_data"
piwigo_uploads="${restore_id}_piwigo_uploads"
piwigo_galleries="${restore_id}_piwigo_galleries"
piwigo_derivatives="${restore_id}_piwigo_derivatives"
piwigo_db="${restore_id}_piwigo_db"
piwigo_scripts="${restore_id}_piwigo_scripts"
piwigo_backups="${restore_id}_piwigo_backups"
immich_upload="${restore_id}_immich_upload"
immich_model_cache="${restore_id}_immich_model_cache"
immich_db="${restore_id}_immich_db"
immich_gateway_secret="${restore_id}_immich_gateway_secret"

all_volumes=(
  "$piwigo_data" "$piwigo_uploads" "$piwigo_galleries" "$piwigo_derivatives"
  "$piwigo_db" "$piwigo_scripts" "$piwigo_backups" "$immich_upload"
  "$immich_model_cache" "$immich_db" "$immich_gateway_secret"
)

assert_web_build_present() {
  [ -f "$checkout_root/infra/immich-spike/source/official-v3.1.0/web/build/index.html" ] \
    && [ ! -L "$checkout_root/infra/immich-spike/source/official-v3.1.0/web/build/index.html" ] \
    || fail official_web_build_not_prepared
}

assert_host_architecture() {
  local arch
  arch=$(uname -m)
  case "$arch" in
    x86_64|amd64) printf 'CONTAINER_ARCH_GATE=HOST_AMD64_STATIC_MATCH\n' ;;
    arm64|aarch64)
      [ "$allow_amd64_emulation" -eq 1 ] || fail apple_silicon_amd64_emulation_not_acknowledged
      printf 'CONTAINER_ARCH_GATE=EXPERIMENTAL_AMD64_EMULATION_NOT_RUNTIME_PROVEN\n'
      ;;
    *) fail host_architecture_unsupported ;;
  esac
}

assert_ports_available() {
  python3 -I - "$core_port" "$compat_port" <<'PY' || fail loopback_port_unavailable
import socket,sys
sockets=[]
try:
  for raw in sys.argv[1:]:
    s=socket.socket(socket.AF_INET,socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,0)
    s.bind(("127.0.0.1",int(raw)))
    sockets.append(s)
finally:
  for s in sockets: s.close()
PY
}

docker_object_absent() {
  local kind=$1 name=$2
  if docker "$kind" inspect "$name" >/dev/null 2>&1; then
    fail "fresh_${kind}_already_exists"
  fi
}

assert_fresh_docker_namespace() {
  docker info >/dev/null 2>&1 || fail docker_engine_unreachable
  docker compose version >/dev/null 2>&1 || fail docker_compose_v2_missing
  local project found volume
  for project in "$piwigo_project" "$immich_project"; do
    found=$(docker ps -aq --filter "label=com.docker.compose.project=$project") || fail docker_container_inventory_failed
    [ -z "$found" ] || fail fresh_project_container_exists
    found=$(docker volume ls -q --filter "label=com.docker.compose.project=$project") || fail docker_volume_inventory_failed
    [ -z "$found" ] || fail fresh_project_volume_exists
    found=$(docker network ls -q --filter "label=com.docker.compose.project=$project") || fail docker_network_inventory_failed
    [ -z "$found" ] || fail fresh_project_network_exists
  done
  for volume in "${all_volumes[@]}"; do docker_object_absent volume "$volume"; done
  docker_object_absent network "$gateway_network"
}

assert_gateway_subnet_fresh() {
  local ids
  ids=$(docker network ls -q) || fail docker_network_inventory_failed
  [ -z "$ids" ] && return 0
  docker network inspect $ids | python3 -I -c '
import ipaddress,json,sys
target=ipaddress.ip_network(sys.argv[1],strict=True)
for network in json.load(sys.stdin):
  for cfg in (network.get("IPAM") or {}).get("Config") or []:
    raw=cfg.get("Subnet")
    if not raw: continue
    try: existing=ipaddress.ip_network(raw,strict=False)
    except ValueError: continue
    if existing.version == target.version and existing.overlaps(target): raise SystemExit(1)
' "$gateway_subnet" || fail gateway_subnet_overlaps_existing_network
}

write_runtime_files() {
  local target=$1
  mkdir -p -- "$target"
  chmod 0700 "$target"
  local nginx_source="$checkout_root/infra/piwigo-nginx/nginx.conf"
  python3 -I - "$nginx_source" "$target/nginx.conf" "$gateway_bff_ip" <<'PY' || fail nginx_render_failed
import ipaddress,pathlib,sys
source=pathlib.Path(sys.argv[1]).read_text(encoding="utf-8")
address=str(ipaddress.ip_address(sys.argv[3]))
needle="    map $realip_remote_addr $class_archive_web_compat_bff_source {\n        default 0;\n"
if source.count(needle) != 1: raise SystemExit(1)
rendered=source.replace(needle,needle+f"        {address} 1;\n",1)
path=pathlib.Path(sys.argv[2]); path.write_text(rendered,encoding="utf-8",newline="\n")
PY
  chmod 0644 "$target/nginx.conf"

  cat >"$target/piwigo.runtime.env" <<EOF
COMPOSE_PROJECT_NAME=$piwigo_project
CLASS_ARCHIVE_HTTP_PORT=$core_port
CLASS_ARCHIVE_COMPAT_HTTP_PORT=$compat_port
CLASS_ARCHIVE_GATEWAY_NETWORK=$gateway_network
CLASS_ARCHIVE_MAC_NGINX_CONFIG=$target/nginx.conf
CLASS_ARCHIVE_TIMEZONE=Asia/Shanghai
PIWIGO_UID=1000
PIWIGO_GID=1000
PIWIGO_DATA_VOLUME=$piwigo_data
PIWIGO_UPLOADS_VOLUME=$piwigo_uploads
PIWIGO_GALLERIES_VOLUME=$piwigo_galleries
PIWIGO_DERIVATIVES_VOLUME=$piwigo_derivatives
PIWIGO_DB_VOLUME=$piwigo_db
PIWIGO_SCRIPTS_VOLUME=$piwigo_scripts
PIWIGO_BACKUPS_VOLUME=$piwigo_backups
PIWIGO_IMAGE=$PIWIGO_IMAGE_LOCK
MARIADB_IMAGE=$MARIADB_IMAGE_LOCK
DB_NAME=piwigo
DB_USER=piwigo
DB_PASSWORD=$piwigo_db_password
DB_ROOT_PASSWORD=$piwigo_db_root_password
CLASS_ARCHIVE_CLAIM_CODE_PEPPER=$claim_code_pepper
CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=$anonymous_secret
EOF
  chmod 0600 "$target/piwigo.runtime.env"

  cat >"$target/immich.runtime.env" <<EOF
IMMICH_COMPOSE_PROJECT_NAME=$immich_project
CLASS_ARCHIVE_COMPAT_HTTP_PORT=$compat_port
CLASS_ARCHIVE_CORE_PUBLIC_PORT=$core_port
CLASS_ARCHIVE_GATEWAY_NETWORK=$gateway_network
IMMICH_UPLOAD_VOLUME=$immich_upload
IMMICH_MODEL_CACHE_VOLUME=$immich_model_cache
IMMICH_DB_VOLUME=$immich_db
IMMICH_GATEWAY_SECRET_VOLUME=$immich_gateway_secret
PIWIGO_UPLOADS_VOLUME=$piwigo_uploads
PIWIGO_GALLERIES_VOLUME=$piwigo_galleries
DB_PASSWORD=$immich_db_password
DB_USERNAME=postgres
DB_DATABASE_NAME=immich
TZ=Asia/Shanghai
EOF
  chmod 0600 "$target/immich.runtime.env"

  cat >"$target/piwigo.override.yml" <<'YAML'
services:
  db:
    platform: linux/amd64
    restart: "no"
    labels:
      com.classarchive.scope: mac-private-restore
  piwigo:
    platform: linux/amd64
    restart: "no"
    labels:
      com.classarchive.scope: mac-private-restore
    environment:
      CLASS_ARCHIVE_RUNTIME_SCOPE: PRIVATE_REAL_FULL
      CLASS_ARCHIVE_PRIVATE_REAL_FULL: "1"
    volumes:
      - type: bind
        source: ${CLASS_ARCHIVE_MAC_NGINX_CONFIG:?Set generated Mac restore nginx config}
        target: /etc/nginx/nginx.conf
        read_only: true
        bind:
          create_host_path: false
networks:
  app:
    internal: false
    labels:
      com.classarchive.scope: mac-private-restore
  immich_gateway:
    internal: true
    labels:
      com.classarchive.scope: mac-private-restore
    ipam:
      config:
        - subnet: ${CLASS_ARCHIVE_GATEWAY_SUBNET:?Set dedicated gateway subnet}
volumes:
  piwigo_data:
    labels: &mac-volume-labels
      com.classarchive.scope: mac-private-restore
  piwigo_uploads:
    labels: *mac-volume-labels
  piwigo_galleries:
    labels: *mac-volume-labels
  piwigo_derivatives:
    labels: *mac-volume-labels
  piwigo_db:
    labels: *mac-volume-labels
  piwigo_scripts:
    labels: *mac-volume-labels
  backups:
    labels: *mac-volume-labels
YAML

  cat >"$target/immich.override.yml" <<'YAML'
services:
  immich-server:
    platform: linux/amd64
    restart: "no"
    labels: &mac-service-labels
      com.classarchive.scope: mac-private-restore
    environment:
      DB_PASSWORD: ${DB_PASSWORD:?Set private Immich database password}
      DB_USERNAME: ${DB_USERNAME:-postgres}
      DB_DATABASE_NAME: ${DB_DATABASE_NAME:-immich}
      TZ: ${TZ:-Asia/Shanghai}
  immich-machine-learning:
    platform: linux/amd64
    restart: "no"
    labels: *mac-service-labels
  immich-gateway:
    platform: linux/amd64
    restart: "no"
    labels: *mac-service-labels
  immich-gateway-secret-stager:
    platform: linux/amd64
    restart: "no"
    labels: *mac-service-labels
  immich-web-compat:
    platform: linux/amd64
    restart: "no"
    labels: *mac-service-labels
    environment:
      CLASS_ARCHIVE_PHOTO_UI_ROOT: /photo-ui
      CLASS_ARCHIVE_CORE_PUBLIC_PORT: ${CLASS_ARCHIVE_CORE_PUBLIC_PORT:?Set private core port}
    networks:
      class_archive_gateway:
        ipv4_address: ${CLASS_ARCHIVE_GATEWAY_BFF_IP:?Set dedicated BFF address}
  redis:
    platform: linux/amd64
    restart: "no"
    labels: *mac-service-labels
  database:
    platform: linux/amd64
    restart: "no"
    labels: *mac-service-labels
networks:
  immich_internal:
    labels:
      com.classarchive.scope: mac-private-restore
  immich_ml_internal:
    labels:
      com.classarchive.scope: mac-private-restore
  immich_bridge_internal:
    labels:
      com.classarchive.scope: mac-private-restore
volumes:
  immich_upload:
    labels: &mac-volume-labels
      com.classarchive.scope: mac-private-restore
  immich_model_cache:
    labels: *mac-volume-labels
  immich_db:
    labels: *mac-volume-labels
  immich_gateway_secret:
    labels: *mac-volume-labels
YAML
  chmod 0600 "$target/piwigo.override.yml" "$target/immich.override.yml"
}

compose_piwigo() {
  CLASS_ARCHIVE_GATEWAY_SUBNET="$gateway_subnet" \
  docker compose --env-file "$render_root/piwigo.runtime.env" \
    -f "$checkout_root/infra/docker-compose.yml" -f "$render_root/piwigo.override.yml" \
    -p "$piwigo_project" "$@"
}

compose_immich() {
  CLASS_ARCHIVE_GATEWAY_BFF_IP="$gateway_bff_ip" \
  IMMICH_SPIKE_ENV_FILE="$render_root/immich.runtime.env" \
  docker compose --env-file "$render_root/immich.runtime.env" \
    -f "$checkout_root/infra/immich-spike/docker-compose.yml" -f "$render_root/immich.override.yml" \
    -p "$immich_project" "$@"
}

validate_compose_render() {
  compose_piwigo config --format json | python3 -I -c '
import json,os,sys
data=json.load(sys.stdin); checkout=os.path.realpath(sys.argv[1]); state=os.path.realpath(sys.argv[2])
core=int(sys.argv[3]); compat=int(sys.argv[4]); project=sys.argv[5]
services=data.get("services",{})
if set(services) < {"db","piwigo"}: raise SystemExit(1)
expected={(80,core),(8081,compat)}
actual=set()
for name,service in services.items():
  for port in service.get("ports") or []:
    if name != "piwigo" or port.get("host_ip") != "127.0.0.1": raise SystemExit(1)
    actual.add((int(port["target"]),int(port["published"])))
  for volume in service.get("volumes") or []:
    if volume.get("type") != "bind": continue
    source=os.path.realpath(volume.get("source",""))
    if source == "/etc/localtime": continue
    if not (os.path.commonpath((source,checkout)) == checkout or os.path.commonpath((source,state)) == state): raise SystemExit(1)
if actual != expected: raise SystemExit(1)
if services["db"].get("image") != "mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf": raise SystemExit(1)
if services["piwigo"].get("image") != "piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84": raise SystemExit(1)
' "$checkout_root" "$render_root" "$core_port" "$compat_port" "$piwigo_project" || fail piwigo_compose_boundary_invalid
  compose_immich --profile immich-spike --profile immich-ml --profile immich-gateway-integration --profile immich-web-compat config --format json \
    | python3 -I -c '
import json,os,sys
data=json.load(sys.stdin); checkout=os.path.realpath(sys.argv[1]); services=data.get("services",{})
required={"immich-server","immich-machine-learning","immich-gateway","immich-gateway-secret-stager","immich-web-compat","redis","database"}
if not required.issubset(services): raise SystemExit(1)
for name,service in services.items():
  if service.get("ports"): raise SystemExit(1)
  for volume in service.get("volumes") or []:
    if volume.get("type") != "bind": continue
    source=os.path.realpath(volume.get("source",""))
    if source == "/etc/localtime": continue
    if os.path.commonpath((source,checkout)) != checkout: raise SystemExit(1)
expected={
 "immich-server":"ghcr.io/immich-app/immich-server:v3.1.0@sha256:079cc990b26a88d71f96027341c67329cb11829d4c341ce33b3718fe0f84cbfa",
 "immich-machine-learning":"ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05",
 "database":"ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0@sha256:bcf63357191b76a916ae5eb93464d65c07511da41e3bf7a8416db519b40b1c23",
 "redis":"docker.io/valkey/valkey:9@sha256:8e8d64b405ce18f41b8e5ee20aa4687a8ed0022d1298f2ce31cdcf3a76e09411",
}
for name,image in expected.items():
  if services[name].get("image") != image: raise SystemExit(1)
' "$checkout_root" || fail immich_compose_boundary_invalid
}

run_readonly_preflight() {
  [ "$action" = verify-data ] || assert_web_build_present
  assert_host_architecture
  if [ "$action" != verify-data ]; then
    [ ! -e "$state_dir" ] && [ ! -L "$state_dir" ] || fail state_dir_not_fresh
    local parent
    parent=$(canonical_directory "$(dirname -- "$state_dir")" state_parent_invalid)
    state_dir="$parent/$(basename -- "$state_dir")"
    assert_outside_roots "$state_dir" "$package_root" "$checkout_root"
    assert_ports_available
    assert_fresh_docker_namespace
    assert_gateway_subnet_fresh
  fi
  local temp
  temp=$(mktemp -d "${TMPDIR:-/tmp}/.classarchive-mac-restore.XXXXXXXX") || fail preflight_temp_failed
  temporary_paths+=("$temp")
  render_root=$temp
  write_runtime_files "$render_root"
  validate_compose_render
}

if [ "$action" = preflight ]; then
  run_readonly_preflight
  printf 'MAC_RESTORE_PREFLIGHT=PASS_READ_ONLY\n'
  printf 'DOCKER_OBJECTS_CREATED=0\n'
  printf 'MAC_RUNTIME_TESTED=NO\n'
  exit 0
fi

state_marker="$state_dir/restore-state.json"

assert_completed_state() {
  [ -d "$state_dir" ] && [ ! -L "$state_dir" ] || fail restore_state_missing
  [ -f "$state_marker" ] && [ ! -L "$state_marker" ] || fail restore_state_marker_missing
  python3 -I - "$state_marker" "$package_head" "$restore_id" <<'PY' || fail restore_state_identity_invalid
import json,sys
value=json.load(open(sys.argv[1],encoding="utf-8"))
if set(value) != {"format","package_head","restore_id","piwigo_project","immich_project","status","runtime_boundaries"}: raise SystemExit(1)
if value["format"] != "class-archive-mac-data-restore-state-v1" or value["package_head"] != sys.argv[2] or value["restore_id"] != sys.argv[3]: raise SystemExit(1)
if value["status"] != "DATA_RESTORED_PIWIGO_CORE_READY": raise SystemExit(1)
if value["runtime_boundaries"] != {"immich_metadata_bootstrap":"NOT_RUN","immich_bridge_bootstrap":"NOT_RUN","ml_model_cache":"EXCLUDED_NOT_RESTORED","mac_runtime_tested":False}: raise SystemExit(1)
PY
  render_root="$state_dir/runtime"
  [ -d "$render_root" ] && [ ! -L "$render_root" ] || fail restore_runtime_files_missing
}

verify_volume_identity() {
  local volume=$1 project=$2 logical=$3
  local identity
  identity=$(docker volume inspect --format '{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}' "$volume" 2>/dev/null) || fail restored_volume_missing
  [ "$identity" = "$project|$logical|mac-private-restore" ] || fail restored_volume_identity_invalid
}

mariadb_scalar() {
  local query=$1 value container="${piwigo_project}-db-1"
  value=$(docker exec "$container" sh -eu -c 'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="$1"' _ "$query" 2>/dev/null | tr -d '[:space:]') || fail mariadb_query_failed
  case "$value" in ''|*[!0-9]*) fail mariadb_count_invalid ;; esac
  printf '%s' "$value"
}

postgres_scalar() {
  local query=$1 value container="${immich_project}-database-1"
  value=$(docker exec --user postgres "$container" psql --dbname=immich --tuples-only --no-align --command="$query" 2>/dev/null | tr -d '[:space:]') || fail postgres_query_failed
  case "$value" in ''|*[!0-9]*) fail postgres_count_invalid ;; esac
  printf '%s' "$value"
}

volume_file_count() {
  local volume=$1
  docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/source,readonly" --entrypoint sh "$MARIADB_IMAGE_LOCK" \
    -eu -c 'find /source -xdev -type f -printf ".\n" | wc -l' 2>/dev/null | tr -d '[:space:]'
}

managed_original_file_count() {
  local container="${piwigo_project}-db-1"
  local expected
  expected=$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_images;')
  if ! docker exec "$container" sh -eu -c \
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
    | docker run --rm -i --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
      --mount "type=volume,source=$piwigo_uploads,target=/source/upload,readonly" \
      --mount "type=volume,source=$piwigo_galleries,target=/source/galleries,readonly" \
      --entrypoint xargs "$MARIADB_IMAGE_LOCK" -0 -r -n 64 sh -eu -c '
        for relative do
          case "$relative" in upload/*|galleries/*) ;; *) exit 71 ;; esac
          target=/source/$relative
          [ -f "$target" ] && [ ! -L "$target" ] || exit 73
        done
      ' _ 2>/dev/null; then
    fail restored_managed_original_verification_failed
  fi
  printf '%s' "$expected"
}

verify_loopback_exposure() {
  local piwigo_container="${piwigo_project}-piwigo-1" container mapping
  mapping=$(docker inspect --format '{{json .HostConfig.PortBindings}}' "$piwigo_container" 2>/dev/null) || fail piwigo_exposure_inspect_failed
  python3 -I - "$mapping" "$core_port" "$compat_port" <<'PY' || fail piwigo_loopback_exposure_invalid
import json,sys
value=json.loads(sys.argv[1]); expected={"80/tcp":int(sys.argv[2]),"8081/tcp":int(sys.argv[3])}
if set(value) != set(expected): raise SystemExit(1)
for key,port in expected.items():
  rows=value[key]
  if len(rows)!=1 or rows[0].get("HostIp") != "127.0.0.1" or int(rows[0].get("HostPort",0)) != port: raise SystemExit(1)
PY
  for container in "${piwigo_project}-db-1" "${immich_project}-database-1"; do
    mapping=$(docker inspect --format '{{json .HostConfig.PortBindings}}' "$container" 2>/dev/null) || fail internal_exposure_inspect_failed
    [ "$mapping" = null ] || [ "$mapping" = '{}' ] || fail internal_database_host_port_forbidden
  done
}

verify_restored_data() {
  local expected_owner="$package_root/payloads/private-metadata/owner-capture-counts.json"
  local expected_pg="$package_root/payloads/private-metadata/owner-postgres-capture-counts.json"
  expected_line=$(python3 -I - "$expected_owner" "$expected_pg" <<'PY'
import json,sys
a=json.load(open(sys.argv[1],encoding="utf-8")); b=json.load(open(sys.argv[2],encoding="utf-8"))
values=[]
for key in ("schema_version","source_records","canonical_photos","piwigo_images","physical_originals","album_relationships","albums","comments_and_replies","replies","visible_people","ai_index_rows","ai_jobs","ai_jobs_complete","ai_jobs_open","managed_upload_originals","managed_gallery_originals","raw_upload_files","raw_gallery_files"):
  value=a.get(key)
  if not isinstance(value,int) or value < 0: raise SystemExit(1)
  values.append(str(value))
for key in ("assets","faces","raw_people","search_indexed"):
  value=b.get(key)
  if not isinstance(value,int) or value < 0: raise SystemExit(1)
  values.append(str(value))
print(" ".join(values))
PY
) || fail expected_count_manifest_invalid
  IFS=' ' read -r -a expected <<EOF
$expected_line
EOF
  [ "${#expected[@]}" -eq 22 ] || fail expected_count_manifest_invalid

  local actual=(
    "$(mariadb_scalar 'SELECT COALESCE(MAX(version),0) FROM piwigo_class_identity_migration;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_photo_source;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_photo;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_images;')"
    "$(managed_original_file_count)"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_image_category;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_album;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_photo_comment;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_photo_comment WHERE parent_comment_id IS NOT NULL;')"
    "$(mariadb_scalar "SELECT COUNT(*) FROM piwigo_class_identity_person WHERE state='ACTIVE' AND visibility='VISIBLE';")"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_ai_asset_index;')"
    "$(mariadb_scalar 'SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job;')"
    "$(mariadb_scalar "SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job WHERE state='COMPLETE';")"
    "$(mariadb_scalar "SELECT COUNT(*) FROM piwigo_class_identity_ai_index_job WHERE state<>'COMPLETE';")"
    "$(mariadb_scalar "SELECT COUNT(*) FROM piwigo_images WHERE path LIKE './upload/%';")"
    "$(mariadb_scalar "SELECT COUNT(*) FROM piwigo_images WHERE path LIKE './galleries/%';")"
    "$(volume_file_count "$piwigo_uploads")"
    "$(volume_file_count "$piwigo_galleries")"
    "$(postgres_scalar 'SELECT COUNT(*) FROM asset;')"
    "$(postgres_scalar 'SELECT COUNT(*) FROM asset_face;')"
    "$(postgres_scalar 'SELECT COUNT(*) FROM person;')"
    "$(postgres_scalar 'SELECT COUNT(*) FROM smart_search;')"
  )
  local index
  for index in "${!expected[@]}"; do
    [ "${actual[$index]}" = "${expected[$index]}" ] || fail restored_count_mismatch
  done
  [ "${actual[13]}" = 0 ] || fail restored_ai_jobs_open

  local invalid_modes
  invalid_modes=$(docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    --mount "type=volume,source=$piwigo_uploads,target=/uploads,readonly" \
    --mount "type=volume,source=$piwigo_galleries,target=/galleries,readonly" \
    --entrypoint sh "$MARIADB_IMAGE_LOCK" -eu -c \
    'find /uploads /galleries -xdev -type f ! -perm 0660 -printf ".\n" | wc -l' 2>/dev/null | tr -d '[:space:]') || fail restored_media_mode_check_failed
  [ "$invalid_modes" = 0 ] || fail restored_media_mode_invalid
  verify_loopback_exposure
  printf 'OWNER_DATABASE_COUNTS=PASS_MANIFEST_EXACT\n'
  printf 'OWNER_CANONICAL_MEDIA=PASS_MANIFEST_EXACT\n'
  printf 'OWNER_IMMICH_POSTGRES=PASS_MANIFEST_EXACT\n'
  printf 'AI_INDEX_ROWS_RESTORED=PASS_MANIFEST_EXACT\n'
  printf 'AI_REINDEX_TRIGGERED_AFTER_DATA_RESTORE=NO_APP_AI_WORKER_STARTED\n'
}

guest_media_guard_smoke() {
  local raw encoded derivative_raw derivative_encoded status
  raw=$(docker exec "${piwigo_project}-db-1" sh -eu -c 'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="SELECT path FROM piwigo_images WHERE path REGEXP 0x5e5c2e2f2875706c6f61647c67616c6c6572696573292f ORDER BY COALESCE(filesize,2147483647),id LIMIT 1;"' 2>/dev/null | tr -d '\r\n') || fail media_path_probe_failed
  [ -n "$raw" ] || fail media_path_probe_empty
  encoded=$(python3 -I - "$raw" <<'PY'
import sys,urllib.parse
value=sys.argv[1]
if not value.startswith("./") or ".." in value.split("/"): raise SystemExit(1)
print("/"+urllib.parse.quote(value[2:],safe="/"))
PY
) || fail media_path_probe_invalid
  for mode in get head range; do
    case "$mode" in
      get) status=$(curl --silent --show-error --output /dev/null --max-time 20 --write-out '%{http_code}' "http://127.0.0.1:$core_port$encoded") ;;
      head) status=$(curl --silent --show-error --head --output /dev/null --max-time 20 --write-out '%{http_code}' "http://127.0.0.1:$core_port$encoded") ;;
      range) status=$(curl --silent --show-error --range 0-0 --output /dev/null --max-time 20 --write-out '%{http_code}' "http://127.0.0.1:$core_port$encoded") ;;
    esac
    case "$status" in 403|404) ;; *) fail guest_original_media_guard_failed ;; esac
  done

  derivative_raw=$(docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    --mount "type=volume,source=$piwigo_derivatives,target=/source,readonly" --entrypoint sh "$MARIADB_IMAGE_LOCK" \
    -eu -c 'find /source -xdev -type f -printf "%s\t%P\n" | sort -n | head -n 1 | cut -f2-' 2>/dev/null | tr -d '\r\n') || fail derivative_path_probe_failed
  if [ -n "$derivative_raw" ]; then
    derivative_encoded=$(python3 -I - "$derivative_raw" <<'PY'
import sys,urllib.parse
value=sys.argv[1]
if value.startswith("/") or ".." in value.split("/"): raise SystemExit(1)
print("/_data/i/"+urllib.parse.quote(value,safe="/"))
PY
) || fail derivative_path_probe_invalid
    for mode in get head range; do
      case "$mode" in
        get) status=$(curl --silent --show-error --output /dev/null --max-time 20 --write-out '%{http_code}' "http://127.0.0.1:$core_port$derivative_encoded") ;;
        head) status=$(curl --silent --show-error --head --output /dev/null --max-time 20 --write-out '%{http_code}' "http://127.0.0.1:$core_port$derivative_encoded") ;;
        range) status=$(curl --silent --show-error --range 0-0 --output /dev/null --max-time 20 --write-out '%{http_code}' "http://127.0.0.1:$core_port$derivative_encoded") ;;
      esac
      case "$status" in 403|404) ;; *) fail guest_derivative_media_guard_failed ;; esac
    done
    printf 'MEDIAGUARD_GUEST_ORIGINAL_DERIVATIVE_GET_HEAD_RANGE=PASS\n'
  else
    printf 'MEDIAGUARD_GUEST_ORIGINAL_GET_HEAD_RANGE=PASS\n'
    printf 'MEDIAGUARD_DERIVATIVE_SMOKE=NOT_RUN_NO_DERIVATIVE_FILE\n'
  fi
  printf 'MEDIAGUARD_AUTHORIZED_ROLE_MATRIX=NOT_RUN_ON_TARGET_MAC\n'
}

if [ "$action" = verify-data ]; then
  assert_completed_state
  local_running=$(docker ps -q --filter "label=com.docker.compose.project=$piwigo_project" | wc -l | tr -d '[:space:]')
  [ "$local_running" -ge 2 ] || fail restored_piwigo_project_not_running
  pg_running=$(docker ps -q --filter "label=com.docker.compose.project=$immich_project" --filter 'label=com.docker.compose.service=database' | wc -l | tr -d '[:space:]')
  [ "$pg_running" -eq 1 ] || fail restored_postgres_not_running
  verify_restored_data
  curl --silent --show-error --fail --output /dev/null --max-time 20 "http://127.0.0.1:$core_port/" || fail piwigo_core_health_failed
  guest_media_guard_smoke
  printf 'IMMICH_METADATA_BOOTSTRAP=NOT_RUN\n'
  printf 'IMMICH_BRIDGE_BOOTSTRAP=NOT_RUN\n'
  printf 'ML_MODEL_CACHE=EXCLUDED_NOT_RESTORED\n'
  printf 'AI_RESULTS_AVAILABLE_IMMEDIATELY=NOT_RUNTIME_TESTED\n'
  printf 'MAC_DATA_RESTORE_VERIFY=PASS\n'
  printf 'MAC_RUNTIME_TESTED=NO\n'
  exit 0
fi

[ "$action" = restore ] || fail action_invalid
run_readonly_preflight

# The read-only gate above used a disposable render root. Create a new,
# persistent state directory only after every admission check passed.
[ ! -e "$state_dir" ] && [ ! -L "$state_dir" ] || fail state_dir_race
mkdir -- "$state_dir" || fail state_dir_create_failed
chmod 0700 "$state_dir"
render_root="$state_dir/runtime"
write_runtime_files "$render_root"
validate_compose_render
printf 'CLASS_ARCHIVE_MAC_RESTORE_IN_PROGRESS_V1\n' >"$state_dir/IN_PROGRESS"
chmod 0600 "$state_dir/IN_PROGRESS"

create_volume() {
  local name=$1 project=$2 logical=$3
  docker volume create \
    --label "com.docker.compose.project=$project" \
    --label "com.docker.compose.volume=$logical" \
    --label 'com.classarchive.scope=mac-private-restore' \
    "$name" >/dev/null || fail volume_create_failed
  verify_volume_identity "$name" "$project" "$logical"
}

create_volume "$piwigo_data" "$piwigo_project" piwigo_data
create_volume "$piwigo_uploads" "$piwigo_project" piwigo_uploads
create_volume "$piwigo_galleries" "$piwigo_project" piwigo_galleries
create_volume "$piwigo_derivatives" "$piwigo_project" piwigo_derivatives
create_volume "$piwigo_db" "$piwigo_project" piwigo_db
create_volume "$piwigo_scripts" "$piwigo_project" piwigo_scripts
create_volume "$piwigo_backups" "$piwigo_project" backups
create_volume "$immich_upload" "$immich_project" immich_upload
create_volume "$immich_model_cache" "$immich_project" immich_model_cache
create_volume "$immich_db" "$immich_project" immich_db
create_volume "$immich_gateway_secret" "$immich_project" immich_gateway_secret

restore_tar_to_empty_volume() {
  local archive=$1 volume=$2
  assert_archive_safe "$archive" || fail restore_tar_unsafe
  local empty
  empty=$(docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/target" --entrypoint sh "$MARIADB_IMAGE_LOCK" \
    -eu -c 'find /target -mindepth 1 -print -quit' 2>/dev/null) || fail restore_volume_empty_check_failed
  [ -z "$empty" ] || fail restore_volume_not_empty
  docker run --rm -i --log-driver none --network none --read-only --cap-drop ALL \
    --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/target" --entrypoint sh "$MARIADB_IMAGE_LOCK" \
    -eu -c 'exec tar --numeric-owner --same-owner --same-permissions --acls --xattrs --xattrs-include="*" -C /target -xf -' \
    <"$archive" >/dev/null 2>&1 || fail restore_tar_extract_failed
}

restore_tar_roots_to_volume() {
  local archive=$1 volume=$2 roots=$3
  assert_archive_safe "$archive" || fail restore_tar_unsafe
  docker run --rm --log-driver none --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/target" --entrypoint sh "$MARIADB_IMAGE_LOCK" \
    -eu -c 'for root in $1; do test ! -e "/target/$root" && test ! -L "/target/$root" || exit 1; done' _ "$roots" \
    >/dev/null 2>&1 || fail restore_volume_root_collision
  docker run --rm -i --log-driver none --network none --read-only --cap-drop ALL \
    --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true \
    --mount "type=volume,source=$volume,target=/target" --entrypoint sh "$MARIADB_IMAGE_LOCK" \
    -eu -c 'exec tar --numeric-owner --same-owner --same-permissions --acls --xattrs --xattrs-include="*" -C /target -xf -' \
    <"$archive" >/dev/null 2>&1 || fail restore_tar_extract_failed
}

owner_payload="$package_root/payloads/owner"
restore_tar_to_empty_volume "$owner_payload/owner-piwigo-data.tar" "$piwigo_data"
restore_tar_to_empty_volume "$owner_payload/owner-piwigo-scripts.tar" "$piwigo_scripts"
restore_tar_to_empty_volume "$owner_payload/owner-canonical-uploads.tar" "$piwigo_uploads"
restore_tar_to_empty_volume "$owner_payload/owner-canonical-galleries.tar" "$piwigo_galleries"
restore_tar_to_empty_volume "$owner_payload/owner-piwigo-derivatives.tar" "$piwigo_derivatives"
restore_tar_roots_to_volume "$owner_payload/owner-immich-canonical.tar" "$immich_upload" 'library upload profile'
restore_tar_roots_to_volume "$owner_payload/owner-immich-derivatives.tar" "$immich_upload" 'thumbs encoded-video'

compose_piwigo create --no-build db piwigo >/dev/null || fail piwigo_container_create_failed
compose_piwigo up -d --no-deps db >/dev/null || fail mariadb_start_failed
for ((attempt=0; attempt<120; attempt++)); do
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${piwigo_project}-db-1" 2>/dev/null || true)
  [ "$health" = healthy ] && break
  sleep 1
done
[ "${health:-}" = healthy ] || fail mariadb_health_timeout
tables=$(mariadb_scalar 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();')
[ "$tables" = 0 ] || fail fresh_mariadb_database_not_empty
gzip -t "$owner_payload/owner-mariadb.sql.gz" || fail mariadb_dump_invalid
gzip -dc "$owner_payload/owner-mariadb.sql.gz" \
  | docker exec -i "${piwigo_project}-db-1" sh -eu -c \
    'exec mariadb --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
    >/dev/null 2>&1 || fail mariadb_restore_failed

docker run --rm --log-driver none --network none --read-only --cap-drop ALL \
  --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true \
  --env-file "$runtime_env" --mount "type=volume,source=$piwigo_data,target=/target" \
  --entrypoint sh "$MARIADB_IMAGE_LOCK" -eu -c '
    target=/target/local/config/database.inc.php
    test ! -e "$target" && test ! -L "$target"
    mkdir -p /target/local/config
    temp="$target.mac-restore.$$"
    trap '\''rm -f -- "$temp"'\'' EXIT HUP INT TERM
    printf "%s\n" "<?php" \
      "\$conf['\''dblayer'\''] = '\''mysqli'\'';" \
      "\$conf['\''db_base'\''] = '\''piwigo'\'';" \
      "\$conf['\''db_user'\''] = '\''piwigo'\'';" \
      "\$conf['\''db_password'\''] = '\''$PIWIGO_DB_PASSWORD'\'';" \
      "\$conf['\''db_host'\''] = '\''db'\'';" "" \
      "\$prefixeTable = '\''piwigo_'\'';" "" \
      "define('\''PHPWG_INSTALLED'\'', true);" \
      "define('\''PWG_CHARSET'\'', '\''utf-8'\'');" \
      "define('\''DB_CHARSET'\'', '\''utf8'\'');" \
      "define('\''DB_COLLATE'\'', '\'''\'');" "" "?>" >"$temp"
    chown 1000:1000 "$temp"
    chmod 0660 "$temp"
    mv -n "$temp" "$target"
    trap - EXIT HUP INT TERM
  ' >/dev/null 2>&1 || fail piwigo_database_config_restore_failed

compose_immich --profile immich-spike create --no-build database >/dev/null || fail postgres_container_create_failed
compose_immich --profile immich-spike up -d --no-deps database >/dev/null || fail postgres_start_failed
for ((attempt=0; attempt<120; attempt++)); do
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${immich_project}-database-1" 2>/dev/null || true)
  [ "$health" = healthy ] && break
  sleep 1
done
[ "${health:-}" = healthy ] || fail postgres_health_timeout
pg_tables=$(postgres_scalar "SELECT COUNT(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p');")
[ "$pg_tables" = 0 ] || fail fresh_postgres_database_not_empty
docker exec -i --user postgres "${immich_project}-database-1" pg_restore --list \
  <"$owner_payload/owner-immich-postgres.dump" >/dev/null 2>&1 || fail postgres_dump_invalid
docker exec -i --user postgres "${immich_project}-database-1" sh -eu -c \
  'exec pg_restore --exit-on-error --clean --if-exists --no-owner --no-privileges --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"' \
  <"$owner_payload/owner-immich-postgres.dump" >/dev/null 2>&1 || fail postgres_restore_failed

compose_piwigo up -d --no-deps piwigo >/dev/null || fail piwigo_start_failed
for ((attempt=0; attempt<180; attempt++)); do
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${piwigo_project}-piwigo-1" 2>/dev/null || true)
  [ "$health" = healthy ] && break
  sleep 1
done
[ "${health:-}" = healthy ] || fail piwigo_health_timeout

verify_restored_data
curl --silent --show-error --fail --output /dev/null --max-time 20 "http://127.0.0.1:$core_port/" || fail piwigo_core_health_failed
guest_media_guard_smoke

# Publish the completed-state marker only after the restored counts, volume
# modes, loopback exposure, core health, and guest media denials all pass.  A
# failed verification deliberately leaves IN_PROGRESS in place and therefore
# cannot be mistaken for a completed restore by --verify-data.
python3 -I - "$state_marker" "$package_head" "$restore_id" "$piwigo_project" "$immich_project" <<'PY' || fail restore_state_publish_failed
import json,os,sys
path=sys.argv[1]
value={
 "format":"class-archive-mac-data-restore-state-v1",
 "package_head":sys.argv[2],
 "restore_id":sys.argv[3],
 "piwigo_project":sys.argv[4],
 "immich_project":sys.argv[5],
 "status":"DATA_RESTORED_PIWIGO_CORE_READY",
 "runtime_boundaries":{
   "immich_metadata_bootstrap":"NOT_RUN",
   "immich_bridge_bootstrap":"NOT_RUN",
   "ml_model_cache":"EXCLUDED_NOT_RESTORED",
   "mac_runtime_tested":False,
 },
}
temp=path+".partial"
with open(temp,"x",encoding="utf-8",newline="\n") as handle:
  json.dump(value,handle,ensure_ascii=False,separators=(",",":")); handle.write("\n")
os.chmod(temp,0o600); os.replace(temp,path)
PY
rm -f -- "$state_dir/IN_PROGRESS"
printf 'MAC_OWNER_DATA_RESTORE=PASS\n'
printf 'PIWIGO_CORE_RUNTIME=PASS_LOOPBACK_ONLY\n'
printf 'IMMICH_POSTGRES_DATA_RESTORE=PASS\n'
printf 'IMMICH_SYSTEM_USER_METADATA=EXCLUDED_REQUIRES_PINNED_SERVER_BOOTSTRAP\n'
printf 'IMMICH_METADATA_BOOTSTRAP=NOT_RUN\n'
printf 'IMMICH_BRIDGE_BOOTSTRAP=NOT_RUN\n'
printf 'ML_MODEL_CACHE=EXCLUDED_NOT_RESTORED\n'
printf 'AI_RESULTS_AVAILABLE_IMMEDIATELY=NOT_RUNTIME_TESTED\n'
printf 'ANONYMOUS_PSEUDONYM_CONTINUITY=NOT_GUARANTEED\n'
printf 'MAC_RUNTIME_TESTED=NO\n'
printf 'PRODUCTION_READY=NO\n'
