# Reproducible local first run: Piwigo-first Phase 0

This procedure creates the supported **local engineering spike** on the current
Windows + WSL2 Ubuntu workstation. It is not production approval: the known
media-URL authorization blocker described below must be resolved before any real
photo is imported.

## 1. Data and secret boundary

Two ignored environment files may be present for different preserved runtimes:

- Root **`.env` belongs to the historical HumHub recovery snapshot**. Never
  delete it, overwrite it, load it for Piwigo, or regenerate it. It must continue
  to match the preserved HumHub volumes.
- **`.env.piwigo` belongs to the supported Piwigo stack**. It contains database
  credentials, the bootstrap administrator password and future Claim/anonymous
  derivation secrets. It is ignored by Git and must be backed up separately in
  encrypted off-device storage.

Never commit either file, print its values, or place a real member list, Claim
Code, invitation token, database dump or photo in Git.

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

The idempotent bootstrap:

1. starts the exact Piwigo and MariaDB image digests;
2. waits for the loopback HTTP endpoint;
3. installs Piwigo Core only when its database configuration is absent;
4. performs one real administrator login so the upstream installer MD5 hash is
   migrated to Piwigo's current phpass representation;
5. installs/verifies only extension archives allowed by the lock;
6. configures the fail-closed baseline and verifies it.

The baseline disables guest gallery access, open registration, guest comments,
ratings and web-UI extension installation; creates private HERITAGE/LIVING root
albums and non-default business groups; and keeps Community/User Collections
inactive. It does not patch Piwigo Core.

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
The administrator credentials are in the ignored `.env.piwigo`; do not paste
them into issue reports or logs.

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

## 6. Run the real access and photo UI gates

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-access
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 test-phase0
```

`test-access` provisions only synthetic role users with a transient random
password and uses the real Piwigo API. Expected output:

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

This verifies album/API discovery and the signed-in UI. It does **not** clear the
known-media security blocker: a separate probe currently gets HTTP 200 for an
already-known LIVING derivative and original URL without a session. Until a
server-side fix and negative regression test land, this stack is synthetic-data
only and must not be exposed publicly.

## 7. Create a quiesced backup

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

## 8. Normal lifecycle commands

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 up
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 logs
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 down
```

`stop` and `down` preserve named volumes. **Never run `docker compose down -v`**
for this project: `-v` deletes the persistent database, originals, application
state and local backups. Never use volume deletion as a routine reset method.

Extension changes must go through the reviewed lock and installer. Do not use
Piwigo's web extension updater in this baseline, do not manually edit files in
the application volume, and do not enable Community or User Collections.

## Current completion boundary

This first run proves a reproducible localhost-only, photo-first Piwigo spike
with 72 synthetic images, group/album visibility, no-copy multi-album placement,
thumbnail-first pages and a mature viewer. It does not yet prove production
media confidentiality, ClassIdentity, Community moderation safety, anonymous
comments, named collections, NAS coexistence, public HTTPS or restore. Those are
explicit later gates, not implied by a healthy container.
