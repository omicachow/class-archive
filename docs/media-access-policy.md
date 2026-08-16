# Media access policy

Status: Phase 0 media-confidentiality gate passed on the localhost synthetic
stack; identity-lifecycle production gates remain open

Applies to: Piwigo 16.4.0 / ClassArchivePolicy 0.1.0

## Security invariant

A source or derivative URL is an identifier, never an authorization token.
Every new HTTP request must resolve an authenticated Piwigo session, a single
Class Archive actor role, the image's effective era, Piwigo's current private
album/image ACL and the requested variant. Any missing, ambiguous or failed
input is a denial.

```text
HTTP request
  -> ClassArchivePolicy MediaGuard (PHP, current session + database)
  -> ALLOW only after role + era + Core ACL + variant policy
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
Family, Classmate, Teacher, Anonymous and phase-0 Admin actors; HERITAGE and
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
- phase-0 Admin: both eras and all variants allowed;
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

During the Phase 0 spike, normal accounts resolve from exactly one managed
Piwigo group: `CLASSMATE`, `TEACHER`, `FAMILY` or `ANONYMOUS`. Zero or multiple
managed roles are `UNKNOWN` and denied. Piwigo `admin`/`webmaster` resolves to
`SYSTEM_ADMIN` for media delivery only.

ClassIdentity will replace the normal-account resolver with an active
Identity/Seat/Account binding and will require an explicit system-account
binding for the Class Archive Admin Console. A `SYSTEM_ADMIN` is never a Seat.
The transition must retain the same MediaGuard interface and fail closed while
a binding is incomplete, frozen or inconsistent.

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
from the session cache. Pending Family submissions therefore remain
inaccessible even when their planned era is HERITAGE.

Logout and switching the same browser from a privileged actor to a less
privileged actor have been verified to re-run authorization and deny stale
known URLs. Managed role/album permission removal is also verified against an
already-authenticated session: MediaGuard recomputes current Core permissions
from database tables rather than trusting cached forbidden-category state.
ClassIdentity freeze/release and explicit session revocation remain the next
lifecycle production gate.

## HTTP and cache rules

- Both `GET` and `HEAD` execute MediaGuard.
- Authorized static transfer and byte ranges are performed by nginx, not a PHP
  `readfile()` loop.
- First-time derivative generation may execute Piwigo's existing `i.php`
  pipeline only after authorization; cached derivatives use nginx sendfile.
- Responses use `Cache-Control: private, no-cache, must-revalidate, max-age=0`,
  `Pragma: no-cache`, `Vary: Cookie`, `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: no-referrer`. A browser may retain bytes privately but must
  revalidate through MediaGuard before reuse, so an authorized `304` saves
  bandwidth without turning a stale cache entry into persistent access.
- Range, query strings, filename guesses, image-id guesses, path encoding and
  normalization never alter the actor/era decision.
- Raw paths, cookies, passwords and authorization data are not written to the
  Class Archive audit log. Internal gateway failures log only a bounded error
  code and return no media bytes.

## Fail-closed behavior

The gateway returns no `X-Accel-Redirect` unless every check succeeds.
Database failure, absent plugin files, unresolved actor, ambiguous source path,
unknown request kind, invalid query shape, absent image, absent era, mixed era,
duplicate canonical source binding, invalid derivative token and Core ACL
failure are all denials. An error status
may be 403, 404 or 503, but never includes media bytes.

Nginx configuration and plugin activation are both required runtime artifacts.
Having only the PHP plugin or only rewrite rules is not a passing state.

The current outage test confirms the fail-closed response boundary, but it is
not a substitute for ClassIdentity freeze/session-revoke tests or an Admin
Console health indicator.

## Deployment boundary

The current implementation is local Docker only. A NAS deployment must carry
the equivalent internal-location rules in its inner Piwigo nginx service;
putting a reverse proxy in front does not make a public media volume safe. Any
other application indexing the same master files is a separate access path and
must be validated independently before real photos are used.
