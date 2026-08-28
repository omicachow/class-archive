#!/usr/bin/env python3
"""Synthetic-only protocol gate for MPO presentation-surrogate preparation."""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import importlib.util
from pathlib import Path

from PIL import Image, ImageCms


ROOT = Path(__file__).resolve().parents[2]
INVENTORY = ROOT / "infra" / "scripts" / "private-real-data-qa.py"
FULL = ROOT / "infra" / "scripts" / "private-real-full-library.py"
AUDIT = ROOT / "infra" / "scripts" / "private-real-unimported-audit.py"
SUPPLEMENTAL = ROOT / "infra" / "scripts" / "private-real-supplemental.py"
SENSITIVE = ["fixture-source-a", "fixture-source-b", "secret-mpo", "duplicate-mpo"]


def run(tool: Path, *arguments: str, expected: int = 0) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        [sys.executable, str(tool), *arguments], cwd=ROOT, capture_output=True, text=True,
        encoding="utf-8", errors="strict", check=False,
        env={**os.environ, "PYTHONUTF8": "1", "PYTHONDONTWRITEBYTECODE": "1"},
    )
    if result.returncode != expected:
        raise AssertionError(f"supplemental_exit expected={expected} actual={result.returncode} stdout={result.stdout} stderr={result.stderr}")
    if any(value in result.stdout + result.stderr for value in SENSITIVE):
        raise AssertionError("supplemental_sensitive_name_echo")
    return result


