# Photo performance reuse audit

- Status: Phase 3.3A architecture and private localhost runtime acceptance complete
- Scope: Immich v3.1.0 (`8aa95c67470a02a8ddedf03c2e52963af33065ff`), Piwigo 16.4.0, the current Class Archive Gateway and owned Photo UI
- Decision date: 2026-08-24
- Implementation verification date: 2026-08-25

The reuse decision was recorded before implementation; this revision records
the resulting Phase 3.3A boundary. It separates presentation reuse from
authorization: ClassIdentity and ClassArchivePolicy remain the identity and
visibility truths, and MediaGuard remains the final media authorization point.
Private before/after measurements are retained only in the ignored local QA
workspace. This public document records the method and decisions without
publishing private-corpus telemetry.

## Measured baseline and acceptance

The same local Chromium build, viewport and private fixture were measured at
DPR 1, 1.5 and 2 before and after the change. The pre-change grid selected a
fixed low-resolution derivative regardless of its rendered size, while the
post-change grid selected from responsive persisted variants. The acceptance
run additionally covered cold and warm navigation, constrained network
profiles, conditional requests, logout, account switching, freeze/revoke and
BFCache restoration.

Therefore `UPSCALE_BLUR_ROOT_CAUSE=YES`: the normal high-resolution sources are
not the cause of the observed grid blur. Low-resolution transferred sources are
tracked separately as source-limited quality.

## Post-change evidence status

`PRIVATE_PHOTO_VALIDATION=PASS` and
`EVIDENCE=LOCAL_PRIVATE_UNPUBLISHED`. The ignored evidence set contains the
full timing distribution, transfer/request counts, derivative-size audit and
screenshots; none is copied into Git or public CI. The public synthetic suites
prove the same architecture, invalidation and authorization boundaries without
depending on that private corpus.

The acceptance found no undersized response for normal-resolution sources at
any tested DPR. Responsive variants were persisted before member reads,
conditional requests revalidated to zero-byte `304` responses after fresh
authorization, and warm navigation reused the immutable shell plus
session-scoped presentation metadata. Source-limited images remain explicitly
source-limited rather than being upscaled. System Health deliberately reports
runtime derivative hit/miss metrics as not collected instead of inferring a
ratio from maintenance warmup counts.

## Upstream findings

### Immich v3.1.0

The fixed upstream defaults are a 250 px WebP thumbnail and a 1440 px JPEG
preview at quality 80 (`server/src/config.ts:369-390`). Sharp uses
`withoutEnlargement`, and media derivatives plus ThumbHash are persisted by
background jobs (`server/src/repositories/media.repository.ts:175-233`,
`server/src/services/media.service.ts:312-408`). The web client progressively
selects ThumbHash, thumbnail and preview and preloads adjacent viewer images
(`web/src/lib/components/AdaptiveImage.svelte`,
`web/src/lib/components/asset-viewer/PreloadManager.svelte.ts`).

Immich protects media with its own AssetView/AssetDownload decision and emits a
private cache policy. Those endpoints cannot become Class Archive browser media
endpoints because Immich is not the Class Archive ACL truth. Its progressive
selection, preload and viewport ideas are reusable; its media URLs are not.

### Piwigo 16.4.0

Piwigo already supplies the required presentation pipeline:

- standard derivative profiles from 120 through 3000 px, with the runtime
  profiles in this installation verified as 144/240/432/576/792/1008/1224/1656;
- `DerivativeImage`, persisted `_data/i` cache, source/config mtime invalidation,
  and no source upscaling (`include/derivative.inc.php`);
- `i.php` generation, Last-Modified/conditional GET and persisted output;
- ImageMagick/Imagick/GD provider selection and quality controls;
- missing-derivative discovery through `pwg.getMissingDerivatives`.

Piwigo's existing administration batch is browser-driven: it discovers missing
URLs and asks a browser to fetch them. Class Archive can reuse the discovery and
generation mechanics, but must adapt them into an idempotent maintenance job so
ordinary member reads do not pay the resize cost. The Class Archive generator
continues to normalize generated files to the project policy (0660).

### Pre-change Class Archive path

The owned UI exposed only `thumbnail|preview`; all grid, person, search, album,
memory and Spotlight cards requested `thumbnail`. MediaGuard mapped that to
`IMG_THUMB` (144 px), while `preview` mapped to `IMG_XLARGE` (1224 x 918 by
default). The UI had no `srcset` or DPR-aware selection. A missing Piwigo
derivative was routed through the internal generator, so a first read could
resize.

