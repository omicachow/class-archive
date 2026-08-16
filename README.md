# Class Archive / 班级数字档案馆

Class Archive is a long-lived, private, photo-first memory space for one
high-school class, its teachers and invited family members.

The selected platform direction is **Piwigo-first**: Piwigo owns originals,
derivatives, metadata, private albums, comments and multi-album relationships.
Project code is limited to the class-specific Identity -> Seat -> Account
model, HERITAGE/LIVING policy, secure collections, anonymous rendering,
moderation guards, Spotlight and the photo-first theme. Neither Piwigo nor the
archived HumHub spike is forked.

The engineering rule remains:

`Reuse > Adapter > Extension > Rewrite`

## Current state

The photo-first Phase 0 media foundation and Phase 1 identity/control-plane
foundation are runnable on this workstation. The final coordinated localhost
Phase 1 and Phase 0 regressions pass on the current tree:

- Piwigo Core 16.4.0 in the pinned official 16.4.0a container image.
- MariaDB 11.8.8, both images locked by immutable digest.
- `ClassArchivePolicy` 0.1.0 MediaGuard, with every public original and
  derivative route authorized by PHP and delivered by nginx only after an
  internal `X-Accel-Redirect`; no Piwigo Core file is patched.
- `ClassIdentity` 0.1.0 with four checksum-attested migrations, ten InnoDB
  tables, explicit `SEAT_ACCOUNT` / `SYSTEM_ACCOUNT` principals, hashed
  Claim/Invite credentials, account lifecycle guards and an independent
  `SYSTEM_ADMIN` that is never a Seat.
- A minimum business Admin Console for Dashboard, Identities, Teachers,
  Invitations, Audit and System Health. Submissions, Anonymous governance,
  Archive and Spotlight pages do not exist yet.
- The private UI defaults to Simplified Chinese (`zh_CN`) with browser-language
  negotiation disabled. Core login/gallery labels and the implemented
  ClassIdentity forms and Admin Console are translated; product names and
  security machine identifiers remain stable where needed for operations.
- PHP-FPM runs with a restrictive `0007` umask and the media-permission gate is
  rerun after Phase 1 requests generate runtime files. Piwigo upload paths that
  use an explicit permissive `chmod` still require a dedicated adapter and
  regression before Community can be enabled.
- Bootstrap Darkroom 16.d with bundled PhotoSwipe 4.1.3, used only to validate
  derivative-first markup, preview loading and integration markers; interactive
  browser/touch QA remains pending.
- Community 16.f downloaded and integrity-verified but deliberately inactive
  until its recorded CSRF/input/default-permission gates are fixed.
- User Collections 16.a deliberately absent after a reproduced private-album
  ACL bypass.
- 72 deterministic synthetic images across private HERITAGE and LIVING album
  trees; no real class data is used.

The architecture decision is in
[`docs/photo-first-architecture-decision.md`](docs/photo-first-architecture-decision.md).
The implemented identity boundary and its remaining work are specified in
[`docs/class-identity-design.md`](docs/class-identity-design.md), and the
implemented-versus-pending business control plane is recorded in
[`docs/admin-console.md`](docs/admin-console.md). The
vendor-neutral deployment/coexistence gates are in
[`docs/nas-deployment.md`](docs/nas-deployment.md).
Historical HumHub evidence remains under `docs/evaluations/humhub/` and on the
`codex/humhub-first-snapshot` branch.

> Development only: the former known-media-URL P0 blocker now passes a 290-probe
> real HTTP matrix that verifies response bytes as well as status and headers.
> Guest receives no HERITAGE/LIVING media, FAMILY receives
> HERITAGE previews but no LIVING media (and no originals by default), and
> Classmate/Teacher/SYSTEM_ADMIN access follows the locked policy. This freezes
> Piwigo-first for media feasibility; it is **not** production approval.
> Identity freeze and session revocation, independent `SYSTEM_ADMIN`, Claim,
> Family Invitation and anonymous presentation now have real HTTP coverage.
> Pending Community media also passes 75 real GET probes: every Seat role is
> denied, only SYSTEM_ADMIN may review, malformed/duplicate state fails closed,
> and cleanup restores the 72-image model.
> Active Family-account release/member password reset, Community moderation,
> collections, Admin MFA, persisted MediaGuard HTTP attestation, fresh-install,
> restore/cron, NAS and public-deployment gates remain incomplete. Keep the stack on
> localhost with synthetic data.

