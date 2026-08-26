#!/usr/bin/env python3
"""Synthetic-only protocol gate for the private unimported-image auditor."""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
INVENTORY_TOOL = ROOT / "infra" / "scripts" / "private-real-data-qa.py"
FULL_TOOL = ROOT / "infra" / "scripts" / "private-real-full-library.py"
AUDIT_TOOL = ROOT / "infra" / "scripts" / "private-real-unimported-audit.py"
SENSITIVE = ["fixture-source-a", "fixture-source-b", "private-mpo", "duplicate-mpo"]


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
        raise AssertionError(
            f"unimported_audit_exit expected={expected} actual={result.returncode} "
            f"stdout={result.stdout} stderr={result.stderr}"
        )
    if any(marker in result.stdout + result.stderr for marker in SENSITIVE):
        raise AssertionError("unimported_audit_sensitive_name_echo")
    return result


def snapshot(root: Path) -> dict[str, tuple[int, int, bytes]]:
    return {
        path.relative_to(root).as_posix(): (path.stat().st_size, path.stat().st_mtime_ns, path.read_bytes())
        for path in sorted(root.rglob("*"))
        if path.is_file()
    }


def make_mpo(path: Path, first: tuple[int, int, int], second: tuple[int, int, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    primary = Image.new("RGB", (96, 64), first)
    alternate = Image.new("RGB", (96, 64), second)
    primary.save(path, format="MPO", save_all=True, append_images=[alternate])


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="class-archive-unimported-audit-") as temporary:
        base = Path(temporary)
        source_a = base / "fixture-source-a"
        source_b = base / "fixture-source-b"
        output = base / "private-output"
        source_a.mkdir()
        source_b.mkdir()
        Image.new("RGB", (80, 60), (20, 80, 180)).save(source_a / "imported.png", format="PNG")
        first_mpo = source_b / "private-mpo.jpg"
        second_mpo = source_b / "other-private-mpo.jpeg"
        duplicate_mpo = source_a / "duplicate-mpo.jpg"
        partial_mpo = source_a / "partial-private-mpo.jpg"
        make_mpo(first_mpo, (180, 30, 20), (20, 180, 30))
        make_mpo(second_mpo, (30, 30, 210), (210, 180, 30))
        make_mpo(partial_mpo, (90, 80, 70), (70, 80, 90))
        partial_mpo.write_bytes(partial_mpo.read_bytes()[:-100])
        duplicate_mpo.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(first_mpo, duplicate_mpo)
        before_a = snapshot(source_a)
        before_b = snapshot(source_b)

        inventory_result = run(
            INVENTORY_TOOL,
            "inventory",
            "--source", f"Private Source A={source_a}",
            "--source", f"Private Source B={source_b}",
            "--output", str(output),
        )
        if "files=5 images=5" not in inventory_result.stdout:
            raise AssertionError("unimported_audit_inventory_fixture")
        inventory = output / "inventory" / "real-data-inventory.json"
        prepare_result = run(
            FULL_TOOL,
            "prepare",
            "--inventory", str(inventory),
            "--output", str(output),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B",
        )
        if "PRIVATE_FULL_LIBRARY_MANIFEST=PASS images=1 unsupported=4 videos=0" not in prepare_result.stdout:
            raise AssertionError("unimported_audit_manifest_fixture")
        manifest = output / "manifests" / "full-real-import-manifest.json"
        report = output / "reports" / "unimported-images.json"
        audit_result = run(
            AUDIT_TOOL,
            "--inventory", str(inventory),
            "--runtime-manifest", str(manifest),
            "--output", str(report),
        )
        expected = "PRIVATE_REAL_UNIMPORTED_AUDIT=PASS discovered=5 imported=1 missing=4 safe=4 unique_safe=3 deferred=0 source_integrity=PASS"
        if expected not in audit_result.stdout:
            raise AssertionError("unimported_audit_gate_missing")
        payload = json.loads(report.read_text(encoding="utf-8"))
        summary = payload.get("summary", {})
        if payload.get("kind") != "class_archive_private_unimported_image_audit":
            raise AssertionError("unimported_audit_report_kind")
        if summary.get("reason_counts") != {"mpo_multi_picture": 3, "mpo_secondary_frame_decode_failure": 1}:
            raise AssertionError("unimported_audit_reason_counts")
        if summary.get("unique_unimported_source_hashes") != 3 \
                or summary.get("exact_duplicate_records_within_unimported") != 1:
            raise AssertionError("unimported_audit_exact_duplicate_counts")
        if summary.get("source_integrity_checked") != 4 or summary.get("source_integrity_result") != "PASS":
            raise AssertionError("unimported_audit_source_integrity")
        if len(payload.get("items", [])) != 4 or any(
            item.get("disposition") != "SAFE_SUPPLEMENTAL_IMPORT_WITH_JPEG_SURROGATE"
            or item.get("decoder_probe", {}).get("frame_count") != 2
            or item.get("decoder_probe", {}).get("surrogate_format") != "JPEG"
            or not item.get("decoder_probe", {}).get("surrogate_sha256")
            for item in payload.get("items", [])
        ):
            raise AssertionError("unimported_audit_mpo_probe")
        partial = [item for item in payload["items"] if item["reason_category"] == "mpo_secondary_frame_decode_failure"]
        if len(partial) != 1 or partial[0]["decoder_probe"].get("secondary_frame_failures") != 1:
            raise AssertionError("unimported_audit_partial_mpo_primary_recovery")
        if any("source_root" in item or "absolute" in item for item in payload.get("items", [])):
            raise AssertionError("unimported_audit_absolute_path_disclosure")
        if snapshot(source_a) != before_a or snapshot(source_b) != before_b:
            raise AssertionError("unimported_audit_source_mutated")

        overlap = source_a / "unimported-images.json"
        overlap_result = run(
            AUDIT_TOOL,
            "--inventory", str(inventory),
            "--runtime-manifest", str(manifest),
            "--output", str(overlap),
            expected=2,
        )
        if "reason=report_source_overlap" not in overlap_result.stderr or overlap.exists():
            raise AssertionError("unimported_audit_output_overlap_gate")

        inventory_payload = json.loads(inventory.read_text(encoding="utf-8"))
        target = next(record for record in inventory_payload["records"] if record.get("format") == "mpo")
        target["sha256"] = "0" * 64
        tampered = output / "inventory" / "tampered.json"
        tampered.write_text(json.dumps(inventory_payload), encoding="utf-8")
        tamper_report = output / "reports" / "tampered" / "unimported-images.json"
        tamper_result = run(
            AUDIT_TOOL,
            "--inventory", str(tampered),
            "--runtime-manifest", str(manifest),
            "--output", str(tamper_report),
            expected=2,
        )
        if "reason=source_integrity_changed_before_probe" not in tamper_result.stderr or tamper_report.exists():
            raise AssertionError("unimported_audit_source_integrity_fail_closed")

    print("PRIVATE_REAL_UNIMPORTED_AUDIT_PROTOCOL=PASS assertions=18")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
