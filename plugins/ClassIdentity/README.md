# ClassIdentity

`ClassIdentity` is the Piwigo plugin boundary for the Class Archive identity
model. It adds permanent roster Identities, materialized Seats, account-binding
history, authenticated principals, hashed credential-token records,
provisioning sagas, managed role/group projection and append-only audit events.

It does **not** store passwords, patch Piwigo Core, or make Piwigo group
membership an authorization source by itself. Piwigo remains responsible for
credential hashing, sessions, users and groups. All plugin-owned tables are
InnoDB/utf8mb4 and use foreign keys only to other ClassIdentity tables.

## Principal boundary

- `SEAT_ACCOUNT` has exactly one `account_id` and no `system_role`.
- `SYSTEM_ACCOUNT` has no account/Seat and has a system role. V1 authorizes only
  `SYSTEM_ADMIN`; `ARCHIVIST` and `MODERATOR` are reserved schema values, not
  implemented permissions.
- `piwigo_user_id` and `auth_epoch` live only on `principal`, preventing two
  competing sources of session authority.
- `SYSTEM_ADMIN` is never a Classmate, Teacher, Family or Anonymous Seat, even
  when the human operator also has a separate Classmate login.

## Migration behavior

Piwigo calls `maintain.class.php` on install, activation and update. Each entry
point invokes `ClassIdentity\Schema::migrate()`; the supplied old plugin version
is ignored. The schema's own numbered ledger records a binary SHA-256 checksum
for every migration. DDL is inspect/create/assert based so interrupted MariaDB
DDL can be retried safely. A changed checksum, missing constraint, non-InnoDB
engine or non-utf8mb4 table fails closed.

The native-mutation trigger integration is explicitly locked to **Piwigo
16.4.0**. Activation, install migration and runtime schema attestation reject a
different or unknown `PHPWG_VERSION`; a Core upgrade therefore requires a new
source-schema review and lifecycle regression first. The 18 `BEFORE` triggers
are plugin-owned MariaDB objects on `images`, `image_category` and `categories`.
They are a database extension point, not a modification of any Piwigo Core
source file.

Deactivation first marks all six presentation projections stale, rotates the
native source epoch, and then drops all 18 plugin-owned triggers. Uninstall
repeats the same cleanup idempotently while retaining ClassIdentity data.
Reactivation/reinstall restores and attests the exact trigger definitions, then
again rotates the epoch and invalidates all projections before migration may
complete. This prevents native writes made while inactive from reviving an old
ACTIVE projection. Retirement deliberately remains available after an
accidental unsupported Core upgrade so cleanup cannot be blocked by the version
guard. Data erasure still requires a separate, explicit, backed-up governance
procedure.

## Tables

The configured Piwigo table prefix is followed by:

- `class_identity_migration`
- `class_identity_identity`
- `class_identity_seat`
- `class_identity_account`
- `class_identity_principal`
- `class_identity_operation`
- `class_identity_token`
- `class_identity_audit_event`
- `class_identity_role_group`
- `class_identity_photo`

Non-Family Seat types have a generated singleton marker and a unique
`(identity_id, singleton_marker)` key. This prevents more than one Classmate,
Teacher or Anonymous Seat per Identity while allowing multiple Family Seats.
Token target checks require Claim/Family Invite tokens to target a Seat and
Password Reset tokens to target a principal, including a Seat-less system
administrator.

## Canonical photo mapping and Gateway contract

`class_identity_photo` assigns an opaque RFC 4122 ClassArchivePhoto UUID to a
Piwigo image, its verified media checksum/reference and a nullable future
Immich asset link. The UUID is the only planned public photo identity; Piwigo
image ids, Immich asset ids, checksums and storage references remain internal.
An accepted mapping is never silently rebound: source drift becomes `STALE`
and Gateway projection fails closed. Pending Family submissions have a private
`PENDING` mapping which is promoted only after the existing audited Piwigo
approval pipeline succeeds.

The `Gateway` source boundary separates `IdentityAdapter`, `PiwigoAdapter` and
`ImmichAdapter`. At the Phase 2 pre-runtime stage its `/api` definitions and
policy are contract-tested only; routes are deliberately not HTTP-bound until
an opaque dispatcher can delegate media delivery back to MediaGuard. See
`docs/class-archive-gateway-contract.md` for ACL and evidence-level details.

## Runtime APIs

