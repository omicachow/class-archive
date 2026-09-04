#!/usr/bin/env bash
# Verify and safely inspect a single Class Archive .tar.zst handoff.

set -euo pipefail

fail() {
  printf 'HANDOFF_ARCHIVE_VERIFY=FAIL reason=%s\n' "$1" >&2
  exit 1
}

[ "$#" -ge 2 ] && [ "$#" -le 3 ] || {
  printf 'Usage: verify-handoff-archive.sh ARCHIVE.tar.zst EXPECTED_SHA256 [WORK_DIR]\n' >&2
  exit 64
}

archive=$1
expected=$(printf '%s' "$2" | tr 'A-F' 'a-f')
work_dir=${3:-$(dirname -- "$archive")}
[ -f "$archive" ] && [ ! -L "$archive" ] || fail archive_missing_or_symlink
[ -d "$work_dir" ] && [ ! -L "$work_dir" ] || fail work_directory_missing_or_symlink
work_lexical=$(python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$work_dir")
work_physical=$(python3 -c 'import pathlib,sys; print(pathlib.Path(sys.argv[1]).resolve(strict=True))' "$work_dir")
[ "$work_lexical" = "$work_physical" ] || fail work_directory_symlink_forbidden
work_dir=$work_physical
case "$expected" in ''|*[!0-9a-f]*) fail expected_sha256_invalid ;; esac
[ "${#expected}" -eq 64 ] || fail expected_sha256_invalid

for command_name in zstd python3 tar df awk; do
  command -v "$command_name" >/dev/null 2>&1 || fail "missing_command_$command_name"
done

if command -v gsha256sum >/dev/null 2>&1; then
  actual=$(gsha256sum -- "$archive" | awk '{print $1}')
elif command -v sha256sum >/dev/null 2>&1; then
  actual=$(sha256sum -- "$archive" | awk '{print $1}')
else
  actual=$(shasum -a 256 -- "$archive" | awk '{print $1}')
fi
[ "$(printf '%s' "$actual" | tr 'A-F' 'a-f')" = "$expected" ] || fail outer_sha256_mismatch

# Read every tar header before extraction. Only one ordinary top-level folder
# and regular files/directories are accepted; links and special nodes fail.
uncompressed_bytes=$(zstd -q -dc -- "$archive" | python3 -I -c '
import pathlib, sys, tarfile, unicodedata
if not __debug__:
    raise RuntimeError("python_assertions_disabled")
seen = set()
portable_seen = set()
portable_types = {}
roots = set()
regular_bytes = 0
with tarfile.open(fileobj=sys.stdin.buffer, mode="r|") as handle:
    for member in handle:
        name = member.name
        pure = pathlib.PurePosixPath(name)
        assert name and not name.startswith("/") and "\\" not in name
        assert ".." not in pure.parts and pure.parts
        canonical = "/".join(pure.parts)
        assert canonical and name not in seen
        seen.add(name)
        portable_name = unicodedata.normalize("NFC", canonical).casefold()
        assert portable_name not in portable_seen
        portable_seen.add(portable_name)
        for index in range(1, len(pure.parts)):
            parent = unicodedata.normalize("NFC", "/".join(pure.parts[:index])).casefold()
            assert portable_types.get(parent) != "file"
        if member.isfile():
            assert not any(existing.startswith(portable_name + "/") for existing in portable_types)
            portable_types[portable_name] = "file"
            regular_bytes += member.size
        else:
            portable_types[portable_name] = "dir"
        roots.add(pure.parts[0])
        assert member.isfile() or member.isdir()
assert len(roots) == 1
print(regular_bytes)
') || fail archive_member_boundary_invalid
case "$uncompressed_bytes" in ''|*[!0-9]*) fail archive_uncompressed_size_invalid ;; esac
free_bytes=$(df -Pk "$work_dir" | awk 'NR==2 {printf "%.0f\n", $4 * 1024}')
required_free=$((uncompressed_bytes + uncompressed_bytes / 20 + 1073741824))
[ "${free_bytes%.*}" -ge "$required_free" ] || fail work_directory_insufficient_space

tmp=$(mktemp -d "$work_dir/.classarchive-handoff-verify.XXXXXXXX")
chmod 700 "$tmp"
trap 'rm -rf -- "$tmp"' EXIT HUP INT TERM
zstd -q -dc -- "$archive" | tar -xf - -C "$tmp" --no-same-owner
root=$(find "$tmp" -mindepth 1 -maxdepth 1 -type d -print)
[ -n "$root" ] && [ "$(printf '%s\n' "$root" | wc -l | tr -d ' ')" = 1 ] || fail extracted_root_invalid
verifier=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)/verify-handoff-package.sh
"$verifier" "$root"
if command -v gsha256sum >/dev/null 2>&1; then
  actual_after=$(gsha256sum -- "$archive" | awk '{print $1}')
elif command -v sha256sum >/dev/null 2>&1; then
  actual_after=$(sha256sum -- "$archive" | awk '{print $1}')
else
  actual_after=$(shasum -a 256 -- "$archive" | awk '{print $1}')
fi
[ "$(printf '%s' "$actual_after" | tr 'A-F' 'a-f')" = "$expected" ] || fail outer_sha256_changed_during_verification
printf 'OUTER_ARCHIVE_SHA256=%s\n' "$actual"
printf 'HANDOFF_ARCHIVE_VERIFY=PASS\n'
