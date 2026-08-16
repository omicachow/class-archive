# NAS deployment and photo-library coexistence

Status: **production design draft, not a completed NAS acceptance test**

Applies to: Class Archive's Piwigo-first architecture spike
Last reviewed: 2026-08-16

This guide is vendor-neutral. The UGREEN appendix records currently available
vendor documentation, but no real UGREEN device, UGOS release, reverse proxy,
or UGREEN Photos library has been changed or tested by this project.

## Evidence labels

- **Official fact** — behavior stated by Piwigo, Docker, MariaDB, or the NAS
  vendor, or visible in the pinned upstream source.
- **Repository fact** — behavior present in this repository and locally tested
  on the Windows/WSL development host.
- **Engineering recommendation** — the proposed production policy. It remains
  a deployment gate until it passes a restore drill and target-NAS tests.

These labels are important because a NAS vendor can change filesystem paths,
container permissions, Photos indexing behavior, or reverse-proxy features in
an UGOS update.

## Deployment decision

Use the standard Compose stack in `infra/docker-compose.yml` with Piwigo and
MariaDB as separate containers. Persist every durable data class outside the
container layers. Keep the application port on loopback and place a same-host
HTTPS reverse proxy in front of it for production.

For coexistence with an existing NAS photo application, the preferred design
is:

1. Create a **dedicated Class Archive shared original master**, separate from
   personal photo libraries.
2. Make **Piwigo the sole writer and path authority** for that master.
3. Allow the NAS photo application to index it only after a real-device test
   proves that the application can be read-only and will not rename, move,
   delete, rewrite metadata, or create sidecar files in the master.
4. Keep Piwigo derivatives in a separate disposable directory. Derivatives are
   expected duplicates optimized for web delivery; originals remain singular.

This is a conditional recommendation, not a claim that UGREEN Photos currently
honors a read-only mount. If read-only indexing cannot be proved, keep the two
libraries isolated and accept a one-way copy/import boundary.

## Capacity and platform preflight

### Architecture

**Repository fact:** registry manifests for the locked Piwigo `16.4.0a` image
and MariaDB `11.8.8` image contain both `linux/amd64` and `linux/arm64` variants.
The development spike ran only the amd64 variants; arm64 is not runtime-tested.

Recheck the manifests before every deployment because a tag can be republished:

```sh
docker manifest inspect piwigo/piwigo:16.4.0a
docker manifest inspect mariadb:11.8.8
uname -m
```

Use the immutable image references in `.env.example`, not floating `latest`
tags. If the NAS reports `aarch64`, require an `arm64` manifest for every image
and test every plugin on that device before importing real data.

### CPU, memory, and storage

Piwigo does not publish a single fixed CPU/RAM minimum for this workload in the
official Docker guide. The following numbers are therefore **engineering
recommendations**, sized for fewer than 200 accounts, very low concurrency, and
less than 200 GB of originals:

| Resource | Initial baseline | Comfortable target | Reason |
| --- | ---: | ---: | --- |
| CPU | 2 modern cores | 4 cores | Derivative generation is bursty and CPU-bound |
| RAM | 4 GB total system memory available to the stack | 8 GB | Leaves headroom for MariaDB, image decoding, NAS services, and backup compression |
| Network | 1 GbE LAN | 2.5 GbE where available | Helps initial ingest and restore; home uplink remains the external bottleneck |
| Free storage | Originals plus working data plus one local recovery bundle, with at least 20% filesystem headroom | Additional off-device copy | Derivatives and backups grow independently of originals |

Measure derivative size using a representative sample before allocating the
final volume. Do not assume a RAID array, snapshot, recycle bin, or the backup
volume is an independent backup.

### Required host capabilities

- Linux container runtime with Compose v2.
- A NAS model whose vendor explicitly supports Docker/Compose.
- A filesystem that preserves Unix ownership/ACLs and stable paths.
- A mechanism for scheduled jobs, or a small management container dedicated to
  them.
