# Media access policy

Status: Phase 0 media confidentiality, Phase 1 explicit-principal integration
and the Pending-moderation guard pass the final coordinated localhost synthetic
regression. Production gates remain open.

Applies to: Piwigo 16.4.0 / ClassArchivePolicy 0.1.0

## Security invariant

A source or derivative URL is an identifier, never an authorization token.
Every new HTTP request must resolve an authenticated Piwigo session, a single
Class Archive actor role, any Community moderation state, the image's effective
era, Piwigo's current private album/image ACL and the requested variant. Any
missing, ambiguous or failed input is a denial.

```text
HTTP request
  -> ClassArchivePolicy MediaGuard (PHP, current session + database)
  -> ALLOW only after role + moderation state + era + Core ACL + variant policy
  -> X-Accel-Redirect
  -> nginx internal file location (sendfile / Range / HEAD)
```

The public web server never serves `/upload`, `/galleries` or `/_data/i`
directly. Public `i.php` derivative requests and Core `action.php` original
links are dispatched to the same gateway. The internal source, derivative and
generation locations return 404 when requested by a client.

This is not signed-URL authorization. There is no bearer media token to copy.
Copying an otherwise valid URL to another browser/account causes a fresh policy
check for that browser/account.

## Verified Phase 0 evidence

The real Piwigo 16.4.0 + MariaDB runtime passed 290 HTTP probes across Guest,
Family, Classmate, Teacher, Anonymous and the independent SYSTEM_ADMIN actor; HERITAGE and
LIVING; thumbnail/preview/original variants; and `GET`, `HEAD` and Range
requests. The matrix also covered logout, same-browser account switching,
known backing paths, guessed ids/filenames, query tampering, URL encoding/path
normalization and private-cache revalidation. Allowed `GET` responses and
32-byte `206` Range responses are checked for real image bytes; denied paths
are checked for the absence of image magic; `HEAD` is checked for an empty
body. The result is not inferred from status codes alone.

The observed default result is:

- Guest: every HERITAGE/LIVING derivative and original denied;
- Family: HERITAGE preview allowed through Core ACL, HERITAGE original denied,
  and all LIVING variants denied;
- Classmate and Teacher: both eras allowed through Core ACL, with originals
  enabled by default;
- Anonymous: both-era previews allowed through Core ACL, originals denied by
  default;
- SYSTEM_ADMIN: both eras and all variants allowed;
- cross-Era image association: denied for every actor, including Admin;
- duplicate canonical original path: if two image rows reference the same
  source path, source, derivative, `action.php` (`part=e` and `part=r`) and
  format delivery all fail closed for every actor, including Admin;
- controlled database outage: generic HTTP 503, with no media bytes or
  diagnostic detail in the response;
- exact `/upload`, `/galleries` and `/_data/i` roots and direct internal
  locations: 404.

This evidence freezes the Piwigo-first decision for media feasibility. It does
not approve real data, NAS access or public exposure.

## Actor roles

The supported runtime resolves authority from one explicit ClassIdentity
Principal, never from a username, Core status or Piwigo group alone:

- `SEAT_ACCOUNT` requires one active Principal, account binding, Seat and
  Identity whose role shapes are mutually consistent. Its role is
  `CLASSMATE`, `TEACHER`, `FAMILY` or `ANONYMOUS`.
- `SYSTEM_ACCOUNT` requires an active Principal with `SYSTEM_ADMIN`, no account
  binding and therefore no Identity or Seat. Merely being a Piwigo
  `admin`/`webmaster` does not create this authority.
- Missing, duplicate, partially provisioned, frozen, released, disabled or
  shape-inconsistent state is `UNKNOWN` and denied.

The four managed Piwigo groups are an exact projection used by Core private-
album ACL and drift detection. They do not grant Class Archive authority by
themselves. This explicit-principal resolver has passed the post-Phase 1
MediaGuard regression; the historical Phase 0 group-only resolver is not a
supported fallback.

