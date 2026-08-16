# ClassIdentity design for Piwigo-first V1

Status: design only; no plugin code exists yet

Target runtime: locked Piwigo Core 16.4.0 + MariaDB 11.8.8
Design date: 2026-08-16 (Asia/Shanghai)

## Purpose and boundary

`ClassIdentity` adds only the class-specific identity lifecycle that Piwigo does
not model:

```text
Identity -> Seat -> ClassIdentity account binding -> Piwigo User Account
```

Piwigo continues to own usernames, password hashing, login, PHP sessions,
authentication keys and the user/group tables. ClassIdentity never stores a
password, implements a second login system, adds columns to a Piwigo table, or
patches Core. It uses plugin hooks, numbered migrations, Core functions and a
small version-gated adapter for any Core table operation without a public
function.

This design covers Phase 1 identity and governance. Media authorization,
HERITAGE/LIVING action policy, comments, collections and Spotlight remain
separate concerns: the deployable `ClassArchivePolicy` plugin owns its internal
MediaGuard/AnonymousPresenter/Collections/Interactions services, while
`ClassSpotlight` remains a separate plugin.

## Domain invariants

### Identity

- An Identity is a permanent class-roster subject, not a login.
- `CLASSMATE` has one formal `CLASSMATE` Seat, a configurable number of
  `FAMILY` Seats, and at most one `ANONYMOUS` Seat.
- `TEACHER` has exactly one `TEACHER` Seat and no Family or Anonymous Seats.
- The default Classmate template is five Seats: one Classmate, three Family and
  one Anonymous. These numbers are settings used to materialize Seat rows; no
  permission code relies on fixed ordinals or a fixed count.
- Changing the template affects new Identities only. Existing Identity rows
  retain their materialized Seats and recorded template version.
- The roster code (`C25-018`, `T-001`, or another administrator-defined format)
  is unique and stable. Prefixes and validation patterns are configuration, not
  constants in code.
- Freeze is reversible. Retirement/deletion is a deliberate governance action;
  it never silently removes authored photos or comments.

### Seat and account

- A Seat is an entitlement slot. It is not a Piwigo profile and is never shared
  as one login by several people.
- Each current Seat has zero or one current account binding. A Piwigo user id
  has at most one ClassIdentity account binding.
- Historical released bindings are retained for registration/audit history, but
  cannot authenticate through the ClassIdentity guard.
- A Family relationship belongs to the current Family account assignment, not
  permanently to the Seat. Allowed values are `FATHER`, `MOTHER`, `SIBLING`,
  `GUARDIAN`, `OTHER_FAMILY`.
- Classmate and Teacher real names come from the roster Identity. Family real
  names are collected on invitation acceptance. Anonymous accounts never
  expose either value to ordinary render/API paths.
- Only an active Classmate account may issue or revoke an invitation for an
  available Family Seat under the same Identity. Admin may revoke/release or
  replace a Seat with a reason.
- An Anonymous Seat may be activated only by its owning active Classmate or an
  authorized administrator. It creates an independent Piwigo user, credential
  and session.

### Lifecycle states

Use strings rather than database enums so migrations can add a state without a
table rebuild. Application validation and locked-runtime `CHECK` constraints
enforce the allowed set.

| Aggregate | States |
|---|---|
| Identity | `ACTIVE`, `FROZEN`, `RETIRED` |
| Seat | `AVAILABLE`, `INVITED`, `PROVISIONING`, `ACTIVE`, `FROZEN`, `DISABLED`, `RELEASED` |
| Account binding | `PREPARED`, `CORE_CREATED`, `ACTIVE`, `FROZEN`, `RELEASED`, `DELETED`, `COMPENSATION_REQUIRED` |
| Credential token | `ISSUED`, `RESERVED`, `CONSUMED`, `REVOKED`, `EXPIRED` |
| Provisioning operation | `PREPARED`, `CORE_USER_CREATED`, `CORE_GROUP_ASSIGNED`, `COMMITTED`, `RETRY_CREDENTIAL_REQUIRED`, `COMPENSATING`, `COMPENSATED`, `FAILED_MANUAL` |

