# Class Archive Admin Console

Status: minimum Phase 1 business control plane and credential hardening pass the
final coordinated localhost Piwigo 16.4.0 regression

The Class Archive Admin Console is the business-management surface for class
identity and governance. It is not the member photo application and it is not a
replacement for Piwigo's technical administration pages.

## Administrator identity boundary

Access requires both:

1. a Piwigo Core `admin` or `webmaster` login; and
2. one active ClassIdentity `SYSTEM_ACCOUNT` Principal whose role is exactly
   `SYSTEM_ADMIN`, whose `piwigo_user_id` matches that login, and whose
   `account_id` is null.

`SYSTEM_ADMIN` is never a Classmate/Teacher Identity or Seat, cannot be created
through Claim/Family Invitation, and is absent from ordinary member discovery.
A Piwigo admin status or group named `ADMIN` alone grants no Class Archive
authority. Missing, frozen, disabled, ambiguous or downgraded Principal state
fails closed.

The initial System Admin is provisioned only by the guarded CLI/bootstrap
workflow. If that person is also a classmate, ordinary photo browsing must use
a separate claimed Classmate account.

### Administrator credential provisioning

No public or Admin Console form creates a SYSTEM_ADMIN. On a fresh database,
the local bootstrap asks twice through a no-echo prompt (or consumes a one-time
ordinary file restricted to its owner, SYSTEM and Administrators with no other
principal), submits the value only to the loopback installer/Core hasher, and
does not retain administrator plaintext in `.env.piwigo`. A legacy
`PIWIGO_ADMIN_PASSWORD` entry is a refused state until the explicit migration
atomically removes it and restores that restricted ACL.

After the independent Principal is converged, the guarded
`infra/scripts/set-system-admin-password.ps1` path accepts a new password with
no echo and passes it to the unprivileged CLI over STDIN. One transaction first
increments the Principal auth epoch and records `PASSWORD_RESET_INITIATED`;
then Piwigo Core hashes the password, revokes sessions/auth keys and a redacted
success Audit event is appended. It is a local
operator recovery/rotation command, not a web registration path. Live rotation
returns `sessions=revoked`; the legacy env key count is zero and the secret-file
ACL is restricted. Fresh empty-volume installation remains unrehearsed and
production Admin MFA remains a separate hard gate.

Before ClassIdentity exists, credential recovery is allowed only when
`information_schema` proves zero `${prefix}class_identity_%` tables and the
target is the exact Core webmaster. Any partial/remnant ClassIdentity schema
fails closed rather than falling back to Core status.

## Implemented routes

Piwigo plugin routing is the framework-equivalent of `/admin/class-archive`.
These routes are currently deployed:

| Page | Route | Current capability |
|---|---|---|
| Dashboard | `admin.php?page=plugin-ClassIdentity-dashboard` | Identity/Seat/content summary, recent Audit and prominent `PRODUCTION BLOCKED` state |
| Identities | `admin.php?page=plugin-ClassIdentity-identities` | List/detail/create Classmate Identity; inspect Seats/accounts; issue/reissue Claim; freeze/unfreeze Identity |
| Teachers | `admin.php?page=plugin-ClassIdentity-teachers` | List/detail/create Teacher Identity; inspect its single Seat/account; issue/reissue Claim; freeze/unfreeze Identity |
| Invitations | `admin.php?page=plugin-ClassIdentity-invitations` | Inspect Claim/Family Invitation lifecycle; revoke Claim; revoke/reissue Family Invitation |
| Submissions | `admin.php?page=plugin-ClassIdentity-submissions` | View safe pending thumbnails and metadata; approve into HERITAGE through the Piwigo upload pipeline or reject with an audited reason |
| Anonymous | `admin.php?page=plugin-ClassIdentity-anonymous` | List context-scoped aliases and interaction counts; explicitly resolve a real identity with an Audit event; disable/restore an Anonymous Seat |
| Archive | `admin.php?page=plugin-ClassIdentity-archive` | Create private official albums under HERITAGE/LIVING; edit archive date precision/confidence/event label/official flag and associate existing Piwigo albums without copying the original |
| Audit | `admin.php?page=plugin-ClassIdentity-audit` | Read recent redacted ClassIdentity Audit events |
| System | `admin.php?page=plugin-ClassIdentity-system` | Schema/migration, Principal, MediaGuard configuration, storage and provisioning health; bounded compensation for one proven incident shape |

