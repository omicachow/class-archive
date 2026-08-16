# Piwigo-first research and verified runtime

Research refreshed: 2026-08-16 (Asia/Shanghai)

This document records the photo-first spike that supersedes the original
HumHub-first product direction. Historical HumHub evidence remains under
`docs/evaluations/humhub/`; it is not rewritten or discarded here.

## Current facts from upstream

- **Piwigo 16.4.0 is the current stable Core selected for this repository.** It
  was released on 2026-05-03 and includes security fixes. The official stable
  changelog lists 16.4.0 as the newest stable release; no beta or upcoming Core
  is used. Sources: [16.4.0 release note](https://piwigo.org/release-16.4.0),
  [stable changelog](https://piwigo.org/changelogs).
- Piwigo Core is licensed under **GPL-2.0-or-later**. The project is a PHP photo
  gallery with albums, metadata, derivative generation, comments, ratings,
  favorites, groups, private-album access and a Web API. Source:
  [official repository](https://github.com/Piwigo/Piwigo).
- Current official requirements are a web server such as Nginx or Apache,
  PHP 8.2+, MySQL 5.6+ or MariaDB 10.1+, and GD or ImageMagick. ImageMagick is
  recommended. Multiple generated image sizes consume additional storage.
  Source: [Piwigo requirements](https://piwigo.org/guides/install/requirements).
- The official Docker project documents a loopback-prefixed port binding and
  persistent application/database data. The repository uses that upstream
  image but supplies its own localhost-only Compose definition and explicit
  named volumes. Source: [official Docker repository](https://github.com/Piwigo/piwigo-docker).
- Piwigo's `image_category` relation is many-to-many. One image record and one
  original file can therefore appear in several logical albums; the local
  runtime test confirms this behavior.
- Core supports photo comments, but not album comments or nested threads. Core
  Favorites is a private, flat per-user photo list. Core ratings are global
  stars, not an account-bound Like model with the required role matrix.

## Evaluated extensions and viewer

| Candidate | Upstream state | Local result | Phase 0 decision |
|---|---|---|---|
| [Community 16.f](https://piwigo.org/ext/index.php?eid=303) | Compatible with Piwigo 16; released 2026-04-21; lets contributors create albums/upload, optionally with moderation | Low-trust pending and high-trust direct-publish behavior were demonstrated in the isolated evaluation runtime. A tokenless moderation POST was also accepted, and an array-shaped category input reached an unsafe/fatal path. | Its archive is pinned and the plugin is installed, but **inactive**. It is not a supported upload path until its CSRF/input and class-policy gates pass. |
| [User Collections 16.a](https://piwigo.org/ext/index.php?eid=615) | Compatible with Piwigo 16; released 2026-01-15; named collections, optional public sharing and email | A Family user who knew a LIVING-only image id could obtain its derivative through the plugin despite album ACL. | **Quarantined, not installed, not active.** Core Favorites is only a narrower interim candidate and needs the same ACL/revocation tests; implement a guarded ClassCollections relation if no fixed upstream release passes. |
| Bootstrap Darkroom 16.d | Compatible with Piwigo 16; responsive Bootstrap 4 theme with PhotoSwipe | HTTP/HTML checks exercised a derivative-first album page, screen-sized viewer preview, adjacent prefetch and a PhotoSwipe trigger. No supported-browser layout/touch QA was completed. | Active only as the **engineering-spike theme**. It is not the final Class Archive UX. |
| Bundled PhotoSwipe 4.1.3 | MIT; shipped inside Bootstrap Darkroom | Its initialization markers and assets occur in the live photo response; full-screen navigation, swipe and zoom were not interactively verified in this run. | Reused only for the spike. Do not build a viewer from scratch. |
| PhotoSwipe 5.4.4 | MIT; latest stable v5 release. Responsive sources, touch/zoom, modular lazy loading and configurable adjacent preload | Not yet integrated into the product theme. PhotoSwipe v6 is still under development with no ETA. | Planned viewer for the final Class Archive Theme; pin exact v5 assets and hashes when integrated. Sources: [releases](https://github.com/dimsemenov/PhotoSwipe/releases), [v5 documentation](https://photoswipe.com/). |
| [Comments on Albums 14.a](https://piwigo.org/ext/index.php?eid=512) | GPL-2.0; declares compatibility with Piwigo 16/15/14, last functional release in 2024 | README describes the plugin as unmaintained, and an open Piwigo 16 regression remains. | Excluded from the supported lock. Re-evaluate only after a maintained release and runtime authorization tests. |
| [Reply To 12.a](https://piwigo.org/ext/index.php?eid=556) | GPL-2.0; declares compatibility through Piwigo 16, last release in 2022 | Adds `@name`-style text, not a true threaded reply model; maintenance is stale. | Excluded. A minimal photo/album comment-parent adapter is safer if replies remain a V1 acceptance requirement. |
| [Subscribe to Comments 14.a](https://piwigo.org/ext/index.php?eid=587) | GPL-2.0; compatible with Piwigo 16/15/14, last release in 2024 | Useful email subscription primitive, but SMTP and HERITAGE/LIVING recipient filtering are not configured or proven. | Optional later, after real SMTP is supplied and notification ACL tests pass. |
| [Like / Dislike 1.0](https://piwigo.org/ext/index.php?eid=1019); [Smileys & Votes 1.6](https://piwigo.org/ext/index.php?eid=1021) | Marketplace pages declare Piwigo 16 compatibility | Small, young extensions with unclear package licensing/upstream maturity; their cookie/hash behavior does not match the required account/role rules. | Excluded. If Like remains required, implement one thin account-bound relation and policy hook. |

Exact installed archive URLs, commits, hashes and licenses are recorded in
`infra/piwigo-extensions.lock.json` and summarized in
`docs/dependency-matrix.md`.

## Observed supported runtime

The supported Compose stack was inspected and tested on 2026-08-16:

- Piwigo Core 16.4.0, packaged as immutable image
  `piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84`.
- PHP 8.4.20, Nginx 1.28.3, ImageMagick 7.1.2-19 and the PHP GD module.
- MariaDB 11.8.8, immutable digest
  `sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf`.
- Only Piwigo is mapped to the host, at `127.0.0.1:8090`; MariaDB has no host
  port. Application, uploads, synchronized galleries, derivatives, database,
  scripts and backups use separate named volumes.
- Private, non-default groups `CLASSMATE`, `TEACHER`, `FAMILY` and `ANONYMOUS`
  exist. The HERITAGE and LIVING roots are private. Family has HERITAGE only;
  the other three fixture roles have both roots.
- Community 16.f is present but inactive. Bootstrap Darkroom 16.d is active.
  User Collections is absent.
- `ClassArchivePolicy` 0.1.0 and `ClassIdentity` 0.1.0 are active without a Core
  patch. Media authority resolves through explicit ClassIdentity Principals;
  the Piwigo role groups are an exact ACL projection, not an authorization
  fallback.
- The initial Piwigo webmaster is bound to an independent
  `SYSTEM_ACCOUNT/SYSTEM_ADMIN` Principal with no Identity, Seat or ordinary
  account binding. The business Admin Console exposes only Dashboard,
  Identities, Teachers, Invitations, Audit and System Health in this phase.
- Administrator plaintext is no longer a long-lived environment value. The
  ACL-restricted `.env.piwigo` stores database/derivation secrets and the
  configured username, while fresh bootstrap uses no-echo input (or a consumed
  one-time file restricted to its owner, SYSTEM and Administrators) and later
  rotation uses a guarded CLI Core-hash path. Live rotation returns
  `sessions=revoked`; the legacy env key count is zero and its ACL is restricted.
  Fresh empty-volume installation remains unrehearsed.
- PHP-FPM uses a `0007` umask and the post-Phase 1 media-permission regression
  passes in the final coordinated baseline. Explicit permissive `chmod` calls in
  future upload paths still require a normalization adapter and real regression
  before Community activation.
- MediaGuard now recognizes the pinned Community Pending states, and a
  reversible 75-real-GET focused test passes without activating Community or
  uploading an image. It proves Seat denial, SYSTEM_ADMIN review,
  malformed/duplicate fail-closed and restoration to 72 images, both focused
  and inside the complete Phase 1 aggregate.
- The database contains **72 deterministic synthetic PNGs** and no real people
  or class material. All 72 have unique original paths; **8** are associated
  with more than one logical album without another image row or original file.

### Automated checks observed

The results below are the final coordinated localhost baseline, not production
deployment approval.

`dev.ps1 test-access` passed:

```text
ACCESS_MATRIX_ASSERTIONS=PASS
GUEST_ALBUM_API_DENIED=PASS
FAMILY_HERITAGE_ONLY=PASS
CLASSMATE_TEACHER_ANONYMOUS_BOTH_ERAS=PASS
```

`dev.ps1 test-phase0` passed its photo-model and HTML/media behavior checks:

```text
PHOTO_MODEL_ASSERTIONS=PASS
IMAGES=72
ORIGINAL_FILES=72
MULTI_ALBUM_IMAGES=8
MEDIA_PERMISSIONS=PASS
PHOTO_UI_SMOKE=PASS
GUEST_PRIVATE=PASS
OPEN_REGISTRATION_DISABLED=PASS
REMEMBER_ME_DISABLED=PASS
THUMBNAIL_FIRST=PASS
PHOTOSWIPE_INTEGRATION_MARKERS=PASS
```

These checks prove that the guest entry is the sign-in surface, open
registration returns HTTP 403, grids use generated cover/thumbnail derivatives,
the signed-in viewer uses a screen-sized preview and explicit original-download
action, and the configured API/album enumeration enforces the tested role
matrix.

`dev.ps1 test-phase1` exits zero against the real locked runtime after
coordinated plugin publication:

| Gate | Verified result |
|---|---:|
| ClassIdentity HTTP | 87 probes |
| Workflow mutex | 12 checks |
| Maintenance protocol | 40 assertions |
| Enforcement context | 8 assertions |
| Anonymous pure policy | 12 assertions |
| Audit reason safety | 20 assertions |
| CapabilityGuard pure policy | 96 assertions |
| Rate limiter | 22 assertions |
| Schema semantics | 9 assertions |
| Synthetic bootstrap protocol | 13 assertions |
| SYSTEM_ADMIN credential protocol | 24 assertions |
| SYSTEM_ADMIN commit/output fault | 1 real fault scenario; no residual lease/session |
| Maintenance HTTP | 11 probes |
| Runtime surface | 45 probes / 352 assertions |
| Enforcement-fault HTTP | 116 assertions |
| CapabilityGuard HTTP | 43 assertions |
| Pending Community media HTTP | 75 probes |
| AnonymousPresenter HTTP | 211 assertions |

The subsequent Phase 0 regression again passed the 72 image / 72 original /
8 multi-album model, media permissions and the 290 + 16 + 38 HTTP probes. The
controlled database-outage state-transition variant remains an explicit
40-probe opt-in test and passes.

The supported backup helper was also exercised. A direct Compose run was
refused by its quiescence guard; `dev.ps1 backup` preserved the application's
prior run state and atomically published `database.sql.gz`,
`piwigo-data.tar.gz`, `uploads.tar.gz`, `galleries.tar.gz`, `COMPLETE` and
`SHA256SUMS`. All five recorded entries and gzip payloads verified. An injected
dump failure returned nonzero and published neither a completed nor partial
bundle. Host and backup-volume locks reject overlapping local runs. This proves
fail-closed local data-bundle creation, not restoration or
a complete encrypted off-device recovery set.

## Historical media finding and resolution

The architecture spike originally requested an already-known LIVING derivative
URL and a direct `/upload/...` original storage path without a session; both
returned **HTTP 200**. That historical failure proved album discovery ACL was
not a sufficient file-delivery boundary.

The supported runtime now routes every public original and derivative path
through `ClassArchivePolicy` MediaGuard. PHP recomputes the current actor, Era,
Core album/privacy ACL and original policy; nginx sends bytes only after an
authorized `X-Accel-Redirect`. The 290-request HTTP matrix, 38-request mutable
state/path-alias suite and the opt-in 40-request database-outage suite pass. The
specific known-URL blocker is resolved without a Core patch. Real photos/public/NAS
deployment remain prohibited until the remaining admin, upload, recovery and
deployment gates below are complete.

## Other unresolved risks

1. The ClassIdentity Phase 1 foundation exists and its current HTTP contract
   passes. Active Family-account release, account-level freeze, member password reset,
   standalone force logout, roster import/edit/retire and full Admin RBAC remain
   unimplemented.
2. Comments are fail-closed in the ordinary baseline: global public commenting
   is off and the era roots are not generally commentable. Family/Anonymous
   action guards and context-scoped anonymous presentation are implemented and
   tested through a controlled comment fixture; reports, replies, Likes and the
   production comment enablement policy remain pending.
3. Community remains inactive until the moderation CSRF/input findings and
   permission bootstrap are fixed without patching Core.
4. Piwigo Core and mature plugins include MyISAM tables. Custom identity tables
   must use InnoDB and idempotent provisioning/reconciliation instead of
   pretending account creation is one cross-table transaction.
5. User Collections cannot be enabled with its current ACL behavior. Core
   Favorites is only a narrower interim candidate until its add/read/revocation
   paths pass the same album/media authorization tests.
6. Bootstrap Darkroom and PhotoSwipe 4 are evidence, not the final UI. The final
   theme needs a time-grouped photo home and a pinned PhotoSwipe 5 integration,
   with mobile browser tests.
7. The backup command creates a guarded, checksum-verified local bundle, but
   off-device export and a complete restore drill are not automated yet.
8. Safe coexistence with an existing NAS photo library is not proven by this
   local spike. Never point Piwigo or a synchronization job at real NAS files
   until the read-only/source-of-truth policy and restore behavior are tested.
9. The repository now has a project `LICENSE` and `NOTICE`, but digest/hash
   locks do not guarantee that OCI images or extension ZIPs remain downloadable
   for 10-20 years. Legal review and an authorized offline artifact-retention
   plan remain release gates.
10. System Health deliberately remains `PRODUCTION BLOCKED`: it has no
    persisted digest-bound MediaGuard HTTP attestation, Admin MFA, configured
    cron, completed empty-volume restore drill, enabled safe Community upload,
    or complete audited business-mutation coverage. A fresh empty-volume
    bootstrap/credential rehearsal is also still required.

## Scope boundary

No HumHub/Piwigo hybrid, independent frontend, Redis, Elasticsearch, object
storage, CDN, AI/OCR/face recognition, SSO, public ingress or real deployment
data is part of this baseline. No Piwigo Core file is modified. Piwigo owns
media storage, derivative generation, metadata, albums, album/query ACL
primitives and basic comments; Class Archive plugins own only class identity
and the end-to-end class/media policy those primitives do not cover.