An account is authorized only when Identity, Seat and account binding are all
active. A group membership alone never authorizes it.

## InnoDB schema

All tables use `ENGINE=InnoDB`, `utf8mb4` and UTC timestamps. Foreign keys exist
only among ClassIdentity tables. Piwigo user/group/auth-key tables include
MyISAM in the supported runtime, so `piwigo_user_id` is an explicitly reconciled
external reference, never a cross-engine foreign key.

The final physical prefix uses Piwigo's configured table prefix plus
`class_identity_`; names below omit the Piwigo prefix for readability.

### `class_identity_identity`

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Internal id; never exposed to ordinary clients |
| `roster_code` | `VARCHAR(64)` unique, immutable | Permanent Classmate/Teacher ID |
| `identity_type` | `VARCHAR(16)` | `CLASSMATE` or `TEACHER` |
| `real_name` | `VARCHAR(190)` | Internal class real name; admin and owner surfaces only |
| `state` | `VARCHAR(16)` indexed | Identity lifecycle |
| `seat_template_version` | `INT UNSIGNED` | Template applied when Seats were created |
| `lock_version` | `INT UNSIGNED` | Optimistic concurrency for admin edits |
| `created_at`, `updated_at`, `frozen_at`, `retired_at` | UTC `DATETIME(6)`, nullable where appropriate | Lifecycle history |

`roster_code` is not encoded into anonymous usernames or aliases. Delete is
normally a soft retirement; any later PII erasure is an explicit audited
pseudonymization migration that preserves referential history.

### `class_identity_seat`

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Seat id |
| `identity_id` | FK to Identity, `ON DELETE RESTRICT` | Permanent owner |
| `ordinal` | `SMALLINT UNSIGNED` | Display/order only, not authorization |
| `seat_type` | `VARCHAR(16)` | `CLASSMATE`, `TEACHER`, `FAMILY`, `ANONYMOUS` |
| `state` | `VARCHAR(24)` indexed | Seat lifecycle |
| `pseudonym_subject` | nullable `BINARY(16)` unique, random | Anonymous HMAC subject; never rendered; null on every non-Anonymous Seat |
| `invite_generation` | `INT UNSIGNED` | Invalidates every earlier invitation generation |
| `lock_version` | `INT UNSIGNED` | Concurrent claim/invite protection |
| lifecycle timestamps | UTC `DATETIME(6)` | Created/invited/activated/frozen/released times |

Unique key `(identity_id, ordinal)` prevents duplicate slots. A service-level
constraint validates the allowed role counts from the Identity's captured
template. `pseudonym_subject` is populated only for Anonymous Seats.

### `class_identity_account`

This table is an account **binding/history**, not a credential store.

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Binding id |
| `seat_id` | FK to Seat, `ON DELETE RESTRICT` | Seat assignment |
| `piwigo_user_id` | `BIGINT UNSIGNED` nullable, unique when present | Reconciled Core user reference |
| `requested_username` | `VARCHAR(100)` | Nonsecret idempotent reconciliation input |
| `real_name` | `VARCHAR(190)` nullable | Family assignment name; formal name remains on Identity |
| `family_relationship` | `VARCHAR(24)` nullable | Required only for Family |
| `state` | `VARCHAR(32)` indexed | Binding lifecycle |
| `current_marker` | nullable `TINYINT`, value `1` only for a current binding | Supports unique `(seat_id, current_marker)` while allowing many historical null rows |
| `auth_epoch` | `BIGINT UNSIGNED` | Optional defense-in-depth session generation; Core session/API-key deletion remains the primary revocation mechanism |
| `pseudonym_key_version` | `SMALLINT UNSIGNED` nullable | Stable anonymous HMAC key version |
| `provisioning_operation_id` | nullable unique FK to Operation, added after both tables exist | Saga correlation |
| lifecycle/reconciliation timestamps | UTC `DATETIME(6)` | Bound, frozen, released, last checked |

No email or password is duplicated here. Piwigo remains the source of truth for
login/email/password. The unique current-marker invariant is also checked under
`SELECT ... FOR UPDATE`; database uniqueness handles concurrent claims.

