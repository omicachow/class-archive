# ClassIdentity design for Piwigo-first V1

Status: Phase 1 foundation, credential hardening and Pending-media integration
pass the final coordinated localhost regression; bounded lifecycle/Admin
Console and production gaps remain explicit

Target runtime: locked Piwigo Core 16.4.0 + MariaDB 11.8.8
Design date: 2026-08-16 (Asia/Shanghai)

## Purpose and boundary

`ClassIdentity` adds only the class-specific identity lifecycle that Piwigo does
not model:

```text
Identity -> Seat -> ClassIdentity account binding -> Principal -> Piwigo User Account
```

Piwigo continues to own usernames, password hashing, login, PHP sessions,
authentication keys and the user/group tables. ClassIdentity never stores a
password, implements a second login system, adds columns to a Piwigo table, or
patches Core. It uses plugin hooks, numbered migrations, Core functions and a
small version-gated adapter for any Core table operation without a public
function.

This design covers Phase 1 identity and governance. The deployed
`ClassArchivePolicy` plugin owns MediaGuard. The deployed `ClassIdentity`
plugin owns Principal/login enforcement, Claim/Invite provisioning, the
minimum Admin Console, action capability guards, anonymous presentation and
audited resolution. Collections, Family submission workflow, Likes/reports and
Spotlight remain separate later concerns; `ClassSpotlight` is still only a
planned plugin.

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
- Each current Seat has zero or one current account binding. Every authenticated
  Piwigo user id has at most one ClassIdentity principal; a Seat binding does
  not duplicate that external id or its authorization epoch.
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
- An expired issued Family Invitation is atomically changed to `EXPIRED` and
  its matching `INVITED` Seat to `AVAILABLE` when presented or before the owner
  next issues an invitation. Explicit Admin revoke performs the equivalent
  `REVOKED` transition. Neither transition decrements `invite_generation`; a
  reissue increments it and therefore cannot resurrect an old validator.
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

An account is authorized only when its `SEAT_ACCOUNT` Principal, Identity, Seat
and account binding are all active and mutually consistent. A group membership
alone never authorizes it. A `SYSTEM_ACCOUNT` follows its separate exclusive
shape and never receives a Seat.

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
| `singleton_marker` | generated nullable `VARCHAR(16)` | Mirrors non-Family `seat_type`; null for Family |
| `state` | `VARCHAR(24)` indexed | Seat lifecycle |
| `pseudonym_subject` | nullable `BINARY(16)` unique, random | Anonymous HMAC subject; never rendered; null on every non-Anonymous Seat |
| `invite_generation` | `INT UNSIGNED` | Invalidates every earlier invitation generation |
| `lock_version` | `INT UNSIGNED` | Concurrent claim/invite protection |
| lifecycle timestamps | UTC `DATETIME(6)` | Created/invited/activated/frozen/released times |

Unique keys `(identity_id, ordinal)` and `(identity_id, singleton_marker)`
prevent duplicate slots and enforce at most one Classmate, Teacher and
Anonymous Seat per Identity while permitting multiple Family Seats. A
service-level constraint additionally validates the exact allowed role counts
from the Identity's captured template. `pseudonym_subject` is populated only
for Anonymous Seats.

### `class_identity_account`

This table is an account **binding/history**, not a credential store.

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Binding id |
| `seat_id` | FK to Seat, `ON DELETE RESTRICT` | Seat assignment |
| `requested_username` | `VARCHAR(100)` | Nonsecret idempotent reconciliation input |
| `real_name` | `VARCHAR(190)` nullable | Family assignment name; formal name remains on Identity |
| `family_relationship` | `VARCHAR(24)` nullable | Required only for Family |
| `state` | `VARCHAR(32)` indexed | Binding lifecycle |
| `current_marker` | nullable `TINYINT`, value `1` only for a current binding | Supports unique `(seat_id, current_marker)` while allowing many historical null rows |
| `pseudonym_key_version` | `SMALLINT UNSIGNED` nullable | Stable anonymous HMAC key version |
| `provisioning_operation_id` | nullable unique FK to Operation, added after both tables exist | Saga correlation |
| lifecycle/reconciliation timestamps | UTC `DATETIME(6)` | Bound, frozen, released, last checked |

No email or password is duplicated here. Piwigo remains the source of truth for
login/email/password. The unique current-marker invariant is also checked under
`SELECT ... FOR UPDATE`; database uniqueness handles concurrent claims.

### `class_identity_principal`

