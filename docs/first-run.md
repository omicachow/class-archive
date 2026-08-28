# Reproducible local first run: Piwigo-first Phase 0 + Phase 1

This procedure creates the supported **local engineering spike** on the current
Windows + WSL2 Ubuntu workstation. The known-media-URL P0 gate now passes, but
this is not production approval: the ClassIdentity/independent Admin foundation
is implemented, while active Family release/member password reset, Community/
collections, Admin MFA, persisted MediaGuard HTTP attestation, restore/cron,
NAS and public-deployment gates remain open. Use only synthetic photos on
localhost.

## 1. Data and secret boundary

Two ignored environment files may be present for different preserved runtimes:

- Root **`.env` belongs to the historical HumHub recovery snapshot**. Never
  delete it, overwrite it, load it for Piwigo, or regenerate it. It must continue
  to match the preserved HumHub volumes.
- **`.env.piwigo` belongs to the supported Piwigo stack**. It contains database
  credentials and Claim/anonymous derivation secrets, is restricted to the
  Windows owner, SYSTEM and Administrators with inheritance disabled and no
  additional principal, and is ignored by Git. It must be backed up separately
  in encrypted off-device storage. It must never contain an administrator
  plaintext password.

Never commit either file, print its values, or place a real member list, Claim
Code, invitation token, database dump or photo in Git.

Older local environments may still have a legacy `PIWIGO_ADMIN_PASSWORD` line.
Every supported Piwigo command now refuses that state. Remove only that line
with the explicit high-impact migration command below, then keep the real
password in an external password manager:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\remove-admin-password-from-env.ps1 -Confirm:$false
```

Review and back up the ignored file before explicitly approving this irreversible
line removal. The migration refuses duplicate keys, atomically rewrites the
file, applies the restricted ACL and never prints the removed value. It is not
a password rotation. Once ClassIdentity is converged, use the no-echo
`infra/scripts/set-system-admin-password.ps1` command to rotate the
SYSTEM_ADMIN hash and revoke its existing credentials.

The Piwigo initializer refuses to create new secrets if any default Piwigo
persistent volume already exists. If volumes exist but `.env.piwigo` is missing,
stop and restore the matching file from secure backup. Do not generate another
credential set against existing data.

## 2. Prerequisites and preflight

- Windows 11, the existing WSL2 distribution named `Ubuntu`, and PowerShell.
- Docker Engine and Docker Compose v2 running inside that distribution.
- Host port 8090 free. Compose exposes Piwigo only as
  `127.0.0.1:8090`; MariaDB is not host-published.

From the repository root:

```powershell
git status --short
wsl.exe -d Ubuntu -- docker info
wsl.exe -d Ubuntu -- docker volume ls
```

If the separate Piwigo evaluation stack is still using port 8090, stop that
stack **without `-v`** before starting this supported stack. Do not remove its
volumes merely to free a port.

## 3. Generate the Piwigo-only secret file

Run this once on a new environment with no Piwigo volumes:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\init-dev-env.ps1
```

Expected result:

```text
Created ignored .env.piwigo with cryptographically random local secrets.
```

If `.env.piwigo` already exists, the script preserves it. If any Piwigo volume
exists without that file, the script deliberately fails.

## 4. Bootstrap the locked private baseline

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 bootstrap
```

On a fresh database the command prompts twice without echo. For a deliberately
staged operator run, call `infra/scripts/bootstrap-piwigo.ps1` with
`-AdminPasswordFile <path>` only after protecting an ordinary UTF-8 file so its
ACL contains the owner, SYSTEM and Administrators and no other principal. The
script rejects a directory/link/reparse point, removes a successfully consumed
file in `finally` on either success or later failure, and asserts that the path
is gone. A retry needs fresh no-echo input or a newly protected file.

An interrupted pre-ClassIdentity install may use the same staged recovery only
when `information_schema` proves that **zero** `${prefix}class_identity_%`
tables exist and the target is the exact Core webmaster. Any partial/remnant
ClassIdentity schema fails closed instead of guessing which credential boundary
applies. Once the independent SYSTEM_ADMIN Principal is converged, ordinary
repeat bootstrap needs no password.

The idempotent bootstrap:

1. starts the exact Piwigo and MariaDB image digests;
2. waits for the loopback HTTP endpoint;
3. installs Piwigo Core only when its database configuration is absent;
4. on a fresh database, asks twice through a no-echo prompt for the initial
   SYSTEM_ADMIN password, submits it only to the loopback installer and Core
   password hasher, and retains no administrator plaintext;
5. installs/verifies only extension archives allowed by the lock;
6. installs/activates the tracked `ClassArchivePolicy` and `ClassIdentity`
   plugins, applies the guarded Identity bootstrap, and installs nginx
   MediaGuard routing;
7. configures the fail-closed baseline and verifies it.

The baseline disables guest gallery access, open registration, guest comments,
ratings and web-UI extension installation; creates private HERITAGE/LIVING root
albums and non-default business groups; and keeps Community/User Collections
inactive. Public `/upload/`, `/galleries/`, `/_data/i/`, `i.php` and
`action.php` media requests pass through MediaGuard; authorized bytes are sent
by nginx internal locations. It does not patch Piwigo Core.

Verify the running state and locks:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 baseline-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 extensions-verify
```