## Local start

Prerequisites on this workstation are Windows, the existing WSL2 Ubuntu
distribution, Docker Engine and Docker Compose v2. The site binds only to
`127.0.0.1`.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\init-dev-env.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 bootstrap
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 seed
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 identity-bootstrap-synthetic
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase0
```

On a fresh database, `bootstrap` asks twice through a no-echo prompt for the
initial SYSTEM_ADMIN password. Store it in an external password manager: no
administrator plaintext belongs in `.env.piwigo`, Git, process arguments or
logs. Repeat bootstrap is idempotent and does not ask for that password again.
After ClassIdentity is converged, the guarded
`infra/scripts/set-system-admin-password.ps1` command is the supported local
password-rotation path and revokes existing credentials. Open
`http://localhost:8090` after bootstrap. `test-phase0` is safe to repeat only
after `identity-bootstrap-synthetic` has created and bound the exact allowlisted
fixture principals; the tests then rotate those already-bound accounts using
per-run secrets. Treat `seed` as a clean-stack fixture operation; do not use it
after adding non-synthetic content.

An older ignored `.env.piwigo` that still contains
`PIWIGO_ADMIN_PASSWORD` is deliberately refused by init/bootstrap/test/reset
commands. Review and run the explicit one-line migration in
[`docs/first-run.md`](docs/first-run.md) before continuing; removing that legacy
entry is not itself a password rotation.

On this verified workstation runtime the legacy administrator key count is zero
and `.env.piwigo` has the restricted owner/SYSTEM/Administrators ACL. A live
SYSTEM_ADMIN password rotation returned `sessions=revoked`; a fresh empty-volume
install remains a separate unrehearsed gate.

Useful commands:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 baseline-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 extensions-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 class-plugins-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-access
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase0\media-guard-http.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 backup
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 stop
```

The fast probe and full matrix verify the former known-URL production blocker:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase0\probe-known-media-gap.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase0\media-guard-http.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase0\media-guard-tiny-preview.ps1
```

The authorization and effective-Era rules are recorded in
[`docs/media-access-policy.md`](docs/media-access-policy.md). A URL identifies a
media object; it never grants access by itself.

Never use `down -v`: the named volumes are the application data boundary.

## Persistent data

- `class_archive_piwigo_data`: Piwigo Core runtime, local configuration,
  installed extension code and non-derivative application state.
- `class_archive_piwigo_uploads`: originals uploaded through Piwigo.
- `class_archive_piwigo_galleries`: synchronized/archive originals.
- `class_archive_piwigo_derivatives`: rebuildable thumbnails and previews.
- `class_archive_piwigo_db`: MariaDB data.
- `class_archive_piwigo_scripts`: official image lifecycle scripts.
- `class_archive_piwigo_backups`: explicit consistent backup bundles.

The supported backup helper stops the app, locks MyISAM-aware database tables,
archives database plus writable source volumes, writes SHA-256 checksums, then
restores the prior run state. Backups still require an encrypted off-device
copy and a restore drill.

The old ignored root `.env` belongs to the preserved HumHub evaluation data.
Do not overwrite it. Piwigo uses the independent ignored `.env.piwigo`.

## Open-source boundary

Git must never contain real names, photos, emails, claim/invitation codes,
credentials, database dumps, NAS paths or deployment keys. All tracked fixtures
are generated abstract images and synthetic accounts. A new class should be
deployable by supplying private settings and importing its roster outside Git.

## License

Class Archive project-authored source code is available under the GNU General
Public License, version 2 or (at your option) any later version
(`GPL-2.0-or-later`). See [`LICENSE`](LICENSE) for the complete terms and
[`NOTICE`](NOTICE) for the ownership and license boundaries of the mature
third-party components retrieved by the local bootstrap process.