The server also reconstructed the archive repeatedly:

- the only Gateway cache was one `GatewayService` object's request-scoped
  `visiblePhotoResolution`;
- every HTTP request created another service;
- `photoCandidates()` performed root lookups, a whole-gallery aggregate, a whole
  association scan and a whole source-binding scan;
- even a single media UUID was resolved by constructing and linearly scanning the
  full visible gallery;
- albums performed approximately album-by-photo work plus N+1 lookups;
- People called the live Immich bridge and could create/lock person mappings during
  a GET;
- the BFF first called `/api/me` and then the real endpoint, serializing both for
  the same session;
- the frontend forced `cache: no-store` for every JSON read.

This proves that the wait was not only image transfer latency. The pre-change
read path repeated database, policy-projection and AI aggregation work.

## Candidate decisions

| Candidate | Existing capability | Phase 3.3A use | Reusable? | Security implications | Maintenance cost | Decision |
|---|---|---|---|---|---|---|
| Immich Thumbnail | Persisted 250 px WebP, job generated, ThumbHash-aware | Not in the browser media path | UX concepts only | Direct use would move byte authorization outside MediaGuard | Two derivative truths if adopted | **REJECT** as Class Archive delivery |
| Immich Preview | Persisted 1440 px JPEG, job generated | AI/runtime internal only | UX concepts only | Browser endpoint uses Immich ACL, not ClassArchivePolicy | Duplicate presentation cache | **REJECT** as Class Archive delivery |
| Immich ThumbHash | Persisted compact placeholder | Compatibility payload still returns null | Yes, through canonical ACL-filtered metadata only | Must never carry an Immich asset URL/id or hidden record | Small adapter | **DEFER**; not required for Phase 3.3A |
| Piwigo Derivative Cache | Existing standard profiles, persisted `_data/i`, no upscale | Canonical `thumbnail`/`xsmall`/`small`/`medium`/`large`/`preview` routes plus maintenance warmup | Yes | Existing MediaGuard/X-Accel boundary remains intact | Low | **REUSE — IMPLEMENTED** as the one presentation derivative truth |
| Piwigo `i.php` | Mature generation and invalidation | CLI/import/approval/maintenance warmup only; member miss is 503 | Yes, outside member reads | No nginx/member generation route; output permissions are normalized | Low/medium | **ADAPT — IMPLEMENTED** as maintenance warmup |
| HTTP Browser Cache | Validators and 304 already available below the BFF | Protected media now revalidates; content-versioned Photo UI assets are immutable | Yes | Private media only; revalidate through fresh authorization; `Vary: Cookie` | Low | **ADAPT — IMPLEMENTED** |
| Workbox | Mature app-shell/runtime strategies | Not installed | App shell only, if needed later | Private photo CacheFirst could leak across role/account changes | Dependency and lifecycle cost is not justified now | **REJECT** for private media; native hashed shell cache first |
| Service Worker private media | Can retain response bodies | Not used | Not safely needed | Weakens logout/freeze/account-switch semantics and enables offline pixels | High security/test cost | **REJECT** |
| Valkey | Existing instance is Immich-only on `immich_internal` | Gateway/BFF deliberately cannot reach it | Not in its present boundary | Sharing unauthenticated Immich cache would break isolation | New network, ACL and client dependency | **REJECT** now; reconsider only after MariaDB projection benchmarks |
| Persistent DB Projection | Existing MariaDB business truth can feed deterministic read models | `read_photo` catalog plus six projection state rows and scoped aggregate payloads | Yes | Cache presentation data, never authorization; fresh principal check stays mandatory | Medium | **ADAPT — IMPLEMENTED** |
| TanStack Query | Mature server-state cache | Owned UI is dependency-free JS, not a Svelte query stack | Unnecessary for current scope | Principal scoping/purge would still need custom policy | New build/dependency stack | **REJECT** |
| IndexedDB | Durable browser data | Not used | Unnecessary for the current bounded metadata set | Long-lived private metadata is harder to revoke/purge | Medium/high | **REJECT** |
| `sessionStorage` metadata SWR | Browser-session scoped presentation cache | Five presentation endpoints use an opaque scope and a 12-hour in-tab maximum age while revalidating | Yes | Purge on auth failure/scope change; no sensitive identity/admin data | Low | **ADAPT — IMPLEMENTED** |
| imgproxy/thumbor/new image proxy | Mature resizing services | Not used | No gap remains after Piwigo profiles | New attack surface and second media path | High | **REJECT** |