Expected lock output includes:

```text
BASELINE_VERIFIED
VERIFIED plugin Community 16.f
VERIFIED theme bootstrap_darkroom 16.d
SKIPPED plugin UserCollections (install=false; no download)
```

Open <http://localhost:8090>. The guest-facing page must be the sign-in surface.
The administrator username is configured in ignored `.env.piwigo`, but its
password is not. Keep that password outside the project and never paste it into
issue reports, command arguments or logs.

## 5. Seed synthetic media once

Only on a clean development database, generate and import the fixed 72-image
fixture set:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 seed
```

The PNGs are deterministic geometric test graphics generated under `/tmp`
inside the container. They contain no real people or class photos and never
enter Git. The seed creates two HERITAGE and two LIVING fixture albums. Some
photos are assigned to two albums in the same Era to prove reference-only
organization.

**Do not run `seed` twice on the same database.** The current fixture importer
is intentionally a clean-stack test seed, not a production idempotent importer;
the model test expects exactly 72 image records.

## 6. Bind synthetic principals, then run the Phase 0 access and photo UI gates

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 identity-bootstrap-synthetic
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 class-plugins-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-access
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase0\media-guard-http.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase0
```

`identity-bootstrap-synthetic` creates only the exact allowlisted localhost
fixture users when absent, gives them random transient passwords that are
immediately discarded, and binds them to explicit ClassIdentity Principals.
Normal `class-plugins` never creates fixture accounts. `test-access` refuses
unbound users and only rotates the already-bound synthetic accounts with
per-run passwords before using the real Piwigo API. Expected output:

```text
ACCESS_MATRIX_ASSERTIONS=PASS
GUEST_ALBUM_API_DENIED=PASS
FAMILY_HERITAGE_ONLY=PASS
CLASSMATE_TEACHER_ANONYMOUS_BOTH_ERAS=PASS
```

`test-phase0` inspects the real database, pages and generated derivatives.
Expected output:

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

The standalone MediaGuard matrix has passed with:

```text
MEDIA_GUARD_HTTP=PASS
HTTP_PROBES=290
ROLE_ERA_VARIANT_MATRIX=PASS
KNOWN_URL_LOGOUT_SWITCH=PASS
HEAD_RANGE_TAMPER_NORMALIZATION_GUESS=PASS
PRIVATE_CACHE_POLICY=PASS
ALLOW_BODY_IMAGE_MAGIC=PASS
DENY_BODY_IMAGE_MAGIC_ABSENT=PASS
RANGE_206_CONTENT_RANGE_32_BYTES=PASS
HEAD_ZERO_BODY=PASS
```

It verifies that known original/derivative URLs are re-authorized for Guest,
Family, Classmate, Teacher, Anonymous and independent SYSTEM_ADMIN sessions. It also covers
logout/account switching, `GET`/`HEAD`/Range, path/query tampering and cache
revalidation. Allowed and denied bodies are inspected rather than inferred from
headers; Range must be an exact 32-byte `206`, and HEAD must have no body. A
controlled database outage returned a generic 503 without media bytes or
diagnostics.

The broader `test-phase0` command also invokes the mutable authorization-state
suite. Its default 38-probe run verifies old-session permission revocation,
same-Era multi-album union, cross-Era fail-closed behavior and rejection of two
image rows that alias the same physical original path. The explicit
`-IncludeDatabaseOutage` run adds a real database stop/recovery and passes with
40 probes. The four allowlisted synthetic role accounts already have explicit
Principals at this point; the HTTP gates only rotate their password hashes for
the duration of each controlled test.

## 7. ClassIdentity bootstrap boundary

The first two commands in Section 6 run the complete coordinated workflow. Do
not invoke the bootstrap PHP file directly.

`identity-bootstrap-synthetic` is strictly for this localhost fixture stack. It
acquires an exclusive host workflow lock, creates the exact maintenance marker,
atomically publishes both Class plugins, restarts/waits for PHP-FPM, applies and
semantically verifies the four ClassIdentity migrations, creates/verifies the
independent `SYSTEM_ACCOUNT/SYSTEM_ADMIN`, maps only the allowlisted fixture
users, verifies explicit Principal resolution, and only then finalizes
maintenance. Failure leaves the site fail closed for operator recovery; it does
not silently remove unknown maintenance state.

