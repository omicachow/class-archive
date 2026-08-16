# Changelog

All notable project changes are documented here. Each implementation phase is
committed separately.

## Unreleased

### Phase 1 ClassIdentity and independent administrator control plane

- Added the tracked `ClassIdentity` 0.1.0 plugin without modifying Piwigo Core:
  four checksum-attested forward migrations create ten MariaDB InnoDB/utf8mb4
  tables for Identity, Seat, account history, Principal, tokens, provisioning,
  audit, role projection and rate limiting.
- Added explicit `SEAT_ACCOUNT` and `SYSTEM_ACCOUNT` principals. The bootstrap
  Piwigo webmaster is securely provisioned as an independent `SYSTEM_ADMIN`
  with no Identity, Seat or ordinary account binding; unmapped, ambiguous,
  frozen and incorrectly downgraded principals fail closed.
- Removed long-lived SYSTEM_ADMIN plaintext from `.env.piwigo` and the HTTP
  fixtures. Fresh install accepts a no-echo prompt or a one-time file restricted
  to its owner, SYSTEM and Administrators, and the guarded CLI rotation path
  hashes through Piwigo Core, revokes
  sessions/auth keys and audits only after ClassIdentity convergence. The
  final coordinated local regression passes; fresh empty-volume install remains
  an explicit unrehearsed gate.
- Added one-time hashed Classmate/Teacher Claims, Family Invitations,
  Classmate/Teacher account provisioning, configurable Family/Anonymous Seats,
  Identity freeze/unfreeze, credential/session revocation and bounded
  compensation for the one proven post-Core provisioning-failure shape. A
  `MANUAL_COMPENSATION_ATTEMPT` Audit event and durable `COMPENSATING` operation
  state are committed before any Core-side credential quarantine begins.
- Hardened Audit persistence itself: bounded Chinese business labels remain
  valid, while reason text and structured old/new values containing password-
  shaped or credential-like material are rejected before persistence.
- Added a minimum Class Archive Admin Console with Dashboard, Identities,
  Teachers, Invitations, Audit and System Health. Submissions, Anonymous
  governance, Archive and Spotlight remain explicit later work.
- Added server-side action guards for all Seat roles. Unknown/unclassified
  state-changing Web API methods fail closed even for Classmate/Teacher. Added
  context-scoped anonymous aliases, ordinary HTML/API redaction and audited
  SYSTEM_ADMIN resolution. Community remains inactive and User Collections
  remains excluded.
- Replaced the Phase 0 group-only media actor resolver with live ClassIdentity
  Principal/Identity/Seat/account resolution. Piwigo groups remain an exact
  projection and Core album-ACL input, never the source of Class Archive
  authority.
- Added an explicit Community Pending-media state check before Era/Core ACL:
  `moderation_pending` denies every Seat role and permits only SYSTEM_ADMIN
  review, while malformed/duplicate state denies everyone. Its reversible
  75-real-GET gate passes without activating Community or uploading a new
  image, including inside the complete Phase 1 aggregate.
- Added a fail-closed plugin publication protocol: a nonblocking host workflow
  mutex, durable maintenance marker, atomic source replacement, PHP-FPM
  restart/readiness, semantic schema/runtime verification and independently
  verified maintenance finalization.
- Restricted synthetic fixture creation to the explicit
  `identity-bootstrap-synthetic` maintenance/bootstrap window. It creates and
  binds only the exact allowlist with discarded random passwords; normal
  `class-plugins` never creates test accounts and later gates only rotate users
  that are already bound.
- Hardened the public runtime surface: private `_data` trees and install/
  upgrade/tools entry points are denied, PHP-FPM uses umask `0007`, and Phase 0
  media ownership/mode checks run again after Phase 1. Explicit upload `chmod`
  behavior remains a gate before Community uploads can be enabled.
- Added real Piwigo/MariaDB/HTTP gates: ClassIdentity HTTP 87 probes, workflow
  lock 12 checks, maintenance protocol 40 assertions, enforcement context 8,
  anonymous pure policy 12, audit reason 20, capability policy 96, rate limit
  22, schema semantics 9, synthetic bootstrap 13, SYSTEM_ADMIN credential 24,
  SYSTEM_ADMIN commit/output fault 1 real scenario,
  maintenance HTTP 11 probes, runtime surface 45 probes / 352 assertions,
  enforcement-fault HTTP 116 assertions, capability HTTP 43 assertions,
  Pending-media HTTP 75 probes and anonymous-presentation HTTP 211 assertions.
- The coordinated current-tree `test-phase1` and `test-phase0` runs both exit
  zero. Phase 0 preserves 72 images / 72 originals / 8 multi-album images and
  passes permissions, UI, access, MediaGuard 290, tiny-preview 16 and mutable
  state 38 probes; the opt-in database-outage state suite passes 40 probes.
- Added project `LICENSE` (`GPL-2.0-or-later`) and `NOTICE` with third-party
  ownership/license boundaries.
- Kept production blocked on Admin MFA, persisted digest-bound MediaGuard HTTP
  attestation, fresh empty-volume bootstrap, restore/cron operations, Community
  upload/moderation hardening, audited business mutation coverage, active
  Family-account release/member password reset, collections, NAS and public
  deployment.

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
- Kept production blocked at that Phase 0 snapshot on ClassIdentity, independent
  SYSTEM_ADMIN, moderation/collections safety, NAS and public-deployment gates;
  the Phase 1 section above records the identity/admin work completed since.
- Added MyISAM-consistent app-quiesced backup bundles, independent original,
  derivative, database and backup volumes, and configurable NAS UID/GID/volume
  names.

### Historical HumHub Phase 0

- Locked HumHub 1.18.4 and compatible Gallery, Report Content, Content
  Bookmarks, Share Content and TwoFA builds.
- Completed Gallery/Space/permission/reuse experiments and preserved their
  documentation and runtime helpers as evaluation evidence.