### `class_identity_token`

One table holds Classmate/Teacher Claim Codes, Family invitations and
administrator-issued password-reset links.

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Internal token record |
| `seat_id` | FK to Seat | Target Seat |
| `purpose` | `VARCHAR(24)` | `CLAIM`, `FAMILY_INVITE`, `PASSWORD_RESET` |
| `generation` | `INT UNSIGNED` | Reissue/revoke generation |
| `selector_hash` | `BINARY(32)` unique | SHA-256 of public random selector |
| `validator_hash` | `BINARY(32)` unique | HMAC-SHA-256 of secret, purpose, Seat and generation |
| `pepper_version` | `SMALLINT UNSIGNED` | Environment key-ring version |
| `state` | `VARCHAR(16)` indexed | Token lifecycle |
| `reserved_by_operation_id` | nullable FK to Operation | Makes retries single-owner |
| `issued_by_user_id` | nullable external Piwigo user id | Issuer audit link |
| `issued_at`, `expires_at`, `reserved_at`, `consumed_at`, `revoked_at` | UTC `DATETIME(6)` | Lifecycle |

Only hashes are stored. Claim codes should use at least 80 bits of generated
entropy; Family/reset validators use 256 random bits. Comparison is constant
time. Plain values exist only during generation/request handling and are
cleared from memory variables after use.

Invitation links use a nonsecret random selector in the path and the validator
in the URL fragment, for example:

```text
/class-identity/family-invite/<selector>#token=<validator>
```

The browser submits the validator in a CSRF-protected POST body. URL fragments
are not sent in HTTP requests, so reverse-proxy/access logs never receive the
secret. The response sets `Referrer-Policy: no-referrer` and
`Cache-Control: no-store`. A no-JavaScript fallback accepts a manually pasted
validator in a POST form. Neither middleware nor audit payloads log it.

### `class_identity_operation`

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Saga id |
| `operation_type` | `VARCHAR(32)` | Claim/invite/anonymous/release/reset action |
| `idempotency_hash` | `BINARY(32)` unique | HMAC of bounded request identity, never raw token |
| target ids | nullable FKs to Identity/Seat/Account | Aggregate being changed |
| `state` | `VARCHAR(32)` indexed | Compensation state machine |
| `core_user_id` | nullable external id | Record immediately after Core creation |
| `safe_payload` | `JSON` | Username/role/generation only; never password/token/session data |
| `attempt_count`, `next_attempt_at`, `lease_until` | retry fields | Idempotent worker lease/backoff |
| `last_error_code` | `VARCHAR(64)` nullable | Bounded code, not raw exception/secret |
| `created_at`, `updated_at`, `completed_at` | UTC `DATETIME(6)` | Operation history |

### `class_identity_audit_event`

Append-only through one Audit service; there is no ordinary update/delete UI.

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Ordered event id |
| `occurred_at` | UTC `DATETIME(6)` indexed | Event time |
| `request_id` | `BINARY(16)` indexed | Correlates HTTP/job actions |
| `actor_user_id` | nullable external Piwigo user id | Admin/user actor; null for system job |
| `actor_kind`, `action` | bounded `VARCHAR` | Event classification |
| target Identity/Seat/Account ids | nullable indexed FKs | Governance target |
| `old_value`, `new_value` | nullable redacted `JSON` | Necessary change summary only |
| `reason` | nullable `VARCHAR(500)` | Required for high-risk actions |
| `source_ip_hash` | nullable `BINARY(32)` | Default privacy-preserving HMAC of canonical IP |
| `result`, `error_code` | bounded `VARCHAR` | Success/failure without raw exception |

Audit JSON has a field allowlist. Passwords, Claim/Invite/reset validators,
session ids/cookies, HMAC secrets, API keys and raw authorization headers are
forbidden. Raw IP storage defaults off; a future explicit setting may enable it
only with retention and access controls.

The `JSON` columns above target the project's locked MariaDB 11.8 runtime. The
plugin does not claim compatibility with every database version accepted by
Piwigo Core; supporting an older server requires a tested `LONGTEXT` fallback
and explicit schema/validation migration first.