Guest, Classmate, Teacher, Family and Anonymous direct requests to each Admin
alias are denied with HTTP 403. The direct plugin form
`admin.php?page=plugin&section=ClassIdentity/admin.php` has the same server-side
check; hiding the navigation is not an authorization control.

## Implemented mutations

- Create a Classmate or Teacher Identity and materialize its configured Seats.
- Issue/reissue/revoke a Classmate or Teacher Claim.
- Freeze/unfreeze an Identity. Freeze increments active Principal epochs and
  revokes Core sessions, auth keys and API keys; unfreeze does not restore old
  credentials.
- Revoke an issued Family Invitation and release its `INVITED` Seat.
- Reissue an expired/revoked Family Invitation with a higher generation; every
  earlier validator remains invalid.
- Compensate only the exact durable post-Core provisioning failure whose
  operation/account/Seat/Core-user shape is proven safe. Ambiguous or stale
  incidents remain visible and non-repairable. Before Core credentials/groups
  are touched, the transaction appends `MANUAL_COMPENSATION_ATTEMPT` and moves
  the operation to durable `COMPENSATING`, preserving evidence across a later
  Core-side failure.

New Claim/Invitation values are never read back from storage. A successful
issue/reissue POST displays the raw value once in a terminal response with
`Cache-Control: no-store`, restrictive CSP and no referrer; the database stores
only selector/validator hashes.

## Implemented Phase 1 pages and remaining surfaces

The following business pages are now implemented for the localhost synthetic
baseline. Community remains inactive; the custom ClassIdentity submission
service is the upload boundary, and Piwigo Core is called only after an Admin
approval. Pending originals and derivatives are never exposed to Family.

- Submissions: Family can submit HERITAGE-only images; Admin can inspect a
  safe thumbnail, approve into the HERITAGE root or reject with an Audit reason.
- Anonymous: aliases are context-scoped; real-identity lookup is an explicit,
  audited action; enable/disable revokes credentials when disabling.
- Archive: Admin can create private official albums below the two Era roots;
  existing Piwigo images can receive Class Archive date precision and
  confidence metadata and be associated without copying originals. Bulk
  curation and Spotlight remain separate work.
- Spotlight;
- a standalone Seats/Accounts console;
- release of an already-active Family account;
- account-level freeze/unfreeze or standalone force logout;
- member-account password reset and its browser workflow (the bounded local
  SYSTEM_ADMIN CLI rotation described above is separate);
- roster import/edit/retire;
- Audit search/export or batch governance.

The audited `AnonymousResolutionService` is now exposed only through the
SYSTEM_ADMIN Anonymous page's explicit resolution action. Community is still
inactive, so the Dashboard's Pending count refers to the custom
ClassIdentitySubmissionService rather than Community moderation.

## Piwigo technical Admin boundary

Piwigo Core remains responsible for plugin/theme/Core configuration, derivative
technology and other technical maintenance. Class Archive does not reimplement
those pages.

Conversely, Piwigo's native `profile`, `user_list`, `group_list` and
`user_perm` routes can mutate business identity state outside the ClassIdentity
saga/Audit contract. A SYSTEM_ADMIN GET from one of these legacy menu items is
redirected to the corresponding Class Archive console (`identities` or
`system`); the native controller is never reached. POST/mutation requests and
all non-admin principals remain HTTP 403. Technical pages that do not bypass
ClassIdentity remain available. Reopening a blocked business route requires a
dedicated audited adapter and negative HTTP tests.

