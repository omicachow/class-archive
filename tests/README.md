# Test strategy

Automated tests are added alongside each phase. Final acceptance uses the real
Piwigo application, MariaDB, installed extensions, generated derivatives and
browser-visible behavior. Mocks are not accepted as the final gate.

Phase 0 tracked gates cover private-by-default UI/API access,
HERITAGE/LIVING album isolation, media-tree filesystem permissions,
multi-album association without a second image/original path, extension
integrity, derivative-first pages and PhotoSwipe integration markers. Community
upload/approve/reject and the User Collections attack were demonstrated in an
isolated evaluation runtime and remain blocked. A sanitized mainline
Pending-media URL gate now passes its focused live run without activating
Community; upload/approve/reject remain blocked.

`probe-known-media-gap.ps1` is a fast regression for the exact bypass found by
the architecture spike. `media-guard-http.ps1` is the production-facing gate:
it exercises the role/Era/variant matrix and known-path, logout, account-switch,
HEAD, Range, query, encoding, normalization, guessing and cache boundaries with
290 real HTTP requests. That matrix passes on the final coordinated localhost
synthetic baseline. Allowed GET/Range bodies must contain image magic, denied bodies must
not, Range must be an exact 32-byte `206`, and HEAD must return no body. A
controlled database-outage probe also returned a generic 503 with no media
bytes or diagnostics.

`media-guard-tiny-preview.ps1` adds 16 HTTP probes around Piwigo's
same-as-source derivative branch. It proves a no-download action is a
metadata-stripped, separately hashed SAFE_PREVIEW, while adding `download`
remains an ORIGINAL request and Family/Anonymous cannot obtain those bytes.
Its synthetic database row, original and derivative are physically removed in
`finally`, and the image count returns to 72.

`media-guard-state-transitions.ps1` covers mutable authorization state such as
managed-group/album permission removal, temporary cross-Era association, and
Piwigo's non-unique `images.path`. Its default 38-probe run copies one image
row onto the same physical original under the other Era and proves raw source,
derivative, and both action ids through `part=e` and `part=r` fail closed for
Family, Classmate, and SYSTEM_ADMIN.
The row and association are removed exactly and the 72-image unique-path model
is rechecked in `finally`. The opt-in database-outage run adds two probes and
requires a generic 503. Phase 1 now separately proves Identity freeze and its
current page/media session revocation. Release of an already-active Family
account remains a production gate even though Core ACL revocation, logout and
account-switch revalidation pass.

The archived HumHub spike remains evidence, not a supported runtime target.

## Phase 1: ClassIdentity and SYSTEM_ADMIN

`phase1/class-identity-http.ps1` is the tracked ClassIdentity acceptance gate.
It provisions a random `CITEST` namespace, then drives the real Piwigo HTTP
surfaces for Admin Console, Claim, Teacher Claim, Family Invitation, Anonymous
activation, My, login and MediaGuard. Database reads are used only to assert
domain invariants; they are never accepted as proof that an HTTP actor was
authorized.

The last coordinated baseline exercised **87 real HTTP probes**. On the
2026-08-19 public-sync rerun, the same script returned `FAIL` with 87 probes
and one known `provisioning/stale-visible` health assertion: seeded
long-running operation/account/Seat counts were not surfaced as a production
blocker. Until that assertion is fixed and rerun, this suite is an active
release gate rather than a passing release claim.