### `class_identity_throttle`

A small InnoDB bucket table implements local rate limits without Redis:
`action`, HMAC'd subject/IP bucket, window start, count and `blocked_until`, with
a unique key on `(action, bucket_hash, window_start)`. Expired rows are deleted
by an idempotent job. The HMAC key is environment-only and separate from Claim
and anonymous secrets.

## Migrations

- The plugin ships ordered, forward-only migration classes and records the
  applied schema version in a ClassIdentity migration table or Piwigo config.
- Install/upgrade uses Piwigo's plugin maintenance entry points. Runtime
  requests never opportunistically create or alter tables.
- Each migration is restart-safe: inspect-before-create/index, bounded data
  backfill, then advance the version. MariaDB DDL auto-commit is assumed.
- Every table and index is asserted after migration, including `ENGINE=InnoDB`,
  charset/collation, foreign keys and uniqueness constraints.
- A database/application/upload backup and restore drill is required before a
  production migration. Destructive down-migrations are not automatic.
- No migration alters `piwigo_users`, `piwigo_user_infos`,
  `piwigo_user_group`, `piwigo_user_auth_keys` or another Core/plugin table.

## MyISAM compensation state machine

Custom InnoDB changes and Piwigo Core user/group writes cannot form one atomic
transaction. Provisioning is therefore an idempotent saga, not a pretend
cross-engine transaction.

### Claim/invite happy path

1. Start an InnoDB transaction. Lock Identity, Seat and token rows with
   `SELECT ... FOR UPDATE`.
2. Validate Identity/Seat state, token hash/generation/expiry, rate limit and
   exactly-one-current-binding invariant.
3. Create a `PREPARED` account binding and operation; reserve the token to that
   operation; set Seat `PROVISIONING`; commit.
4. Call Piwigo `register_user()` with the submitted password in memory and
   `notify_admin=false`, `notify_user=false`; no registration notification may
   escape before the saga commits. Store the returned Core user id immediately
   and move to `CORE_USER_CREATED`.
5. Assign exactly the required managed business group through the Core adapter,
   invalidate Piwigo's user cache, verify the effective group, then move to
   `CORE_GROUP_ASSIGNED`.
6. In one InnoDB transaction mark binding/Seat `ACTIVE`, set
   `current_marker=1`, consume the token once and commit the operation.
7. Append a redacted audit event. The account may log in only after step 6.

### Failure and retry rules

- Failure before a Core user exists releases the reservation or moves to
  `RETRY_CREDENTIAL_REQUIRED`; the same token/request resumes the same
  operation. A new operation cannot take the reserved Seat.
- Passwords are never put in the operation payload. If a crash happened before
  Core creation, the user must submit the password again.
- If Core creation may have succeeded before its id was recorded, reconciliation
  searches the unique requested username, validates it is not bound elsewhere,
  records the id and continues. It never creates a second user blindly.
- Any noncommitted binding is denied by login/request hooks even if its Core
  user row exists. This makes partial accounts fail closed.
- Group assignment is set reconciliation over ClassIdentity-managed groups,
  not “append one row.” It preserves unrelated extension groups.
- Compensation first freezes the binding, increments `auth_epoch`, revokes Core
  auth keys and removes managed business groups. A Core user is deleted only by
  an explicit official-Core operation after proving it has no authored content;
  otherwise it remains a disabled tombstone so attribution is preserved.
- A released Family Seat receives a new invitation generation. Old tokens stay
  revoked/consumed and can never be resurrected.
- A lease-based background reconciler retries safe steps with backoff and scans
  for drift: missing Core user, wrong group, duplicate/current binding conflict,
  or operation stalled past its lease. Jobs are idempotent.
- `FAILED_MANUAL` is visible in the Admin Panel with a safe error code and
  remediation action; it never silently grants access.

## Core account and group integration

ClassIdentity resolves group ids by configured name, never hardcoded ids.

