#!/usr/bin/env bash
# Verify an already-extracted Class Archive private Mac handoff package.
# The package tree is read-only: this script never decrypts or restores data.

set -euo pipefail

fail() {
  printf 'HANDOFF_PACKAGE_VERIFY=FAIL reason=%s\n' "$1" >&2
  exit 1
}

usage() {
  printf 'Usage: verify-handoff-package.sh EXTRACTED_PACKAGE_ROOT\n' >&2
}

[ "$#" -eq 1 ] || { usage; exit 64; }
input=$1
[ -d "$input" ] || fail 'package_root_missing'
[ ! -L "$input" ] || fail 'package_root_symlink_forbidden'
root=$(cd "$input" && pwd -P)

for required in HANDOFF-MAC-PRIVATE.md manifest.json checksums.sha256 COMPLETE; do
  [ -f "$root/$required" ] || fail "required_file_missing_$required"
  [ ! -L "$root/$required" ] || fail "required_file_symlink_$required"
done

marker=$(tr -d '\r\n' < "$root/COMPLETE")
case "$marker" in
  CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V1|CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2) ;;
  *) fail 'complete_marker_invalid' ;;
esac

if command -v gsha256sum >/dev/null 2>&1; then
  hash_kind=gsha256sum
elif command -v sha256sum >/dev/null 2>&1; then
  hash_kind=sha256sum
elif command -v shasum >/dev/null 2>&1; then
  hash_kind=shasum
else
  fail 'sha256_tool_missing'
fi

calculate_sha256() {
  case "$hash_kind" in
    gsha256sum) gsha256sum -- "$1" | awk '{print $1}' ;;
    sha256sum) sha256sum -- "$1" | awk '{print $1}' ;;
    shasum) shasum -a 256 -- "$1" | awk '{print $1}' ;;
  esac
}

symlink_count=$(find "$root" -type l -print | wc -l | tr -d ' ')
[ "$symlink_count" = '0' ] || fail 'symlink_in_outer_package_forbidden'

checksum_count=0
while IFS= read -r line || [ -n "$line" ]; do
  [ -n "$line" ] || continue
  expected=$(printf '%s' "$line" | cut -c1-64)
  separator=$(printf '%s' "$line" | cut -c65-66)
  relative=$(printf '%s' "$line" | cut -c67-)
  case "$expected" in
    *[!0-9a-fA-F]*|'') fail 'checksum_digest_invalid' ;;
  esac
  [ "${#expected}" -eq 64 ] || fail 'checksum_digest_length_invalid'
  [ "$separator" = '  ' ] || fail 'checksum_separator_invalid'
  case "$relative" in
    ''|/*|../*|*/../*|*/..|.|*\\*) fail 'checksum_path_unsafe' ;;
    checksums.sha256|COMPLETE) fail 'self_or_marker_checksum_forbidden' ;;
  esac
  candidate="$root/$relative"
  [ -f "$candidate" ] && [ ! -L "$candidate" ] || fail 'checksummed_file_missing_or_not_regular'
  actual=$(calculate_sha256 "$candidate")
  [ "$(printf '%s' "$actual" | tr 'A-F' 'a-f')" = "$(printf '%s' "$expected" | tr 'A-F' 'a-f')" ] || fail 'payload_sha256_mismatch'
  checksum_count=$((checksum_count + 1))
done < "$root/checksums.sha256"
[ "$checksum_count" -gt 0 ] || fail 'checksum_inventory_empty'

python3 - "$root" <<'PY' || fail 'manifest_contract_invalid'
import hashlib
import json
import os
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1]).resolve(strict=True)
manifest_path = root / "manifest.json"
with manifest_path.open(encoding="utf-8") as handle:
    manifest = json.load(handle)

assert isinstance(manifest.get("created_at"), str) and manifest["created_at"]
git = manifest.get("git", {})
assert re.fullmatch(r"[0-9a-f]{40}", str(git.get("head", "")))
assert str(git.get("branch", "")).startswith("codex/")

package_format = manifest.get("format")
package_version = manifest.get("version")
marker = (root / "COMPLETE").read_text(encoding="utf-8").strip()
if package_format == "class-archive-mac-private-handoff-v1":
    assert package_version == 1
    assert marker == "CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V1"
    plaintext_private_allowed = False
elif package_format == "class-archive-mac-private-handoff-v2":
    assert package_version == 2
    assert marker == "CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2"
    assert manifest.get("transport", {}) == {
        "archive_format": "POSIX_TAR_ZSTD",
        "single_file": True,
        "encryption": "NONE",
        "confidentiality_protection": "NONE",
        "storage_scope": "LOCAL_PHYSICAL_MEDIA_ONLY",
        "public_distribution_allowed": False,
        "cloud_transfer_allowed": False,
        "outer_sha256_verification": "OUT_OF_BAND_REQUIRED",
    }
    assert manifest.get("acknowledgement", {}) == {
        "unencrypted_private_transfer": True,
        "physical_custody_required": True,
    }
    plaintext_private_allowed = True