HTTP tests never read a SYSTEM_ADMIN password. Their localhost-only helper
starts from one fresh unauthenticated Piwigo cookie and, through an explicit
per-exec CLI gate, upgrades exactly that session only after resolving an active
independent SYSTEM_ADMIN Principal. The cookie is delivered to the helper over
STDIN, never argv/stdout; the owner-only lease retains only a SHA-256 database
session locator. Normal `finally` cleanup revokes the exact session. A later
mint sweeps leases older than 7200 seconds; this is bounded crash recovery for a
serial localhost suite, not a proactive timer or production monitor. The caller
now requires `absent=false` for normal removal; only compensation after a failed
mint may accept safe `absent=true`,
and the 24-assertion credential protocol covers that distinction. A real
`after_db_commit_before_json` fault gate additionally proves that an ambiguous
native result retains its lease until the exact SYSTEM_ADMIN session is revoked.
This is
synthetic test plumbing, not a production authentication, impersonation or
provisioning path. Focused mint, real HTTP `getStatus` and exact revoke now pass
with no residual lease. Live password reset returns `sessions=revoked` and the
complete HTTP aggregate exits zero. The ignored env has zero legacy admin keys
and its ACL is restricted to the approved Windows principals. Fresh empty-volume
installation remains unrehearsed.

The current contract covers:

- Guest, Classmate, Teacher, Family and Anonymous direct Admin Console denial,
  plus SYSTEM_ADMIN access;
- missing-CSRF and cross-origin rejection on both admin and public mutations;
- SYSTEM_ADMIN as a `SYSTEM_ACCOUNT` with no Identity, Seat or Account;
- Claim reissue invalidating the old code, one-time Classmate/Teacher Claim,
  one Teacher Seat/account, Family Invitation acceptance and an independent
  Anonymous account;
- Family Invitation expiry/revoke releasing the same Seat, Admin reissue
  incrementing generation, old-token replay denial, and one-time no-store
  delivery of each replacement validator;
- database-seeded post-Core and stale provisioning incidents: System Health
  must show `PROVISIONING_INCIDENT`/`PRODUCTION BLOCKED`, only the proven
  post-Core failure exposes the real HTTP compensation action, a durable
  `MANUAL_COMPENSATION_ATTEMPT`/`COMPENSATING` checkpoint precedes Core
  quarantine, and ambiguous stale state remains non-repairable;
- unbound `normal` and unbound `admin` Piwigo login denial;
- Family denial when replaying known LIVING preview/original URLs;
- unknown/unclassified state-changing Web API methods fail closed for
  Classmate/Teacher as well as lower-trust Seat roles;
- Identity freeze invalidating an already-authenticated page/media session and
  blocking a new login;
- Anonymous My-page redaction of the underlying Identity graph;
- exact raw-token/password absence across all database text/blob/json columns,
  known PHP session/Piwigo log directories and container logs; and
- exact `finally` cleanup of test Identities, Seats, Accounts, Principals,
  Tokens, Operations, Audit rows and Core users, with the image count preserved.

Run only after `ClassIdentity` has been installed, migrated and securely
bootstrapped with synthetic fixtures:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase1\class-identity-http.ps1
```

Exit `0` means PASS, `1` means FAIL, and `3` means a required product surface
is explicitly PENDING. PENDING is never reported as passing. The script prints
its non-secret 12-hex run namespace at startup. Its outer `finally` normally
cleans even after an assertion failure. If the host process is forcibly killed,
use that printed id for the same namespace-bounded cleanup (replace `RUN_ID`):

```powershell
wsl.exe -d Ubuntu --cd $PWD -- docker compose --env-file .env.piwigo -f infra/docker-compose.yml exec -T --user nginx piwigo php /workspace/tests/phase1/class-identity-fixture.php cleanup RUN_ID
```

The helper rejects root, protected Core users, unexpected roster/user prefixes
and Piwigo versions other than the locked 16.4.0 runtime. Its lifecycle fault
injection is limited to the random run's own Family Seats/Tokens/Operations and
Core users. It never mutates an image, category or image-category association,
and cleanup verifies that the synthetic image count is unchanged when the
normal test runner supplies the baseline count.

`phase1/maintenance-gate-http.ps1` is the separate reversible gate for the
installation/bootstrap maintenance boundary. It first proves a normal page and
an authorized synthetic preview/original are healthy, then creates the exact
nginx marker used by the plugin installer. While the marker exists, the same
three HTTP URLs must return a generic 503 with neither an image MIME type nor
image bytes, while a direct external request to the maintenance error endpoint
must remain 404. Its outer `finally` removes only a marker paired with this run's
exact owner sidecar, verifies both files are absent, and proves the ordinary
page and both media variants recover without rebuilding containers or sessions.

Run this gate only while no plugin install/bootstrap/restart is in progress:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase1\maintenance-gate-http.ps1
```

