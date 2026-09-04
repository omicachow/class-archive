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
  case "$line" in *$'\r'*) fail 'checksum_crlf_forbidden' ;; esac
  expected=$(printf '%s' "$line" | cut -c1-64)
  separator=$(printf '%s' "$line" | cut -c65-66)
  relative=$(printf '%s' "$line" | cut -c67-)
  case "$expected" in
    *[!0-9a-f]*|'') fail 'checksum_digest_invalid' ;;
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

python3 -I - "$root" <<'PY' || fail 'manifest_contract_invalid'
import hashlib
import io
import json
import os
import pathlib
import re
import stat
import subprocess
import sys
import tarfile
import tempfile
import unicodedata
import zipfile
from datetime import datetime

if not __debug__:
    raise RuntimeError("python_assertions_disabled")

root = pathlib.Path(sys.argv[1]).resolve(strict=True)

def validate_inner_tar(path: pathlib.Path) -> None:
    seen = set()
    portable_types = {}
    with tarfile.open(path, mode="r:*") as handle:
        for member in handle:
            name = member.name
            pure = pathlib.PurePosixPath(name)
            assert name and not name.startswith("/") and "\\" not in name
            assert ".." not in pure.parts
            if not pure.parts:
                assert member.isdir()
                continue
            canonical = "/".join(pure.parts)
            portable = unicodedata.normalize("NFC", canonical).casefold()
            assert portable not in seen
            seen.add(portable)
            for index in range(1, len(pure.parts)):
                parent = unicodedata.normalize("NFC", "/".join(pure.parts[:index])).casefold()
                assert portable_types.get(parent) != "file"
            if member.isfile():
                assert not any(existing.startswith(portable + "/") for existing in portable_types)
                portable_types[portable] = "file"
            else:
                assert member.isdir()
                portable_types[portable] = "dir"

def verified_tar_member_bytes(handle: tarfile.TarFile, name: str, minimum_size: int = 1) -> bytes:
    try:
        member = handle.getmember(name)
    except KeyError as error:
        raise AssertionError(f"required_tar_member_missing:{name}") from error
    assert member.isfile() and member.size >= minimum_size
    stream = handle.extractfile(member)
    assert stream is not None
    data = stream.read()
    assert len(data) == member.size
    return data

