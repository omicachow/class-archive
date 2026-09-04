#!/usr/bin/env bash
# Read-only host admission check for a Class Archive private handoff on macOS.
# It does not create containers, volumes, networks, files, or secrets.

set -euo pipefail

package_root=""
fresh_project="classarchive-mac-restore"
ports="8090,8091,8190,8191"
check_build_toolchain=0
failures=0
warnings=0

usage() {
  cat <<'EOF'
Usage: mac-preflight.sh [options]

Options:
  --package-root PATH       Extracted handoff package to inspect.
  --fresh-project NAME      New Compose project name (default: classarchive-mac-restore).
  --ports CSV               Loopback ports that must be free (default: 8090,8091,8190,8191).
  --check-build-toolchain   Also require Node 24.15.0, pnpm 11.13.1 and Chrome Stable.
  -h, --help                Show this help.

This command is a STATIC host preflight. PASS does not mean that a restore,
MediaGuard, role ACL, Immich AI, or browser E2E has run on the Mac.
EOF
}

pass() { printf 'PASS  %s\n' "$1"; }
warn() { printf 'WARN  %s\n' "$1" >&2; warnings=$((warnings + 1)); }
fail() { printf 'FAIL  %s\n' "$1" >&2; failures=$((failures + 1)); }

require_command() {
  if command -v "$1" >/dev/null 2>&1; then
    pass "command_$1"
  else
    fail "missing_command_$1"
  fi
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --package-root)
      [ "$#" -ge 2 ] || { printf 'missing value for --package-root\n' >&2; exit 64; }
      package_root=$2
      shift 2
      ;;
    --fresh-project)
      [ "$#" -ge 2 ] || { printf 'missing value for --fresh-project\n' >&2; exit 64; }
      fresh_project=$2
      shift 2
      ;;
    --ports)
      [ "$#" -ge 2 ] || { printf 'missing value for --ports\n' >&2; exit 64; }
      ports=$2
      shift 2
      ;;
    --check-build-toolchain)
      check_build_toolchain=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown option: %s\n' "$1" >&2
      usage >&2
      exit 64
      ;;
  esac
done

case "$fresh_project" in
  ''|*[!a-z0-9_-]*|[!a-z0-9]*)
    fail 'fresh_project_name_invalid'
    ;;
  classarchive|classarchive-owner|classarchive-private-full|classarchive-restore-v1|classarchive-restore-v2)
    fail 'fresh_project_must_not_reuse_known_runtime_identity'
    ;;
  *) pass 'fresh_project_name_syntax' ;;
esac

if [ "$(uname -s 2>/dev/null || true)" = "Darwin" ]; then
  pass 'host_os_darwin'
else
  fail 'host_os_not_darwin'
fi

host_arch=$(uname -m 2>/dev/null || printf 'unknown')
case "$host_arch" in
  arm64|aarch64) pass 'host_arch_apple_silicon' ;;
  x86_64|amd64) pass 'host_arch_intel_amd64' ;;
  *) fail "unsupported_host_arch_$host_arch" ;;
esac

for command_name in git docker gpg python3 tar gzip zstd shasum lsof; do
  require_command "$command_name"
done

if command -v docker >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1; then
    compose_version=$(docker compose version --short 2>/dev/null || docker compose version 2>/dev/null || true)
    pass "docker_compose_v2_${compose_version:-detected}"
  else
    fail 'docker_compose_v2_unavailable'
  fi

  if docker info >/dev/null 2>&1; then
    pass 'docker_engine_reachable'
    existing_containers=$(docker ps -aq --filter "label=com.docker.compose.project=$fresh_project" 2>/dev/null || true)
    existing_volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$fresh_project" 2>/dev/null || true)
    existing_networks=$(docker network ls -q --filter "label=com.docker.compose.project=$fresh_project" 2>/dev/null || true)
    if [ -n "$existing_containers$existing_volumes$existing_networks" ]; then
      fail 'fresh_project_identity_already_has_docker_objects'
    else
      pass 'fresh_project_has_no_docker_objects'
    fi
  else
    fail 'docker_engine_unreachable'
  fi
fi

old_ifs=$IFS
IFS=','
seen_ports=','
for port in $ports; do
  case "$port" in
    ''|*[!0-9]*) fail 'port_list_invalid' ;;
    *)
      if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
        fail "port_${port}_out_of_range"
        continue
      fi
      case "$seen_ports" in
        *,"$port",*) fail "port_${port}_duplicate"; continue ;;
      esac
      seen_ports="${seen_ports}${port},"
      if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
        fail "port_${port}_already_listening"
      else
        pass "port_${port}_available"
      fi
      ;;
  esac
done
IFS=$old_ifs