| Seat/account kind | Required Piwigo state | Managed group |
|---|---|---|
| Classmate | Core status `normal` | exactly `CLASSMATE` |
| Teacher | Core status `normal` | exactly `TEACHER` |
| Family | Core status `normal` | exactly `FAMILY` |
| Anonymous | Core status `normal`; internal opaque username | exactly `ANONYMOUS` |
| Super Admin | Core `admin`/`webmaster`; not claimed through a Seat | optional mirrored `ADMIN`, outside the exactly-one business-role set |

The four business groups and optional ADMIN mirror are non-default. A managed
normal account must have exactly one business group. Admin status is always
checked through Piwigo Core; membership in a string-named `ADMIN` group alone
never grants administration.

Registration calls the Core password hasher through `register_user()`. Password
reset uses a one-time hashed reset token and the Core password-update/hash
function; the administrator never sees the chosen password. Changing a password
also triggers session/auth-key revocation and an audit event.

Core already offers `generate_password_link()` and stores a hashed activation
key, but its link carries the secret in a query string and its reset lifecycle
does not provide the ClassIdentity saga/audit plus complete session and API-key
revocation contract. The custom fragment-to-POST wrapper is therefore a thin
policy adapter, not a replacement password hasher.

All nonadministrator Core users with `normal` or `generic` status are denied
unless they have exactly one active ClassIdentity binding. This closes the path
where a webmaster could create an otherwise valid user directly through
Piwigo. Core `admin`/`webmaster` accounts remain outside Seat claiming and are
handled by the administrator policy.

## Login, freeze and session revocation

Piwigo 16 exposes `finalize_login`, `user_login`, `user_init` and `user_logout`
hooks. The implementation must version-gate and integration-test these hooks:

- `finalize_login` rejects password login for a nonactive binding/Seat/Identity.
- `user_login` stores the binding id, current `auth_epoch` and issue time in a
  stateful PHP session. It is not assumed to run for a Header API Key login.
- `user_init` guards every established UI/API request, remember-me/auth-key path
  and partially provisioned or unbound normal/generic account. Header API Key
  authentication is stateless in Core and can bypass `user_login`, so it must
  validate the current binding directly and rely on Core API-key revocation,
  not merely the presence of a session epoch.
- A mismatch/frozen/unbound state calls Core logout for a stateful session and
  then immediately terminates dispatch with a generic 401/403 or sign-in
  redirect. The already constructed Core `$user` must never continue through
  the current request.
- Freeze or “revoke sessions” increments `auth_epoch` as defense in depth, calls
  Core `delete_user_sessions($userId)`, calls `deactivate_user_auth_keys()`, and
  enumerates Core API keys through `revoke_api_key()`. Every stateful session
  and stateless key is removed, then the guard rejects any stale request.

V1 sets Piwigo `authorize_remembering=false`. Core remember-me cookies are
derived from the password hash and cannot be individually revoked through a
stable public API; leaving them enabled would let an old cookie create a new
post-revocation session. Re-enabling Remember Me requires a tested per-account
revocation adapter and is outside the minimal design.

Identity freeze logically cascades through the request guard immediately, then
the reconciler increments/revokes every bound account. Account freeze affects
only that binding. Unfreeze never restores an old invitation or authentication
key.

### Core administration guard

ClassIdentity registers a `ws_invoke_allowed` guard around Piwigo's native user,
group, auth-key and API-key methods. Direct calls to at least
`pwg.users.add/delete/setInfo/setMyInfo`, `pwg.groups.addUser/deleteUser`,
`pwg.users.getAuthKey` and `pwg.users.api_key.*` fail for managed accounts unless
they originate from the plugin's audited internal operation. Native password or
business-group changes are redirected to ClassIdentity so session/key revoke,
saga state and Audit cannot be bypassed. `save_profile_from_post` is monitored
for allowed profile changes and audit/drift reconciliation; it is not treated
as an authorization substitute.

## Anonymous pseudonyms

The canonical context is the containing photo or album, not an individual reply,
so all comments in one discussion use one alias. Compute:

```text
HMAC-SHA-256(
  secret[key_version],
  "class-archive/anonymous/v1\0" || context_type || "\0" ||
  canonical_context_id || "\0" || seat.pseudonym_subject
)
```