## Effective era

The effective era is calculated from every Piwigo `image_category` association:

1. Walk each associated album to its top-level root via `uppercats`.
2. Root permalink `class-archive-heritage` means `HERITAGE`.
3. Root permalink `class-archive-living` means `LIVING`.
4. Multiple albums under the same root retain one effective era and do not
   duplicate or broaden the media object.
5. An unmapped root, no album association, or malformed hierarchy is
   `UNKNOWN` and denied.
6. Associations under both era roots are `CONFLICT`. Every actor, including a
   system admin, is denied ordinary media delivery even if one association
   would otherwise be visible. The future repair surface may expose strictly
   controlled diagnostics, and System Health must mark the conflict
   `PRODUCTION BLOCKED` until resolved.

This prevents the unsafe rule “any accessible album makes the file public”. An
image may be in multiple official/community albums without copying its source,
but it may have only one effective era.

## Permission matrix

`original` settings are administrator-configurable. The locked V1 defaults are
shown below.

| Actor | HERITAGE derivative | HERITAGE original | LIVING derivative | LIVING original |
|---|---:|---:|---:|---:|
| Guest | deny | deny | deny | deny |
| Family | allow when Core ACL allows | deny by default | deny | deny |
| Classmate | allow when Core ACL allows | allow by default | allow when Core ACL allows | allow by default |
| Teacher | allow when Core ACL allows | allow by default | allow when Core ACL allows | allow by default |
| Anonymous | allow when Core ACL allows | deny by default | allow when Core ACL allows | deny by default |
| System admin | allow | allow | allow | allow |

The original switches are:

- `class_archive_family_original_download=false`
- `class_archive_classmate_original_download=true`
- `class_archive_teacher_original_download=true`
- `class_archive_anonymous_original_download=false`

Small originals are not silently delivered as previews. Piwigo normally emits
`action.php?id=N&part=e` when a derivative would have the source dimensions;
Class Archive treats that no-download action as `SAFE_PREVIEW`. Only an
explicit `download` query requests `ORIGINAL`. If Core returns its exact
`X-i: No change` redirect, nginx keeps it internal and a thin fallback uses
Piwigo `pwg_image` to re-encode the same dimensions, strip metadata and publish
an atomic `0660` derivative before nginx sends it. A 16-request synthetic HTTP
fixture verifies no external redirect, distinct preview/original hashes,
Family/Anonymous original denial, HEAD/Range, removal of the temporary database
row and physical original/derivative files, and cleanup back to 72 images.

## Piwigo ACL composition

Era authorization does not replace Piwigo private-album authorization. For a
non-admin actor, at least one same-era association must also pass Core's current
private-album permission calculation and image privacy level. These values are
recomputed from current Core tables for each media request rather than trusted
from the session cache.

There is currently no supported Family submission surface: Community remains
inactive. MediaGuard nevertheless now contains an explicit guard for the exact
pinned Community `community_pendings` state model so later activation cannot
rely on album privacy alone:

- no pending row or one exact `validated` row continues to the ordinary
  Principal/Era/Core ACL/variant policy;
- one exact `moderation_pending` row denies every Seat role and allows only an
  active independent SYSTEM_ADMIN to continue through the remaining media
  checks;
- malformed, unknown or duplicate rows deny every actor, including
  SYSTEM_ADMIN;
- an absent Community table is treated as ordinary Core-published media because
  the plugin is inactive/absent, not as an unresolved Pending record.

The reversible test creates the exact pinned pending table only when it is
absent, reuses one existing HERITAGE fixture image without uploading or adding
an image row, raises every tested user/image privacy level to prove state is the
deciding barrier, and exercises thumbnail/preview/original delivery. It then
restores passwords, levels, rows, table presence and the 72-image baseline.
Its 75 real HTTP GET probes pass under Windows PowerShell 5.1:
`COMMUNITY_INACTIVE`, `PENDING_SEAT_ROLES_DENY`,
`PENDING_SYSTEM_ADMIN_ALLOW`, `MALFORMED_STATE_FAIL_CLOSED`,
`DUPLICATE_STATE_FAIL_CLOSED` and `IMAGE_MODEL_RESTORED=72` are all verified.
Community upload/moderation itself remains inactive and is not accepted by this
guard alone. The same 75 probes pass inside the complete Phase 1 aggregate.