def make_mpo(path: Path, first: tuple[int, int, int], second: tuple[int, int, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    primary = Image.new("RGB", (96, 64), first)
    alternate = Image.new("RGB", (96, 64), second)
    primary.save(path, format="MPO", save_all=True, append_images=[alternate])


def snapshot(root: Path) -> dict[str, tuple[int, int, bytes]]:
    return {p.relative_to(root).as_posix(): (p.stat().st_size, p.stat().st_mtime_ns, p.read_bytes())
            for p in sorted(root.rglob("*")) if p.is_file()}


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="class-archive-supplemental-") as temporary:
        base = Path(temporary)
        source_a = base / "fixture-source-a"
        source_b = base / "fixture-source-b"
        output = base / "private-output"
        staging = output / "supplemental-staging"
        source_a.mkdir()
        source_b.mkdir()
        Image.new("RGB", (80, 60), (20, 80, 180)).save(source_a / "already-imported.png", format="PNG")
        first = source_b / "secret-mpo.jpg"
        second = source_b / "second-secret-mpo.jpeg"
        duplicate = source_a / "duplicate-mpo.jpg"
        partial = source_a / "partial-secret-mpo.jpg"
        make_mpo(first, (180, 30, 20), (20, 180, 30))
        make_mpo(second, (30, 30, 210), (210, 180, 30))
        make_mpo(partial, (90, 80, 70), (70, 80, 90))
        partial.write_bytes(partial.read_bytes()[:-100])
        shutil.copyfile(first, duplicate)
        before_a, before_b = snapshot(source_a), snapshot(source_b)

        run(INVENTORY, "inventory", "--source", f"Private Source A={source_a}",
            "--source", f"Private Source B={source_b}", "--output", str(output))
        inventory = output / "inventory" / "real-data-inventory.json"
        run(FULL, "prepare", "--inventory", str(inventory), "--output", str(output),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B")
        full_manifest = output / "manifests" / "full-real-import-manifest.json"
        audit = output / "reports" / "unimported-images.json"
        run(AUDIT, "--inventory", str(inventory), "--runtime-manifest", str(full_manifest), "--output", str(audit))
        manifest = output / "manifests" / "supplemental-import-manifest.json"
        prepared = run(SUPPLEMENTAL, "prepare", "--inventory", str(inventory), "--audit", str(audit),
            "--output", str(output), "--staging", str(staging),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B")
        if "PRIVATE_REAL_SUPPLEMENTAL_PREPARE=PASS sources=4 presentations=3" not in prepared.stdout:
            raise AssertionError("supplemental_prepare_counts")
        payload = json.loads(manifest.read_text(encoding="utf-8"))
        if payload.get("kind") != "class_archive_private_supplemental_library" or len(payload.get("items", [])) != 4:
            raise AssertionError("supplemental_manifest_shape")
        if payload.get("canonical_identity_basis") != "PRESENTATION_SHA256" \
                or any(item.get("canonical_identity_basis") != "PRESENTATION_SHA256" for item in payload["items"]):
            raise AssertionError("supplemental_canonical_basis_missing")
        if any(any(key in item for key in ("relative_source_path", "source_root", "original_filename"))
               for item in payload["items"]):
            raise AssertionError("supplemental_manifest_sensitive_fields")
        if len({item["presentation_sha256"] for item in payload["items"]}) != 3 \
                or len(list(staging.glob("frs-*.jpg"))) != 3:
            raise AssertionError("supplemental_exact_duplicate_collapse")
        if any(item.get("source_format") != "MPO" or item.get("presentation_format") != "JPEG"
               or item.get("transform_tool") != "PILLOW" or not item.get("transform_version")
               or len(item.get("transform_recipe_digest", "")) != 64 for item in payload["items"]):
            raise AssertionError("supplemental_transform_provenance")

        # Exact crash remnants are promoted; corrupt remnants are discarded
        # only at their deterministic .partial name. Neither case forces the
        # owner to delete a resumable staging tree manually.
        presentation = next(staging.glob("frs-*.jpg"))
        presentation_partial = presentation.with_name(presentation.name + ".partial")
        presentation.rename(presentation_partial)
        manifest_partial = manifest.with_name(manifest.name + ".partial")
        manifest.rename(manifest_partial)
        run(SUPPLEMENTAL, "prepare", "--inventory", str(inventory), "--audit", str(audit),
            "--output", str(output), "--staging", str(staging),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B")
        if not presentation.is_file() or presentation_partial.exists() or not manifest.is_file() or manifest_partial.exists():
            raise AssertionError("supplemental_exact_partial_not_recovered")
        presentation_partial.write_bytes(b"truncated")
        run(SUPPLEMENTAL, "prepare", "--inventory", str(inventory), "--audit", str(audit),
            "--output", str(output), "--staging", str(staging),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B")
        if presentation_partial.exists():
            raise AssertionError("supplemental_corrupt_partial_not_cleaned")

        # Every audited item must have been probed by this exact decoder, not
        # merely one member of a mixed-version set.
        drift_payload = json.loads(audit.read_text(encoding="utf-8"))
        drift_payload["items"][0]["decoder_probe"]["decoder_version"] = "0.0.synthetic-drift"
        drift_audit = base / "decoder-version-drift.json"
        drift_audit.write_text(json.dumps(drift_payload, ensure_ascii=False), encoding="utf-8")
        drift = run(SUPPLEMENTAL, "prepare", "--inventory", str(inventory), "--audit", str(drift_audit),
            "--output", str(base / "drift-output"), "--staging", str(base / "drift-staging"),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B", expected=2)
        if "reason=decoder_version_drift" not in drift.stderr:
            raise AssertionError("supplemental_mixed_decoder_version_allowed")

        # Validate both ends of ICC preservation: only RGB device profiles are
        # accepted, and the generated JPEG is checked again after round-trip.
        spec = importlib.util.spec_from_file_location("private_real_supplemental", SUPPLEMENTAL)
        if spec is None or spec.loader is None:
            raise AssertionError("supplemental_module_load_failed")
        supplemental_module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(supplemental_module)
        rgb_icc = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()
        lab_icc = ImageCms.ImageCmsProfile(ImageCms.createProfile("LAB")).tobytes()
        supplemental_module.validate_rgb_icc(rgb_icc, "source_icc_invalid")
        try:
            supplemental_module.validate_rgb_icc(lab_icc, "source_icc_invalid")
        except supplemental_module.SupplementalError:
            pass
        else:
            raise AssertionError("supplemental_non_rgb_icc_allowed")

        # A symlink/junction must be rejected before resolve() can turn it into
        # an apparently ordinary path. Skip only when the host cannot create a
        # symlink at all; the implementation is also asserted statically.
        source_link = base / "source-root-link"
        try:
            source_link.symlink_to(source_a, target_is_directory=True)
        except OSError:
            source_link = None
        if source_link is not None:
            linked_inventory = json.loads(inventory.read_text(encoding="utf-8"))
            for source in linked_inventory["source_roots"]:
                if source.get("source_label") == "Private Source A":
                    source["root"] = str(source_link)
            linked_inventory_path = base / "linked-inventory.json"
            linked_inventory_path.write_text(json.dumps(linked_inventory, ensure_ascii=False), encoding="utf-8")
            linked = run(SUPPLEMENTAL, "prepare", "--inventory", str(linked_inventory_path), "--audit", str(audit),
                "--output", str(base / "linked-output"), "--staging", str(base / "linked-staging"),
                "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
                "--collection-label", "PRIVATE_SOURCE_B=Source Collection B", expected=2)
            if "reason=private_path_untrusted" not in linked.stderr:
                raise AssertionError("supplemental_pre_resolve_symlink_allowed")
        parent_escape = run(SUPPLEMENTAL, "prepare", "--inventory", str(inventory), "--audit", str(audit),
            "--output", str(base / "escape" / ".." / "escaped-output"),
            "--staging", str(base / "escaped-staging"),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B", expected=2)
        if "reason=private_path_untrusted" not in parent_escape.stderr:
            raise AssertionError("supplemental_parent_escape_allowed")
        before_output = snapshot(output)
        run(SUPPLEMENTAL, "prepare", "--inventory", str(inventory), "--audit", str(audit),
            "--output", str(output), "--staging", str(staging),
            "--collection-label", "PRIVATE_SOURCE_A=Source Collection A",
            "--collection-label", "PRIVATE_SOURCE_B=Source Collection B")
        if snapshot(output) != before_output:
            raise AssertionError("supplemental_prepare_not_idempotent")
        verified = run(SUPPLEMENTAL, "verify", "--output", str(output), "--staging", str(staging))
        if "PRIVATE_REAL_SUPPLEMENTAL_VERIFY=PASS sources=4 presentations=3" not in verified.stdout:
            raise AssertionError("supplemental_verify_counts")
        if snapshot(source_a) != before_a or snapshot(source_b) != before_b:
            raise AssertionError("supplemental_source_mutated")
        (staging / "unexpected.jpg").write_bytes(b"not-an-image")
        failed = run(SUPPLEMENTAL, "verify", "--output", str(output), "--staging", str(staging), expected=2)
        if "reason=staging_file_set_invalid" not in failed.stderr:
            raise AssertionError("supplemental_unknown_staging_fail_closed")

    source = SUPPLEMENTAL.read_text(encoding="utf-8")
    if "assert_no_link_components(requested, allow_missing=False)" not in source \
            or "canonical_identity_basis" not in source:
        raise AssertionError("supplemental_static_safety_contract_missing")
    print("PRIVATE_REAL_SUPPLEMENTAL_PROTOCOL=PASS assertions=20")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