- A reverse proxy capable of HTTPS and forwarding the original client/protocol
  headers.
- Off-device backup storage.

## Persistent data map

The repository Compose file uses named volumes so local development does not
depend on host paths. On a NAS, bind mounts to dedicated shared folders are
usually easier to inspect, snapshot, and restore. Use one approach consistently;
do not point a named volume and a bind mount at the same live dataset.

| Data class | Container path | Repository volume | Durable? | Backup policy |
| --- | --- | --- | --- | --- |
| Piwigo application/config/plugins/themes | `/var/www/html/piwigo` | `class_archive_piwigo_data` | Yes | Back up with database |
| Uploaded originals | `/var/www/html/piwigo/upload` | `class_archive_piwigo_uploads` | Yes | Required |
| Filesystem-synchronized originals | `/var/www/html/piwigo/galleries` | `class_archive_piwigo_galleries` | Yes | Required when used |
| Generated previews/thumbnails | `/var/www/html/piwigo/_data/i` | `class_archive_piwigo_derivatives` | Rebuildable | Optional; normally regenerate |
| MariaDB data | `/var/lib/mysql` | `class_archive_piwigo_db` | Yes | Logical dump plus tested restore |
| Container startup scripts | `/usr/local/bin/scripts` | `class_archive_piwigo_scripts` | Reproducible | Back up only if deployment-local scripts exist |
| Recovery bundles | `/backup` | `class_archive_piwigo_backups` | Yes, but not independent | Replicate off-device |

For a NAS bind-mount deployment, use a stable layout such as:

```text
/srv/class-archive/
├── deployment/        # compose files; .env.piwigo is secret
├── app/               # Piwigo persistent application tree
├── uploads/           # Piwigo-managed uploaded originals
├── galleries/         # filesystem-synchronized originals, if used
├── derivatives/       # disposable thumbnails/previews
├── database/          # MariaDB files; never copy while live
├── scripts/           # reviewed startup/maintenance scripts
└── backups/           # local staging only; replicate elsewhere
```

Do not store `.env.piwigo` inside a Photos-indexed folder. Keep it mode `0600`,
include it only in an encrypted recovery set, and never regenerate its keyed
secrets against an existing database. It contains database/derivation secrets
and non-sensitive administrator username/email, never the raw SYSTEM_ADMIN
password. Store that password in an external password manager and provision it
through a reviewed no-echo/staged-secret bootstrap; production Admin MFA remains
a separate deployment gate.

## UID, GID, and ACL policy