Render a fixed 40-bit Base32 prefix (eight characters), such as
`匿名 K7M2P4QX`. Forty bits keeps collision risk negligible for this class-sized
system; a runtime uniqueness assertion extends the displayed prefix if a
collision is ever detected. The roster code, Core user id and database ids are
not inputs exposed to clients.

The HMAC key exists only in versioned environment secrets. Each Anonymous
binding records its key version so aliases remain stable for 10-20 years; old
key material remains in encrypted recovery storage. A compromised-key rotation
is an explicit audited migration that may intentionally change aliases.

ClassIdentity owns the HMAC derivation/key-version contract and audited
administrator resolution. The `ClassArchivePolicy` plugin's internal
AnonymousPresenter owns ordinary comment/photo/album DTO and template/API
rewriting: it replaces Core username, profile/avatar links and author ids before
output. Browser/API regression tests scan for the Core id, username, Seat id,
Identity id and roster code. Admin resolution maps the authenticated comment
author -> binding -> Seat -> Identity, requires admin permission plus a reason,
and writes an audit event. HMAC is not treated as encryption or a substitute
for output filtering.

## Settings

Nonsecret settings use namespaced Piwigo configuration; secrets remain in
`.env.piwigo`/production secrets and are never copied into the database.

| Setting | Default / rule |
|---|---|
| classmate Seat template | 1 Classmate + 3 Family + 1 Anonymous; versioned |
| Teacher Seat template | 1 Teacher only |
| Anonymous enabled | true; disabling prevents activation and login but retains history |
| Family invitation TTL | 7 days, administrator configurable |
| Claim Code expiry | configurable; single-use regardless of expiry |
| Claim/invite/reset entropy | minimums fixed by security policy, not lowerable in UI |
| roster-code validation | administrator-supplied safe regex/prefix settings |
| business group names | CLASSMATE, TEACHER, FAMILY, ANONYMOUS; resolved and verified |
| Remember Me | false in V1 |
| rate limits | per action + HMAC'd IP/subject windows |
| audit IP mode | HMAC by default; raw off; retention configurable |
| anonymous alias key version | current environment key version; old versions retained |

Class name, school and graduation metadata belong to broader Class Archive
settings, not this plugin's authorization logic.

## HTTP and Admin surfaces

Exact route registration follows Piwigo plugin routing; the semantic endpoints
are:

| Surface | Operations |
|---|---|
| Public Claim | Show/submit Classmate or Teacher ID + Claim Code; create independent username/email/password |
| Family invitation | Resolve selector; submit fragment/manual validator, real name, relationship and independent Core credentials |
| My Identity | Read own Identity/Seat status; issue/revoke an available Family invitation; activate/disable own Anonymous Seat; request own reset |
| Admin Identities | List/filter/import Classmate/Teacher roster; create/freeze/unfreeze/retire Identity |
| Admin Seats/Accounts | Freeze/unfreeze, release/replace Family, disable Anonymous, revoke sessions, issue password reset |
| Admin Claims/Invites | Batch generate hashed Claim Codes, reissue/revoke, inspect status without revealing secrets |
| Admin Audit/Reconcile | Search redacted events; inspect/retry compensation operations; resolve anonymous ownership with reason |
| Export | CSV/JSON Identity/Seat/account metadata only; never password/token/hash/session/secret fields |

Every mutation is POST, CSRF-protected, same-origin checked and authorized on
the server. Public errors do not reveal whether an Identity or Seat exists.
Claim/invite/login actions are rate-limited. Pages carrying selectors use
`no-store`/`no-referrer`; secrets never appear in query strings. Admin actions
use Core `admin`/`webmaster` authorization plus action-specific permission and
require a reason for freeze, release/replace, reset, anonymous resolution and
manual compensation.

No general REST API is enabled in Phase 1. A future API must use the same domain
services and sanitized DTOs; it cannot expose tables directly.

## Audit actions

