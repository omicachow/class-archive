#!/usr/bin/env python3
"""Prepare a path-free, resumable MPO presentation-surrogate import.

Source roots are opened read-only from the ignored inventory.  Only verified
single-frame JPEG presentation objects and a path-free manifest cross the
Piwigo boundary; raw paths remain in an ignored owner-local journal.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import re
import stat
import sys
from pathlib import Path, PurePosixPath
from typing import Any

import PIL
from PIL import Image, ImageCms, ImageOps

VERSION = 1
KIND = "class_archive_private_supplemental_library"
REPORT_KIND = "class_archive_private_unimported_image_audit"
CANONICAL_IDENTITY_BASIS = "PRESENTATION_SHA256"
SOURCE_CODES = {"Private Source A": "PRIVATE_SOURCE_A", "Private Source B": "PRIVATE_SOURCE_B"}
MANIFEST_NAME = "supplemental-import-manifest.json"
JOURNAL_NAME = "supplemental-source-journal.json"
MARKER_NAME = ".classarchive-private-supplemental-staging-v1.json"
MAX_ITEMS = 10_000
RECIPE = {
    "version": 1,
    "source_format": "MPO",
    "presentation_format": "JPEG",
    "transform_kind": "MPO_PRIMARY_FRAME_JPEG",
    "transform_tool": "PILLOW",
    "quality": 95,
    "subsampling": 0,
    "optimize": False,
    "progressive": False,
    "orientation": "EXIF_TRANSPOSE_AND_STRIP",
    "mpf": "STRIP",
    "icc": "PRESERVE_VALID_RGB_PROFILE",
    # Different immutable MPO source objects may contain an identical primary
    # frame.  The managed JPEG presentation bytes are the canonical-photo
    # identity; every distinct MPO remains separately recorded as provenance.
    "canonical_identity_basis": CANONICAL_IDENTITY_BASIS,
}


class SupplementalError(RuntimeError):
    pass


def digest_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def digest_text(value: str) -> str:
    return digest_bytes(value.encode("utf-8", "strict"))


def json_digest(value: Any) -> str:
    return digest_text(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")))


def file_digest(path: Path) -> str:
    result = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            result.update(chunk)
    return result.hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    assert_no_link_components(path, allow_missing=False)
    assert_regular_file(path, "private_artifact_unavailable")
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise SupplementalError("private_artifact_unavailable") from error
    if not isinstance(value, dict):
        raise SupplementalError("private_artifact_invalid")
    return value


def is_link_like(path: Path) -> bool:
    try:
        return path.is_symlink() or bool(getattr(path, "is_junction", lambda: False)())
    except OSError as error:
        raise SupplementalError("private_path_untrusted") from error


def assert_no_link_components(path: Path, *, allow_missing: bool) -> Path:
    """Reject symlinks/junctions before resolve() can erase their identity."""
    absolute = path if path.is_absolute() else Path.cwd() / path
    if ".." in absolute.parts:
        raise SupplementalError("private_path_untrusted")
    anchor = Path(absolute.anchor)
    current = anchor
    for component in absolute.parts[1:]:
        current = current / component
        if is_link_like(current):
            raise SupplementalError("private_path_untrusted")
        if not current.exists():
            if allow_missing:
                break
            raise SupplementalError("private_artifact_unavailable")
    return absolute


def assert_regular_file(path: Path, code: str) -> None:
    if is_link_like(path) or not path.is_file():
        raise SupplementalError(code)


def write_json_once(path: Path, value: Any) -> None:
    encoded = json.dumps(value, ensure_ascii=False, indent=2) + "\n"
    assert_no_link_components(path.parent, allow_missing=True)
    path.parent.mkdir(parents=True, exist_ok=True)
    assert_no_link_components(path.parent, allow_missing=False)
    temporary = path.with_name(path.name + ".partial")
    if is_link_like(path):
        raise SupplementalError("prepared_artifact_drift")
    if path.exists():
        assert_regular_file(path, "prepared_artifact_drift")
        if path.read_text(encoding="utf-8") != encoded:
            raise SupplementalError("prepared_artifact_drift")
        if temporary.exists() or is_link_like(temporary):
            assert_regular_file(temporary, "prepared_partial_untrusted")
            temporary.unlink()
        return
    if temporary.exists() or is_link_like(temporary):
        assert_regular_file(temporary, "prepared_partial_untrusted")
        if temporary.read_text(encoding="utf-8") == encoded:
            os.chmod(temporary, stat.S_IRUSR | stat.S_IWUSR)
            os.replace(temporary, path)
            return
        temporary.unlink()
    try:
        with temporary.open("x", encoding="utf-8", newline="\n") as handle:
            handle.write(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, stat.S_IRUSR | stat.S_IWUSR)
        os.replace(temporary, path)
    finally:
        if temporary.exists() and not is_link_like(temporary):
            temporary.unlink()


def write_binary_once(path: Path, encoded: bytes, expected_digest: str) -> None:
    temporary = path.with_name(path.name + ".partial")
    if is_link_like(path):
        raise SupplementalError("staging_resume_integrity_invalid")
    if path.exists():
        assert_regular_file(path, "staging_resume_integrity_invalid")
        if path.stat().st_size != len(encoded) or file_digest(path) != expected_digest:
            raise SupplementalError("staging_resume_integrity_invalid")
        if temporary.exists() or is_link_like(temporary):
            assert_regular_file(temporary, "staging_partial_untrusted")
            temporary.unlink()
        return
    if temporary.exists() or is_link_like(temporary):
        assert_regular_file(temporary, "staging_partial_untrusted")
        if temporary.stat().st_size == len(encoded) and file_digest(temporary) == expected_digest:
            os.chmod(temporary, stat.S_IRUSR | stat.S_IWUSR)
            os.replace(temporary, path)
            return
        temporary.unlink()
    try:
        with temporary.open("xb") as handle:
            handle.write(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, stat.S_IRUSR | stat.S_IWUSR)
        os.replace(temporary, path)
    finally:
        if temporary.exists() and not is_link_like(temporary):
            temporary.unlink()
    if path.stat().st_size != len(encoded) or file_digest(path) != expected_digest:
        raise SupplementalError("staging_write_invalid")


def valid_hex(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value.lower()) is not None


def safe_relative(value: Any) -> tuple[str, list[str], str]:
    if not isinstance(value, str) or not value or "\\" in value or "\0" in value:
        raise SupplementalError("source_reference_invalid")
    path = PurePosixPath(value)
    if path.is_absolute() or any(part in {"", ".", ".."} for part in path.parts):
        raise SupplementalError("source_reference_invalid")
    return path.as_posix(), list(path.parts[:-1]), path.name


def roots(inventory: dict[str, Any]) -> dict[str, Path]:
    result: dict[str, Path] = {}
    for item in inventory.get("source_roots", []):
        if not isinstance(item, dict) or item.get("source_label") not in SOURCE_CODES or not isinstance(item.get("root"), str):
            raise SupplementalError("inventory_source_roots_invalid")
        requested = assert_no_link_components(Path(item["root"]), allow_missing=False)
        root = requested.resolve(strict=True)
        if not root.is_dir() or is_link_like(requested):
            raise SupplementalError("inventory_source_roots_invalid")
        result[item["source_label"]] = root
    if set(result) != set(SOURCE_CODES):
        raise SupplementalError("inventory_source_roots_invalid")
    return result


def resolve_source(root: Path, relative: str) -> Path:
    requested = root / Path(relative)
    assert_no_link_components(requested, allow_missing=False)
    candidate = requested.resolve(strict=True)
    if root not in candidate.parents or is_link_like(requested) or not candidate.is_file():
        raise SupplementalError("source_path_invalid")
    return candidate


def collection_labels(values: list[str]) -> dict[str, str]:
    result: dict[str, str] = {}
    for value in values:
        if "=" not in value:
            raise SupplementalError("collection_label_invalid")
        code, label = value.split("=", 1)
        label = label.strip()
        if code not in set(SOURCE_CODES.values()) or code in result or not label or len(label) > 190 \
                or any(char in label for char in ("/", "\\", "\0")) or re.match(r"^[A-Za-z]:", label):
            raise SupplementalError("collection_label_invalid")
        result[code] = label
    if set(result) != set(SOURCE_CODES.values()):
        raise SupplementalError("collection_label_invalid")
    return result


def validate_rgb_icc(value: Any, code: str) -> bytes:
    if not isinstance(value, bytes) or not value or len(value) > 16 * 1024 * 1024:
        raise SupplementalError(code)
    try:
        profile = ImageCms.ImageCmsProfile(io.BytesIO(value))
        color_space = str(getattr(profile.profile, "xcolor_space", "")).strip().upper()
    except (OSError, ValueError, TypeError) as error:
        raise SupplementalError(code) from error
    if color_space != "RGB":
        raise SupplementalError(code)
    return value


def convert_primary(path: Path) -> bytes:
    with Image.open(path) as image:
        if str(image.format or "").upper() != "MPO" or int(getattr(image, "n_frames", 1)) < 2:
            raise SupplementalError("source_not_mpo")
        image.seek(0)
        image.load()
        icc = image.info.get("icc_profile")
        if icc is not None:
            icc = validate_rgb_icc(icc, "source_icc_invalid")
        primary = ImageOps.exif_transpose(image).copy()
        if primary.mode != "RGB":
            if icc is not None:
                raise SupplementalError("source_color_transform_unreviewed")
            primary = primary.convert("RGB")
        output = io.BytesIO()
        arguments: dict[str, Any] = {
            "format": "JPEG", "quality": 95, "subsampling": 0,
            "optimize": False, "progressive": False,
        }
        if icc is not None:
            arguments["icc_profile"] = icc
        primary.save(output, **arguments)
        encoded = output.getvalue()
        with Image.open(io.BytesIO(encoded)) as check:
            if check.format != "JPEG" or check.mode != "RGB" or int(getattr(check, "n_frames", 1)) != 1 or check.size != primary.size:
                raise SupplementalError("presentation_roundtrip_invalid")
            check.load()
            output_icc = check.info.get("icc_profile")
            if icc is not None:
                output_icc = validate_rgb_icc(output_icc, "presentation_icc_invalid")
                if not hash_equals_bytes(icc, output_icc):
                    raise SupplementalError("presentation_icc_invalid")
            elif output_icc is not None:
                validate_rgb_icc(output_icc, "presentation_icc_invalid")
        return encoded


def hash_equals_bytes(left: bytes, right: bytes) -> bool:
    return left == right


def manifest_digest(items: list[dict[str, Any]]) -> str:
    lines = ["CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_LIBRARY", f"VERSION={VERSION}",
             f"CANONICAL_IDENTITY_BASIS={CANONICAL_IDENTITY_BASIS}"]
    for item in sorted(items, key=lambda value: (value["source_collection_code"], value["item_digest"])):
        lines.append("\x1e".join([
            item["item_digest"], item["source_collection_code"], item["source_collection_label"],
            item["folder_path_digest"], item["parent_folder_path_digest"] or "", "\x1f".join(item["folder_segments"]),
            item["source_reference_digest"], item["original_filename_digest"], item["source_sha256"],
            str(item["source_byte_size"]), item["presentation_sha256"], str(item["presentation_byte_size"]),
            item["presentation_staging_name"], item["source_format"], item["presentation_format"],
            item["transform_kind"], item["transform_tool"], item["transform_version"], item["transform_recipe_digest"],
            item["canonical_identity_basis"],
        ]))
    return digest_text("\n".join(lines) + "\n")


def prepare(args: argparse.Namespace) -> None:
    inventory_path = assert_no_link_components(Path(args.inventory), allow_missing=False).resolve(strict=True)
    audit_path = assert_no_link_components(Path(args.audit), allow_missing=False).resolve(strict=True)
    inventory = load_json(inventory_path)
    report = load_json(audit_path)
    source_roots = roots(inventory)
    labels = collection_labels(args.collection_label)
    if report.get("kind") != REPORT_KIND or report.get("version") != 1 or not isinstance(report.get("items"), list):
        raise SupplementalError("audit_report_invalid")
    items = report["items"]
    if not items or len(items) > MAX_ITEMS or any(
        not isinstance(item, dict) or item.get("disposition") != "SAFE_SUPPLEMENTAL_IMPORT_WITH_JPEG_SURROGATE"
        for item in items
    ):
        raise SupplementalError("audit_item_set_invalid")
    decoder_versions = [str(item.get("decoder_probe", {}).get("decoder_version", "")) for item in items]
    if any(version != PIL.__version__ for version in decoder_versions):
        raise SupplementalError("decoder_version_drift")
    output_requested = assert_no_link_components(Path(args.output), allow_missing=True)
    staging_requested = assert_no_link_components(Path(args.staging), allow_missing=True)
    output_requested.mkdir(parents=True, exist_ok=True)
    staging_requested.mkdir(parents=True, exist_ok=True)
    assert_no_link_components(output_requested, allow_missing=False)
    assert_no_link_components(staging_requested, allow_missing=False)
    output = output_requested.resolve(strict=True)
    staging = staging_requested.resolve(strict=True)
    for root in source_roots.values():
        if output == root or root in output.parents or output in root.parents or staging == root or root in staging.parents or staging in root.parents:
            raise SupplementalError("output_source_overlap")
    if is_link_like(output_requested) or is_link_like(staging_requested):
        raise SupplementalError("output_path_untrusted")
    recipe_digest = json_digest(RECIPE)
    runtime_items: list[dict[str, Any]] = []
    journal_items: list[dict[str, Any]] = []
    source_seen: set[tuple[str, str]] = set()
    presentation_objects: dict[str, bytes] = {}
    for source_item in items:
        label = source_item.get("source_label")
        reference = str(source_item.get("relative_path_digest", "")).lower()
        source_hash = str(source_item.get("source_sha256", "")).lower()
        if label not in SOURCE_CODES or not valid_hex(reference) or not valid_hex(source_hash):
            raise SupplementalError("audit_item_invalid")
        relative, folders, filename = safe_relative(source_item.get("relative_source_path"))
        code = SOURCE_CODES[label]
        identity = (code, reference)
        if identity in source_seen:
            raise SupplementalError("audit_item_duplicate")
        source_seen.add(identity)
        source_path = resolve_source(source_roots[label], relative)
        before = source_path.stat()
        if before.st_size != source_item.get("file_size") or file_digest(source_path) != source_hash:
            raise SupplementalError("source_integrity_changed_before_prepare")
        encoded = convert_primary(source_path)
        after = source_path.stat()
        if after.st_size != before.st_size or after.st_mtime_ns != before.st_mtime_ns or file_digest(source_path) != source_hash:
            raise SupplementalError("source_integrity_changed_during_prepare")
        presentation_hash = digest_bytes(encoded)
        if presentation_hash == source_hash:
            raise SupplementalError("source_presentation_identity_invalid")
        probe = source_item.get("decoder_probe", {})
        if not isinstance(probe, dict) or presentation_hash != probe.get("surrogate_sha256") or len(encoded) != probe.get("surrogate_bytes"):
            raise SupplementalError("audit_presentation_drift")
        presentation_objects[presentation_hash] = encoded
        folder_relative = "/".join(folders)
        parent_relative = "/".join(folders[:-1]) if folders else None
        item_digest = digest_text(f"{code}\0{reference}")
        staging_name = f"frs-{presentation_hash}.jpg"
        runtime_items.append({
            "item_digest": item_digest,
            "source_collection_code": code,
            "source_collection_label": labels[code],
            "folder_path_digest": digest_text(f"{code}\0{folder_relative}"),
            "parent_folder_path_digest": digest_text(f"{code}\0{parent_relative}") if parent_relative is not None else None,
            "folder_segments": folders,
            "source_reference_digest": reference,
            "original_filename_digest": digest_text(filename),
            "source_sha256": source_hash,
            "source_byte_size": int(before.st_size),
            "presentation_sha256": presentation_hash,
            "presentation_byte_size": len(encoded),
            "presentation_staging_name": staging_name,
            "source_format": "MPO",
            "presentation_format": "JPEG",
            "transform_kind": "MPO_PRIMARY_FRAME_JPEG",
            "transform_tool": "PILLOW",
            "transform_version": PIL.__version__,
            "transform_recipe_digest": recipe_digest,
            "canonical_identity_basis": CANONICAL_IDENTITY_BASIS,
        })
        journal_items.append({
            "item_digest": item_digest, "source_collection_code": code,
            "relative_source_path": relative, "source_reference_digest": reference,
            "source_sha256": source_hash, "source_byte_size": int(before.st_size),
            "presentation_sha256": presentation_hash, "presentation_byte_size": len(encoded),
            "presentation_staging_name": staging_name,
        })
    runtime_items.sort(key=lambda item: (item["source_collection_code"], item["item_digest"]))
    journal_items.sort(key=lambda item: (item["source_collection_code"], item["item_digest"]))
    import_hash = manifest_digest(runtime_items)
    expected_names = {f"frs-{key}.jpg" for key in presentation_objects}
    for presentation_hash, encoded in presentation_objects.items():
        target = staging / f"frs-{presentation_hash}.jpg"
        write_binary_once(target, encoded, presentation_hash)
    for entry in staging.iterdir():
        if entry.name == MARKER_NAME:
            if entry.exists() and (is_link_like(entry) or not entry.is_file()):
                raise SupplementalError("staging_unknown_file")
            continue
        if entry.name not in expected_names or is_link_like(entry) or not entry.is_file():
            raise SupplementalError("staging_unknown_file")
    file_set_digest = digest_text("\n".join(sorted(expected_names)) + "\n")
    manifest = {"version": VERSION, "kind": KIND, "canonical_identity_basis": CANONICAL_IDENTITY_BASIS,
                "import_digest": import_hash, "items": runtime_items}
    journal = {
        "version": VERSION, "kind": "class_archive_private_supplemental_source_journal",
        "audit_report_digest": json_digest(report), "inventory_digest": json_digest(inventory),
        "runtime_manifest_digest": import_hash, "canonical_identity_basis": CANONICAL_IDENTITY_BASIS,
        "transform_recipe": RECIPE, "items": journal_items,
    }
    marker = {"version": VERSION, "layout": "OPAQUE_PRESENTATION_FILES", "import_digest": import_hash,
              "file_count": len(expected_names), "file_set_digest": file_set_digest}
    write_json_once(output / "manifests" / MANIFEST_NAME, manifest)
    write_json_once(output / "manifests" / JOURNAL_NAME, journal)
    write_json_once(staging / MARKER_NAME, marker)
    print(f"PRIVATE_REAL_SUPPLEMENTAL_PREPARE=PASS sources={len(runtime_items)} presentations={len(expected_names)} source_integrity=PASS")


def verify(args: argparse.Namespace) -> None:
    output = assert_no_link_components(Path(args.output), allow_missing=False).resolve(strict=True)
    staging = assert_no_link_components(Path(args.staging), allow_missing=False).resolve(strict=True)
    manifest = load_json(output / "manifests" / MANIFEST_NAME)
    journal = load_json(output / "manifests" / JOURNAL_NAME)
    marker = load_json(staging / MARKER_NAME)
    if manifest.get("version") != VERSION or manifest.get("kind") != KIND \
            or manifest.get("canonical_identity_basis") != CANONICAL_IDENTITY_BASIS \
            or journal.get("canonical_identity_basis") != CANONICAL_IDENTITY_BASIS \
            or not isinstance(manifest.get("items"), list):
        raise SupplementalError("manifest_invalid")
    items = manifest["items"]
    if manifest.get("import_digest") != manifest_digest(items) or journal.get("runtime_manifest_digest") != manifest.get("import_digest") \
            or marker.get("import_digest") != manifest.get("import_digest"):
        raise SupplementalError("manifest_digest_invalid")
    expected_names = {str(item.get("presentation_staging_name")) for item in items}
    actual_names: set[str] = set()
    for entry in staging.iterdir():
        if entry.name == MARKER_NAME:
            continue
        if is_link_like(entry) or not entry.is_file():
            raise SupplementalError("staging_file_set_invalid")
        actual_names.add(entry.name)
    if expected_names != actual_names or marker.get("file_count") != len(expected_names) \
            or marker.get("file_set_digest") != digest_text("\n".join(sorted(expected_names)) + "\n"):
        raise SupplementalError("staging_file_set_invalid")
    for item in items:
        presentation = staging / item["presentation_staging_name"]
        if not valid_hex(item.get("source_sha256")) or not valid_hex(item.get("presentation_sha256")) \
                or item.get("canonical_identity_basis") != CANONICAL_IDENTITY_BASIS \
                or is_link_like(presentation) or not presentation.is_file() \
                or file_digest(presentation) != item["presentation_sha256"]:
            raise SupplementalError("staging_integrity_invalid")
    print(f"PRIVATE_REAL_SUPPLEMENTAL_VERIFY=PASS sources={len(items)} presentations={len(expected_names)}")


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser()
    commands = root.add_subparsers(dest="command", required=True)
    create = commands.add_parser("prepare")
    create.add_argument("--inventory", required=True)
    create.add_argument("--audit", required=True)
    create.add_argument("--output", required=True)
    create.add_argument("--staging", required=True)
    create.add_argument("--collection-label", action="append", default=[])
    check = commands.add_parser("verify")
    check.add_argument("--output", required=True)
    check.add_argument("--staging", required=True)
    return root


def main() -> int:
    try:
        args = parser().parse_args()
        (prepare if args.command == "prepare" else verify)(args)
        return 0
    except SupplementalError as error:
        print(f"PRIVATE_REAL_SUPPLEMENTAL=FAIL reason={str(error)}", file=sys.stderr)
        return 2
    except (OSError, ValueError, KeyError, TypeError):
        print("PRIVATE_REAL_SUPPLEMENTAL=FAIL reason=private_runtime_error", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