`ClassIdentity\Repository::fromPiwigo()` sets the database connection to
`utf8mb4`, exposes prepared `fetchOne`, `fetchAll`, `execute`, nested InnoDB
`transaction` helpers, row-lock helpers and the canonical
`findAuthorizationContextByPiwigoUserId()` join. That join resolves state but
does not grant access: the Access layer must verify all applicable principal,
account, Seat, Identity, Core-status and managed-group conditions.

`ClassIdentity\Audit::append()` is the sole supported audit writer. It accepts
only known top-level and JSON fields, recursively rejects password/token/
session/key/hash material, bounds payload size and requires a reason for listed
high-risk actions. IP addresses are stored only as an environment-keyed HMAC.

## Member capability boundary

`CapabilityGuard` is a coarse deny boundary in front of Piwigo's own CSRF,
album, image, ownership and Community checks. It covers both
`ws_invoke_allowed` and the direct `picture.php`, `comments.php` and Community
HTML upload paths; an ALLOW never grants or elevates a Core permission.
FAMILY and ANONYMOUS also use a reviewed read-method allowlist, so activating a
new plugin does not silently add a writable WS surface. Its methods must be
classified before activation.

| Principal | Comment | Rate | Direct upload | Public album | Private favorite |
|---|---:|---:|---:|---:|---:|
| CLASSMATE | continue to Core | continue to Core | continue to Community/Core | continue to Community/Core | continue to Core |
| TEACHER | continue to Core | continue to Core | continue to Community/Core | continue to Community/Core | continue to Core |
| FAMILY | deny | deny | deny | deny | continue to Core |
| ANONYMOUS | deny until presenter gate | deny | deny | deny | deny |
| SYSTEM_ADMIN | continue to Core admin policy | continue | continue | continue | continue |

Family photo contribution must use the future audited Pending Submission
controller; assigning a Family user Community permissions must not enable the
direct uploader. Anonymous comment/reply is fail closed until both
`class_identity_anon_presenter_ready=true` and the
`class_identity_anonymous_presenter_ready` event return exactly `true`. This
two-part gate prevents a config-only change from publishing a stable Piwigo
username or user id before all HTML/API serializers are replaced.

## Anonymous privacy boundary

`AnonymousPresenter` derives a photo-context alias from a versioned environment
HMAC secret and the Seat's random 16-byte pseudonym subject. A Piwigo comment
belongs to one image even when that image is associated with several albums,
so V1 uses `PHOTO:<image_id>` as its canonical discussion context. The same
Anonymous Seat is stable inside that photo and unlinkable across photos.

The presenter uses supported Piwigo events and services only. It sanitizes the
request-scoped Anonymous username, rewrites Core `pwg.images.getInfo`, rebuilds
the already-materialized `comment_list.tpl` handle from a sanitized Smarty DTO,
denies Anonymous/SYSTEM_ADMIN profile pages, and removes hidden principals from
ordinary uploader search/user discovery. Piwigo's native SYSTEM_ADMIN comment
moderation API remains available, but its hidden-author filter/id fields are
removed so it cannot bypass audited deanonymization. Any lookup/serializer
uncertainty is a 503 or empty result, never the original identifying DTO.

`AnonymousResolutionService` is SYSTEM_ADMIN-only, requires a reason, and
appends `ANONYMOUS_RESOLVE` to the Audit Log before returning an Identity
mapping. The event contains target ids but never the displayed alias, Core
username, HMAC secret, password, token or session material.

No install or migration step bootstraps a real administrator or synthetic
class roster. Secure provisioning and fixtures are explicit higher-layer
operations so activation cannot silently elevate an existing Piwigo account.

## Invitation and saga governance

Family Invitation expiry and Admin revoke both release only the exact matching
`INVITED` Family Seat. The token becomes `EXPIRED` or `REVOKED`, while Seat
generation is retained until the next issue increments it. Admin reissue emits
the new raw code only in a CSP-restricted, `no-store` terminal POST response;
database and Audit retain lifecycle metadata and hashes only.

Dashboard and System Health fail closed on `FAILED_MANUAL`,
`COMPENSATION_REQUIRED`, and long-running provisioning operation/account/Seat
states. The Admin repair action is intentionally narrower than a general retry:
it is shown only when the saga durably proves its Core user was created before a
later failure and no Principal exists. The action revokes Core credentials and
groups, retains an unbound tombstone, compensates the InnoDB rows, releases the
Seat, and appends `MANUAL_COMPENSATION`. Ambiguous provenance stays blocked for
manual investigation.