else:
    raise AssertionError("unsupported_package_format")

privacy = manifest.get("privacy", {})
assert privacy.get("classification") == "PRIVATE_LOCAL_ARTIFACT"
assert privacy.get("contains_real_media") is True
assert privacy.get("git_safe") is False
if plaintext_private_allowed:
    assert privacy.get("contains_database_dumps") is True
    assert privacy.get("contains_password_hashes") is True
    assert privacy.get("contains_face_embeddings") is True
    assert privacy.get("contains_real_filenames") is True
    assert privacy.get("contains_plaintext_runtime_secrets") is False
    assert manifest.get("integrity", {}) == {
        "algorithm": "SHA-256",
        "authenticated": False,
        "external_archive_sha256_required": True,
    }
evidence = manifest.get("evidence", {})
assert "package_verified" in evidence
assert evidence.get("mac_runtime_tested") is False
payloads = manifest.get("payloads")
assert isinstance(payloads, list) and payloads

checksums = {}
for raw in (root / "checksums.sha256").read_text(encoding="utf-8").splitlines():
    if not raw:
        continue
    assert len(raw) >= 67 and raw[64:66] == "  "
    relative = raw[66:]
    assert relative not in checksums
    checksums[relative] = raw[:64].lower()

payload_paths = set()
components = set()
private_payload_count = 0
for item in payloads:
    relative = item.get("path")
    assert isinstance(relative, str) and relative.startswith("payloads/")
    parts = pathlib.PurePosixPath(relative).parts
    assert relative not in {"payloads", "payloads/"} and ".." not in parts and not relative.startswith("/")
    candidate = (root / pathlib.PurePosixPath(relative)).resolve(strict=True)
    assert root in candidate.parents and candidate.is_file() and not candidate.is_symlink()
    assert item.get("classification") in {
        "PUBLIC_SAFE_SOURCE",
        "SYNTHETIC_TEST_DATA",
        "PRIVATE_NONSECRET_METADATA",
        "PRIVATE_ENCRYPTED_DATA",
        "PRIVATE_UNENCRYPTED_LOCAL_DATA",
    }
    assert relative not in payload_paths
    payload_paths.add(relative)
    assert isinstance(item.get("encrypted"), bool)
    if item.get("classification") == "PRIVATE_ENCRYPTED_DATA":
        assert item.get("encrypted") is True
        assert relative.endswith(".gpg")
        private_payload_count += 1
    if item.get("classification") == "PRIVATE_UNENCRYPTED_LOCAL_DATA":
        assert plaintext_private_allowed
        assert item.get("encrypted") is False
        private_payload_count += 1
    component = item.get("component")
    if component is not None:
        assert isinstance(component, str) and re.fullmatch(r"[A-Z][A-Z0-9_]+", component)
        components.add(component)
    assert isinstance(item.get("required"), bool)
    assert item.get("size") == candidate.stat().st_size
    assert re.fullmatch(r"[0-9a-f]{64}", str(item.get("sha256", "")))
    assert checksums.get(relative) == item["sha256"]

assert private_payload_count > 0
if plaintext_private_allowed:
    required_components = {
        "SOURCE_CODE", "SYNTHETIC_BASELINE", "OWNER_MARIADB",
        "OWNER_IMMICH_POSTGRES", "OWNER_CANONICAL_MEDIA",
        "OWNER_DERIVATIVES", "OWNER_PRIVATE_METADATA",
        "PRIVATE_SOURCE_LIBRARY", "IMMICH_LOCKS_AND_ML_MANIFEST",
    }
    assert required_components <= components

allowed_unlisted = {"checksums.sha256", "COMPLETE"}
regular_files = {
    path.relative_to(root).as_posix()
    for path in root.rglob("*")
    if path.is_file() and not path.is_symlink()
}
assert regular_files - allowed_unlisted == set(checksums)
assert {"manifest.json", "HANDOFF-MAC-PRIVATE.md"}.issubset(checksums)
assert {path for path in regular_files if path.startswith("payloads/")} == payload_paths
PY

printf 'CHECKSUM_TOOL=%s\n' "$hash_kind"
printf 'CHECKSUM_FILE_COUNT=%s\n' "$checksum_count"
printf 'PACKAGE_VERIFIED=PASS\n'
printf 'MAC_RUNTIME_TESTED=NO\n'
printf 'HANDOFF_PACKAGE_VERIFY=PASS\n'