At minimum record Identity import/create/edit/freeze/unfreeze/retire; Claim Code
issue/reissue/reserve/consume/revoke; Family invitation issue/revoke/accept;
Anonymous activation/disable/administrator resolution; account bind/freeze/
release/delete/reset; group reconciliation; session/auth-key revoke; saga retry/
compensation/manual failure; metadata export; and any future impersonation.

High-volume invalid public attempts go to aggregated throttle/security events,
not token-bearing audit rows. Audit output never contains a password, raw token,
token hash, session secret, API key or anonymous HMAC secret.

## Required automated tests

Final acceptance uses the real Piwigo 16.4.0/MariaDB stack and browser/HTTP
requests. Unit tests may exercise pure domain functions, but mocks are not the
final gate.

### Schema and migration

1. Fresh install and upgrade apply each numbered migration once.
2. Every ClassIdentity table is InnoDB/utf8mb4; Core tables are unmodified.
3. Concurrent transactions cannot create two current bindings for one Seat or
   bind one Piwigo user twice.
4. Seat-template changes affect new Identities only; default Classmate has
   exactly three Family Seats and Teacher has one Teacher Seat only.

### Claim, invite and token security

5. Classmate and Teacher Claim Codes succeed once, then remain consumed.
6. Two concurrent submissions of one Claim Code yield one Core user/account.
7. An expired, revoked, wrong-generation or wrong-Seat token fails generically.
8. Family invitation may be issued only by the matching active Classmate, is
   one-use/expiring and cannot exceed available Family Seats.
9. Database, application logs, access logs, audit JSON and responses contain no
   plaintext Claim/Invite/reset validator or password.
10. CSRF, enumeration and rate-limit tests cover every public mutation.

### Compensation and reconciliation

11. Inject failure before Core creation, after Core creation, after group
    assignment and before final commit; retry converges without duplicate user,
    binding, group or token consumption.
12. A partial account cannot log in or access a formal album.
13. Reconciler repairs managed-group drift but preserves unrelated groups.
14. Release preserves authored content attribution and denies the old login;
    replacement uses a new binding and invitation generation.

### Freeze, password and sessions

15. Freeze rejects a new password login and an already-authenticated session on
    its next request.
16. Session revoke increments epoch, revokes Core auth/API keys and leaves the
    password unchanged; Remember Me remains unavailable.
17. Admin reset reveals no password, consumes one reset token, stores only the
    Core hash and revokes prior sessions/keys.
18. Identity freeze blocks all its accounts; account freeze blocks only its
    binding; unfreeze does not resurrect old tokens.

### Groups and anonymity (cross-plugin contract)

19. Formal accounts have exactly their one managed business group; Family and
    Anonymous never acquire Classmate/Teacher group access through drift.
20. One Anonymous Seat/account maximum per Classmate; none for Teacher.
21. Same Anonymous Seat + context yields the same alias; a different context
    yields a different alias; collision extension is deterministic.
22. Through ClassArchivePolicy's AnonymousPresenter, ordinary HTML/API contains
    no anonymous Core username/id, Identity/Seat id, roster code or identifying
    profile/avatar link.
23. Admin resolution returns the correct Identity only to an authorized admin,
    requires a reason and writes a redacted audit event.
24. Anonymous account is absent from all ordinary user discovery surfaces and
    cannot upload/create album/Like through direct endpoints; comment/output
    behavior is a cross-plugin acceptance contract implemented by
    ClassArchivePolicy.

These tests cover the Phase 1 portions of acceptance criteria 2-6, 19, 26-27
and 31-32, plus concurrency and MyISAM failure modes that the product-level list
does not spell out.

## Non-goals and implementation gate

ClassIdentity does not implement media storage, photo ACL, comments, Likes,
Family submission review, named photo collections, Spotlight, Activity, SSO,
email delivery, a second user directory or the photo-first Theme. It does not
activate Community or User Collections.

Implementation may start only as an isolated Piwigo plugin under `plugins/`,
with migrations and tests. Any discovered need to edit Piwigo Core, add a
cross-engine foreign key, store plaintext credentials/tokens, or bypass the
compensation guard is an architecture blocker, not an invitation to weaken this
boundary.