## Selected architecture

### Images

`Piwigo persisted derivatives -> canonical ClassPhoto UUID route -> fresh
ClassIdentity/ClassArchivePolicy check -> MediaGuard -> X-Accel-Redirect`.

The canonical route exposes `thumbnail`, `xsmall`, `small`, `medium`, `large`
and `preview`, mapped server-side to existing Piwigo profiles. The primary photo
grid and viewer emit aspect-ratio-correct `srcset/sizes`; portrait, cover and
hero surfaces request explicit bounded variants. A content-derived opaque
revision versions URLs without exposing a Piwigo image id, Immich asset id,
storage path or raw checksum.

Import/approval and maintenance paths precompute the bounded variants through
Piwigo. Durable path-free queue markers survive a failed post-commit warmup for
maintenance retry. A member cache miss returns a generic 503 and cannot invoke
`i.php`; a warmed read remains a static internal nginx delivery, not a PHP/Node
byte relay or image resize.

### Metadata

MariaDB now persists a `read_photo` catalog and state/payload records for
`PHOTO_CATALOG`, `TIMELINE`, `ALBUMS`, `PEOPLE`, `MEMORIES` and `SPOTLIGHT`.
Every request still performs a current principal/freeze/session check before
selecting either the `FULL` or `HERITAGE` presentation scope. Unknown roles,
missing/stale projections, digest drift and source-epoch mismatch fail closed;
an HTTP GET never falls back to a live whole-library scan.

Two nine-trigger families cover native writes to Piwigo `images`,
`image_category` and `categories`: one marks dependent projections stale and
the other rotates the durable MyISAM source epoch. Business mutations and their
invalidation share a transaction. After commit, the write-side refresh reads
only changed catalog rows and rebuilds only declared dependent aggregates. Full
rebuild and dry-run remain explicit maintenance operations.

This native-table integration is locked to Piwigo 16.4.0 and is lifecycle
managed by the plugin. Install/activation refuses an unknown or different Core
version. Deactivation and uninstall first invalidate every presentation
projection and rotate the source epoch, then remove all 18 plugin-owned
triggers; reinstall restores and attests them before any projection can be
rebuilt. The trigger DDL is a compatibility-coupled MariaDB extension and must
be re-reviewed for a Core upgrade, but it changes no Piwigo Core source file.

The current Immich Valkey remains isolated and is not reused. MariaDB is the
restart-safe first layer; a separate authenticated Valkey hot layer is only a
future option if real benchmarks show it is needed.

### Browser

- HTML and authorization failures remain `private, no-store`.
- Static JS/CSS use content-derived versioned URLs, validators and immutable
  one-year caching.
- Protected media uses `private, no-cache, must-revalidate` plus validators and
  `max-age=0`, `no-transform` and `Vary: Cookie`; this permits storage but
  requires a fresh server authorization before reuse and can return `304` only
  after that authorization.
- Five presentation endpoints use session-scoped stale-while-revalidate with a
  12-hour maximum record age inside `sessionStorage`. The opaque scope binds
  role, presentation epoch, client address and session cookie. Cached payloads
  exclude admin/audit/deanonymization/identity secrets and are purged on scope
  change or any authentication failure.
- No Service Worker stores private media, and no offline private photo vault is
  introduced.

## Explicit maintenance boundary

Business backup restores Piwigo/ClassIdentity/archive truth. Projection DDL is
retained, but `read_projection` and `read_photo` cache rows are excluded from the
SQL snapshot and rebuilt after restore. Derivatives likewise remain rebuildable
cache rather than business truth. Browser cache is never backup input. Scoped
rebuild and dry-run tooling are mandatory; a rebuild failure blocks the affected
projection and never widens visibility or falls back to Immich's full library.

## Final reuse decision

`REUSE_PIWIGO_DERIVATIVES + ADAPT_RESPONSIVE_CANONICAL_VARIANTS +
ADAPT_PRIVATE_CONDITIONAL_CACHE + ADAPT_MARIADB_READ_PROJECTIONS +
REUSE_IMMICH_PROGRESSIVE_UX`.

No new image service, Redis instance, deep Immich fork or Piwigo Core patch is
required. Phase 3.3A implements the first four terms and reuses the bounded
Immich ideas of progressive selection and adjacent-viewer preload; it does not
adopt Immich media URLs, ThumbHash payloads or authorization.
