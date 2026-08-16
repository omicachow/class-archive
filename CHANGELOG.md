# Changelog

All notable project changes are documented here. Each implementation phase is
committed separately.

## Unreleased

### Photo-first architecture spike

- Preserved the completed HumHub 1.18.4/Gallery evaluation in Git and under
  `docs/evaluations/humhub/` before changing the supported platform.
- Installed and pinned Piwigo Core 16.4.0, MariaDB 11.8.8, Community 16.f and
  Bootstrap Darkroom 16.d by immutable image digest or archive SHA-256.
- Selected Piwigo-first after live HumHub/Piwigo comparison; HumHub is not part
  of the selected V1 runtime.
- Added an idempotent, localhost-only Piwigo bootstrap with private registration,
  group and HERITAGE/LIVING album baselines.
- Added 72 deterministic synthetic photos, multiple logical album associations
  with one image row/referenced physical original, thumbnail-first pages and PhotoSwipe
  integration-marker/preview smoke tests.
- Added real HTTP/API access tests for Guest, CLASSMATE, TEACHER, FAMILY and
  ANONYMOUS group visibility.
- Quarantined Community pending CSRF/category/default-permission guards and
  excluded User Collections after reproducing a cross-private-album ACL bypass.
- Closed the direct original/derivative media ACL blocker with
  `ClassArchivePolicy`: PHP re-authorizes every request and nginx transfers
  approved bytes through internal `X-Accel-Redirect` locations without a Core
  patch.
- Added 290 role/Era/variant/path/cache probes, 38 mutable ACL/cross-Era/path-
  alias probes, a 40-probe database-outage run and 16 same-size safe-preview
  probes. Small
  originals are re-encoded and stripped through Piwigo's image library instead
  of being exposed as implicit previews.
- Rejects every delivery variant when more than one Piwigo image row resolves
  to the same canonical original path, including cross-Era aliases and Admin
  requests.
- Kept production blocked on ClassIdentity, independent SYSTEM_ADMIN,
  moderation/collections safety, NAS and public-deployment gates.
- Added MyISAM-consistent app-quiesced backup bundles, independent original,
  derivative, database and backup volumes, and configurable NAS UID/GID/volume
  names.

### Historical HumHub Phase 0

- Locked HumHub 1.18.4 and compatible Gallery, Report Content, Content
  Bookmarks, Share Content and TwoFA builds.
- Completed Gallery/Space/permission/reuse experiments and preserved their
  documentation and runtime helpers as evaluation evidence.
