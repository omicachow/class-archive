#!/usr/bin/env bash
# Verify and safely inspect a single Class Archive .tar.zst handoff.

set -euo pipefail

fail() {
  printf 'HANDOFF_ARCHIVE_VERIFY=FAIL reason=%s\n' "$1" >&2
  exit 1
}

[ "$#" -eq 2 ] || {
  printf 'Usage: verify-handoff-archive.sh ARCHIVE.tar.zst EXPECTED_SHA256\n' >&2
  exit 64
}

archive=$1
expected=$(printf '%s' "$2" | tr 'A-F' 'a-f')
[ -f "$archive" ] && [ ! -L "$archive" ] || fail archive_missing_or_symlink
case "$expected" in ''|*[!0-9a-f]*) fail expected_sha256_invalid ;; esac
[ "${#expected}" -eq 64 ] || fail expected_sha256_invalid

for command_name in zstd python3 tar; do
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
zstd -q -dc -- "$archive" | python3 -c '
import pathlib, sys, tarfile
seen = set()
roots = set()
with tarfile.open(fileobj=sys.stdin.buffer, mode="r|") as handle:
    for member in handle:
        name = member.name
        pure = pathlib.PurePosixPath(name)
        assert name and not name.startswith("/") and "\\" not in name
        assert ".." not in pure.parts and pure.parts
        assert name not in seen
        seen.add(name)
        roots.add(pure.parts[0])
        assert member.isfile() or member.isdir()
assert len(roots) == 1
' || fail archive_member_boundary_invalid

tmp=$(mktemp -d "${TMPDIR:-/tmp}/classarchive-handoff.XXXXXXXX")
chmod 700 "$tmp"
trap 'rm -rf -- "$tmp"' EXIT HUP INT TERM
zstd -q -dc -- "$archive" | tar -xf - -C "$tmp" --no-same-owner
root=$(find "$tmp" -mindepth 1 -maxdepth 1 -type d -print)
[ -n "$root" ] && [ "$(printf '%s\n' "$root" | wc -l | tr -d ' ')" = 1 ] || fail extracted_root_invalid
verifier=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)/verify-handoff-package.sh
"$verifier" "$root"
printf 'OUTER_ARCHIVE_SHA256=%s\n' "$actual"
printf 'HANDOFF_ARCHIVE_VERIFY=PASS\n'