Logout and switching the same browser from a privileged actor to a less
privileged actor have been verified to re-run authorization and deny stale
known URLs. Managed role/album permission removal is also verified against an
already-authenticated session: MediaGuard recomputes current Core permissions
from database tables rather than trusting cached forbidden-category state.
Identity freeze and its Core session/key revocation have passed real page and
media-session tests. Releasing an already-active Family account and proving its
old session loses access immediately remain lifecycle production gates.

## HTTP and cache rules

- Both `GET` and `HEAD` execute MediaGuard.
- Authorized static transfer and byte ranges are performed by nginx, not a PHP
  `readfile()` loop.
- Member `GET`, `HEAD` and `Range` never generate a derivative. A missing,
  unsafe or stale presentation cache entry returns a generic HTTP 503 with no
  path/filename and no `X-Accel-Redirect`; the request cannot reach `i.php`.
  Approval/import first queues only a canonical ClassArchivePhoto UUID + Piwigo
  image mapping, then performs a bounded warm after the administrator/CLI write
  has committed. Failure retains that path-free marker for maintenance retry;
  it never moves image work into a Family/member read. Cached derivatives
  continue to use nginx sendfile/X-Accel delivery.
- Responses use `Cache-Control: private, no-cache, must-revalidate, max-age=0`,
  `Pragma: no-cache`, `Vary: Cookie`, `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: no-referrer`. A browser may retain bytes privately but must
  revalidate through MediaGuard before reuse, so an authorized `304` saves
  bandwidth without turning a stale cache entry into persistent access.
- Range, query strings, filename guesses, image-id guesses, path encoding and
  normalization never alter the actor/era decision.
- Before emitting `X-Accel-Redirect`, MediaGuard revalidates that the target is
  a private `0660` regular file with exactly one hard-link and a trusted media
  owner. Symlinks, hardlinks and ownership/mode drift return no internal URI;
  `GET`, `HEAD` and `Range` share this fail-closed check.
- Raw paths, cookies, passwords and authorization data are not written to the
  Class Archive audit log. Internal gateway failures log only a bounded error
  code and return no media bytes.
- PHP-FPM starts with umask `0007`, so runtime-generated private files default
  to group-readable/other-denied modes; Phase 0 rechecks the full media tree
  after Phase 1. This does not override a Core/plugin call that explicitly uses
  a permissive `chmod`. Upload post-write normalization to `0660` and a real
  Community upload regression are mandatory before Community activation.

## Fail-closed behavior

The gateway returns no `X-Accel-Redirect` unless every check succeeds.
Database failure, absent plugin files, unresolved actor, ambiguous source path,
unknown request kind, invalid query shape, absent image, absent era, mixed era,
duplicate canonical source binding, invalid derivative token and Core ACL
failure are all denials. An error status
may be 403, 404 or 503, but never includes media bytes.

Nginx configuration and plugin activation are both required runtime artifacts.
Having only the PHP plugin or only rewrite rules is not a passing state.

The outage and Phase 1 lifecycle tests confirm their respective fail-closed
boundaries. The Admin Console reports MediaGuard configuration and keeps
`PRODUCTION BLOCKED`, because a digest-bound, persisted record of the complete
HTTP matrix is not implemented; the live matrix passing is not itself a
production attestation.

## Deployment boundary

The current implementation is local Docker only. A NAS deployment must carry
the equivalent internal-location rules in its inner Piwigo nginx service;
putting a reverse proxy in front does not make a public media volume safe. Any
other application indexing the same master files is a separate access path and
must be validated independently before real photos are used.
