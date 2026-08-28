#!/usr/bin/env python3
"""Synthetic-only protocol gate for the full private-library manifest/copy path."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
from collections import Counter
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
INVENTORY_TOOL = ROOT / "infra" / "scripts" / "private-real-data-qa.py"
FULL_TOOL = ROOT / "infra" / "scripts" / "private-real-full-library.py"
SENSITIVE = ["fixture-source-a", "fixture-source-b", "portrait-secret", "private-name"]


def run(tool: Path, *arguments: str, expected: int = 0) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        [sys.executable, str(tool), *arguments],
        cwd=ROOT,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="strict",
        env={**os.environ, "PYTHONUTF8": "1", "PYTHONDONTWRITEBYTECODE": "1"},
        check=False,
    )
    if result.returncode != expected:
        raise AssertionError(f"full_library_protocol_exit expected={expected} actual={result.returncode} stdout={result.stdout} stderr={result.stderr}")
    if any(marker in result.stdout + result.stderr for marker in SENSITIVE):
        raise AssertionError("full_library_protocol_sensitive_name_echo")
    return result


def snapshot(root: Path) -> dict[str, tuple[int, int, bytes]]:
    return {
        path.relative_to(root).as_posix(): (path.stat().st_size, path.stat().st_mtime_ns, path.read_bytes())
        for path in sorted(root.rglob("*"))
        if path.is_file()
    }


def image(path: Path, color: tuple[int, int, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.new("RGB", (96, 64), color).save(path, format="PNG")


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="class-archive-full-library-") as temporary:
        base = Path(temporary)
        source_a = base / "fixture-source-a"
        source_b = base / "fixture-source-b"
        output = base / "private-real-full"
        staging = base / "managed-private-staging"
        image(source_a / "运动会" / "portrait-secret.png", (10, 100, 220))
        image(source_a / "活动" / "same-name.png", (220, 90, 10))
        # Equal leaf labels beneath distinct parents must remain distinct
        # folder mappings. The import protocol keys every folder by its full
        # relative hierarchy rather than by a display name.
        image(source_a / "A" / "活动" / "folder-a.png", (30, 160, 30))
        image(source_a / "B" / "活动" / "folder-b.png", (160, 30, 160))
        (source_b / "毕业" / "private-name-copy.png").parent.mkdir(parents=True, exist_ok=True)
        # Exact duplicate content stays as a second source item and can later
        # become a second album membership without a second canonical original.
        (source_b / "毕业" / "private-name-copy.png").write_bytes((source_a / "运动会" / "portrait-secret.png").read_bytes())
        Image.new("RGB", (20, 20), (1, 2, 3)).save(source_b / "毕业" / "unsupported.gif", format="GIF")
        (source_b / "毕业" / "deferred.mp4").write_bytes(b"synthetic-video-marker")
        before_a = snapshot(source_a)
        before_b = snapshot(source_b)

        inventory_result = run(
            INVENTORY_TOOL,
            "inventory",
            "--source", f"Private Source A={source_a}",
            "--source", f"Private Source B={source_b}",
            "--output", str(output),
        )
        if "PRIVATE_QA_INVENTORY=PASS" not in inventory_result.stdout:
            raise AssertionError("full_library_inventory_gate_missing")
        inventory = output / "inventory" / "real-data-inventory.json"
        prepare_result = run(
            FULL_TOOL,
            "prepare",
            "--inventory", str(inventory),
            "--output", str(output),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B",
        )
        if "PRIVATE_FULL_LIBRARY_MANIFEST=PASS images=5 unsupported=1 videos=1" not in prepare_result.stdout:
            raise AssertionError("full_library_manifest_counts")
        manifest_path = output / "manifests" / "full-real-import-manifest.json"
        journal_path = output / "manifests" / "full-real-source-journal.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        if manifest.get("kind") != "class_archive_private_full_library" or len(manifest.get("items", [])) != 5:
            raise AssertionError("full_library_manifest_shape")
        runtime_text = manifest_path.read_text(encoding="utf-8")
        if not journal_path.is_file() or any(marker in runtime_text for marker in ["portrait-secret", "private-name", "fixture-source"]):
            raise AssertionError("full_library_runtime_manifest_path_disclosure")
        if "relative_source_path" not in journal_path.read_text(encoding="utf-8"):
            raise AssertionError("full_library_source_journal_missing")
        if len({item["staging_name"] for item in manifest["items"]}) != 4 or any("portrait" in item["staging_name"] for item in manifest["items"]):
            raise AssertionError("full_library_opaque_staging_name")
        hashes = Counter(item["source_sha256"] for item in manifest["items"])
        duplicate_hash = next((value for value, count in hashes.items() if count == 2), None)
        duplicate_items = [item for item in manifest["items"] if item["source_sha256"] == duplicate_hash]
        if duplicate_hash is None or len(duplicate_items) != 2 or len({item["folder_path_digest"] for item in duplicate_items}) != 2:
            raise AssertionError("full_library_duplicate_membership_protocol")
        same_leaf = [item for item in manifest["items"] if item["folder_segments"][-1:] == ["活动"]]
        if len(same_leaf) != 3 or len({item["folder_path_digest"] for item in same_leaf}) != 3:
            raise AssertionError("full_library_nested_same_name_folder_protocol")
        if any("source_root" in item or "original_filename" in item for item in manifest["items"]):
            raise AssertionError("full_library_persistable_item_disclosure")
        if str(staging) in manifest_path.read_text(encoding="utf-8"):
            raise AssertionError("full_library_staging_path_disclosure")

        copy_result = run(FULL_TOOL, "copy", "--manifest", str(manifest_path), "--output", str(output), "--staging", str(staging))
        if "PRIVATE_FULL_LIBRARY_COPY=PASS copied=4 reused=0 integrity=sha256" not in copy_result.stdout:
            raise AssertionError("full_library_copy_gate_missing")
        verify_result = run(FULL_TOOL, "verify", "--manifest", str(manifest_path), "--output", str(output), "--staging", str(staging))
        if "PRIVATE_FULL_LIBRARY_VERIFY=PASS sources=5 staging=4" not in verify_result.stdout:
            raise AssertionError("full_library_verify_gate_missing")
        resume_result = run(FULL_TOOL, "copy", "--manifest", str(manifest_path), "--output", str(output), "--staging", str(staging))
        if "PRIVATE_FULL_LIBRARY_COPY=PASS copied=0 reused=4 integrity=sha256" not in resume_result.stdout:
            raise AssertionError("full_library_resume_copy_missing")
        if snapshot(source_a) != before_a or snapshot(source_b) != before_b:
            raise AssertionError("full_library_source_mutated")

        overlap = source_a / "private-output"
        failure = run(
            INVENTORY_TOOL,
            "inventory",
            "--source", f"Private Source A={source_a}",
            "--output", str(overlap),
            expected=2,
        )
        if "reason=output_source_overlap" not in failure.stderr or overlap.exists():
            raise AssertionError("full_library_output_overlap_gate")

    print("PRIVATE_FULL_LIBRARY_PROTOCOL=PASS assertions=19")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