This is the single mapping from an authenticated Piwigo user to Class Archive
authority. Core user id and session generation are intentionally not duplicated
on the account-binding history table.

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Principal id |
| `principal_type` | `VARCHAR(24)` | `SEAT_ACCOUNT` or `SYSTEM_ACCOUNT` |
| `system_role` | nullable `VARCHAR(24)` | `SYSTEM_ADMIN`; schema also reserves `ARCHIVIST`/`MODERATOR` for later policy migrations |
| `account_id` | nullable unique FK to Account | Required only for a Seat account |
| `piwigo_user_id` | `BIGINT UNSIGNED` unique external reference | The one Core login represented by this principal |
| `state` | `VARCHAR(16)` | `ACTIVE`, `FROZEN`, `DISABLED` |
| `auth_epoch` | `BIGINT UNSIGNED` | Defense-in-depth session generation; Core session/key deletion remains primary revocation |
| lifecycle timestamps | UTC `DATETIME(6)` | Created, updated, frozen and disabled times |

A database `CHECK` enforces the exclusive shapes: `SEAT_ACCOUNT` requires an
Account and no system role; `SYSTEM_ACCOUNT` requires no Account/Seat and one
system role. V1 authorization accepts only `SYSTEM_ADMIN`. Reserving future
role strings does not grant their permissions.

### `class_identity_token`

One table holds Classmate/Teacher Claim Codes, Family invitations and
administrator-issued password-reset links.

| Column | Type / constraint | Purpose |
|---|---|---|
| `id` | `BIGINT UNSIGNED` primary key | Internal token record |
| `seat_id` | nullable FK to Seat | Required for Claim/Family Invite; null for reset |
| `principal_id` | nullable FK to Principal | Required only for Password Reset, including Seat-less administrators |
| `purpose` | `VARCHAR(24)` | `CLAIM`, `FAMILY_INVITE`, `PASSWORD_RESET` |
| `generation` | `INT UNSIGNED` | Reissue/revoke generation |
| `selector_hash` | `BINARY(32)` unique | SHA-256 of public random selector |
| `validator_hash` | `BINARY(32)` unique | HMAC-SHA-256 of secret, purpose, Seat and generation |
| `pepper_version` | `SMALLINT UNSIGNED` | Environment key-ring version |
| `state` | `VARCHAR(16)` indexed | Token lifecycle |
| `reserved_by_operation_id` | nullable FK to Operation | Makes retries single-owner |
| `issued_by_user_id` | nullable external Piwigo user id | Issuer audit link |
| `issued_at`, `expires_at`, `reserved_at`, `consumed_at`, `revoked_at` | UTC `DATETIME(6)` | Lifecycle |

`CHECK` requires Claim/Family Invite to target exactly a Seat and Password
Reset to target exactly a principal. This removes the earlier contradiction
where an administrator reset was described but every token required a Seat.
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

Administrative reasons are validated at both the service and final Audit
persistence boundaries. Normal bounded single-line Chinese business labels are
accepted. Token-shaped values, authorization/cookie/session text, password
assignments, bare password values and high-entropy credential-like strings are
rejected before any side effect and are never reflected in the error response.
The same secret scan applies recursively to structured `old_value` / `new_value`
content, so moving a password-shaped value into Audit JSON is not a bypass.

### `class_identity_role_group`

This table projects a business role code to an external Piwigo group id and
expected name. It never replaces the active Identity/Seat/principal checks.
Business roles require a group id; a disabled/missing/mismatched projection
fails closed. No cross-engine foreign key points to Piwigo's group table.

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

- The plugin ships ordered, forward-only migrations and records each version,
  name and binary SHA-256 checksum in `class_identity_migration`; it does not
  use the caller-supplied old plugin version as migration state.
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
- The Admin Console offers bounded idempotent compensation only when durable
  saga state proves that the Core user was created by that operation before a
  later step failed (`post_core_provisioning_failed`), the binding has no
  Principal, and Seat/account/operation states still match. Before touching the
  Core MyISAM user, it commits a `MANUAL_COMPENSATION_ATTEMPT` Audit event and
  changes the durable operation state to `COMPENSATING`. It then revokes all
  Core sessions/keys and removes every group before marking the operation
  `COMPENSATED`, the binding `DELETED`, and the Seat `AVAILABLE`. The unbound
  Core row remains a fail-closed tombstone for later content-aware cleanup.
- A registration failure with uncertain Core-user provenance is never offered
  automatic compensation: it stays `FAILED_MANUAL` / `COMPENSATION_REQUIRED`
  and keeps production blocked so an existing same-name account cannot be
  quarantined by guesswork.
- A released Family Seat receives a new invitation generation. Old tokens stay
  revoked/consumed and can never be resurrected.
- Dashboard/System Health count `FAILED_MANUAL`, `COMPENSATION_REQUIRED`, and
  operation/account/Seat provisioning states older than the bounded stale
  threshold. Any count or health-query error adds `PROVISIONING_INCIDENT` to
  `PRODUCTION BLOCKED`; the incident table exposes only safe error metadata.