**Official fact:** the Piwigo image accepts `PIWIGO_UID` and `PIWIGO_GID`. Its
startup script applies ACLs recursively below `/var/www/html/piwigo` and then
uses `find ... -exec chown` for every entry whose owner/group differs. This is
visible in the pinned [Piwigo Docker init script](https://github.com/Piwigo/piwigo-docker/blob/972d7eabaff0c1c22746c291bc24cb1367077c90/config/init-script.sh#L40-L52).

Before first start:

1. Create a dedicated unprivileged NAS service account for Class Archive.
2. Record its numeric IDs with `id class-archive` (or the vendor UI equivalent).
3. Set `PIWIGO_UID` and `PIWIGO_GID` to those numeric IDs.
4. Grant that account read/write/traverse access only to Class Archive folders.
5. Grant a NAS photo indexer read/traverse access only after its behavior is
   proven safe.
6. Keep MariaDB's directory under MariaDB's own ownership; do not recursively
   assign it to the Piwigo account.
7. Never solve permission failures with world-writable `0777`.

**Repository fact:** the bootstrap now installs a reviewed
`/usr/local/bin/scripts/user.sh` hook which removes all `other` permissions from
`upload`, `galleries` and `_data`, preserves the explicit nginx ACL, and sets a
private default ACL. Directories grant nginx `rwx`; files grant nginx `rw-` and
are not executable. The PHP-FPM wrapper also sets umask `0007`, so files created
by normal request-time library code remain other-denied. These controls have
passed a container restart plus recursive, request-generated and new-file
`MEDIA_PERMISSIONS=PASS` checks. A NAS bind mount still needs a host-side ACL
test; container ACL success does not prove the NAS share is private. Umask does
not override a component that explicitly calls `chmod(0644)`: Community upload
cannot be enabled until the completed original is normalized to `0660` and that
path passes a real upload/restart/NAS ACL regression.

### Recursive ownership trap

Do **not** bind an existing 200 GB photo tree directly anywhere below
`/var/www/html/piwigo` and start the official image without a synthetic-scale
test. The entrypoint can traverse and change ownership across the whole tree on
every container start. On a shared NAS library this can be slow and can break
the other photo application's expectations.

The production gate is to measure cold and warm restarts against a disposable
tree with the same file count and ACL pattern. If traversal remains material,
mount the original master outside the Piwigo web root and use a reviewed thin
container/entrypoint adapter or stable symlink boundary. That adapter may change
container wiring, but it must not patch Piwigo Core. Include both upstream
recursive ownership/ACL work and the Class Archive hook's directory/file ACL
passes in the measurement; the current image walks media trees more than once.

## Standard Compose deployment

The following flow is vendor-neutral. NAS GUI wording may differ.

1. Clone or copy a reviewed release of this repository into the deployment
   directory.
2. Verify the Git commit, `infra/piwigo-extensions.lock.json`, and image digests.
3. Copy `.env.example` to `.env.piwigo`, replace every generated placeholder
   with a strong unique value, set the numeric UID/GID, and do not add an
   administrator password variable.
4. Keep the app port bound to `127.0.0.1`. Do not publish MariaDB.
5. Validate Compose before pulling or starting:

   ```sh
   docker compose --env-file .env.piwigo \
     -f infra/docker-compose.yml config --quiet
   ```

6. Start the database and application:

   ```sh
   docker compose --env-file .env.piwigo \
     -f infra/docker-compose.yml pull
   docker compose --env-file .env.piwigo \
     -f infra/docker-compose.yml up -d
   docker compose --env-file .env.piwigo \
     -f infra/docker-compose.yml ps
   ```

7. Complete the locked application bootstrap and private-access baseline using
   the project runbook. Do not install arbitrary plugins from the web UI.
8. Verify that no guest, Family account, or direct media URL crosses its access
   boundary before importing real photographs.

If bind mounts are selected, implement them in a deployment-specific Compose
override and validate the merged result with `docker compose config`. A Compose
project imported through a NAS GUI must resolve the same absolute paths after a
reboot; GUI-generated temporary paths are not acceptable.

## Cron and background work

Prefer the host's scheduled-task facility or a dedicated maintenance container
over installing ad-hoc cron state inside the application container.

Schedule at least:

- nightly database/files backup staging;
- off-device replication and checksum verification;
- periodic restore drills;
- future Class Archive Spotlight expiry and invitation cleanup jobs.

The Class Archive plugin command for Spotlight expiry does not exist yet. When
implemented, it must be idempotent, retryable, locked against overlapping runs,
and tested with a missed schedule. Do not invent a cron command before that CLI
entry point is shipped.

For backup scheduling, call a reviewed host wrapper that records whether Piwigo
was running, quiesces it only when necessary, runs the repository's `ops`
backup service, restores the prior run state even on failure, and then copies
the completed bundle off-device. Keep the database running while its logical
dump is taken. The current tested wrapper is PowerShell/WSL-specific; a POSIX
NAS wrapper and cron entry have not shipped yet.

## Backup policy

The repository backup service deliberately refuses to run unless the caller
sets `CLASS_ARCHIVE_BACKUP_QUIESCED=true`. It dumps with
`--lock-all-tables` because Piwigo or mature plugins may contain MyISAM tables;
an InnoDB-only `--single-transaction` assumption is not sufficient. The dump
process must succeed before compression begins (not through a pipeline whose
failure could be hidden), all payloads are built in a `.partial` directory, checksums
and a `COMPLETE` marker are verified, then the directory is atomically renamed
into its published name. The data service holds a lock directory in the backup
volume, and the Windows helper holds an exclusive host file before it inspects
or stops services, so overlapping local backups fail closed. A future POSIX NAS
wrapper must acquire its host lock before checking Piwigo state.

The current automated output is a **local data bundle**, not the complete
recovery point. It contains the database, Piwigo application tree, uploads,
galleries, checksums and completion marker. A complete recovery point contains
that bundle plus:

- compressed logical MariaDB dump;
- Piwigo application/config/plugin/theme tree;
- uploaded originals;
- synchronized-gallery originals, if Class Archive owns them;
- exact release/lock files and image digests;
- any reviewed NAS Compose override and deployment-local scripts;
- encrypted `.env.piwigo` recovery copy;
- SHA-256 manifest.

The whole recovery set—not only `.env.piwigo`—must be encrypted in transit and
at rest because it contains photos, account/anonymous mapping data and a Piwigo
database credential inside the application config. Derivatives can be omitted
to save space because they are rebuildable. If they
are retained to shorten recovery time, treat them as a cache and never restore
them in place of originals.

The supported local command is currently:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 backup
```

Do not copy this Windows wrapper into NAS cron. A POSIX wrapper must preserve
the prior run state and propagate both backup and restart failure before NAS
deployment is accepted. The named backup volume also lacks a shipped off-device
export command; the NAS override should bind `/backup` to a dedicated encrypted
staging directory or provide a reviewed exporter. Replicate the complete
recovery set to a different device/account, verify `SHA256SUMS` after transfer,
and retain multiple dated generations. Test a restore into isolated empty
volumes at least quarterly and before every upgrade.

## Restore drill

Never overwrite the only live copy during a restore test.

1. Isolate an empty Compose project and fresh volumes/directories.
2. Verify every archive against `SHA256SUMS`.
3. Restore the exact pinned repository release and encrypted environment file.
4. Restore the Piwigo application tree, uploads, and galleries with their
   original relative paths and intended UID/GID.
5. Start MariaDB alone and import the logical dump into the named database.
6. Leave derivatives empty initially; let Piwigo regenerate them.
7. Start Piwigo and check health, Core/plugin versions, album/image counts,
   sample EXIF, comments, collections, Identity/Seat links, and audit rows.
8. Run the complete role/era access matrix, including known direct original and
   derivative URLs.
9. Download several originals and compare hashes with the recovery source.
10. Record elapsed time and any manual step. A backup is not accepted until this
    drill succeeds.

If the live database and application tree have already advanced, rollback must
restore them **as one recovery point**. Never pair an older database with newer
plugin files or attempt a Core downgrade against a migrated live database.

## Upgrade procedure

1. Read Piwigo and every enabled plugin's release/compatibility notes.
2. Update locks and image digests in a branch; never change a running NAS first.
3. Restore the latest production backup into an isolated test stack.
4. Run the upgrade there, then run role/era, submission, comments, Collections,
   direct-media, thumbnail, mobile viewer, and backup/restore tests.
5. Measure official-image startup traversal on the representative library.
6. Schedule maintenance, stop writes, and take a final coherent backup.
7. Upgrade production with the exact tested artifacts.
8. Re-run smoke and authorization tests before reopening access.
9. Roll back only by restoring the pre-upgrade database and files together.

The official image copies a newer Piwigo release into the persistent
application tree at startup, so restoring only the old container tag is not a
complete rollback.

## HTTPS reverse proxy

Keep `127.0.0.1:${CLASS_ARCHIVE_HTTP_PORT}:80` as the only published Piwigo
socket and run the reverse proxy on the same NAS. MariaDB remains on the private
Compose network with no host port.

The proxy must:

- terminate TLS with a valid certificate;
- redirect HTTP to HTTPS only after HTTPS is verified;
- forward `Host`, client IP, and protocol headers;
- set an upload body limit and timeouts that match Class Archive settings;
- deny dotfiles, recovery bundles, environment files, and internal scripts;
- preserve secure cookies and CSRF behavior;
- log without query-string secrets or invitation tokens;
- enforce the media policy described below.

Do not expose the stack publicly until the domain, certificate, proxy trust,
rate limits, and direct-media tests pass. The development localhost binding is
not a production HTTPS configuration.

## Direct-media authorization gate

Piwigo's normal pages enforce album access, but a private album policy must also
cover the underlying files and generated derivatives.

**Official source observation:** Piwigo's derivative endpoint starts with a
fast bootstrap described as having no database connection, later reads image
and derivative data, and serves a generated file, but it does not establish the
current user's album/era authorization in that path. See
[`i.php`](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/i.php#L8-L16).
Static files exposed directly by nginx can bypass application authorization
entirely. Therefore a private album page returning 403 is not enough evidence.

Before production, all originals, previews, and thumbnails need one of these
reviewed controls:

- a Class Archive authorization endpoint that checks Account, Seat, album, and
  HERITAGE/LIVING policy, then issues short-lived signed media URLs; or
- a reverse-proxy authorization subrequest followed by internal file delivery
  (`X-Accel-Redirect` or an equivalent), with storage paths unreachable
  directly.

The implementation must bind the authorization to the permitted media variant
and expiry; prevent path traversal; avoid signatures in logs/referrers; and
support immediate account/session revocation. A CDN must not be introduced in
V1.

Required negative tests:

- guest requests a known original and derivative URL;
- FAMILY requests a captured LIVING original and derivative URL;
- disabled ANONYMOUS Seat reuses an old media URL;
- expired signature is replayed;
- direct `/upload`, `/galleries`, and `/_data/i` paths are requested;
- a symlink path is substituted for an allowed path.

All must fail closed. This gate is unresolved in the current spike and blocks a
public deployment.

## Photo-directory coexistence

### V1 original-root mapping

The local Compose file still uses named volumes and does **not** yet expose an
UGREEN-indexable master. The proposed NAS override must bind one dedicated
shared folder such as `/srv/class-archive/originals-upload` to Piwigo's
`/var/www/html/piwigo/upload`; Piwigo's upload path is the sole V1 ingestion
writer, and UGREEN Photos may scan only the host folder with read-only rights.
The Piwigo `galleries` root remains separate and empty in V1. It is introduced
only for a later immutable external-source synchronization adapter with a mount
sentinel and deletion review. Derivatives remain outside both original roots.

This mapping is a design decision, not implemented NAS infrastructure. It must
pass the official-image ownership traversal, UGREEN scan, Era side-door and
restore gates before real photos move into it.

### Route 1 — dedicated shared master, Piwigo sole writer (preferred)

Use a new shared folder exclusively for Class Archive originals. Piwigo owns
all writes and path changes. The NAS Photos application may index it only if a
real-device test proves:

- it can index a shared folder rather than requiring a personal-home library;
- its account has read/traverse but no write permission;
- indexing, face analysis, thumbnailing, and upgrades do not mutate originals
  or create required sidecars in the master;
- deleting or editing an item in the Photos UI cannot affect the master;
- Piwigo uploads and renames remain visible without a full destructive rescan;
- indexing does not expose LIVING files to a FAMILY NAS account outside Class
  Archive's own authorization layer.

The last point is fundamental: UGREEN Photos and Class Archive have independent
identity systems. A NAS-level shared library can bypass HERITAGE/LIVING access
even if Piwigo is correct. If UGREEN Photos cannot reproduce those rules, its
index must be restricted to NAS administrators or to a non-sensitive subset.

### Route 2 — read-only external source plus symlink and filesystem sync

For an existing, immutable archival subset:

1. Mount the source read-only outside `/var/www/html/piwigo`, at a stable path
   such as `/archive-source`.
2. Create a controlled symlink below Piwigo's `galleries` tree pointing to the
   source. Keep the symlink creation in reviewed deployment code so it survives
   container replacement.
3. Run Piwigo filesystem synchronization in **simulation** mode first, reduced
   to the intended album/subtree.
4. Compare new/deleted counts, then run the real synchronization.
5. Generate derivatives into the dedicated derivatives volume, never beside
   the original files.

Piwigo officially documents filesystem import under `galleries`, directory and
filename restrictions, simulation, reduced-scope sync, and the need to
resynchronize after add/rename/move/delete operations in its
[FTP/filesystem synchronization guide](https://doc.piwigo.org/self-hosting-piwigo/importing-and-synchronizing-ftp-photos).
That documented filesystem mode restricts directory/file names to letters,
numbers, `-`, `_`, and `.`. Do not rename a real archive destructively to meet
that rule. Prefer the Piwigo-managed upload route, or build a reversible stable
shadow namespace and prove its mapping and recovery behavior first.
Current Core rejects remote HTTP gallery sites, so this route requires a local
filesystem mount; see the
[site manager source](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/admin/site_manager.php#L52-L58).

This route avoids a second original copy, but it is an engineering adapter, not
an officially documented Docker layout. Validate symlink traversal, nginx
serving, derivatives, ACLs, backups, and upgrades using disposable data before
adoption.

### Path stability and deletion semantics

Filesystem synchronization treats the relative path as identity. Renaming or
moving a source file can appear as deletion plus addition, not a harmless
metadata update. When a database image is missing from the filesystem, Core
calls `delete_elements`; this removes the image row and its comments, tags,
favorites, rates, and album relationships. See the
[sync deletion path](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/admin/site_update.php#L715-L733)
and
[`delete_elements`](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/admin/include/functions.php#L233-L314).

Consequently:

- never run a real sync when the external mount is absent or partially mounted;
- require a mount sentinel and expected file-count threshold before sync;
- use simulation and human review for deletion counts above zero;
- forbid the other photo application from renaming/moving/deleting the source;
- keep source paths stable across NAS migration and restore.

### Routes not preferred

- **Two-way synchronization:** not acceptable for the original master. Vendor
  guidance notes that two-way/mirror deletion can propagate; it also creates
  two writers with conflicting metadata and path semantics.
- **Hardlinks:** save blocks only on one filesystem, but both paths share the
  same inode. A writer can still modify the other's original, and unlink/rename
  behavior is easy to misunderstand. Use only in an isolated, tested import
  pipeline, not as the default master.
- **Direct shared-folder bind below Piwigo root:** rejected until the recursive
  ownership/startup cost and ACL mutation are measured.
- **Remote HTTP index:** not supported by current Piwigo Core's site manager.
- **Independent duplicate library:** operationally safest fallback when the NAS
  Photos application cannot index read-only. It costs storage but preserves
  clear ownership and authorization boundaries.

## Local-to-NAS migration

1. Finish a local restore drill and record Core/plugin/database versions,
   volume sizes, image/album/user counts, and source hashes.
2. Confirm the target NAS CPU architecture and Compose support.
3. Create empty target directories and service accounts; do not point at an
   existing Photos library.
4. Stop local writes and create a final coherent backup bundle.
5. Transfer the bundle and encrypted environment recovery file over an
   authenticated channel; verify SHA-256 values on the NAS.
6. Deploy the exact same Git release and image digests into an isolated NAS
   project.
7. Restore database and files, initially without derivatives.
8. Start on loopback/LAN-only access and run the complete restore and access
   acceptance tests.
9. Measure derivative generation, restart traversal, CPU/RAM use, and backup
   duration with synthetic or already authorized data.
10. Configure HTTPS and the signed media policy; re-run direct-media tests.
11. Only then evaluate UGREEN Photos indexing against a disposable copy of the
    proposed shared master.
12. Keep the local recovery point until the NAS has passed at least one full
    backup/restore cycle.

## UGREEN / 绿联 appendix

This appendix does not override the vendor-neutral procedure.

### What current vendor material supports

- UGREEN documents Docker as an UGOS Pro capability in its
  [Knowledge Center](https://support.ugnas.com/detail/article/en-US/59).
- Its official walkthrough shows a Compose-style project being created through
  the Docker application's **Project** interface in
  [Set Up a Transmission Downloader Using Docker](https://support.ugnas.com/detail/article/en-US/364).
- A UGREEN support article describes mounting shared folders for containers;
  personal folders are a different boundary. Verify the exact UI and paths on
  the target release before deployment:
  [shared-folder mount guidance](https://support.ugnas.com/detail/article/fr-FR/611).
- UGREEN has published updates concerning the Photos shared library and AI
  resource use. These establish product direction, not a guarantee of the
  read-only behavior Class Archive needs:
  [Photos shared library update](https://www.ugnas.com/news-detail/id-33.html)
  and [AI resource note](https://www.ugnas.com/play-detail/id-98.html).
- A vendor-community FAQ discusses scanning a custom shared folder:
  [custom shared-folder scanning](https://club.ugnas.com/thread-64-1-1.html).
  Treat this as a lead for real-device validation, not as a stable API contract.

Docker/Compose availability varies by model and UGOS version. Verify the actual
model before purchase or migration; do not infer support from another DXP/DH
model.

### UGREEN acceptance checklist

- [ ] Docker application exposes Project/Compose on this exact model and UGOS
      version.
- [ ] Both locked images pull for the NAS architecture without emulation.
- [ ] Absolute shared-folder paths remain unchanged after reboot and UGOS
      update.
- [ ] Numeric UID/GID and ACLs survive reboot, snapshot restore, and container
      recreation.
- [ ] Reverse proxy can reach the loopback-bound application without publishing
      Piwigo directly to the LAN/WAN.
- [ ] Scheduled backup wrapper runs while the NAS is unattended and always
      restarts Piwigo.
- [ ] UGREEN Photos indexes the disposable shared master with a read-only
      account.
- [ ] Photos indexing and AI analysis do not alter originals, EXIF, timestamps,
      filenames, paths, ACLs, or sidecar requirements.
- [ ] Photos UI delete/edit cannot mutate the master.
- [ ] UGREEN Photos does not give Family users a side door into LIVING media.
- [ ] Piwigo restart does not recursively mutate or stall on the shared master.
- [ ] Direct original/preview/thumbnail URL tests fail closed for unauthorized
      roles.
- [ ] Full restore to empty directories succeeds and hashes match.

Until every applicable item passes, the NAS deployment remains a private test
environment, not production.

## Primary upstream references

- [Piwigo Docker repository and deployment model](https://github.com/Piwigo/piwigo-docker)
- [Piwigo filesystem import and synchronization](https://doc.piwigo.org/self-hosting-piwigo/importing-and-synchronizing-ftp-photos)
- [Piwigo server migration guide](https://doc.piwigo.org/self-hosting-piwigo/move-your-piwigo-to-another-server)
- [Pinned Piwigo Docker startup ownership behavior](https://github.com/Piwigo/piwigo-docker/blob/972d7eabaff0c1c22746c291bc24cb1367077c90/config/init-script.sh)
- [Pinned Piwigo synchronization deletion behavior](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/admin/site_update.php#L715-L733)
- [Pinned Piwigo media derivative endpoint](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/i.php#L8-L16)
- [UGREEN UGOS Pro overview](https://support.ugnas.com/detail/article/en-US/59)
- [UGREEN Docker Project example](https://support.ugnas.com/detail/article/en-US/364)
