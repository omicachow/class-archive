#!/usr/bin/env python3
"""Prepare and verify a local-only, resumable full photo-library import.

The tool consumes the ignored inventory produced by private-real-data-qa.py.
It never mutates a source root.  Its owner-only copier journal retains source
paths and original filenames solely for local resume and integrity checks.  The
separate manifest mounted into Piwigo is deliberately path-free: it contains
only opaque item/source digests, allowlisted collection labels, folder segments,
and opaque staging-object references.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import stat
import sys
import re
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any


VERSION = 1
SAFE_EXTENSIONS = {"jpg", "jpeg", "png", "webp"}
RUNTIME_MANIFEST_NAME = "full-real-import-manifest.json"
SOURCE_JOURNAL_NAME = "full-real-source-journal.json"
INVENTORY_SNAPSHOT_NAME = "full-real-source-inventory.json"
COLLECTION_CODES = {
    "Private Source A": "PRIVATE_SOURCE_A",
    "Private Source B": "PRIVATE_SOURCE_B",
}
MAX_ITEMS = 200_000


class FullLibraryError(RuntimeError):
    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def sha256_file(path: Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8", "strict")).hexdigest()


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".partial")
    with temporary.open("w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    os.replace(temporary, path)


def json_digest(payload: Any) -> str:
    """Digest JSON data independently of indentation/line-ending choices."""

    return sha256_text(json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")))


def load_json(path: Path) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise FullLibraryError("manifest_unavailable") from exc
    if not isinstance(payload, dict):
        raise FullLibraryError("manifest_schema_invalid")
    return payload


def external_output(output: Path, source_roots: list[Path]) -> Path:
    resolved = output.expanduser().resolve(strict=False)
    for source in source_roots:
        if resolved == source or source in resolved.parents or resolved in source.parents:
            raise FullLibraryError("output_source_overlap")
    resolved.mkdir(parents=True, exist_ok=True)
    return resolved


def managed_staging(value: str | None, output: Path, source_roots: list[Path]) -> Path:
    """Resolve a private, non-source staging root without serialising it.

    The manifest lives under the ignored work tree, while a full library may
    need its opaque staging objects on a separately provisioned private media
    volume.  That absolute media-volume path is deliberately a runtime input,
    never manifest data.  Keeping the default below `output` makes the
    synthetic protocol self-contained; the private full-runtime passes its
    managed staging root explicitly.
    """

    staging = external_output(Path(value) if value is not None else output / "staging", source_roots)
    manifest_directory = (output / "manifests").resolve(strict=False)
    if staging == manifest_directory or staging in manifest_directory.parents or manifest_directory in staging.parents:
        raise FullLibraryError("staging_manifest_overlap")
    return staging


def runtime_manifest_path(output: Path) -> Path:
    return output / "manifests" / RUNTIME_MANIFEST_NAME


def source_journal_path(output: Path) -> Path:
    return output / "manifests" / SOURCE_JOURNAL_NAME


def inventory_snapshot_path(output: Path) -> Path:
    return output / "inventory" / INVENTORY_SNAPSHOT_NAME


def source_roots(inventory: dict[str, Any]) -> dict[str, Path]:
    roots: dict[str, Path] = {}
    values = inventory.get("source_roots")
    if not isinstance(values, list):
        raise FullLibraryError("inventory_schema_invalid")
    for item in values:
        if not isinstance(item, dict):
            raise FullLibraryError("inventory_schema_invalid")
        label = item.get("source_label")
        root = item.get("root")
        if label not in COLLECTION_CODES or not isinstance(root, str) or label in roots:
            raise FullLibraryError("inventory_source_roots_invalid")
        resolved = Path(root).resolve(strict=True)
        if not resolved.is_dir() or resolved.is_symlink():
            raise FullLibraryError("inventory_source_roots_invalid")
        roots[label] = resolved
    if set(roots) != set(COLLECTION_CODES):
        raise FullLibraryError("inventory_source_roots_invalid")
    return roots


def safe_relative(value: Any) -> tuple[str, list[str], str]:
    if not isinstance(value, str) or not value or "\\" in value or "\x00" in value:
        raise FullLibraryError("inventory_relative_path_invalid")
    path = PurePosixPath(value)
    if path.is_absolute() or any(segment in {"", ".", ".."} for segment in path.parts):
        raise FullLibraryError("inventory_relative_path_invalid")
    filename = path.name
    if not filename:
        raise FullLibraryError("inventory_relative_path_invalid")
    folders = list(path.parts[:-1])
    return path.as_posix(), folders, filename


def digest(value: str) -> str:
    return sha256_text(value)


def valid_hex(value: Any) -> bool:
    return isinstance(value, str) and len(value) == 64 and all(char in "0123456789abcdef" for char in value.lower())


def collection_labels(values: list[str]) -> dict[str, str]:
    result: dict[str, str] = {}
    for value in values:
        if "=" not in value:
            raise FullLibraryError("collection_label_invalid")
        code, label = value.split("=", 1)
        if code not in set(COLLECTION_CODES.values()) or code in result:
            raise FullLibraryError("collection_label_invalid")
        label = label.strip()
        if not label or len(label) > 190 or "\x00" in label or any(ord(char) < 32 or ord(char) == 127 for char in label) \
            or "/" in label or "\\" in label or re.match(r"^[A-Za-z]:", label):
            raise FullLibraryError("collection_label_invalid")
        result[code] = label
    if set(result) != set(COLLECTION_CODES.values()):
        raise FullLibraryError("collection_label_invalid")
    return result


def public_item(item: dict[str, Any]) -> dict[str, Any]:
    """Return fields that the PHP importer is allowed to persist/check.

    The raw `relative_source_path` and original filename deliberately stay out
    of this return value.  Business-visible folder segments are retained so
    Piwigo can reproduce the owner-approved album hierarchy without receiving
    a workstation path.
    """

    return {
        "item_digest": item["item_digest"],
        "source_collection_code": item["source_collection_code"],
        "source_collection_label": item["source_collection_label"],
        "folder_path_digest": item["folder_path_digest"],
        "parent_folder_path_digest": item["parent_folder_path_digest"],
        "folder_segments": item["folder_segments"],
        "source_reference_digest": item["source_reference_digest"],
        "original_filename_digest": item["original_filename_digest"],
        "source_sha256": item["source_sha256"],
        "staging_name": item["staging_name"],
        "staging_name_digest": item["staging_name_digest"],
        "file_size": item["file_size"],
        "extension": item["extension"],
    }


def import_digest(items: list[dict[str, Any]]) -> str:
    # This format is consumed independently by the PHP importer.  It is not a
    # JSON formatting hash, so either runtime can reproduce it byte-for-byte.
    lines = ["CLASS_ARCHIVE_PRIVATE_FULL_LIBRARY", f"VERSION={VERSION}"]
    for item in sorted(items, key=lambda value: (value["source_collection_code"], value["item_digest"])):
        value = public_item(item)
        lines.append("\x1e".join([
            value["item_digest"], value["source_collection_code"], value["source_collection_label"],
            value["folder_path_digest"], value["parent_folder_path_digest"] or "",
            "\x1f".join(value["folder_segments"]), value["source_reference_digest"],
            value["original_filename_digest"], value["source_sha256"], value["staging_name"],
            value["staging_name_digest"], str(value["file_size"]), value["extension"],
        ]))
    return sha256_text("\n".join(lines) + "\n")


def canonical_extension(record: dict[str, Any]) -> str | None:
    """Use decoder-confirmed format for a shared checksum staging object."""

    value = str(record.get("format") or "").lower()
    if value == "jpeg":
        return "jpg"
    return value if value in SAFE_EXTENSIONS else None


def prepare(args: argparse.Namespace) -> None:
    inventory_path = Path(args.inventory).expanduser().resolve(strict=True)
    inventory = load_json(inventory_path)
    if inventory.get("version") != 1 or not isinstance(inventory.get("records"), list):
        raise FullLibraryError("inventory_schema_invalid")
    roots = source_roots(inventory)
    labels = collection_labels(args.collection_label)
    output = external_output(Path(args.output), list(roots.values()))
    manifest_path = runtime_manifest_path(output)
    journal_path = source_journal_path(output)
    snapshot_path = inventory_snapshot_path(output)
    if (manifest_path.exists() or journal_path.exists()) and not args.replace:
        raise FullLibraryError("manifest_exists")

    # The full import owns an ignored snapshot of the inventory. This is the
    # only location from which the local copier learns source roots/paths. The
    # Piwigo-facing runtime manifest below deliberately contains neither.
    snapshot_digest = json_digest(inventory)
    if snapshot_path.exists() and not args.replace:
        existing_snapshot = load_json(snapshot_path)
        if json_digest(existing_snapshot) != snapshot_digest:
            raise FullLibraryError("inventory_snapshot_drift")
    else:
        write_json(snapshot_path, inventory)

    items: list[dict[str, Any]] = []
    unsupported_images = 0
    skipped_damaged = 0
    for record in inventory["records"]:
        if not isinstance(record, dict) or record.get("media_kind") != "image":
            continue
        if record.get("damaged") or record.get("unreadable"):
            skipped_damaged += 1
            continue
        label = record.get("source_label")
        if label not in COLLECTION_CODES:
            raise FullLibraryError("inventory_source_label_invalid")
        relative, folders, filename = safe_relative(record.get("relative_source_path"))
        extension = canonical_extension(record)
        if extension is None:
            unsupported_images += 1
            continue
        source_hash = record.get("sha256")
        source_reference_digest = record.get("relative_path_digest")
        size = record.get("file_size")
        if not valid_hex(source_hash) or not valid_hex(source_reference_digest) or not isinstance(size, int) or size <= 0:
            raise FullLibraryError("inventory_item_invalid")
        code = COLLECTION_CODES[label]
        display = labels[code]
        folder_relative = "/".join(folders)
        parent_relative = "/".join(folders[:-1]) if folders else None
        # Item identity is deterministic but path-free for the Piwigo-facing
        # manifest: its source reference is already a source-label/relative
        # path digest made by the readonly inventory.
        item_digest = digest(f"{code}\0{source_reference_digest}")
        folder_digest = digest(f"{code}\0{folder_relative}")
        parent_digest = digest(f"{code}\0{parent_relative}") if parent_relative is not None else None
        # Exact byte duplicates deliberately share one staging copy. The
        # per-source item digest still remains distinct for provenance and
        # direct-leaf album membership during PHP import.
        staging_name = f"frl-{source_hash.lower()}.{extension}"
        items.append({
            "item_digest": item_digest,
            "source_collection_code": code,
            "source_collection_label": display,
            "relative_source_path": relative,
            "folder_path_digest": folder_digest,
            "parent_folder_path_digest": parent_digest,
            "folder_segments": folders,
            "source_reference_digest": source_reference_digest,
            "original_filename_digest": digest(filename),
            "source_sha256": source_hash.lower(),
            "staging_name": staging_name,
            "staging_name_digest": digest(staging_name),
            "staging_sha256": None,
            "file_size": size,
            "extension": extension,
            "copied_at": None,
        })
    items.sort(key=lambda value: (value["source_collection_code"], value["item_digest"]))
    if not items or len(items) > MAX_ITEMS or len({item["item_digest"] for item in items}) != len(items):
        raise FullLibraryError("full_manifest_item_set_invalid")
    staging_to_hash = {item["staging_name"]: item["source_sha256"] for item in items}
    if any(staging_to_hash[item["staging_name"]] != item["source_sha256"] for item in items):
        raise FullLibraryError("full_manifest_staging_collision")
    runtime_items = [public_item(item) for item in items]
    manifest = {
        "version": VERSION,
        "kind": "class_archive_private_full_library",
        "created_at": utc_now(),
        "import_digest": import_digest(runtime_items),
        # This is the only manifest mounted into Piwigo. It intentionally has
        # no source root, raw relative path, or raw original filename.
        "items": runtime_items,
        "summary": {
            "supported_images": len(items),
            "unsupported_image_files": unsupported_images,
            "damaged_or_unreadable_images": skipped_damaged,
            "video_files_deferred": sum(record.get("media_kind") == "video" for record in inventory["records"] if isinstance(record, dict)),
            "other_files_deferred": sum(record.get("media_kind") == "other" for record in inventory["records"] if isinstance(record, dict)),
        },
    }
    source_journal = {
        "version": VERSION,
        "kind": "class_archive_private_full_library_source_journal",
        "created_at": utc_now(),
        "runtime_manifest_digest": manifest["import_digest"],
        "inventory_file": "inventory/" + INVENTORY_SNAPSHOT_NAME,
        "inventory_digest": snapshot_digest,
        # Local copier-only mapping. This file is ignored/owner-local and is
        # never mounted into Piwigo or sent to the ClassIdentity service.
        "items": [{
            "item_digest": item["item_digest"],
            "source_collection_code": item["source_collection_code"],
            "relative_source_path": item["relative_source_path"],
            "source_reference_digest": item["source_reference_digest"],
            "source_sha256": item["source_sha256"],
            "staging_name": item["staging_name"],
            "file_size": item["file_size"],
            "extension": item["extension"],
            "staging_sha256": item["staging_sha256"],
            "copied_at": item["copied_at"],
        } for item in items],
    }
    write_json(manifest_path, manifest)
    write_json(journal_path, source_journal)
    print(f"PRIVATE_FULL_LIBRARY_MANIFEST=PASS images={len(items)} unsupported={unsupported_images} videos={manifest['summary']['video_files_deferred']}")


def resolve_source(root: Path, relative: str) -> Path:
    candidate = (root / Path(relative)).resolve(strict=True)
    if root not in candidate.parents or candidate.is_symlink() or not candidate.is_file():
        raise FullLibraryError("source_path_invalid")
    details = candidate.stat(follow_symlinks=False)
    if not stat.S_ISREG(details.st_mode):
        raise FullLibraryError("source_path_invalid")
    return candidate


def validate_manifest(payload: dict[str, Any]) -> list[dict[str, Any]]:
    if payload.get("version") != VERSION or payload.get("kind") != "class_archive_private_full_library":
        raise FullLibraryError("manifest_schema_invalid")
    items = payload.get("items")
    if not isinstance(items, list) or not items or len(items) > MAX_ITEMS:
        raise FullLibraryError("manifest_schema_invalid")
    for item in items:
        if not isinstance(item, dict):
            raise FullLibraryError("manifest_schema_invalid")
        # This is the Piwigo-facing manifest. Raw source paths and filenames
        # belong solely to the local source journal and must not cross this
        # boundary even though both files are ignored locally.
        if "relative_source_path" in item or "original_filename" in item or "source_root" in item:
            raise FullLibraryError("manifest_sensitive_source_field")
        if item.get("source_collection_code") not in set(COLLECTION_CODES.values()):
            raise FullLibraryError("manifest_schema_invalid")
        label = item.get("source_collection_label")
        if not isinstance(label, str) or not label or len(label) > 190 or "\x00" in label \
            or any(ord(char) < 32 or ord(char) == 127 for char in label) \
            or "/" in label or "\\" in label or re.match(r"^[A-Za-z]:", label):
            raise FullLibraryError("manifest_schema_invalid")
        if not all(valid_hex(item.get(key)) for key in [
            "item_digest", "folder_path_digest", "source_reference_digest", "original_filename_digest",
            "source_sha256", "staging_name_digest",
        ]):
            raise FullLibraryError("manifest_schema_invalid")
        parent = item.get("parent_folder_path_digest")
        if parent is not None and not valid_hex(parent):
            raise FullLibraryError("manifest_schema_invalid")
        if not isinstance(item.get("folder_segments"), list) or len(item["folder_segments"]) > 255 or any(
            not isinstance(segment, str) or not segment or segment in {".", ".."} or len(segment) > 190
            or "/" in segment or "\\" in segment or "\x00" in segment
            or any(ord(char) < 32 or ord(char) == 127 for char in segment)
            for segment in item["folder_segments"]
        ):
            raise FullLibraryError("manifest_schema_invalid")
        if item.get("extension") not in SAFE_EXTENSIONS or not isinstance(item.get("file_size"), int) or item["file_size"] <= 0:
            raise FullLibraryError("manifest_schema_invalid")
        if item.get("staging_name") != f"frl-{item['source_sha256']}.{item['extension']}":
            raise FullLibraryError("manifest_schema_invalid")
        expected_item_digest = digest(f"{item['source_collection_code']}\0{item['source_reference_digest']}")
        expected_folder_digest = digest(f"{item['source_collection_code']}\0" + "/".join(item["folder_segments"]))
        expected_parent = None if not item["folder_segments"] else digest(
            f"{item['source_collection_code']}\0" + "/".join(item["folder_segments"][:-1])
        )
        if item["item_digest"] != expected_item_digest or item["folder_path_digest"] != expected_folder_digest \
            or item.get("parent_folder_path_digest") != expected_parent:
            raise FullLibraryError("manifest_digest_invalid")
    staging_to_hash = {item["staging_name"]: item["source_sha256"] for item in items}
    if len({item["item_digest"] for item in items}) != len(items) or any(
        staging_to_hash[item["staging_name"]] != item["source_sha256"] for item in items
    ) or payload.get("import_digest") != import_digest(items):
        raise FullLibraryError("manifest_digest_invalid")
    return items


def journal_inventory(output: Path, journal: dict[str, Any], items: list[dict[str, Any]]) -> tuple[dict[str, Path], dict[str, dict[str, Any]]]:
    """Validate the non-mounted source journal against its immutable inputs."""

    if journal.get("version") != VERSION or journal.get("kind") != "class_archive_private_full_library_source_journal":
        raise FullLibraryError("source_journal_schema_invalid")
    if journal.get("runtime_manifest_digest") != import_digest(items):
        raise FullLibraryError("source_journal_manifest_drift")
    if journal.get("inventory_file") != "inventory/" + INVENTORY_SNAPSHOT_NAME:
        raise FullLibraryError("source_journal_inventory_invalid")
    inventory_path = inventory_snapshot_path(output)
    inventory = load_json(inventory_path)
    if journal.get("inventory_digest") != json_digest(inventory):
        raise FullLibraryError("source_journal_inventory_drift")
    roots = source_roots(inventory)
    records = inventory.get("records")
    journal_items = journal.get("items")
    if not isinstance(records, list) or not isinstance(journal_items, list) or len(journal_items) != len(items):
        raise FullLibraryError("source_journal_schema_invalid")
    record_by_key: dict[tuple[str, str], dict[str, Any]] = {}
    for record in records:
        if not isinstance(record, dict) or record.get("media_kind") != "image":
            continue
        label = record.get("source_label")
        relative = record.get("relative_source_path")
        if label in COLLECTION_CODES and isinstance(relative, str):
            record_by_key[(COLLECTION_CODES[label], relative)] = record
    expected = {item["item_digest"]: item for item in items}
    local: dict[str, dict[str, Any]] = {}
    for entry in journal_items:
        if not isinstance(entry, dict):
            raise FullLibraryError("source_journal_schema_invalid")
        item_digest = entry.get("item_digest")
        code = entry.get("source_collection_code")
        relative = entry.get("relative_source_path")
        if not isinstance(item_digest, str) or item_digest not in expected or code not in set(COLLECTION_CODES.values()):
            raise FullLibraryError("source_journal_schema_invalid")
        relative, _, _ = safe_relative(relative)
        public = expected[item_digest]
        for field in ("source_sha256", "staging_name", "file_size", "extension", "source_reference_digest"):
            if entry.get(field) != public.get(field):
                raise FullLibraryError("source_journal_item_drift")
        if item_digest != digest(f"{code}\0{entry['source_reference_digest']}"):
            raise FullLibraryError("source_journal_item_drift")
        label = next(label for label, source_code in COLLECTION_CODES.items() if source_code == code)
        record = record_by_key.get((code, relative))
        if record is None or record.get("relative_path_digest") != public["source_reference_digest"] \
            or record.get("sha256") != public["source_sha256"] or record.get("file_size") != public["file_size"]:
            raise FullLibraryError("source_journal_inventory_drift")
        if item_digest in local:
            raise FullLibraryError("source_journal_item_duplicate")
        local[item_digest] = entry
    if set(local) != set(expected):
        raise FullLibraryError("source_journal_item_set_invalid")
    return roots, local


def copy_all(args: argparse.Namespace) -> None:
    manifest_path = Path(args.manifest).expanduser().resolve(strict=True)
    manifest = load_json(manifest_path)
    items = validate_manifest(manifest)
    output = Path(args.output).expanduser().resolve(strict=True)
    expected_manifest = runtime_manifest_path(output)
    if manifest_path != expected_manifest:
        raise FullLibraryError("fixed_private_manifest_required")
    journal = load_json(source_journal_path(output))
    roots, local_items = journal_inventory(output, journal, items)
    output = external_output(output, list(roots.values()))
    staging = managed_staging(args.staging, output, list(roots.values()))
    copied = 0
    reused = 0
    seen_staging: set[str] = set()
    for item in items:
        source_label = next(label for label, code in COLLECTION_CODES.items() if code == item["source_collection_code"])
        local = local_items[item["item_digest"]]
        source = resolve_source(roots[source_label], local["relative_source_path"])
        if sha256_file(source) != item["source_sha256"]:
            raise FullLibraryError("source_hash_changed_before_copy")
        if item["staging_name"] in seen_staging:
            local["staging_sha256"] = item["source_sha256"]
            local["copied_at"] = local.get("copied_at") or utc_now()
            continue
        seen_staging.add(item["staging_name"])
        destination = (staging / item["staging_name"]).resolve(strict=False)
        if destination.parent != staging or destination.is_symlink():
            raise FullLibraryError("staging_destination_invalid")
        if destination.exists():
            if not destination.is_file() or sha256_file(destination) != item["source_sha256"]:
                raise FullLibraryError("staging_resume_integrity_invalid")
            reused += 1
        else:
            shutil.copyfile(source, destination)
            if sha256_file(destination) != item["source_sha256"]:
                destination.unlink(missing_ok=True)
                raise FullLibraryError("staging_hash_mismatch")
            copied += 1
        local["staging_sha256"] = item["source_sha256"]
        local["copied_at"] = local.get("copied_at") or utc_now()
    journal["copied_at"] = utc_now()
    write_json(source_journal_path(output), journal)
    print(f"PRIVATE_FULL_LIBRARY_COPY=PASS copied={copied} reused={reused} integrity=sha256")


def verify(args: argparse.Namespace) -> None:
    manifest_path = Path(args.manifest).expanduser().resolve(strict=True)
    manifest = load_json(manifest_path)
    items = validate_manifest(manifest)
    output = Path(args.output).expanduser().resolve(strict=True)
    if manifest_path != runtime_manifest_path(output):
        raise FullLibraryError("fixed_private_manifest_required")
    journal = load_json(source_journal_path(output))
    roots, local_items = journal_inventory(output, journal, items)
    output = external_output(output, list(roots.values()))
    staging = managed_staging(args.staging, output, list(roots.values())).resolve(strict=True)
    source_checked = 0
    staging_checked = 0
    seen_staging: set[str] = set()
    for item in items:
        source_label = next(label for label, code in COLLECTION_CODES.items() if code == item["source_collection_code"])
        local = local_items[item["item_digest"]]
        source = resolve_source(roots[source_label], local["relative_source_path"])
        details = source.stat(follow_symlinks=False)
        if details.st_size != item["file_size"] or sha256_file(source) != item["source_sha256"]:
            raise FullLibraryError("source_integrity_changed")
        source_checked += 1
        if item["staging_name"] in seen_staging:
            continue
        seen_staging.add(item["staging_name"])
        staged = (staging / item["staging_name"]).resolve(strict=True)
        if staged.parent != staging or staged.is_symlink() or not staged.is_file() or sha256_file(staged) != item["source_sha256"]:
            raise FullLibraryError("staging_integrity_changed")
        staging_checked += 1
    print(f"PRIVATE_FULL_LIBRARY_VERIFY=PASS sources={source_checked} staging={staging_checked} import_digest={manifest['import_digest']}")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(add_help=True)
    commands = parser.add_subparsers(dest="command", required=True)
    prepare_parser = commands.add_parser("prepare")
    prepare_parser.add_argument("--inventory", required=True)
    prepare_parser.add_argument("--output", required=True)
    prepare_parser.add_argument("--collection-label", action="append", required=True)
    prepare_parser.add_argument("--replace", action="store_true")
    prepare_parser.set_defaults(function=prepare)
    copy_parser = commands.add_parser("copy")
    copy_parser.add_argument("--manifest", required=True)
    copy_parser.add_argument("--output", required=True)
    copy_parser.add_argument("--staging", help="private opaque staging root; never written into the manifest")
    copy_parser.set_defaults(function=copy_all)
    verify_parser = commands.add_parser("verify")
    verify_parser.add_argument("--manifest", required=True)
    verify_parser.add_argument("--output", required=True)
    verify_parser.add_argument("--staging", help="private opaque staging root; never written into the manifest")
    verify_parser.set_defaults(function=verify)
    return parser


def main() -> int:
    try:
        args = build_parser().parse_args()
        args.function(args)
        return 0
    except FullLibraryError as error:
        print(f"PRIVATE_FULL_LIBRARY=FAIL reason={error.reason}", file=sys.stderr)
        return 2
    except (OSError, ValueError, KeyError, TypeError):
        print("PRIVATE_FULL_LIBRARY=FAIL reason=private_runtime_error", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