- A future lease-based background reconciler may retry additional safe steps
  with backoff. Until then, ambiguous and stale operations remain visible and
  blocked rather than being silently swallowed.

## Core account and group integration

ClassIdentity resolves group ids by configured name, never hardcoded ids.

| Seat/account kind | Required Piwigo state | Managed group |
|---|---|---|
| Classmate | Core status `normal` | exactly `CLASSMATE` |
| Teacher | Core status `normal` | exactly `TEACHER` |
| Family | Core status `normal` | exactly `FAMILY` |
| Anonymous | Core status `normal`; internal opaque username | exactly `ANONYMOUS` |
| Super Admin | Core `admin`/`webmaster` plus active `SYSTEM_ACCOUNT`/`SYSTEM_ADMIN` principal; never claimed through a Seat | optional mirrored `ADMIN`, outside the exactly-one business-role set |

The four business groups and optional ADMIN mirror are non-default. A managed
normal account must have exactly one business group. Admin status is always
checked through Piwigo Core; membership in a string-named `ADMIN` group alone
never grants administration.

Registration calls the Core password hasher through `register_user()`. The
initial SYSTEM_ADMIN plaintext is not an environment value: fresh local
bootstrap accepts no-echo input (or a consumed one-time file restricted to its
owner, SYSTEM and Administrators with no additional principal), and
the bounded local rotation command later passes no-echo input over CLI STDIN.
After proving the independent Principal, one transaction increments its
`auth_epoch` and records `PASSWORD_RESET_INITIATED`; Core then hashes the
password and revokes sessions/auth keys before a redacted success event. A
pre-ClassIdentity recovery may fall back to the exact Core webmaster only when
`information_schema` proves zero `${prefix}class_identity_%` tables; partial or
remnant schema fails closed. The live rotation returns `sessions=revoked`, the
legacy env key count is zero and the secret-file ACL is restricted. A fresh
empty-volume install has not been rehearsed, and this path is not production
MFA.

The separate planned **member-account** password-reset surface will use a
one-time hashed reset token and the Core password-update/hash function; the
administrator must never see the chosen password. It is not implemented in the
current Admin Console. When added, changing a password must also trigger
session/auth-key revocation and an audit event.

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
hooks. The plugin version-gates and integration-tests these hooks:

- `finalize_login` rejects password login for a nonactive binding/Seat/Identity.
- `user_login` stores the principal id, current principal `auth_epoch` and issue time in a
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
- Freeze or “revoke sessions” increments the principal `auth_epoch` as defense in depth, calls
  Core `delete_user_sessions($userId)`, calls `deactivate_user_auth_keys()`, and
  enumerates Core API keys through `revoke_api_key()`. Every stateful session
  and stateless key is removed, then the guard rejects any stale request.

V1 sets Piwigo `authorize_remembering=false`. Core remember-me cookies are
derived from the password hash and cannot be individually revoked through a
stable public API; leaving them enabled would let an old cookie create a new
post-revocation session. Re-enabling Remember Me requires a tested per-account
revocation adapter and is outside the minimal design.

Identity freeze makes the request guard deny immediately and the Admin service
increments each bound Principal epoch and revokes Core sessions/auth/API keys.
Unfreeze repeats credential revocation before restoring the Identity so an old
session or key is never resurrected. A standalone account-freeze action and
release of an already-active Family account are not implemented yet.

### Core administration guard

ClassIdentity registers a `ws_invoke_allowed` guard around Piwigo's native user,
group, auth-key and API-key methods. Direct calls to at least
`pwg.users.add/delete/setInfo/setMyInfo`, `pwg.groups.addUser/deleteUser`,
`pwg.users.getAuthKey` and `pwg.users.api_key.*` fail for managed accounts unless
they originate from the plugin's audited internal operation. Native password or
business-group changes are redirected to ClassIdentity so session/key revoke,
saga state and Audit cannot be bypassed. The native Piwigo `profile`,
`user_list`, `group_list` and `user_perm` business routes never reach Core's
direct mutation controllers. A SYSTEM_ADMIN GET from a legacy menu item is
redirected to the audited Class Archive `identities` or `system` console;
POST/mutation requests and non-admin principals remain denied. Technical Core
maintenance pages remain available. A future scoped profile adapter must be
implemented and tested before any native business controller can be reopened.

CapabilityGuard separately classifies Web API and direct picture/community
mutations. Known reads and explicitly allowed role actions proceed to Core;
known forbidden actions are denied. An unknown or unclassified state-changing
Web API method fails closed for **every** Seat role, including Classmate and
Teacher. Adding a new plugin method therefore requires an explicit policy and
negative HTTP regression rather than inheriting access from a high-trust role.

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

