# HumHub Core boundary

HumHub Core is deliberately **not** copied, modified, or forked in this repository.

The local stack runs the official, immutable `humhub/humhub:1.18.4` image selected in `docs/dependency-matrix.md`. Marketplace modules persist in the `class_archive_humhub_data` volume. Project-owned modules are loaded from the separate read-only `/workspace/modules` loader path.

This directory documents that boundary and is reserved for non-secret integration notes only. Never place runtime configuration, uploads, or a patched Core checkout here.