A future non-synthetic operator workflow uses `dev.ps1 class-plugins`, but that
is not permission to import a real roster or expose this local stack.

## 8. Run Phase 1 and then rerun Phase 0

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase0
```

The final coordinated Phase 1 run exits zero with:

| Gate | Verified result |
|---|---:|
| ClassIdentity real HTTP | 108 probes |
| Host plugin-workflow mutex | 12 checks |
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
| Public runtime surface | 45 probes / 352 assertions |
| Enforcement-fault HTTP | 116 assertions |
| CapabilityGuard HTTP | 43 assertions |
| Pending Community media HTTP | 75 real GET probes; exact cleanup to 72 images |
| AnonymousPresenter HTTP | 211 assertions |

PowerShell 7 and Windows PowerShell 5.1 AST checks each pass 18/18, PHP lint
passes 7/7, and a plaintext-reference scan passes. These are static/protocol
preflights that supplement rather than replace the live acceptance evidence
above. Focused Admin session
mint/`getStatus`/exact revoke also passes with zero residual lease; a live
SYSTEM_ADMIN password rotation returns `sessions=revoked`; and the ignored env
has zero legacy admin keys with the restricted ACL.

The ClassIdentity HTTP gate exercises the independent SYSTEM_ADMIN, Admin route
denials, Classmate/Teacher Claim, Family Invitation expiry/revoke/reissue,
anonymous activation, freeze/session revocation, known LIVING media denial,
provisioning incidents, audit-reason rejection and exact secret/fixture cleanup.

The final post-Phase 1 Phase 0 run again passes the 72-image / 72-original /
8 multi-album model, media permissions and 290 + 16 + 38 HTTP probes. It also
checks the runtime generated after Admin/identity requests. PHP-FPM uses umask
`0007`; however, an upload component that explicitly calls a permissive
`chmod` can bypass umask, so Community remains inactive until upload completion
normalizes originals to `0660` and a real HTTP regression passes.
The explicit database-outage state variant also passes 40 probes and restores
the running database/application.

## 9. Create a quiesced backup

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 backup
```

The helper stops only the Piwigo application when it was running, leaves the
database up, takes a root logical dump with `--lock-all-tables` for MyISAM
consistency, archives Piwigo data/uploads/galleries, writes `SHA256SUMS`, then
publishes only after all payloads and a `COMPLETE` marker verify, then restores
the application's prior run state. Generated derivatives are a reproducible
cache and are not included.

This path has been exercised: five recorded entries (four payloads plus
`COMPLETE`) passed SHA-256, all four gzip archives passed integrity checks, the application returned
to its original running state, and an injected dump failure returned nonzero
without publishing a complete-looking or partial bundle. Calling the backup
service directly without the helper's quiescence marker is intentionally
refused, and overlapping helper/service runs fail closed on their respective
locks.

The bundle remains in the `class_archive_piwigo_backups` Docker volume. That is
not disaster recovery by itself: export it off-device and encrypt the entire
recovery set, including the matching `.env.piwigo`, exact Git release/lock and
any deployment override. The current data bundle does not package those
deployment artifacts and the repository does not yet claim a verified empty-
volume restore command.

## 10. Normal lifecycle commands

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 up
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 logs
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 down
```

`up` also starts the dedicated public synthetic compatibility BFF behind the
loopback-only `8091` Piwigo nginx listener; it does not reuse a private QA
service. `stop` and `down` stop that BFF together with the public synthetic
runtime.

`stop` and `down` preserve named volumes. **Never run `docker compose down -v`**
for this project: `-v` deletes the persistent database, originals, application
state and local backups. Never use volume deletion as a routine reset method.

Extension changes must go through the reviewed lock and installer. Do not use
Piwigo's web extension updater in this baseline, do not manually edit files in
the application volume, and do not enable Community or User Collections.

## Current completion boundary

This baseline proves a reproducible localhost-only Piwigo media and Phase 1
identity/control-plane foundation with 72 synthetic images, explicit
Principals, independent SYSTEM_ADMIN, one-time Claim/Invite flows, Identity
freeze, anonymous presentation, no-copy multi-album placement, thumbnail-first
pages and fail-closed known-media delivery. Same-size small-photo safe preview
is covered by a separate 16-request re-encode/metadata-strip regression with
exact cleanup. The complete current Phase 1/Phase 0 procedure exits zero.

It does **not** prove active Family-account release/member password reset,
Community upload/moderation safety, named collections, Spotlight Admin page,
Admin MFA, persisted
digest-bound MediaGuard HTTP attestation, audited coverage of every future
business mutation, browser/touch viewer UX, NAS coexistence, public HTTPS,
cron, fresh empty-volume bootstrap or empty-volume restore. Those are explicit
production gates, not implied by a healthy container or a green subset of
System Health.
