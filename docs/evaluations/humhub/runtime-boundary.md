# HumHub comparison runtime boundary

HumHub Core is deliberately **not** copied, modified, or forked in this repository.

The comparison stack runs the official, immutable `humhub/humhub:1.18.4` image selected in `docs/evaluations/humhub/dependency-matrix.md`. Marketplace modules persist in the `class_archive_humhub_data` volume. Project-owned evaluation modules are loaded from a separate read-only loader path.

This document preserves that boundary. HumHub is not a V1 runtime dependency after the photo-first architecture decision. Never place runtime configuration, uploads, or a patched Core checkout in Git.