## Audit and request safety

Every implemented Admin mutation is POST-only, uses Piwigo CSRF validation,
requires an exact same-origin request and rechecks SYSTEM_ADMIN authority on the
server. High-risk mutations require a bounded, single-line reason. The Audit
persistence boundary rejects reason text resembling raw Claim/Invite tokens,
password assignments or bare password/high-entropy credential values, cookies,
Authorization headers, session/API secrets or other credentials before any side
effect. Ordinary bounded Chinese business reasons remain valid; rejection uses
a generic error and never reflects the submitted secret. Structured Audit
`old_value` / `new_value` is recursively checked by the same persistence
boundary; a password-shaped value cannot be hidden in JSON.

Audit events contain actor Principal, action, target, redacted state/reason and
request correlation metadata where appropriate. They never contain passwords,
raw one-time codes, session secrets, API keys or anonymous HMAC keys. Viewing an
anonymous real-identity mapping through the dedicated resolution service is
itself audited.

## System Health truth boundary

The System page distinguishes configuration inspection from end-to-end proof.
It can report database/schema status, migration version, Identity enforcement,
SYSTEM_ADMIN count, exact role mappings, anonymous-presenter readiness,
provisioning incidents, storage/free space and derivative-cache writability.

It must continue to show `PRODUCTION BLOCKED` until every production gate is
complete. The localhost synthetic baseline now has a persisted digest-bound
MediaGuard attestation, a destructive backup/restore drill, a single-instance
maintenance runner and a clean reconciliation record. Those facts are visible
as Chinese operational status, not inferred from a green configuration string.

The following blockers remain intentionally open:

- `ADMIN_MFA`;
- browser/touch visual acceptance, which is tracked outside the technical
  dashboard until its screenshot report is complete;
- `COMMUNITY_MODERATION`, including explicit upload post-write mode `0660`;
- `BUSINESS_MUTATION_AUDIT` for later business surfaces.

Database, migration, MediaGuard configuration, Identity enforcement,
SYSTEM_ADMIN, role-mapping, secret, anonymous-presenter or provisioning failures
add their own blockers. Unknown is never treated as allow or healthy.

## Verified Phase 1 evidence

The final coordinated Piwigo/MariaDB/HTTP baseline passes 108 ClassIdentity
workflow probes and 75 Pending-media real GET probes. The
Admin access matrix, CSRF/same-origin negatives, independent Principal shape,
Claim/Invite lifecycle, Identity freeze, provisioning incident handling and
secret scans are included. Supporting gates pass 12 workflow-lock checks, 40
maintenance-protocol assertions, 8 enforcement-context assertions, 12 anonymous
pure assertions, 20 Audit-reason assertions, 96 capability-pure assertions, 22
rate-limit assertions, 9 schema-semantic assertions, 13 synthetic-bootstrap
assertions, 16 credential assertions, 11 maintenance HTTP probes, 45 runtime
surface probes / 352 assertions, 116 enforcement-fault HTTP assertions, 43
capability HTTP assertions and 211 anonymous-presentation HTTP assertions.

The SYSTEM_ADMIN credential protocol passes 24 assertions plus one real
post-commit/pre-JSON fault scenario,
including the rule that normal test-session removal must prove
`absent=false`; only failed-mint compensation may accept safe absence. Focused
session mint, real HTTP status resolution and exact revoke pass with zero lease
residue, and live password rotation passes with credential revocation. Only a
fresh empty-volume bootstrap rehearsal remains open for this local credential
path.

The Phase 0 media regression also passes 290 authorization probes, 16
small-photo/safe-preview probes and 38 state/path probes. Anonymous presentation
passes 211 HTTP assertions and the Pending-media state machine passes 75 HTTP
probes. The destructive `72/72/8` backup/restore rehearsal and maintenance
reconciliation are now also verified. This is a usable minimum local business
console, not production approval; Spotlight, browser/touch QA, mature Admin MFA
and the remaining production gates are still open.
