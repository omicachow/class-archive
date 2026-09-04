#!/usr/bin/env python3
"""Verify immutable source inventories and their two private POSIX tar payloads."""

from __future__ import annotations

import datetime as dt
import hashlib
import json
import pathlib
import sys
import tarfile
import unicodedata


def fail(code: str) -> "NoReturn":
    print(f"PRIVATE_SOURCE_ARCHIVE_VERIFY=FAIL reason={code}", file=sys.stderr)
    raise SystemExit(1)


def load_inventory(path: pathlib.Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        fail("inventory_parse_failed")
    if not isinstance(value, dict) or value.get("format") != "class-archive-private-source-inventory-v1":
        fail("inventory_format_invalid")
    if value.get("algorithm") != "SHA-256":
        fail("inventory_algorithm_invalid")
    if not isinstance(value.get("files"), list) or not isinstance(value.get("roots"), list):
        fail("inventory_shape_invalid")
    return value


def canonical_relative(raw: object) -> str:
    if not isinstance(raw, str) or not raw or raw.startswith("/") or "\\" in raw:
        fail("relative_path_invalid")
    pure = pathlib.PurePosixPath(raw)
    if not pure.parts or ".." in pure.parts or "." in pure.parts:
        fail("relative_path_invalid")
    return "/".join(pure.parts)


def inventory_map(value: dict) -> dict[tuple[str, str], dict]:
    result: dict[tuple[str, str], dict] = {}
    portable: set[tuple[str, str]] = set()
    calculated_bytes = 0
    root_counts: dict[str, int] = {}
    root_bytes: dict[str, int] = {}
    for item in value["files"]:
        if not isinstance(item, dict) or item.get("source_id") not in {"PRIVATE_SOURCE_A", "PRIVATE_SOURCE_B"}:
            fail("inventory_file_record_invalid")
        source_id = item["source_id"]
        relative = canonical_relative(item.get("relative_path"))
        key = (source_id, relative)
        portable_key = (source_id, unicodedata.normalize("NFC", relative).casefold())
        if key in result or portable_key in portable:
            fail("inventory_duplicate_or_portability_collision")
        portable.add(portable_key)
        size = item.get("size")
        digest = item.get("sha256")
        mtime = item.get("mtime_utc")
        if not isinstance(size, int) or size < 0:
            fail("inventory_size_invalid")
        if not isinstance(digest, str) or len(digest) != 64 or any(ch not in "0123456789abcdef" for ch in digest):
            fail("inventory_sha256_invalid")
        if not isinstance(mtime, str) or not mtime.endswith("Z"):
            fail("inventory_mtime_invalid")
        try:
            dt.datetime.fromisoformat(mtime[:-1] + "+00:00")
        except ValueError:
            fail("inventory_mtime_invalid")
        result[key] = item
        calculated_bytes += size
        root_counts[source_id] = root_counts.get(source_id, 0) + 1
        root_bytes[source_id] = root_bytes.get(source_id, 0) + size
    if value.get("total_files") != len(result) or value.get("total_bytes") != calculated_bytes:
        fail("inventory_total_mismatch")
    summaries = {item.get("source_id"): item for item in value["roots"] if isinstance(item, dict)}
    if set(summaries) != {"PRIVATE_SOURCE_A", "PRIVATE_SOURCE_B"} or len(value["roots"]) != 2:
        fail("inventory_root_set_invalid")
    for source_id in summaries:
        if summaries[source_id].get("file_count") != root_counts.get(source_id, 0):
            fail("inventory_root_count_mismatch")
        if summaries[source_id].get("bytes") != root_bytes.get(source_id, 0):
            fail("inventory_root_bytes_mismatch")
    return result


def verify_unchanged(before: dict[tuple[str, str], dict], after: dict[tuple[str, str], dict]) -> None:
    if set(before) != set(after):
        fail("source_inventory_path_set_changed")
    for key, left in before.items():
        right = after[key]
        for field in ("size", "sha256", "extension"):
            if left.get(field) != right.get(field):
                fail("source_inventory_content_changed")
        # PowerShell's second-resolution and round-trip ISO renderings differ
        # textually (Z versus .0000000Z) while denoting the same source mtime.
        # Parse both values so we preserve an exact instant comparison without
        # weakening the source-integrity gate.
        left_mtime = dt.datetime.fromisoformat(left["mtime_utc"][:-1] + "+00:00")
        right_mtime = dt.datetime.fromisoformat(right["mtime_utc"][:-1] + "+00:00")
        if left_mtime != right_mtime:
            fail("source_inventory_content_changed")


def verify_tar(source_id: str, path: pathlib.Path, expected: dict[tuple[str, str], dict]) -> tuple[int, int]:
    if not path.is_file() or path.is_symlink():
        fail("source_archive_missing_or_symlink")
    seen: set[str] = set()
    portable: set[str] = set()
    count = 0
    total = 0
    try:
        with tarfile.open(path, mode="r:") as archive:
            for member in archive:
                if not member.isfile():
                    fail("source_archive_nonregular_member")
                relative = canonical_relative(member.name)
                portable_name = unicodedata.normalize("NFC", relative).casefold()
                if relative in seen or portable_name in portable:
                    fail("source_archive_duplicate_or_portability_collision")
                seen.add(relative)
                portable.add(portable_name)
                item = expected.get((source_id, relative))
                if item is None or member.size != item["size"]:
                    fail("source_archive_inventory_mismatch")
                stream = archive.extractfile(member)
                if stream is None:
                    fail("source_archive_member_unreadable")
                digest = hashlib.sha256()
                for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                    digest.update(chunk)
                if digest.hexdigest() != item["sha256"]:
                    fail("source_archive_member_sha256_mismatch")
                count += 1
                total += member.size
    except (OSError, tarfile.TarError):
        fail("source_archive_read_failed")
    expected_paths = {relative for sid, relative in expected if sid == source_id}
    if seen != expected_paths:
        fail("source_archive_member_set_mismatch")
    return count, total


def main() -> None:
    if len(sys.argv) != 5:
        print("Usage: verify-private-source-archives.py BEFORE.json AFTER.json SOURCE_A.tar SOURCE_B.tar", file=sys.stderr)
        raise SystemExit(64)
    before = inventory_map(load_inventory(pathlib.Path(sys.argv[1])))
    after = inventory_map(load_inventory(pathlib.Path(sys.argv[2])))
    verify_unchanged(before, after)
    count_a, bytes_a = verify_tar("PRIVATE_SOURCE_A", pathlib.Path(sys.argv[3]), before)
    count_b, bytes_b = verify_tar("PRIVATE_SOURCE_B", pathlib.Path(sys.argv[4]), before)
    print(f"PRIVATE_SOURCE_ARCHIVE_FILES={count_a + count_b}")
    print(f"PRIVATE_SOURCE_ARCHIVE_BYTES={bytes_a + bytes_b}")
    print("PRIVATE_SOURCE_INTEGRITY=PASS")
    print("PRIVATE_SOURCE_ARCHIVE_VERIFY=PASS")


if __name__ == "__main__":
    main()