ClassIdentity owns the HMAC derivation/key-version contract, its
`AnonymousPresenter`, and audited administrator resolution. The presenter
rewrites ordinary comment/photo DTOs and Piwigo template/API output: it replaces
Core usernames and removes profile/avatar links and author ids before output.
Browser/API regression tests scan for the Core id, username, Seat id, Identity
id and roster code. Admin resolution maps the authenticated comment author ->
binding -> Seat -> Identity, requires SYSTEM_ADMIN plus a reason, and appends an
audit event before returning the mapping. Native Piwigo comment moderation
remains reusable, but its hidden-author id/filter DTO is removed so it cannot
become an unaudited alternate resolution path. HMAC is not treated as
encryption or a substitute for output filtering.

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

Exact route registration follows Piwigo plugin routing. This table distinguishes
the deployed surface from the target design:

| Surface | Status | Operations |
|---|---|---|
| Public Claim | Implemented | Show/submit Classmate or Teacher ID + one-time Claim; create independent Core credentials |
| Family invitation | Implemented | Submit one-time invitation, real name, relationship and independent Core credentials |
| My Identity | Implemented subset | Read own Identity/Seat status; issue an available Family invitation; activate own Anonymous Seat |
| Admin Dashboard | Implemented | Identity/content summaries, recent Audit and fail-closed production blockers |
| Admin Identities / Teachers | Implemented subset | List/detail/create; issue/reissue Claim; freeze/unfreeze Identity. Import/edit/retire and account-level operations are pending |
| Admin Invitations | Implemented | Inspect lifecycle; revoke/reissue Claim and Family Invitation. A newly issued raw code appears only in the terminal no-store POST response |
| Admin Audit / System | Implemented subset | Read redacted events; inspect provisioning incidents; run only the bounded proven compensation |
| Admin Seats/Accounts | Planned | Active Family release, account freeze, Anonymous disable, force logout and member-account password reset |
| Submissions | Implemented Phase 1 subset | Family HERITAGE-only pending store, safe Admin thumbnail/review, audited approve/reject and Piwigo pipeline handoff; Community remains inactive |
| Anonymous governance | Implemented Phase 1 subset | Context aliases, interaction summary, explicit audited resolution, enable/disable with credential revocation |
| Archive | Implemented Phase 1 subset | Create private official albums below Era roots; existing Piwigo image/album projections, Era/date precision/confidence/event metadata and one-original multi-album association |
| Spotlight | Planned | No Admin page or route exists yet |
| Export | Planned | Future CSV/JSON Identity/Seat/account metadata only; never password/token/hash/session/secret fields |

Every mutation is POST, CSRF-protected, same-origin checked and authorized on
the server. Public errors do not reveal whether an Identity or Seat exists.
Claim/invite/login actions are rate-limited. Pages carrying selectors use
`no-store`/`no-referrer`; secrets never appear in query strings. Admin actions
require both Core `admin`/`webmaster` status and an active independent
`SYSTEM_ACCOUNT/SYSTEM_ADMIN` Principal, plus action-specific permission.
Implemented freeze and manual-compensation actions require a reason; future
release/replace/reset and anonymous-governance UI must retain that rule.

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
   Expiry/revoke releases the Seat, reissue increments generation, and every
   older raw token remains invalid.
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
14a. `FAILED_MANUAL`, `COMPENSATION_REQUIRED`, and stale provisioning rows are
    visible and force `PRODUCTION BLOCKED`. Only proven post-Core failures get
    the bounded compensation action; uncertain failures never do.

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
22. Through ClassIdentity's AnonymousPresenter, ordinary HTML/API contains
    no anonymous Core username/id, Identity/Seat id, roster code or identifying
    profile/avatar link.
23. Admin resolution returns the correct Identity only to an authorized admin,
    requires a reason and writes a redacted audit event.
24. Anonymous account is absent from all ordinary user discovery surfaces and
    cannot upload/create album/Like through direct endpoints; comment/output
    behavior remains fail closed until ClassIdentity's HTML/WS presenter
    attestation and its persistent readiness gate both pass.

These tests cover the Phase 1 portions of acceptance criteria 2-6, 19, 26-27
and 31-32, plus concurrency and MyISAM failure modes that the product-level list
does not spell out.

## Non-goals and implementation gate

ClassIdentity does not implement media storage, photo ACL, comments, Likes,
Family submission review, named photo collections, Spotlight, Activity, SSO,
email delivery, a second user directory or the photo-first Theme. It does not
activate Community or User Collections.

The implementation is isolated under `plugins/ClassIdentity/`, with tracked
migrations and tests. Any future need to edit Piwigo Core, add a cross-engine
foreign key, store plaintext credentials/tokens, or bypass the compensation
guard remains an architecture blocker, not an invitation to weaken this
boundary.