if [ -n "$package_root" ]; then
  if [ ! -d "$package_root" ] || [ -L "$package_root" ]; then
    fail 'package_root_missing_or_symlink'
  else
    package_root=$(cd "$package_root" && pwd -P)
    verifier=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)/verify-handoff-package.sh
    if [ -x "$verifier" ] && "$verifier" "$package_root" >/dev/null; then
      pass 'handoff_package_integrity'
    else
      fail 'handoff_package_integrity'
    fi

    lock="$package_root/payloads/source/immich-upstream.lock.json"
    container_lock="$package_root/payloads/source/container-lock.json"
    if [ -f "$lock" ] && [ -f "$container_lock" ]; then
      locked_platforms=$(python3 - "$lock" "$container_lock" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    upstream = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    containers = json.load(handle)
assert upstream.get("upstream", {}).get("version") == "v3.1.0"
assert upstream.get("upstream", {}).get("commit") == "8aa95c67470a02a8ddedf03c2e52963af33065ff"
assert containers.get("format") == "class-archive-container-lock-v1"
images = containers.get("images")
assert isinstance(images, list) and len(images) >= 6
required = ("piwigo/piwigo:", "mariadb:", "ghcr.io/immich-app/immich-server:",
            "ghcr.io/immich-app/immich-machine-learning:",
            "ghcr.io/immich-app/postgres:", "docker.io/valkey/valkey:")
references = [str(item.get("image_reference", "")) for item in images]
assert all(any(reference.startswith(prefix) for reference in references) for prefix in required)
for item in images:
    assert item.get("os") == "linux"
    assert item.get("architecture") in {"amd64", "arm64"}
    assert str(item.get("image_id", "")).startswith("sha256:")
    assert "@sha256:" in str(item.get("image_reference", ""))
    assert item.get("repo_digests")
values = sorted({"linux/" + str(item["architecture"]) for item in images})
print(",".join(values))
PY
) || locked_platforms='INVALID'
      if [ "$locked_platforms" = INVALID ]; then
        fail 'container_lock_contract_invalid'
      elif [ "$host_arch" = "arm64" ] || [ "$host_arch" = "aarch64" ]; then
        case ",$locked_platforms," in
          *,linux/amd64,*)
            fail 'container_arch_gate_amd64_lock_on_apple_silicon_requires_isolated_runtime_proof'
            ;;
          *) pass 'container_arch_gate_no_explicit_amd64_only_lock' ;;
        esac
      else
        case ",$locked_platforms," in
          *,linux/amd64,*) pass "container_arch_gate_host_matches_$locked_platforms" ;;
          *) fail "container_arch_gate_intel_incompatible_$locked_platforms" ;;
        esac
      fi
    else
      fail 'container_or_immich_lock_not_found_in_package'
    fi

    package_kib=$(du -sk "$package_root" 2>/dev/null | awk '{print $1}')
    available_kib=$(df -Pk "$package_root" | awk 'NR==2 {print $4}')
    required_kib=$((package_kib * 2 + 20 * 1024 * 1024))
    if [ "$available_kib" -ge "$required_kib" ]; then
      pass "storage_margin_kib_${available_kib}"
      warn 'docker_desktop_vm_capacity_requires_manual_gate'
    else
      fail "storage_margin_insufficient_available_${available_kib}_required_${required_kib}"
    fi
  fi
fi

warn 'restore_compose_render_and_named_volume_gate_required'

if [ "$check_build_toolchain" -eq 1 ]; then
  for command_name in node corepack pnpm; do
    require_command "$command_name"
  done
  node_version=$(node --version 2>/dev/null | sed 's/^v//' || true)
  pnpm_version=$(pnpm --version 2>/dev/null || true)
  [ "$node_version" = '24.15.0' ] && pass 'node_version_24.15.0' || fail "node_version_${node_version:-missing}_expected_24.15.0"
  [ "$pnpm_version" = '11.13.1' ] && pass 'pnpm_version_11.13.1' || fail "pnpm_version_${pnpm_version:-missing}_expected_11.13.1"
  if [ -d '/Applications/Google Chrome.app' ]; then
    pass 'google_chrome_stable_present'
  else
    fail 'google_chrome_stable_missing'
  fi
else
  warn 'build_toolchain_not_requested'
fi

if command -v gsha256sum >/dev/null 2>&1; then
  pass 'checksum_tool_gsha256sum'
elif command -v sha256sum >/dev/null 2>&1; then
  pass 'checksum_tool_sha256sum'
elif command -v shasum >/dev/null 2>&1; then
  pass 'checksum_tool_shasum_a_256'
else
  fail 'checksum_tool_missing'
fi

printf 'WARNINGS=%s\n' "$warnings"
printf 'PACKAGE_VERIFIED=%s\n' "$([ "$failures" -eq 0 ] && [ -n "$package_root" ] && printf 'PASS' || printf 'NOT_PROVEN')"
printf 'MAC_RUNTIME_TESTED=NO\n'
if [ "$failures" -eq 0 ]; then
  printf 'MAC_PREFLIGHT=PASS_STATIC_ONLY\n'
  exit 0
fi
printf 'MAC_PREFLIGHT=BLOCKED failures=%s\n' "$failures" >&2
exit 1
