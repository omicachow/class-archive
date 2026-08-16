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

The photo-first Phase 0 spike is runnable on this workstation:

- Piwigo Core 16.4.0 in the pinned official 16.4.0a container image.
- MariaDB 11.8.8, both images locked by immutable digest.
- `ClassArchivePolicy` 0.1.0 MediaGuard, with every public original and
  derivative route authorized by PHP and delivered by nginx only after an
  internal `X-Accel-Redirect`; no Piwigo Core file is patched.
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
The next implementation boundary is specified in
[`docs/class-identity-design.md`](docs/class-identity-design.md), and the
vendor-neutral deployment/coexistence gates are in
[`docs/nas-deployment.md`](docs/nas-deployment.md).
Historical HumHub evidence remains under `docs/evaluations/humhub/` and on the
`codex/humhub-first-snapshot` branch.

> Development only: the former known-media-URL P0 blocker now passes a 290-probe
> real HTTP matrix that verifies response bytes as well as status and headers.
> Guest receives no HERITAGE/LIVING media, FAMILY receives
> HERITAGE previews but no LIVING media (and no originals by default), and
> Classmate/Teacher/Admin access follows the locked policy. This freezes
> Piwigo-first for media feasibility; it is **not** production approval.
> ClassIdentity freeze/release/session-revoke behavior, independent
> `SYSTEM_ADMIN`, Community moderation, collections, NAS and public deployment
> gates remain incomplete. Keep the stack on localhost with synthetic data.

## Local start

Prerequisites on this workstation are Windows, the existing WSL2 Ubuntu
distribution, Docker Engine and Docker Compose v2. The site binds only to
`127.0.0.1`.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\init-dev-env.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 bootstrap
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 seed
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase0
```

Open `http://localhost:8090` after bootstrap. The generated administrator
credential is stored only in ignored `.env.piwigo`; it is never printed or
committed. `bootstrap` is idempotent and `test-phase0` is safe to repeat (it
rotates transient fixture-account password hashes on each run). Treat
`seed` as a clean-stack fixture operation; do not use it after adding
non-synthetic content.

Useful commands:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 baseline-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 extensions-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-access
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
