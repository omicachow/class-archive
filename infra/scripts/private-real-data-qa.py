#!/usr/bin/env python3
"""Local-only inventory, sampling, copy, and integrity checks for private QA.

The program intentionally knows no workstation paths.  Callers must provide
explicit, external source roots and an ignored private output directory.  It
never writes below a source root and never prints source paths or basenames.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import re
import secrets
import shutil
import stat
import sys
import time
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

try:
    from PIL import Image, ImageOps, UnidentifiedImageError
except ImportError as exc:  # pragma: no cover - checked by the runner
    raise SystemExit("PRIVATE_QA=BLOCKED reason=pillow_unavailable") from exc


VERSION = 1
IMAGE_EXTENSIONS = {
    ".jpg", ".jpeg", ".png", ".webp", ".heic", ".heif", ".avif",
    ".gif", ".bmp", ".tif", ".tiff", ".dng", ".cr2", ".cr3",
    ".nef", ".arw", ".raf", ".jxl",
}
VIDEO_EXTENSIONS = {".mov", ".mp4", ".m4v", ".avi", ".mkv", ".webm", ".mts", ".m2ts"}
MEDIA_EXTENSIONS = IMAGE_EXTENSIONS | VIDEO_EXTENSIONS
DATE_TIME_ORIGINAL = 36867
GPS_INFO = 34853
MAX_REPORT_STRING = 512


class PrivateQaError(RuntimeError):
    """An error whose public surface is an allowlisted reason code."""

    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


@dataclass(frozen=True)
class Source:
    label: str
    root: Path


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


def relative_posix(path: Path, root: Path) -> str:
    try:
        relative = path.relative_to(root)
    except ValueError as exc:
        raise PrivateQaError("source_escape_detected") from exc
    value = relative.as_posix()
    if value.startswith("../") or value in {"", ".", ".."} or "\x00" in value:
        raise PrivateQaError("source_relative_path_invalid")
    return value


def parse_source(value: str) -> Source:
    if "=" not in value:
        raise PrivateQaError("source_argument_invalid")
    label, raw_root = value.split("=", 1)
    if not re.fullmatch(r"Private Source [A-Z]", label):
        raise PrivateQaError("source_label_invalid")
    root = Path(raw_root).expanduser().resolve(strict=True)
    if not root.is_dir() or root.is_symlink():
        raise PrivateQaError("source_root_invalid")
    return Source(label=label, root=root)


def assert_external_output(output: Path, sources: Iterable[Source]) -> Path:
    output = output.expanduser().resolve(strict=False)
    for source in sources:
        if output == source.root or source.root in output.parents or output in source.root.parents:
            raise PrivateQaError("output_source_overlap")
    output.mkdir(parents=True, exist_ok=True)
    return output


def safe_files(source: Source) -> Iterable[Path]:
    """Yield ordinary files without following directory reparse points."""

    stack = [source.root]
    while stack:
        directory = stack.pop()
        with os.scandir(directory) as entries:
            for entry in entries:
                try:
                    if entry.is_symlink():
                        continue
                    if entry.is_dir(follow_symlinks=False):
                        # Windows reparse points can be traversable without
                        # reporting as Python symlinks.  Never follow them.
                        attributes = getattr(entry.stat(follow_symlinks=False), "st_file_attributes", 0)
                        if attributes & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0):
                            continue
                        stack.append(Path(entry.path))
                    elif entry.is_file(follow_symlinks=False):
                        yield Path(entry.path)
                except OSError:
                    # Record the aggregate unreadable count in the caller;
                    # never echo the sensitive entry name.
                    yield Path(entry.path)


def filename_kind(name: str) -> str:
    stem = Path(name).stem.strip()
    if not stem:
        return "opaque"
    # File explorers and chat exports commonly add copy counters such as
    # "(1)" to otherwise machine-generated camera names.  Remove only that
    # terminal counter before classification; descriptive text is preserved.
    stem = re.sub(r"(?:\s*[\[(]\d+[\])])+$", "", stem)
    normalized = re.sub(r"[\s_.-]+", "", stem)
    if re.fullmatch(r"\d{10,}", normalized):
        return "timestamp_like"
    if re.fullmatch(r"[0-9a-fA-F]{16,}", normalized):
        return "opaque"
    if re.fullmatch(r"(?:IMG|DSC|PXL|MVIMG|mmexport|wx_camera|Screenshot|QQ图片|微信图片)\d+", normalized, re.I):
        return "camera_or_export"
    if re.fullmatch(r"[A-Za-z]{1,8}\d{4,}[A-Za-z]?", normalized):
        return "camera_or_export"
    if len(normalized) >= 12 and re.fullmatch(r"[A-Za-z0-9]+", normalized):
        return "opaque"
    return "meaningful"


def orientation(width: int | None, height: int | None) -> str:
    if not width or not height:
        return "unknown"
    ratio = width / height
    if 0.94 <= ratio <= 1.06:
        return "square"
    return "landscape" if ratio > 1 else "portrait"


def resolution_class(width: int | None, height: int | None) -> str:
    if not width or not height:
        return "unknown"
    pixels = width * height
    if pixels < 500_000:
        return "very_low"
    if pixels < 2_000_000:
        return "low"
    if pixels < 8_000_000:
        return "medium"
    return "high"


def dhash(image: Image.Image) -> str:
    gray = ImageOps.grayscale(ImageOps.exif_transpose(image)).resize((9, 8), Image.Resampling.LANCZOS)
    flattened = getattr(gray, "get_flattened_data", gray.getdata)
    pixels = list(flattened())
    value = 0
    for row in range(8):
        for column in range(8):
            value = (value << 1) | int(pixels[row * 9 + column] > pixels[row * 9 + column + 1])
    return f"{value:016x}"


def inspect_image(path: Path) -> dict[str, Any]:
    result: dict[str, Any] = {
        "width": None,
        "height": None,
        "format": path.suffix.lower().lstrip(".") or "unknown",
        "exif_present": False,
        "datetime_original_present": False,
        "gps_present": False,
        "dhash": None,
        "damaged": False,
    }
    try:
        with Image.open(path) as image:
            result["format"] = (image.format or result["format"]).lower()
            result["width"], result["height"] = image.size
            exif = image.getexif()
            result["exif_present"] = bool(exif)
            result["datetime_original_present"] = bool(exif.get(DATE_TIME_ORIGINAL)) if exif else False
            result["gps_present"] = bool(exif.get(GPS_INFO)) if exif else False
            result["dhash"] = dhash(image)
        # Pillow requires verify() immediately after a fresh open; feature
        # extraction above legitimately decodes pixels for dHash first.
        with Image.open(path) as verification_image:
            verification_image.verify()
    except (UnidentifiedImageError, OSError, ValueError, SyntaxError, RuntimeError, Image.DecompressionBombError):
        result["damaged"] = True
    return result


def inspect_file(source: Source, path: Path) -> dict[str, Any]:
    relative = relative_posix(path.resolve(strict=False), source.root)
    try:
        info = path.stat(follow_symlinks=False)
        if not stat.S_ISREG(info.st_mode):
            raise PrivateQaError("source_entry_not_regular")
        suffix = path.suffix.lower()
        record: dict[str, Any] = {
            "source_label": source.label,
            "relative_source_path": relative,
            "relative_path_digest": sha256_text(f"{source.label}\0{relative}"),
            "media_kind": "image" if suffix in IMAGE_EXTENSIONS else "video" if suffix in VIDEO_EXTENSIONS else "other",
            "extension": suffix.lstrip(".") or "none",
            "file_size": info.st_size,
            "mtime_ns": info.st_mtime_ns,
            "ctime_ns": info.st_ctime_ns,
            "filename_kind": filename_kind(path.name),
            "sha256": sha256_file(path),
            "unreadable": False,
        }
        if suffix in IMAGE_EXTENSIONS:
            record.update(inspect_image(path))
        else:
            record.update({
                "width": None,
                "height": None,
                "format": suffix.lstrip(".") or "unknown",
                "exif_present": False,
                "datetime_original_present": False,
                "gps_present": False,
                "dhash": None,
                "damaged": False,
            })
        record["orientation"] = orientation(record["width"], record["height"])
        record["resolution_class"] = resolution_class(record["width"], record["height"])
        if record["width"] and record["height"]:
            ratio = max(record["width"] / record["height"], record["height"] / record["width"])
            record["extreme_aspect"] = ratio >= 2.5
        else:
            record["extreme_aspect"] = False
        return record
    except (OSError, PrivateQaError):
        return {
            "source_label": source.label,
            "relative_source_path": relative,
            "relative_path_digest": sha256_text(f"{source.label}\0{relative}"),
            "media_kind": "other",
            "extension": path.suffix.lower().lstrip(".") or "none",
            "file_size": None,
            "mtime_ns": None,
            "ctime_ns": None,
            "filename_kind": "unknown",
            "sha256": None,
            "width": None,
            "height": None,
            "format": "unknown",
            "exif_present": False,
            "datetime_original_present": False,
            "gps_present": False,
            "dhash": None,
            "damaged": True,
            "unreadable": True,
            "orientation": "unknown",
            "resolution_class": "unknown",
            "extreme_aspect": False,
        }


def hamming_hex(left: str, right: str) -> int:
    return (int(left, 16) ^ int(right, 16)).bit_count()


def near_duplicate_groups(records: list[dict[str, Any]], max_distance: int = 5) -> list[list[str]]:
    """Bounded dHash grouping without retaining filenames in the summary."""

    candidates = [(item["relative_path_digest"], item["dhash"]) for item in records if item.get("dhash")]
    # An O(n^2) scan is intentionally capped; larger inventories use 16-bit
    # buckets and compare only neighboring visual fingerprints.
    buckets: dict[str, list[tuple[str, str]]] = defaultdict(list)
    for digest, value in candidates:
        buckets[value[:4]].append((digest, value))
    groups: list[list[str]] = []
    consumed: set[str] = set()
    for bucket in buckets.values():
        for index, (digest, value) in enumerate(bucket):
            if digest in consumed:
                continue
            group = [digest]
            for other_digest, other_value in bucket[index + 1:]:
                if other_digest not in consumed and hamming_hex(value, other_value) <= max_distance:
                    group.append(other_digest)
            if len(group) > 1:
                consumed.update(group)
                groups.append(group)
    return groups


def percent(numerator: int, denominator: int) -> float:
    return round(100.0 * numerator / denominator, 2) if denominator else 0.0


def file_timestamp_reliability(records: list[dict[str, Any]]) -> dict[str, Any]:
    """Estimate whether filesystem mtimes can stand in for capture dates.

    The estimate is deliberately conservative: filesystem timestamps are never
    promoted to archive dates.  A large collection written in one short window
    is strong evidence of a transfer/export timestamp rather than photography.
    """

    timestamps = sorted(record["mtime_ns"] for record in records if isinstance(record.get("mtime_ns"), int))
    if not timestamps:
        return {
            "rating": "UNKNOWN",
            "evidence": "NO_FILESYSTEM_TIMESTAMPS",
            "span_seconds": None,
        }
    span_seconds = round((timestamps[-1] - timestamps[0]) / 1e9, 3)
    if len(timestamps) >= 20 and span_seconds <= 24 * 60 * 60:
        evidence = "BATCH_TRANSFER_TIME_CLUSTER"
    else:
        evidence = "FILESYSTEM_TIME_IS_NOT_CAPTURE_METADATA"
    return {
        "rating": "UNRELIABLE",
        "evidence": evidence,
        "span_seconds": span_seconds,
    }


def source_summary(label: str, records: list[dict[str, Any]]) -> dict[str, Any]:
    images = [record for record in records if record["media_kind"] == "image"]
    videos = [record for record in records if record["media_kind"] == "video"]
    readable_images = [record for record in images if not record["unreadable"] and not record["damaged"]]
    timestamps = [record["mtime_ns"] for record in records if isinstance(record.get("mtime_ns"), int)]
    sizes = sorted(record["file_size"] for record in records if isinstance(record.get("file_size"), int))
    exact = Counter(record["sha256"] for record in records if record.get("sha256"))
    return {
        "source_label": label,
        "files_total": len(records),
        "images_total": len(images),
        "videos_total": len(videos),
        "other_total": len(records) - len(images) - len(videos),
        "unreadable_total": sum(bool(record["unreadable"]) for record in records),
        "damaged_image_total": sum(bool(record["damaged"]) for record in images),
        "format_distribution": dict(sorted(Counter(record["format"] for record in records).items())),
        "orientation_distribution": dict(sorted(Counter(record["orientation"] for record in images).items())),
        "resolution_distribution": dict(sorted(Counter(record["resolution_class"] for record in images).items())),
        "extreme_aspect_total": sum(bool(record["extreme_aspect"]) for record in images),
        "exif_present_percent": percent(sum(bool(record["exif_present"]) for record in readable_images), len(readable_images)),
        "datetime_original_present_percent": percent(sum(bool(record["datetime_original_present"]) for record in readable_images), len(readable_images)),
        "gps_present_percent": percent(sum(bool(record["gps_present"]) for record in readable_images), len(readable_images)),
        "meaningful_filename_percent": percent(sum(record["filename_kind"] == "meaningful" for record in images), len(images)),
        "filename_kind_distribution": dict(sorted(Counter(record["filename_kind"] for record in images).items())),
        "file_size_bytes": {
            "min": sizes[0] if sizes else None,
            "median": sizes[len(sizes) // 2] if sizes else None,
            "max": sizes[-1] if sizes else None,
        },
        "mtime_range_utc": {
            "first": datetime.fromtimestamp(min(timestamps) / 1e9, timezone.utc).isoformat() if timestamps else None,
            "last": datetime.fromtimestamp(max(timestamps) / 1e9, timezone.utc).isoformat() if timestamps else None,
        },
        "file_timestamp_reliability_estimate": file_timestamp_reliability(records),
        "exact_duplicate_groups": sum(count > 1 for count in exact.values()),
        "exact_duplicate_files": sum(count for count in exact.values() if count > 1),
    }


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + f".partial-{secrets.token_hex(6)}")
    with temporary.open("w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    os.replace(temporary, path)


def load_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def inventory(args: argparse.Namespace) -> None:
    sources = [parse_source(value) for value in args.source]
    if len({source.label for source in sources}) != len(sources):
        raise PrivateQaError("source_label_duplicate")
    output = assert_external_output(Path(args.output), sources)
    records: list[dict[str, Any]] = []
    started = time.monotonic()
    for source in sources:
        for path in safe_files(source):
            records.append(inspect_file(source, path))
    if not records:
        raise PrivateQaError("source_inventory_empty")
    summaries = [source_summary(source.label, [record for record in records if record["source_label"] == source.label]) for source in sources]
    exact_groups: dict[str, list[str]] = defaultdict(list)
    for record in records:
        if record.get("sha256"):
            exact_groups[record["sha256"]].append(record["relative_path_digest"])
    payload = {
        "version": VERSION,
        "created_at": utc_now(),
        "tool": "private-real-data-qa",
        "source_roots": [{"source_label": source.label, "root": str(source.root)} for source in sources],
        "records": records,
        "summary": {
            "sources": summaries,
            "exact_duplicate_groups": [items for items in exact_groups.values() if len(items) > 1],
            "near_duplicate_groups": near_duplicate_groups(records),
            "duration_seconds": round(time.monotonic() - started, 3),
        },
    }
    write_json(output / "inventory" / "real-data-inventory.json", payload)
    write_json(output / "reports" / "real-data-metadata-audit.json", {
        "version": VERSION,
        "created_at": payload["created_at"],
        "sources": summaries,
        "method": {
            "filesystem_times_are_capture_times": False,
            "datetime_original_only_counts_when_exif_tag_is_present": True,
            "filename_reliability_is_heuristic": True,
            "near_duplicate_method": "64-bit dHash, Hamming distance <= 5 within 16-bit prefix buckets",
        },
    })
    total_images = sum(summary["images_total"] for summary in summaries)
    total_files = sum(summary["files_total"] for summary in summaries)
    print(f"PRIVATE_QA_INVENTORY=PASS sources={len(sources)} files={total_files} images={total_images}")


def metadata_audit(args: argparse.Namespace) -> None:
    inventory_path = Path(args.inventory).expanduser().resolve(strict=True)
    payload = load_json(inventory_path)
    records = payload.get("records") if isinstance(payload, dict) else None
    roots = payload.get("source_roots") if isinstance(payload, dict) else None
    if payload.get("version") != VERSION or not isinstance(records, list) or not isinstance(roots, list):
        raise PrivateQaError("inventory_schema_invalid")
    labels: list[str] = []
    for root in roots:
        label = root.get("source_label") if isinstance(root, dict) else None
        if not isinstance(label, str) or not re.fullmatch(r"Private Source [A-Z]", label) or label in labels:
            raise PrivateQaError("inventory_schema_invalid")
        labels.append(label)
    summaries = [source_summary(label, [record for record in records if record.get("source_label") == label]) for label in labels]
    report_root = inventory_path.parent.parent
    write_json(report_root / "reports" / "real-data-metadata-audit.json", {
        "version": VERSION,
        "created_at": utc_now(),
        "inventory_created_at": payload.get("created_at"),
        "sources": summaries,
        "method": {
            "filesystem_times_are_capture_times": False,
            "datetime_original_only_counts_when_exif_tag_is_present": True,
            "filename_reliability_is_heuristic": True,
            "timestamp_reliability_is_conservative": True,
            "near_duplicate_method": "64-bit dHash, Hamming distance <= 5 within 16-bit prefix buckets",
        },
    })
    print(f"PRIVATE_QA_METADATA_AUDIT=PASS sources={len(summaries)}")


def sampling_reasons(record: dict[str, Any], duplicate_digests: set[str], near_digests: set[str]) -> list[str]:
    reasons = [f"source:{record['source_label']}", f"orientation:{record['orientation']}", f"resolution:{record['resolution_class']}"]
    reasons.append("exif:present" if record["exif_present"] else "exif:missing")
    reasons.append("datetime_original:present" if record["datetime_original_present"] else "datetime_original:missing")
    reasons.append(f"filename:{record['filename_kind']}")
    if record["extreme_aspect"]:
        reasons.append("edge:extreme_aspect")
    if record["relative_path_digest"] in duplicate_digests:
        reasons.append("edge:exact_duplicate")
    if record["relative_path_digest"] in near_digests:
        reasons.append("edge:near_duplicate")
    if record["file_size"] and record["file_size"] >= 15 * 1024 * 1024:
        reasons.append("edge:large_file")
    return reasons


def deterministic_rank(record: dict[str, Any], salt: str) -> str:
    return sha256_text(f"{salt}\0{record['relative_path_digest']}\0{record['sha256']}")


def select(args: argparse.Namespace) -> None:
    inventory_path = Path(args.inventory).resolve(strict=True)
    payload = load_json(inventory_path)
    if payload.get("version") != VERSION or not isinstance(payload.get("records"), list):
        raise PrivateQaError("inventory_schema_invalid")
    output = Path(args.output).resolve(strict=False)
    output.mkdir(parents=True, exist_ok=True)
    target = max(1, min(int(args.target), 5000))
    images = [record for record in payload["records"] if record.get("media_kind") == "image" and not record.get("damaged") and not record.get("unreadable")]
    if not images:
        raise PrivateQaError("sample_candidates_empty")
    target = min(target, len(images))
    duplicate_digests = {digest for group in payload.get("summary", {}).get("exact_duplicate_groups", []) for digest in group}
    near_digests = {digest for group in payload.get("summary", {}).get("near_duplicate_groups", []) for digest in group}

    selected: list[dict[str, Any]] = []
    selected_ids: set[str] = set()
    selected_hashes: Counter[str] = Counter()

    def take(
        candidates: Iterable[dict[str, Any]],
        count: int,
        salt: str,
        *,
        allow_duplicate_content: bool = False,
    ) -> None:
        available = [
            item for item in candidates
            if item["relative_path_digest"] not in selected_ids
            and (allow_duplicate_content or selected_hashes[item["sha256"]] == 0)
        ]
        available.sort(key=lambda item: deterministic_rank(item, salt))
        for item in available[:max(0, count)]:
            selected.append(item)
            selected_ids.add(item["relative_path_digest"])
            selected_hashes[item["sha256"]] += 1

    # Establish balanced per-source quotas.  Feature strata are selected before
    # the general fill so this is representative sampling rather than a simple
    # deterministic random slice.
    labels = sorted({record["source_label"] for record in images})
    source_targets = {label: target // len(labels) for label in labels}
    for label in labels[:target % len(labels)]:
        source_targets[label] += 1

    record_by_digest = {record["relative_path_digest"]: record for record in images}
    exact_groups = payload.get("summary", {}).get("exact_duplicate_groups", [])
    feature_predicates = [
        ("orientation:portrait", lambda item: item["orientation"] == "portrait"),
        ("orientation:square", lambda item: item["orientation"] == "square"),
        ("resolution:very_low", lambda item: item["resolution_class"] == "very_low"),
        ("resolution:high", lambda item: item["resolution_class"] == "high"),
        ("exif:present", lambda item: item["exif_present"]),
        ("exif:missing", lambda item: not item["exif_present"]),
        ("datetime:present", lambda item: item["datetime_original_present"]),
        ("datetime:missing", lambda item: not item["datetime_original_present"]),
        ("filename:meaningful", lambda item: item["filename_kind"] == "meaningful"),
        ("filename:opaque", lambda item: item["filename_kind"] != "meaningful"),
        ("edge:extreme", lambda item: item["extreme_aspect"]),
        ("edge:near_duplicate", lambda item: item["relative_path_digest"] in near_digests),
        ("edge:large_file", lambda item: bool(item["file_size"] and item["file_size"] >= 15 * 1024 * 1024)),
    ]

    for label in labels:
        source_target = source_targets[label]

        # Preserve only a few complete exact-duplicate pairs for duplicate UI
        # and reconciliation QA.  The normal strata prefer unique content.
        candidate_groups: list[list[dict[str, Any]]] = []
        for group in exact_groups:
            records = [
                record_by_digest[digest]
                for digest in group
                if digest in record_by_digest and record_by_digest[digest]["source_label"] == label
            ]
            if len(records) >= 2:
                candidate_groups.append(records)
        candidate_groups.sort(
            key=lambda group: sha256_text(
                f"duplicate-pair:{label}\0" + "\0".join(sorted(item["relative_path_digest"] for item in group))
            )
        )
        for group_index, group in enumerate(candidate_groups[:3]):
            take(
                group,
                min(2, source_target - sum(item["source_label"] == label for item in selected)),
                f"duplicate-pair:{label}:{group_index}",
                allow_duplicate_content=True,
            )

        per_feature = max(2, math.ceil(source_target * 0.035))
        for name, predicate in feature_predicates:
            source_count = sum(item["source_label"] == label for item in selected)
            if source_count >= source_target:
                break
            take(
                (record for record in images if record["source_label"] == label and predicate(record)),
                min(per_feature, source_target - source_count),
                f"{label}:{name}",
            )

        source_count = sum(item["source_label"] == label for item in selected)
        if source_count < source_target:
            take(
                (record for record in images if record["source_label"] == label),
                source_target - source_count,
                f"source-fill:{label}",
            )
        source_count = sum(item["source_label"] == label for item in selected)
        if source_count < source_target:
            take(
                (record for record in images if record["source_label"] == label),
                source_target - source_count,
                f"source-duplicate-fill:{label}",
                allow_duplicate_content=True,
            )

    if len(selected) < target:
        take(images, target - len(selected), "balanced-final")
    if len(selected) < target:
        take(images, target - len(selected), "balanced-duplicate-final", allow_duplicate_content=True)

    manifest_items = []
    for index, record in enumerate(selected, start=1):
        suffix = "." + record["extension"].lower() if record["extension"] != "none" else ".bin"
        manifest_items.append({
            "private_sample_id": f"PQA-{index:04d}-{record['relative_path_digest'][:10]}",
            "source_label": record["source_label"],
            "relative_source_path": record["relative_source_path"],
            "source_sha256": record["sha256"],
            "staging_name": f"pqa-{index:04d}-{secrets.token_hex(8)}{suffix}",
            "staging_sha256": None,
            "file_size": record["file_size"],
            "dimensions": {"width": record["width"], "height": record["height"]},
            "format": record["format"],
            "exif_present": record["exif_present"],
            "date_original_present": record["datetime_original_present"],
            "sampling_reason": sampling_reasons(record, duplicate_digests, near_digests),
            "copied_at": None,
        })
    manifest = {
        "version": VERSION,
        "created_at": utc_now(),
        "inventory_path": str(inventory_path),
        "target": target,
        "items": manifest_items,
        "summary": {
            "total": len(manifest_items),
            "by_source": dict(sorted(Counter(item["source_label"] for item in manifest_items).items())),
            "by_reason": dict(sorted(Counter(reason for item in manifest_items for reason in item["sampling_reason"]).items())),
        },
    }
    write_json(output / "selection" / "private-selection-manifest.json", manifest)
    print(f"PRIVATE_QA_SELECTION=PASS samples={len(manifest_items)} sources={len(labels)}")


def source_map(inventory_payload: dict[str, Any]) -> dict[str, Path]:
    roots: dict[str, Path] = {}
    for item in inventory_payload.get("source_roots", []):
        if not isinstance(item, dict) or not isinstance(item.get("source_label"), str) or not isinstance(item.get("root"), str):
            raise PrivateQaError("inventory_source_roots_invalid")
        roots[item["source_label"]] = Path(item["root"]).resolve(strict=True)
    return roots


def resolve_source_file(root: Path, relative: str) -> Path:
    candidate = (root / Path(relative)).resolve(strict=True)
    if root not in candidate.parents or candidate.is_symlink() or not candidate.is_file():
        raise PrivateQaError("selection_source_invalid")
    return candidate


def copy_samples(args: argparse.Namespace) -> None:
    manifest_path = Path(args.manifest).resolve(strict=True)
    manifest = load_json(manifest_path)
    inventory_payload = load_json(Path(manifest["inventory_path"]).resolve(strict=True))
    roots = source_map(inventory_payload)
    output = Path(args.output).resolve(strict=False)
    staging = output / "staging"
    staging.mkdir(parents=True, exist_ok=True)
    if any(staging.iterdir()):
        raise PrivateQaError("staging_not_empty")
    copied = 0
    for item in manifest.get("items", []):
        root = roots.get(item.get("source_label"))
        if root is None:
            raise PrivateQaError("selection_source_label_invalid")
        source_path = resolve_source_file(root, item["relative_source_path"])
        if sha256_file(source_path) != item["source_sha256"]:
            raise PrivateQaError("source_hash_changed_before_copy")
        destination = (staging / item["staging_name"]).resolve(strict=False)
        if destination.parent != staging or destination.exists():
            raise PrivateQaError("staging_destination_invalid")
        shutil.copyfile(source_path, destination)
        destination_hash = sha256_file(destination)
        if destination_hash != item["source_sha256"]:
            raise PrivateQaError("staging_hash_mismatch")
        item["staging_path"] = str(destination)
        item["staging_sha256"] = destination_hash
        item["copied_at"] = utc_now()
        copied += 1
    manifest["copied_at"] = utc_now()
    write_json(manifest_path, manifest)
    print(f"PRIVATE_QA_COPY=PASS samples={copied} integrity=sha256")


def verify(args: argparse.Namespace) -> None:
    inventory_payload = load_json(Path(args.inventory).resolve(strict=True))
    manifest = load_json(Path(args.manifest).resolve(strict=True)) if args.manifest else None
    roots = source_map(inventory_payload)
    records = inventory_payload.get("records")
    if not isinstance(records, list):
        raise PrivateQaError("inventory_schema_invalid")
    expected_by_source: dict[str, dict[str, dict[str, Any]]] = defaultdict(dict)
    for record in records:
        expected_by_source[record["source_label"]][record["relative_source_path"]] = record
    selected_keys: set[tuple[str, str]] = set()
    if manifest:
        selected_keys = {(item["source_label"], item["relative_source_path"]) for item in manifest.get("items", [])}

    changed = 0
    checked_hashes = 0
    current_seen: dict[str, set[str]] = defaultdict(set)
    for label, root in roots.items():
        for path in safe_files(Source(label, root)):
            relative = relative_posix(path.resolve(strict=False), root)
            current_seen[label].add(relative)
            expected = expected_by_source[label].get(relative)
            if expected is None:
                changed += 1
                continue
            try:
                info = path.stat(follow_symlinks=False)
            except OSError:
                changed += 1
                continue
            if expected.get("file_size") != info.st_size or expected.get("mtime_ns") != info.st_mtime_ns:
                changed += 1
                continue
            should_hash = args.hash_mode == "full" or (label, relative) in selected_keys
            if should_hash and expected.get("sha256"):
                checked_hashes += 1
                if sha256_file(path) != expected["sha256"]:
                    changed += 1
    for label, expected in expected_by_source.items():
        changed += len(set(expected) - current_seen[label])
    if changed:
        raise PrivateQaError("real_source_integrity_changed")

    staging_checked = 0
    if manifest:
        for item in manifest.get("items", []):
            staging_path = Path(item.get("staging_path", "")).resolve(strict=True)
            if not staging_path.is_file() or sha256_file(staging_path) != item.get("source_sha256"):
                raise PrivateQaError("staging_integrity_changed")
            staging_checked += 1
    print(f"PRIVATE_QA_INTEGRITY=PASS files={len(records)} source_hashes={checked_hashes} staging={staging_checked}")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(add_help=True)
    commands = parser.add_subparsers(dest="command", required=True)

    inventory_parser = commands.add_parser("inventory")
    inventory_parser.add_argument("--source", action="append", required=True)
    inventory_parser.add_argument("--output", required=True)
    inventory_parser.set_defaults(function=inventory)

    select_parser = commands.add_parser("select")
    select_parser.add_argument("--inventory", required=True)
    select_parser.add_argument("--output", required=True)
    select_parser.add_argument("--target", type=int, default=400)
    select_parser.set_defaults(function=select)

    copy_parser = commands.add_parser("copy")
    copy_parser.add_argument("--manifest", required=True)
    copy_parser.add_argument("--output", required=True)
    copy_parser.set_defaults(function=copy_samples)

    verify_parser = commands.add_parser("verify")
    verify_parser.add_argument("--inventory", required=True)
    verify_parser.add_argument("--manifest")
    verify_parser.add_argument("--hash-mode", choices=["selected", "full"], default="selected")
    verify_parser.set_defaults(function=verify)

    audit_parser = commands.add_parser("metadata-audit")
    audit_parser.add_argument("--inventory", required=True)
    audit_parser.set_defaults(function=metadata_audit)
    return parser


def main() -> int:
    try:
        args = build_parser().parse_args()
        args.function(args)
        return 0
    except PrivateQaError as error:
        print(f"PRIVATE_QA=FAIL reason={error.reason}", file=sys.stderr)
        return 2
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError):
        print("PRIVATE_QA=FAIL reason=private_runtime_error", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