def verify_source_snapshot(source_archive: pathlib.Path, bundle: pathlib.Path, branch: str, head: str) -> None:
    with tempfile.TemporaryDirectory(prefix="classarchive-source-tree-") as temporary:
        subprocess.run(
            ["git", "init", "-q"],
            cwd=temporary,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        subprocess.run(
            ["git", "bundle", "verify", str(bundle)],
            cwd=temporary,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        subprocess.run(
            ["git", "fetch", "-q", "--no-tags", str(bundle), f"refs/heads/{branch}:refs/heads/{branch}"],
            cwd=temporary,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        fetched_head = subprocess.run(
            ["git", "rev-parse", f"refs/heads/{branch}^{{commit}}"],
            cwd=temporary,
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()
        assert fetched_head == head
        object_format = subprocess.run(
            ["git", "rev-parse", "--show-object-format"],
            cwd=temporary,
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()
        assert object_format in {"sha1", "sha256"}
        subprocess.run(
            ["git", "symbolic-ref", "HEAD", f"refs/heads/{branch}"],
            cwd=temporary,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        subprocess.run(
            ["git", "reset", "--hard", "-q", head],
            cwd=temporary,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        raw_tree = subprocess.run(
            ["git", "ls-tree", "-rz", "--full-tree", head],
            cwd=temporary,
            check=True,
            capture_output=True,
        ).stdout

        tracked = {}
        portable_paths = set()
        for raw_entry in raw_tree.split(b"\0"):
            if not raw_entry:
                continue
            metadata, raw_path = raw_entry.split(b"\t", 1)
            mode, object_type, object_id = metadata.split(b" ", 2)
            path = raw_path.decode("utf-8", errors="strict")
            pure = pathlib.PurePosixPath(path)
            assert path and not path.startswith("/") and "\\" not in path and ".." not in pure.parts
            portable = unicodedata.normalize("NFC", path).casefold()
            assert portable not in portable_paths
            portable_paths.add(portable)
            # A portable handoff deliberately rejects symlink (120000), gitlink
            # (160000), and any future tracked object type that is not a file.
            assert mode in {b"100644", b"100755"} and object_type == b"blob"
            tracked[path] = {
                "object_id": object_id.decode("ascii"),
                "executable": mode == b"100755",
            }
        assert tracked

        expected_files = {f"class-archive/{path}" for path in tracked}
        expected_directories = {"class-archive"}
        for path in tracked:
            parts = pathlib.PurePosixPath(path).parts
            for index in range(1, len(parts)):
                expected_directories.add("class-archive/" + "/".join(parts[:index]))

        reference_archive = pathlib.Path(temporary) / "reference-source.tar"
        with reference_archive.open("wb") as output:
            subprocess.run(
                ["git", "archive", "--format=tar", "--prefix=class-archive/", head],
                cwd=temporary,
                check=True,
                stdout=output,
                stderr=subprocess.PIPE,
            )
        reference_files = {}
        reference_directories = set()
        with tarfile.open(reference_archive, mode="r:") as reference:
            for member in reference:
                canonical = "/".join(pathlib.PurePosixPath(member.name).parts)
                if member.isdir():
                    reference_directories.add(canonical)
                    continue
                assert member.isfile() and canonical in expected_files
                stream = reference.extractfile(member)
                assert stream is not None
                data = stream.read()
                assert len(data) == member.size
                reference_files[canonical] = {
                    "size": member.size,
                    "sha256": hashlib.sha256(data).hexdigest(),
                    "executable": bool(member.mode & 0o111),
                }
        assert set(reference_files) == expected_files
        assert reference_directories == expected_directories

        actual_files = set()
        actual_directories = set()
        with tarfile.open(source_archive, mode="r:gz") as handle:
            for member in handle:
                canonical = "/".join(pathlib.PurePosixPath(member.name).parts)
                assert canonical == "class-archive" or canonical.startswith("class-archive/")
                if member.isdir():
                    assert canonical in expected_directories
                    actual_directories.add(canonical)
                    continue
                assert member.isfile() and canonical in expected_files
                assert canonical not in actual_files
                actual_files.add(canonical)
                relative = canonical.removeprefix("class-archive/")
                expected = tracked[relative]
                # Git archives encode the executable bit on all three classes.
                assert member.mode & ~0o777 == 0
                assert member.mode & 0o111 in {0, 0o111}
                assert bool(member.mode & 0o111) is expected["executable"]
                stream = handle.extractfile(member)
                assert stream is not None
                data = stream.read()
                assert len(data) == member.size
                reference = reference_files[canonical]
                assert member.size == reference["size"]
                assert hashlib.sha256(data).hexdigest() == reference["sha256"]
                assert bool(member.mode & 0o111) is reference["executable"]
                # Archive export may apply declared EOL/working-tree filters.
                # Hash the exported bytes through Git's path-aware clean filter
                # and require that they resolve to the tracked blob object.
                cleaned_object_id = subprocess.run(
                    ["git", "hash-object", "--stdin", f"--path={relative}"],
                    cwd=temporary,
                    check=True,
                    input=data,
                    capture_output=True,
                ).stdout.decode("ascii").strip()
                assert cleaned_object_id == expected["object_id"]
        assert actual_files == expected_files
        assert actual_directories == expected_directories

def verify_zip_plugin(data: bytes, expected_root: str, required_file: str, expected_header: bytes) -> None:
    assert len(data) >= 50_000 and data.startswith(b"PK")
    portable_paths = set()
    with zipfile.ZipFile(io.BytesIO(data), mode="r") as archive:
        assert archive.testzip() is None
        for info in archive.infolist():
            name = info.filename
            pure = pathlib.PurePosixPath(name)
            assert name and not name.startswith("/") and "\\" not in name and ".." not in pure.parts
            canonical = "/".join(pure.parts)
            assert canonical == expected_root or canonical.startswith(expected_root + "/")
            portable = unicodedata.normalize("NFC", canonical).casefold()
            assert portable not in portable_paths
            portable_paths.add(portable)
            unix_mode = (info.external_attr >> 16) & 0xFFFF
            assert not stat.S_ISLNK(unix_mode)
            assert info.flag_bits & 0x1 == 0
        assert required_file in archive.namelist()
        header = archive.read(required_file)
        assert len(header) > 100 and expected_header in header

def verify_official_upstream_cache(cache_path: pathlib.Path, lock_path: pathlib.Path) -> None:
    lock = json.loads(lock_path.read_text(encoding="utf-8"))
    upstream = lock.get("upstream", {})
    source_lock = lock.get("source_archive", {})
    images = lock.get("images", {})
    version = upstream.get("version")
    commit = upstream.get("commit")
    assert upstream.get("repository") == "https://github.com/immich-app/immich.git"
    assert version == "v3.1.0"
    assert commit == "8aa95c67470a02a8ddedf03c2e52963af33065ff"
    assert upstream.get("license") == "AGPL-3.0-only"
    assert source_lock.get("origin") == "official_github_codeload"
    assert source_lock.get("url") == "https://codeload.github.com/immich-app/immich/tar.gz/refs/tags/v3.1.0"
    assert source_lock.get("tag_ref_commit") == commit
    assert re.fullmatch(r"[0-9a-f]{64}", str(source_lock.get("sha256", "")))
    assert isinstance(source_lock.get("http_content_length"), int) and source_lock["http_content_length"] > 10_000_000

    prefix = "immich-v3.1.0"
    required_files = {
        f"{prefix}/codeload-v3.1.0.headers.txt",
        f"{prefix}/ghcr-immich-ml-v3.1.0-manifest.json",
        f"{prefix}/ghcr-immich-server-v3.1.0-manifest.json",
        f"{prefix}/github-tag-v3.1.0.json",
        f"{prefix}/immich-v3.1.0-official.tar.gz",
        f"{prefix}/immich-v3.1.0-web-build.tar.gz",
        f"{prefix}/immich-v3.1.0.tar.list.txt",
        f"{prefix}/local-image-inspect.json",
        "piwigo-extensions/bootstrap-darkroom-16.d.zip",
        "piwigo-extensions/community-16.f.zip",
        "piwigo-extensions/user-collections-16.a.zip",
    }
    with tarfile.open(cache_path, mode="r:gz") as cache:
        files = {member.name for member in cache if member.isfile()}
        directories = {member.name.rstrip("/") for member in cache if member.isdir()}
        assert files == required_files
        assert directories == {prefix, "piwigo-extensions"}

        source_data = verified_tar_member_bytes(
            cache,
            f"{prefix}/immich-v3.1.0-official.tar.gz",
            source_lock["http_content_length"],
        )
        assert len(source_data) == source_lock["http_content_length"]
        assert hashlib.sha256(source_data).hexdigest() == source_lock["sha256"]

        headers_data = verified_tar_member_bytes(cache, f"{prefix}/codeload-v3.1.0.headers.txt", 100)
        header_lines = headers_data.decode("utf-8", errors="strict").splitlines()
        assert header_lines and re.fullmatch(r"HTTP/[0-9.]+ 200 OK", header_lines[0])
        headers = {}
        for line in header_lines[1:]:
            if ":" in line:
                key, value = line.split(":", 1)
                headers[key.strip().lower()] = value.strip()
        assert int(headers.get("content-length", "-1")) == source_lock["http_content_length"]
        assert headers.get("content-type") == "application/x-gzip"
        assert "filename=immich-3.1.0.tar.gz" in headers.get("content-disposition", "")
        assert headers.get("etag", "").strip('"') == source_lock.get("http_etag")

        tag = json.loads(verified_tar_member_bytes(cache, f"{prefix}/github-tag-v3.1.0.json", 100))
        assert tag.get("ref") == "refs/tags/v3.1.0"
        assert tag.get("object", {}).get("sha") == commit
        assert tag.get("object", {}).get("type") == "commit"

        def verify_oci_manifest(member_name: str, image_key: str) -> None:
            image_lock = images[image_key]
            assert image_lock.get("registry") == "official_ghcr"
            assert image_lock.get("platform") == "linux/amd64"
            digest = image_lock.get("digest")
            assert re.fullmatch(r"sha256:[0-9a-f]{64}", str(digest))
            rows = json.loads(verified_tar_member_bytes(cache, member_name, 1000))
            matches = [
                row for row in rows
                if row.get("Descriptor", {}).get("digest") == digest
                and row.get("Descriptor", {}).get("platform") == {"architecture": "amd64", "os": "linux"}
            ]
            assert len(matches) == 1
            assert matches[0].get("Ref") == f"{image_lock['reference']}@{digest}"

        verify_oci_manifest(f"{prefix}/ghcr-immich-server-v3.1.0-manifest.json", "immich_server")
        verify_oci_manifest(f"{prefix}/ghcr-immich-ml-v3.1.0-manifest.json", "immich_machine_learning")
        local_images = json.loads(verified_tar_member_bytes(cache, f"{prefix}/local-image-inspect.json", 1000))
        local_ids = {item.get("Id") for item in local_images}
        assert images["immich_server"]["local_image_id"] in local_ids
        assert images["immich_machine_learning"]["local_image_id"] in local_ids

        source_listing = verified_tar_member_bytes(cache, f"{prefix}/immich-v3.1.0.tar.list.txt", 1000).decode("utf-8", errors="strict").splitlines()
        with tarfile.open(fileobj=io.BytesIO(source_data), mode="r:gz") as source:
            source_members = list(source)
            assert len(source_members) > 1000
            expected_listing = [member.name + ("/" if member.isdir() and not member.name.endswith("/") else "") for member in source_members]
            assert source_listing == expected_listing
            for member in source_members:
                pure = pathlib.PurePosixPath(member.name)
                assert pure.parts and pure.parts[0] == "immich-3.1.0" and ".." not in pure.parts and "\\" not in member.name
                if member.issym():
                    assert member.name == "immich-3.1.0/fastlane/metadata"
                    assert member.linkname == "../mobile/android/fastlane/metadata"
                else:
                    assert member.isfile() or member.isdir()
            for required in (
                "immich-3.1.0/LICENSE",
                "immich-3.1.0/docker/docker-compose.yml",
                "immich-3.1.0/web/package.json",
                "immich-3.1.0/server/package.json",
            ):
                assert source.getmember(required).isfile()
            assert json.load(source.extractfile("immich-3.1.0/web/package.json"))["version"] == "3.1.0"
            assert json.load(source.extractfile("immich-3.1.0/server/package.json"))["version"] == "3.1.0"
            license_text = source.extractfile("immich-3.1.0/LICENSE").read()
            assert b"GNU AFFERO GENERAL PUBLIC LICENSE" in license_text and b"Version 3" in license_text

        web_data = verified_tar_member_bytes(cache, f"{prefix}/immich-v3.1.0-web-build.tar.gz", 1_000_000)
        with tarfile.open(fileobj=io.BytesIO(web_data), mode="r:gz") as web:
            web_members = list(web)
            assert len(web_members) > 100
            for member in web_members:
                pure = pathlib.PurePosixPath(member.name)
                assert pure.parts and pure.parts[0] == "build" and ".." not in pure.parts and "\\" not in member.name
                assert member.isfile() or member.isdir()
            assert verified_tar_member_bytes(web, "build/index.html", 100).lstrip().lower().startswith(b"<!doctype html>")
            json.loads(verified_tar_member_bytes(web, "build/manifest.json", 100))
            assert json.loads(verified_tar_member_bytes(web, "build/_app/version.json", 1)) == {"version": "3.1.0"}

        verify_zip_plugin(
            verified_tar_member_bytes(cache, "piwigo-extensions/bootstrap-darkroom-16.d.zip", 50_000),
            "bootstrap_darkroom",
            "bootstrap_darkroom/themeconf.inc.php",
            b"Version: 16.d",
        )
        verify_zip_plugin(
            verified_tar_member_bytes(cache, "piwigo-extensions/community-16.f.zip", 50_000),
            "community",
            "community/main.inc.php",
            b"Version: 16.f",
        )
        verify_zip_plugin(
            verified_tar_member_bytes(cache, "piwigo-extensions/user-collections-16.a.zip", 50_000),
            "UserCollections",
            "UserCollections/main.inc.php",
            b"Version: 16.a",
        )

manifest_path = root / "manifest.json"
with manifest_path.open(encoding="utf-8") as handle:
    manifest = json.load(handle)

created_at = manifest.get("created_at")
assert isinstance(created_at, str) and created_at.endswith("Z")
datetime.fromisoformat(created_at[:-1] + "+00:00")
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
assert evidence.get("capture_completed") is True
assert evidence.get("package_verification") == "EXTERNAL_VERIFIER_REQUIRED"
assert evidence.get("private_source_archive_verification") == "PASS"
assert evidence.get("source_integrity_before_after") == "PASS"
assert evidence.get("runtime_sanitization") == "PASS"
assert evidence.get("git_head_public_boundary") == "PASS"
assert evidence.get("git_outgoing_public_boundary") == "PASS"
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
component_items = {}
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
        component_items.setdefault(component, []).append(item)
    assert isinstance(item.get("required"), bool)
    assert item.get("size") == candidate.stat().st_size
    assert re.fullmatch(r"[0-9a-f]{64}", str(item.get("sha256", "")))
    assert checksums.get(relative) == item["sha256"]
    if relative.endswith((".tar", ".tar.gz")):
        validate_inner_tar(candidate)

head = git["head"]
branch = git["branch"]
upstream_caches = [path for path in payload_paths if re.fullmatch(r"payloads/source/official-upstream-cache-[0-9]{8}T[0-9]{6}Z[.]tar[.]gz", path)]
assert len(upstream_caches) == 1
expected_payloads = {
    f"payloads/source/class-archive-source-{head}.tar.gz",
    f"payloads/source/class-archive-history-{head}.bundle",
    upstream_caches[0],
    "payloads/source/immich-upstream.lock.json",
    "payloads/source/ml-artifact-manifest.json",
    "payloads/source/container-lock.json",
    "payloads/synthetic/synthetic-extra-fixtures.tar",
    "payloads/synthetic/synthetic-restore-fixture.json",
    "payloads/synthetic/synthetic-capture-counts.json",
    "payloads/synthetic/synthetic-mariadb.sql.gz",
    "payloads/synthetic/synthetic-piwigo-data.tar",
    "payloads/synthetic/synthetic-piwigo-scripts.tar",
    "payloads/synthetic/synthetic-uploads.tar",
    "payloads/synthetic/synthetic-galleries.tar",
    "payloads/synthetic/synthetic-derivatives.tar",
    "payloads/owner/owner-mariadb.sql.gz",
    "payloads/owner/owner-immich-postgres.dump",
    "payloads/owner/owner-piwigo-data.tar",
    "payloads/owner/owner-piwigo-scripts.tar",
    "payloads/owner/owner-canonical-uploads.tar",
    "payloads/owner/owner-canonical-galleries.tar",
    "payloads/owner/owner-piwigo-derivatives.tar",
    "payloads/owner/owner-immich-canonical.tar",
    "payloads/owner/owner-immich-derivatives.tar",
    "payloads/private-metadata/owner-restore-fixture.json",
    "payloads/private-metadata/owner-mariadb-counts.json",
    "payloads/private-metadata/owner-postgres-counts.json",
    "payloads/private-metadata/owner-capture-counts.json",
    "payloads/private-metadata/owner-postgres-capture-counts.json",
    "payloads/private-metadata/runtime-sanitization.json",
    "payloads/private-metadata/private-import-and-provenance.tar",
    "payloads/private-metadata/source-inventory-before.json",
    "payloads/private-metadata/source-inventory-after.json",
    "payloads/private-metadata/source-allowlist-a.nul",
    "payloads/private-metadata/source-allowlist-b.nul",
    "payloads/private-sources/private-source-a.tar",
    "payloads/private-sources/private-source-b.tar",
}
assert payload_paths == expected_payloads

container_lock = json.loads((root / "payloads/source/container-lock.json").read_text(encoding="utf-8"))
assert container_lock.get("source_git_head") == head
sanitization = json.loads((root / "payloads/private-metadata/runtime-sanitization.json").read_text(encoding="utf-8"))
assert sanitization == {
    "format": "class-archive-runtime-sanitization-v2",
    "owner_mariadb_sessions": 0,
    "owner_mariadb_auth_keys": 0,
    "synthetic_mariadb_sessions": 0,
    "synthetic_mariadb_auth_keys": 0,
    "mariadb_activation_keys": 0,
    "outstanding_identity_tokens": 0,
    "invited_seats": 0,
    "piwigo_secret_config_candidates": 0,
    "audit_raw_token_candidates": 0,
    "postgres_sessions": "excluded",
    "postgres_api_keys": "excluded",
    "postgres_shared_links": "excluded",
    "postgres_stream_sessions": "excluded",
    "postgres_system_metadata": "excluded_all",
    "postgres_user_metadata": "excluded_all",
    "runtime_secrets_included": False,
}

bundle = root / f"payloads/source/class-archive-history-{head}.bundle"
with tempfile.TemporaryDirectory(prefix="classarchive-bundle-verify-") as temporary:
    subprocess.run(["git", "init", "-q"], cwd=temporary, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    subprocess.run(["git", "bundle", "verify", str(bundle)], cwd=temporary, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
heads = subprocess.run(["git", "bundle", "list-heads", str(bundle)], check=True, capture_output=True, text=True).stdout.splitlines()
assert heads == [f"{head} refs/heads/{branch}"]
source_archive = root / f"payloads/source/class-archive-source-{head}.tar.gz"
verify_source_snapshot(source_archive, bundle, branch, head)
verify_official_upstream_cache(root / upstream_caches[0], root / "payloads/source/immich-upstream.lock.json")

assert private_payload_count > 0
required_component_class = {
    "SOURCE_CODE": "PUBLIC_SAFE_SOURCE",
    "SYNTHETIC_BASELINE": "SYNTHETIC_TEST_DATA",
    "OWNER_MARIADB": "PRIVATE_UNENCRYPTED_LOCAL_DATA" if plaintext_private_allowed else "PRIVATE_ENCRYPTED_DATA",
    "OWNER_IMMICH_POSTGRES": "PRIVATE_UNENCRYPTED_LOCAL_DATA" if plaintext_private_allowed else "PRIVATE_ENCRYPTED_DATA",
    "OWNER_CANONICAL_MEDIA": "PRIVATE_UNENCRYPTED_LOCAL_DATA" if plaintext_private_allowed else "PRIVATE_ENCRYPTED_DATA",
    "OWNER_DERIVATIVES": "PRIVATE_UNENCRYPTED_LOCAL_DATA" if plaintext_private_allowed else "PRIVATE_ENCRYPTED_DATA",
    "OWNER_PRIVATE_METADATA": "PRIVATE_UNENCRYPTED_LOCAL_DATA" if plaintext_private_allowed else "PRIVATE_ENCRYPTED_DATA",
    "PRIVATE_SOURCE_LIBRARY": "PRIVATE_UNENCRYPTED_LOCAL_DATA" if plaintext_private_allowed else "PRIVATE_ENCRYPTED_DATA",
    "IMMICH_LOCKS_AND_ML_MANIFEST": "PUBLIC_SAFE_SOURCE",
}
for component, classification in required_component_class.items():
    matching = component_items.get(component, [])
    assert matching
    assert any(item.get("required") is True and item.get("classification") == classification for item in matching)

for item in payloads:
    if item.get("classification") == "PRIVATE_NONSECRET_METADATA":
        assert item["path"].startswith("payloads/source/")

allowed_unlisted = {"checksums.sha256", "COMPLETE"}
regular_files = {
    path.relative_to(root).as_posix()
    for path in root.rglob("*")
    if path.is_file() and not path.is_symlink()
}
for path in root.rglob("*"):
    mode = path.lstat().st_mode
    assert stat.S_ISREG(mode) or stat.S_ISDIR(mode)
    if stat.S_ISREG(mode):
        assert path.stat().st_nlink == 1
assert regular_files - allowed_unlisted == set(checksums)
assert {"manifest.json", "HANDOFF-MAC-PRIVATE.md"}.issubset(checksums)
assert {path for path in regular_files if path.startswith("payloads/")} == payload_paths
assert {
    path for path in regular_files
    if not path.startswith("payloads/") and path not in allowed_unlisted
} == {"manifest.json", "HANDOFF-MAC-PRIVATE.md"}
PY

printf 'CHECKSUM_TOOL=%s\n' "$hash_kind"
printf 'CHECKSUM_FILE_COUNT=%s\n' "$checksum_count"
printf 'PACKAGE_VERIFIED=PASS\n'
printf 'MAC_RUNTIME_TESTED=NO\n'
printf 'HANDOFF_PACKAGE_VERIFY=PASS\n'
