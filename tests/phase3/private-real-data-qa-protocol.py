#!/usr/bin/env python3
"""Synthetic-only protocol tests for the private real-data QA tooling."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "infra" / "scripts" / "private-real-data-qa.py"


def run(*arguments: str, expected: int = 0) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        [sys.executable, str(TOOL), *arguments],
        cwd=ROOT,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="strict",
        env={**os.environ, "PYTHONUTF8": "1", "PYTHONDONTWRITEBYTECODE": "1"},
        check=False,
    )
    if result.returncode != expected:
        raise AssertionError(f"private_qa_protocol_exit expected={expected} actual={result.returncode}")
    if any(marker in (result.stdout + result.stderr) for marker in ["fixture-a", "fixture-b", "portrait-secret"]):
        raise AssertionError("private_qa_protocol_sensitive_name_echo")
    return result


def snapshot(root: Path) -> dict[str, tuple[int, int, bytes]]:
    return {
        path.relative_to(root).as_posix(): (path.stat().st_size, path.stat().st_mtime_ns, path.read_bytes())
        for path in sorted(root.rglob("*"))
        if path.is_file()
    }


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="class-archive-private-protocol-") as temporary:
        base = Path(temporary)
        source_a = base / "fixture-a"
        source_b = base / "fixture-b"
        private_output = base / "private-output"
        source_a.mkdir()
        source_b.mkdir()
        for index in range(5):
            target = (source_a if index % 2 == 0 else source_b) / f"portrait-secret-{index}.png"
            image = Image.new("RGB", (80 + index * 7, 64 + index * 3), (30 * index, 60, 170 - 20 * index))
            image.save(target, format="PNG")
        before_a = snapshot(source_a)
        before_b = snapshot(source_b)

        inventory_result = run(
            "inventory",
            "--source", f"Private Source A={source_a}",
            "--source", f"Private Source B={source_b}",
            "--output", str(private_output),
        )
        if "PRIVATE_QA_INVENTORY=PASS" not in inventory_result.stdout:
            raise AssertionError("private_qa_inventory_gate_missing")
        inventory = private_output / "inventory" / "real-data-inventory.json"
        select_result = run("select", "--inventory", str(inventory), "--output", str(private_output), "--target", "5")
        if "PRIVATE_QA_SELECTION=PASS" not in select_result.stdout:
            raise AssertionError("private_qa_selection_gate_missing")
        manifest = private_output / "selection" / "private-selection-manifest.json"
        copy_result = run("copy", "--manifest", str(manifest), "--output", str(private_output))
        if "PRIVATE_QA_COPY=PASS" not in copy_result.stdout:
            raise AssertionError("private_qa_copy_gate_missing")
        verify_result = run(
            "verify", "--inventory", str(inventory), "--manifest", str(manifest), "--hash-mode", "full",
        )
        if "PRIVATE_QA_INTEGRITY=PASS" not in verify_result.stdout:
            raise AssertionError("private_qa_integrity_gate_missing")

        manifest_payload = json.loads(manifest.read_text(encoding="utf-8"))
        if len(manifest_payload["items"]) != 5:
            raise AssertionError("private_qa_manifest_count")
        if any(Path(item["staging_name"]).stem.startswith("portrait") for item in manifest_payload["items"]):
            raise AssertionError("private_qa_staging_name_disclosure")
        if any(item["source_sha256"] != item["staging_sha256"] for item in manifest_payload["items"]):
            raise AssertionError("private_qa_copy_hash")
        if snapshot(source_a) != before_a or snapshot(source_b) != before_b:
            raise AssertionError("private_qa_source_mutated")

        # Output below an input root must be rejected before any directory is
        # created.  This also proves the generic tool cannot accidentally put
        # thumbnails or sidecars beside originals.
        overlap = source_a / "private-output"
        failure = run(
            "inventory", "--source", f"Private Source A={source_a}", "--output", str(overlap), expected=2,
        )
        if "reason=output_source_overlap" not in failure.stderr or overlap.exists():
            raise AssertionError("private_qa_output_overlap_gate")

    print("PRIVATE_REAL_DATA_QA_PROTOCOL=PASS assertions=13")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