The test refuses any pre-existing marker, symlink, unknown content or owner.
Cleanup never removes another installer/operator's maintenance state. A failed
ownership/content check deliberately leaves the site fail closed for explicit
operator recovery instead of guessing that deletion is safe.

`phase1/runtime-surface-http.ps1` guards the installed Piwigo/Nginx public
runtime surface with 45 raw localhost HTTP probes and 352 assertions. Raw TCP
request targets preserve percent encoding, duplicate slashes and dot segments
instead of allowing an HTTP client to normalize them before Nginx sees them.
The gate covers 18 private `_data` targets (logs, tmp, cache, compiled
templates, maintenance state, root canaries, encoded separators/names and
normalization through the public combined-assets prefix), 24 exact or variant
install/upgrade/tools targets, one healthy-runtime preflight, and one existing
CSS plus one existing JavaScript file below `_data/combined`. Every denial must
be 400/403/404 with no redirect, canary bytes/name/run id, image MIME,
filesystem/database/PHP diagnostic, or oversized body; installed exact entry
points must be 404. The two mature combined assets must remain non-empty HTTP
200 responses.

The root-only PHP CLI fixture accepts only an action and a 16-hex run id; it
derives every filename, marker and exact `_data` path internally. Setup uses
exclusive writes plus a strict manifest. Its outer `finally` deletes only
verified marker-bearing canaries, its exact manifest, and empty directories
created by that run, then calls the fixture status action again to prove no
residue. Symlinks, unknown manifest shapes/content and unexpected filesystem
types fail closed. It does not create or remove the real maintenance marker.
Run this gate only after the checked-in Nginx configuration has been loaded:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase1\runtime-surface-http.ps1
```

The aggregate Phase 1 gate runs deterministic maintenance/enforcement,
Windows workflow-lock, anonymous-presentation, audit-reason safety,
capability, rate-limit, exact schema-semantic, synthetic-bootstrap and
SYSTEM_ADMIN-credential protocol and commit/output fault checks, followed by
ClassIdentity HTTP, the reversible maintenance gate, the
runtime-surface gate, the enforcement-fault gate, CapabilityGuard HTTP and
the Pending-media HTTP gate, then AnonymousPresenter HTTP in fail-fast order.
The schema gate clones the locked
definitions under a random temporary prefix, proves the clean fingerprint, and
then proves that column, uniqueness/order, foreign-key and CHECK drift are all
rejected before its exact cleanup:

```powershell
.\infra\scripts\dev.ps1 test-phase1
```

Do not start plugin installation, bootstrap or container restart concurrently
with this aggregate command. The complete publish/bootstrap workflow now owns a
nonblocking OS file lock and fails before its first WSL/maintenance mutation if
another owner exists; the standalone maintenance test also refuses any existing
marker/owner state.

`phase1/class-plugin-workflow-lock.ps1` safely exercises the host-side mutex
without publishing plugins, preparing maintenance, or restarting a container.
One Windows process owns an exclusive kernel file handle in the ignored
`.codex-work/runtime` directory while a second real `dev.ps1 class-plugins`
process must fail immediately before its first WSL/maintenance call. The test
also proves that the first owner's synthetic marker is unchanged, forcibly
terminates that owner to exercise crash release, reacquires the lock, and
verifies that pre-existing lock-file bytes were neither truncated nor deleted.
`class-plugins`, `identity-bootstrap`, and `identity-bootstrap-synthetic` all
enter this same non-blocking gate for their complete prepare through finalize
workflow.

The previously recorded Windows PowerShell 5.1 aggregate exited zero with:

| Gate | Result |
|---|---:|
| ClassIdentity HTTP | 87 probes |
| Plugin-workflow mutex | 12 checks |
| Maintenance protocol | 40 assertions |
| Enforcement context | 8 assertions |
| Anonymous pure policy | 12 assertions |
| Audit reason safety | 20 assertions |
| CapabilityGuard pure policy | 96 assertions |
| RateLimiter | 22 assertions |
| Schema semantics | 9 assertions |
| Synthetic bootstrap protocol | 13 assertions |
| SYSTEM_ADMIN credential protocol | 24 assertions |
| SYSTEM_ADMIN commit/output fault | 1 real fault scenario; leases/session rows restored to 0 |
| Maintenance HTTP | 11 probes |
| Public runtime surface | 45 probes / 352 assertions |
| Enforcement-fault HTTP | 116 assertions |
| CapabilityGuard HTTP | 43 assertions |
| Pending Community media HTTP | 75 real GET probes |
| AnonymousPresenter HTTP | 211 assertions |

`phase1/pending-media-http.ps1` keeps Community inactive, creates the exact
pinned `community_pendings` table only if absent, and reuses one existing
HERITAGE image rather than exercising upload. With all four Seat users and the
image temporarily raised to privacy level 16, it proves no-row and `validated`
states continue through normal media policy, `moderation_pending` denies every
Seat role but permits SYSTEM_ADMIN review, and malformed/duplicate state denies
everyone. It checks thumbnail, preview and original bodies and restores
password hashes, user/image levels, rows, table presence and exactly 72 images.
The focused Windows PowerShell 5.1 run reports
`PENDING_MEDIA_HTTP=PASS`, `HTTP_PROBES=75`, Seat-role denial,
SYSTEM_ADMIN review, malformed/duplicate fail-closed and
`IMAGE_MODEL_RESTORED=72`. No test-session lease remains; the same gate passes
inside the complete aggregate.

`class-identity-synthetic-bootstrap-protocol.php` statically enforces that only
the explicit synthetic bootstrap mode may create the exact allowlisted fixture
users and that normal class-plugin publication cannot. The fixture passwords
are random, transient and discarded; later HTTP gates may only rotate accounts
already bound to explicit synthetic Principals.

`system-admin-credential-protocol.php` checks 24 assertions across the
no-long-lived-plaintext contract, bounded pre-ClassIdentity recovery stage,
independent-Principal reset boundary and exact revoked-versus-safe-absence
session-lease semantics. `system-admin-session-fault-http.ps1` injects the
single allowed fault after the database update but before JSON output and
requires both the owner-only lease and bound SYSTEM_ADMIN session row to return
to zero. Separate syntax/preflight verification currently passes
PowerShell 7 AST 18/18, Windows PowerShell 5.1 AST 18/18 and PHP lint 7/7.
Those static/protocol results are supplemented by live session, password-reset,
Pending-media and complete HTTP runs. They still do not constitute a fresh
empty-volume installation rehearsal or synthetic creation-failure injection.

After a future green aggregate, `test-phase0` must again pass the 72-image /
72-original / 8 multi-album model, media permission checks and 290 + 16 + 38
media HTTP probes. The PHP-FPM wrapper uses umask `0007`, so request-generated private
files remain other-denied. This does not neutralize an explicit permissive
`chmod`; Community upload remains inactive until a post-write `0660` policy and
real upload regression pass.
The opt-in database-outage state variant also passes 40 probes.

`phase1/enforcement-fault-http.ps1` is a separate real-HTTP fail-closed gate.
It temporarily writes the exact 12-hex run id to the test-only Piwigo config
parameter `ci_enforce_fault_owner`, then changes
`class_identity_enforcement=true` to `false`. The database-owned recovery
marker is created first and must be absent at baseline. Recovery requires the
exact owner, restores enforcement to `true` before deleting that owner, and
refuses missing, malformed or foreign ownership. This avoids relying on write
access to Piwigo's `_data` tree while retaining durable crash-recovery evidence.

`class-identity-anonymous-presenter.php` verifies the pure context-scoped HMAC
contract, including collision extension and malformed-input fail-closed paths.
`phase1/anonymous-presenter-http.ps1` is the real Piwigo/MariaDB release gate.
It scans picture/recent-comment HTML, `pwg.images.getInfo`, session status,
profile, user-list, uploader-search, and SYSTEM_ADMIN native comment-moderation
surfaces; then and only then enables the short persistent presenter gate, posts
a real Anonymous comment, verifies its redacted database author, and proves
SYSTEM_ADMIN resolution produced a redacted Audit event. Its synthetic comments
and temporary Core comment settings are restored in `finally`. Product Audit
remains append-only; the test-only fixture records its single random-run Audit
id and directly removes only a row whose reason, SYSTEM_ADMIN actor, PHOTO
context, Identity/Seat/Account/Principal targets, redacted payload and result
all match that run. Ambiguous or drifted rows are refused, and cleanup must
verify both the tracked id and random-run reason have zero residual rows before
the fixture state is removed.

```powershell
wsl.exe -d Ubuntu --cd $PWD -- docker compose --env-file .env.piwigo -f infra/docker-compose.yml exec -T --user nginx piwigo php /workspace/tests/class-identity-anonymous-presenter.php
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase1\anonymous-presenter-http.ps1
```

## Phase 1.5 browser acceptance

`phase1/browser-qa.ps1` uses a locally installed Chrome executable and
Playwright to drive real page navigation and form submission at
`127.0.0.1:8090`; it does not use a public network or a mock browser. It creates
a random, short-lived synthetic SYSTEM_ADMIN and exercises a real Classmate
Claim, Family invitation/registration/upload/review, Teacher Claim, Anonymous
comments in two contexts, explicit admin resolution, Chinese management pages,
and desktop/mobile layout checks. The test makes no use of the long-lived
administrator password. All temporary credentials live only in ignored
short-lived files and the outer cleanup restores the canonical `72/72/8`
media baseline.

```powershell
.\infra\scripts\dev.ps1 browser-qa
```

The latest successful browser run reported 234 assertions and 11 synthetic-only
screenshots. Full procedure, screenshots and scope limits are recorded in
[`docs/browser-qa-report.md`](../docs/browser-qa-report.md).

## Phase 2: Immich Web compatibility boundary

`phase2/immich-web-compat-http.ps1` is a localhost-only `RUNTIME_TESTED`
gate for the verified upstream Immich Web static build. It verifies the narrow
`127.0.0.1:8091 -> Piwigo nginx -> internal compatibility process -> internal
Gateway -> MediaGuard` topology, immutable official image digest, read-only
compatibility mounts, no compatibility host port, and the absence of an
Immich/Piwigo database or original-file mount. It then exercises real Piwigo
fixture sessions for Guest, Classmate, Teacher, Family, Anonymous and
SYSTEM_ADMIN. It proves canonical UUID DTO redaction, Family aggregate/search/
album/thumbnail LIVING exclusion, Family Heritage thumbnail allow and original
deny, Classmate LIVING preview allow, exact `HEAD`/`Range`, bounded request
input, Piwigo sign-in redirection, and the AGPL notice endpoint. It starts no
Immich browser account, asset, library, API key or duplicate original.

```powershell
.\infra\scripts\dev.ps1 test-phase2-immich-web-compat
```

The current focused run reports `HTTP_PROBES=34` and `ASSERTIONS=325`. This is
not a statement that Immich owns authorization or that browser UI has passed:
browser screenshots and interactions are independently labelled
`BROWSER_E2E_TESTED`. People and Memories deliberately return empty data until
a canonical aggregate adapter can demonstrate policy filtering instead of
inventing or leaking memberships.
