#!/usr/bin/env python3
"""Audit private real-image inventory records excluded by the full importer.

The tool is deliberately read-only with respect to source roots.  It compares
the owner-local inventory with the path-free runtime import manifest, probes
only the missing records with mature local decoders, and writes a per-file
report to an ignored owner-local path.  Console output is an aggregate,
allowlisted protocol line and never includes a source root or filename.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import io
import json
import os
import re
import shutil
import stat
import sys
import warnings
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any

try:
    import PIL
    from PIL import Image, ImageCms, ImageOps, UnidentifiedImageError
except ImportError as exc:  # pragma: no cover - exercised by the wrapper gate
    raise SystemExit("PRIVATE_REAL_UNIMPORTED_AUDIT=BLOCKED reason=pillow_unavailable") from exc


VERSION = 1
INVENTORY_VERSION = 1
IMPORT_MANIFEST_VERSION = 1
MAX_RECORDS = 200_000
MAX_SOURCE_BYTES = 2 * 1024 * 1024 * 1024
MAX_FRAME_PIXELS = 300_000_000
SAFE_IMPORTED_FORMATS = {"jpeg", "png", "webp"}
SOURCE_CODES = {
    "Private Source A": "PRIVATE_SOURCE_A",
    "Private Source B": "PRIVATE_SOURCE_B",
}
REPORT_KIND = "class_archive_private_unimported_image_audit"


class AuditError(RuntimeError):
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


def json_digest(payload: Any) -> str:
    encoded = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8", "strict")).hexdigest()


def valid_hex(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value.lower()) is not None


def load_json(path: Path, unavailable: str, invalid: str) -> dict[str, Any]:
    try:
        raw = path.read_bytes()
        if len(raw) < 20 or len(raw) > 256 * 1024 * 1024:
            raise AuditError(unavailable)
        payload = json.loads(raw.decode("utf-8", "strict"))
    except AuditError:
        raise
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise AuditError(unavailable) from exc
    if not isinstance(payload, dict):
        raise AuditError(invalid)
    return payload


def safe_relative(value: Any) -> str:
    if not isinstance(value, str) or not value or "\\" in value or "\x00" in value:
        raise AuditError("inventory_relative_path_invalid")
    relative = PurePosixPath(value)
    if relative.is_absolute() or any(part in {"", ".", ".."} for part in relative.parts):
        raise AuditError("inventory_relative_path_invalid")
    return relative.as_posix()


def inventory_roots(payload: dict[str, Any]) -> dict[str, Path]:
    roots: dict[str, Path] = {}
    entries = payload.get("source_roots")
    if not isinstance(entries, list):
        raise AuditError("inventory_source_roots_invalid")
    for entry in entries:
        if not isinstance(entry, dict):
            raise AuditError("inventory_source_roots_invalid")
        label = entry.get("source_label")
        raw_root = entry.get("root")
        if label not in SOURCE_CODES or label in roots or not isinstance(raw_root, str):
            raise AuditError("inventory_source_roots_invalid")
        root = Path(raw_root).expanduser().resolve(strict=True)
        details = root.stat(follow_symlinks=False)
        attributes = getattr(details, "st_file_attributes", 0)
        if not root.is_dir() or root.is_symlink() or attributes & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0):
            raise AuditError("inventory_source_roots_invalid")
        roots[label] = root
    if set(roots) != set(SOURCE_CODES):
        raise AuditError("inventory_source_roots_invalid")
    return roots


def resolve_source(root: Path, relative: str) -> Path:
    candidate = (root / Path(relative)).resolve(strict=True)
    if root not in candidate.parents or candidate.is_symlink() or not candidate.is_file():
        raise AuditError("source_path_invalid")
    details = candidate.stat(follow_symlinks=False)
    attributes = getattr(details, "st_file_attributes", 0)
    if not stat.S_ISREG(details.st_mode) or attributes & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0):
        raise AuditError("source_path_invalid")
    return candidate


def assert_external_report(report: Path, roots: dict[str, Path]) -> Path:
    resolved = report.expanduser().resolve(strict=False)
    for root in roots.values():
        if resolved == root or root in resolved.parents or resolved in root.parents:
            raise AuditError("report_source_overlap")
    if resolved.name != "unimported-images.json":
        raise AuditError("report_name_invalid")
    resolved.parent.mkdir(parents=True, exist_ok=True)
    parent = resolved.parent.resolve(strict=True)
    if parent.is_symlink() or resolved.parent != parent:
        raise AuditError("report_path_untrusted")
    return resolved


def inventory_images(payload: dict[str, Any]) -> tuple[list[dict[str, Any]], dict[tuple[str, str], dict[str, Any]]]:
    if payload.get("version") != INVENTORY_VERSION or not isinstance(payload.get("records"), list):
        raise AuditError("inventory_schema_invalid")
    records = payload["records"]
    if not records or len(records) > MAX_RECORDS:
        raise AuditError("inventory_schema_invalid")
    images: list[dict[str, Any]] = []
    by_key: dict[tuple[str, str], dict[str, Any]] = {}
    for record in records:
        if not isinstance(record, dict) or record.get("media_kind") != "image":
            continue
        label = record.get("source_label")
        reference = record.get("relative_path_digest")
        source_hash = record.get("sha256")
        relative = safe_relative(record.get("relative_source_path"))
        size = record.get("file_size")
        mtime = record.get("mtime_ns")
        if label not in SOURCE_CODES or not valid_hex(reference) or not valid_hex(source_hash) \
                or not isinstance(size, int) or size < 0 or size > MAX_SOURCE_BYTES or not isinstance(mtime, int):
            raise AuditError("inventory_image_record_invalid")
        key = (SOURCE_CODES[label], reference.lower())
        if key in by_key:
            raise AuditError("inventory_image_record_duplicate")
        normalized = dict(record)
        normalized["relative_source_path"] = relative
        normalized["relative_path_digest"] = reference.lower()
        normalized["sha256"] = source_hash.lower()
        images.append(normalized)
        by_key[key] = normalized
    if not images:
        raise AuditError("inventory_image_set_empty")
    return images, by_key


def imported_sources(payload: dict[str, Any], inventory_by_key: dict[tuple[str, str], dict[str, Any]]) -> tuple[set[tuple[str, str]], set[str]]:
    if payload.get("version") != IMPORT_MANIFEST_VERSION \
            or payload.get("kind") != "class_archive_private_full_library" \
            or not isinstance(payload.get("items"), list):
        raise AuditError("import_manifest_schema_invalid")
    items = payload["items"]
    if not items or len(items) > MAX_RECORDS:
        raise AuditError("import_manifest_schema_invalid")
    keys: set[tuple[str, str]] = set()
    hashes: set[str] = set()
    for item in items:
        if not isinstance(item, dict):
            raise AuditError("import_manifest_schema_invalid")
        code = item.get("source_collection_code")
        reference = item.get("source_reference_digest")
        source_hash = item.get("source_sha256")
        if code not in set(SOURCE_CODES.values()) or not valid_hex(reference) or not valid_hex(source_hash):
            raise AuditError("import_manifest_schema_invalid")
        key = (code, reference.lower())
        if key in keys or key not in inventory_by_key:
            raise AuditError("import_manifest_inventory_drift")
        if inventory_by_key[key]["sha256"] != source_hash.lower():
            raise AuditError("import_manifest_inventory_drift")
        keys.add(key)
        hashes.add(source_hash.lower())
    return keys, hashes


def tool_inventory() -> dict[str, Any]:
    return {
        "pillow": {
            "available": True,
            "version": PIL.__version__,
            "decision": "ADAPT_MPO_PRIMARY_FRAME_TO_JPEG_SURROGATE",
        },
        "imagemagick": {"available": shutil.which("magick") is not None, "decision": "REUSE_IF_INSTALLED_NOT_REQUIRED"},
        "exiftool": {"available": shutil.which("exiftool") is not None, "decision": "REUSE_IF_INSTALLED_NOT_REQUIRED"},
        "libheif": {
            "available": importlib.util.find_spec("pillow_heif") is not None or shutil.which("heif-convert") is not None,
            "decision": "NOT_APPLICABLE_TO_MPO",
        },
        "ffmpeg": {"available": shutil.which("ffmpeg") is not None, "decision": "NOT_SELECTED_FOR_MPO_STILL_EXTRACTION"},
        "piwigo_class_archive": {
            "available": True,
            "decision": "ADAPT_WITH_MANAGED_JPEG_SURROGATE",
            "direct_pipeline_formats": ["JPEG", "PNG", "WEBP"],
        },
        "immich_v3_1_0": {
            "available": True,
            "decision": "REUSE_AI_AFTER_CLASS_ARCHIVE_PRESENTATION_ADAPTATION",
            "notes": "Fixed upstream declares .mpo as a supported web-unsupported image extension; current source suffix and Piwigo delivery contract still require an explicit surrogate.",
        },
    }


def validate_icc(icc: Any) -> bytes | None:
    if icc is None:
        return None
    if not isinstance(icc, bytes) or len(icc) == 0 or len(icc) > 16 * 1024 * 1024:
        raise AuditError("color_profile_issue")
    try:
        ImageCms.ImageCmsProfile(io.BytesIO(icc))
    except (OSError, ValueError, TypeError) as exc:
        raise AuditError("color_profile_issue") from exc
    return icc


def probe_mpo(path: Path) -> dict[str, Any]:
    try:
        with path.open("rb") as handle:
            if handle.read(2) != b"\xff\xd8":
                raise AuditError("malformed_image")
        with warnings.catch_warnings(record=True) as captured:
            warnings.simplefilter("always")
            with Image.open(path) as image:
                detected = str(image.format or "").upper()
                frames = int(getattr(image, "n_frames", 1))
                if detected != "MPO" or frames < 2 or frames > 64:
                    raise AuditError("malformed_image")
                image.seek(0)
                width, height = image.size
                if width <= 0 or height <= 0 or width * height > MAX_FRAME_PIXELS:
                    raise AuditError("decoder_failure")
                image.load()
                source_mode = image.mode
                source_orientation = int(image.getexif().get(274, 1) or 1)
                icc = validate_icc(image.info.get("icc_profile"))
                primary = ImageOps.exif_transpose(image).copy()
                if primary.mode != "RGB":
                    if icc is not None:
                        # Retaining a non-RGB source profile on converted RGB
                        # pixels would misrepresent colour. Defer instead of
                        # inventing an unreviewed colour-management transform.
                        raise AuditError("color_profile_issue")
                    primary = primary.convert("RGB")
                frame_sizes: list[list[int] | None] = [[width, height]]
                secondary_decoded = 0
                secondary_failures = 0
                for frame in range(1, frames):
                    try:
                        image.seek(frame)
                        frame_width, frame_height = image.size
                        if frame_width <= 0 or frame_height <= 0 or frame_width * frame_height > MAX_FRAME_PIXELS:
                            raise OSError("secondary_frame_dimensions_invalid")
                        image.load()
                        frame_sizes.append([frame_width, frame_height])
                        secondary_decoded += 1
                    except (EOFError, OSError, ValueError, SyntaxError, RuntimeError, Image.DecompressionBombError):
                        # MPO's primary JPEG is the Class Archive presentation
                        # image. A damaged auxiliary/stereo frame is recorded
                        # but does not invalidate a fully decoded, round-trip
                        # verified primary frame. The original MPO bytes remain
                        # preserved in provenance for later specialist repair.
                        frame_sizes.append(None)
                        secondary_failures += 1
                output = io.BytesIO()
                save_arguments: dict[str, Any] = {
                    "format": "JPEG",
                    "quality": 95,
                    "subsampling": 0,
                }
                if icc is not None:
                    save_arguments["icc_profile"] = icc
                # MPF offsets and source Orientation are deliberately not
                # copied into the single-frame presentation surrogate.  The
                # original bytes and source metadata stay in provenance.
                primary.save(output, **save_arguments)
                surrogate = output.getvalue()
                if len(surrogate) < 64:
                    raise AuditError("decoder_failure")
                with Image.open(io.BytesIO(surrogate)) as verification:
                    if verification.format != "JPEG" or int(getattr(verification, "n_frames", 1)) != 1 \
                            or verification.size != primary.size:
                        raise AuditError("decoder_failure")
                    verification.load()
            warning_categories = sorted({type(item.message).__name__ for item in captured})
        return {
            "decoder": "Pillow",
            "decoder_version": PIL.__version__,
            "detected_format": detected,
            "frame_count": frames,
            "frame_sizes": frame_sizes,
            "secondary_frames_decoded": secondary_decoded,
            "secondary_frame_failures": secondary_failures,
            "primary_mode": source_mode,
            "source_orientation": source_orientation,
            "presentation_orientation_applied": source_orientation not in {0, 1},
            "icc_profile_preserved": icc is not None,
            "warnings": warning_categories,
            "surrogate_format": "JPEG",
            "surrogate_width": primary.width,
            "surrogate_height": primary.height,
            "surrogate_bytes": len(surrogate),
            "surrogate_sha256": hashlib.sha256(surrogate).hexdigest(),
            "metadata_policy": "SOURCE_METADATA_IN_PROVENANCE_PRESENTATION_EXIF_STRIPPED",
        }
    except AuditError:
        raise
    except (UnidentifiedImageError, OSError, ValueError, SyntaxError, RuntimeError, Image.DecompressionBombError) as exc:
        raise AuditError("decoder_failure") from exc


def classify_and_probe(path: Path, record: dict[str, Any], imported_hashes: set[str]) -> tuple[str, str, dict[str, Any] | None]:
    source_hash = record["sha256"]
    if record.get("unreadable") or record.get("damaged"):
        return "decoder_failure", "DEFERRED_WITH_REASON", None
    if record.get("file_size") == 0:
        return "zero_byte_or_invalid", "DEFERRED_WITH_REASON", None
    if source_hash in imported_hashes:
        return "exact_duplicate_already_represented", "SAFE_SOURCE_PROVENANCE_LINK", None
    detected = str(record.get("format") or "").lower()
    if detected == "mpo":
        try:
            probe = probe_mpo(path)
            reason = "mpo_secondary_frame_decode_failure" if probe["secondary_frame_failures"] else "mpo_multi_picture"
            return reason, "SAFE_SUPPLEMENTAL_IMPORT_WITH_JPEG_SURROGATE", probe
        except AuditError as error:
            return error.reason, "DEFERRED_WITH_REASON", None
    if detected in SAFE_IMPORTED_FORMATS:
        return "other", "MANUAL_REVIEW", None
    return "unsupported_format", "DEFERRED_WITH_REASON", None


def write_report(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_name(path.name + ".partial")
    if temporary.exists() and temporary.is_symlink():
        raise AuditError("report_path_untrusted")
    try:
        with temporary.open("x", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
        os.chmod(temporary, stat.S_IRUSR | stat.S_IWUSR)
        os.replace(temporary, path)
    except FileExistsError as exc:
        raise AuditError("report_temporary_exists") from exc
    finally:
        if temporary.exists() and not temporary.is_symlink():
            temporary.unlink(missing_ok=True)


def audit(args: argparse.Namespace) -> None:
    inventory_path = Path(args.inventory).expanduser().resolve(strict=True)
    manifest_path = Path(args.runtime_manifest).expanduser().resolve(strict=True)
    inventory = load_json(inventory_path, "inventory_unavailable", "inventory_schema_invalid")
    manifest = load_json(manifest_path, "import_manifest_unavailable", "import_manifest_schema_invalid")
    roots = inventory_roots(inventory)
    report_path = assert_external_report(Path(args.output), roots)
    images, inventory_by_key = inventory_images(inventory)
    imported, imported_hashes = imported_sources(manifest, inventory_by_key)
    missing = [record for key, record in inventory_by_key.items() if key not in imported]
    missing.sort(key=lambda record: (record["source_label"], record["relative_path_digest"]))
    if len(imported) + len(missing) != len(images):
        raise AuditError("unimported_set_invalid")

    missing_hash_counts = Counter(record["sha256"] for record in missing)
    items: list[dict[str, Any]] = []
    reason_counts: Counter[str] = Counter()
    disposition_counts: Counter[str] = Counter()
    integrity_checked = 0
    for record in missing:
        root = roots[record["source_label"]]
        source = resolve_source(root, record["relative_source_path"])
        before = source.stat(follow_symlinks=False)
        before_hash = sha256_file(source)
        if before.st_size != record["file_size"] or before.st_mtime_ns != record["mtime_ns"] \
                or before_hash != record["sha256"]:
            raise AuditError("source_integrity_changed_before_probe")
        reason, disposition, probe = classify_and_probe(source, record, imported_hashes)
        after = source.stat(follow_symlinks=False)
        after_hash = sha256_file(source)
        if after.st_size != before.st_size or after.st_mtime_ns != before.st_mtime_ns \
                or after_hash != before_hash:
            raise AuditError("source_integrity_changed_during_probe")
        integrity_checked += 1
        reason_counts[reason] += 1
        disposition_counts[disposition] += 1
        items.append({
            # This private ignored report intentionally keeps the relative
            # path so the owner can locate a deferred source without storing
            # or printing an absolute workstation path.
            "source_label": record["source_label"],
            "relative_source_path": record["relative_source_path"],
            "relative_path_digest": record["relative_path_digest"],
            "source_sha256": record["sha256"],
            "file_size": record["file_size"],
            "mtime_ns": record["mtime_ns"],
            "extension": str(record.get("extension") or "unknown").lower(),
            "detected_format": str(record.get("format") or "unknown").upper(),
            "reason_category": reason,
            "disposition": disposition,
            "same_hash_unimported_source_records": missing_hash_counts[record["sha256"]],
            "hash_already_in_imported_sources": record["sha256"] in imported_hashes,
            "decoder_probe": probe,
            "source_integrity": {
                "inventory_size_matches": True,
                "inventory_mtime_matches": True,
                "inventory_sha256_matches": True,
                "before_after_size_matches": True,
                "before_after_mtime_matches": True,
                "before_after_sha256_matches": True,
            },
        })

    safe_dispositions = {"SAFE_SUPPLEMENTAL_IMPORT_WITH_JPEG_SURROGATE", "SAFE_SOURCE_PROVENANCE_LINK"}
    safe_count = sum(disposition_counts[value] for value in safe_dispositions)
    safe_hashes = {
        item["source_sha256"] for item in items
        if item["disposition"] in safe_dispositions and not item["hash_already_in_imported_sources"]
    }
    report = {
        "version": VERSION,
        "kind": REPORT_KIND,
        "created_at": utc_now(),
        "inventory_digest": json_digest(inventory),
        "runtime_manifest_digest": json_digest(manifest),
        "summary": {
            "discovered_image_records": len(images),
            "imported_source_records": len(imported),
            "unimported_source_records": len(missing),
            "unique_unimported_source_hashes": len(missing_hash_counts),
            "exact_duplicate_records_within_unimported": sum(count - 1 for count in missing_hash_counts.values()),
            "hashes_already_in_imported_sources": sum(1 for value in missing_hash_counts if value in imported_hashes),
            "safe_supplemental_source_records": safe_count,
            "safe_supplemental_unique_canonical_sources": len(safe_hashes),
            "deferred_source_records": len(missing) - safe_count,
            "reason_counts": dict(sorted(reason_counts.items())),
            "disposition_counts": dict(sorted(disposition_counts.items())),
            "source_integrity_checked": integrity_checked,
            "source_integrity_result": "PASS",
        },
        "decoder_strategy": tool_inventory(),
        "items": items,
    }
    write_report(report_path, report)
    print(
        "PRIVATE_REAL_UNIMPORTED_AUDIT=PASS "
        f"discovered={len(images)} imported={len(imported)} missing={len(missing)} "
        f"safe={safe_count} unique_safe={len(safe_hashes)} deferred={len(missing) - safe_count} "
        f"source_integrity=PASS report=OWNER_LOCAL_IGNORED"
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(add_help=True)
    parser.add_argument("--inventory", required=True)
    parser.add_argument("--runtime-manifest", required=True)
    parser.add_argument("--output", required=True)
    return parser


def main() -> int:
    try:
        audit(build_parser().parse_args())
        return 0
    except AuditError as error:
        print(f"PRIVATE_REAL_UNIMPORTED_AUDIT=FAIL reason={error.reason}", file=sys.stderr)
        return 2
    except (OSError, ValueError, KeyError, TypeError):
        print("PRIVATE_REAL_UNIMPORTED_AUDIT=FAIL reason=private_runtime_error", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
