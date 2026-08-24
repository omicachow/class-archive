# Photo derivative cache warmup

Class Archive precomputes its bounded presentation cache with Piwigo Core. It
does not introduce another resizer, image format, storage identity or media
delivery path.

## Contract

- The command is CLI-only, refuses uid 0, takes no credentials or arbitrary
  filesystem path, and uses a non-blocking single-instance lock.
- The product cache uses only canonical `thumbnail`, `xsmall`, `small`,
  `medium`, `large` and product `preview` profiles. `preview` is Piwigo's
  fixed `XLARGE` profile. Recovery may additionally request Piwigo Core's
  fixed `square` filmstrip profile so its classic picture page is immediately
  healthy after an empty derivative volume is rebuilt. `square` is not a new
  Class Archive API variant, and custom dimensions remain rejected.
- `first-screen` selects at most 48 active canonical photo mappings,
  `covers` selects active Piwigo album cover mappings, and `all` selects all
  active canonical mappings. Mapping/reference drift fails closed.
- `DerivativeImage` computes the canonical target and the protected Core
  derivative generator delegates the operation to `i.php`. The command never
  implements crop, resize, sharpen, watermark or encoding itself.
- A member cache miss is not a generation signal: `GET`, `HEAD` and `Range`
  return a generic 503 and leave `_data/i` unchanged. Nginx has no internal
  request-time generator location. Only this CLI/maintenance path executes the
  protected generator.
- Approved submissions and controlled imports first enqueue a path-free marker
  containing only the canonical photo UUID and its Piwigo image mapping. After
  the business transaction has committed, that administrator/CLI write boundary
  performs one bounded full-profile warm against the exact ACTIVE mapping. A
  successful warm removes the marker; lock contention, drift, timeout or image
  failure preserves APPROVED/import truth and the marker for maintenance retry.
  No Family/member request participates in this path.
- The queue uses one private, empty filesystem lock for enqueue, enumeration,
  completion and quarantine. A crash between temp creation and atomic publish
  leaves only the strict internal name `.pending-<24 lowercase hex>`. On the
  next locked scan, an exact path-free JSON payload is recovered as its
  canonical marker; a partial/malformed but small, regular, single-link,
  trusted-owner temp is moved byte-for-byte to private quarantine. Neither case
  is silently deleted. Any other name, symlink, hardlink, oversized file or
  untrusted owner still fails closed.
- The container startup hook applies that same locked recovery before PHP-FPM
  serves traffic. When a pinned image changes the numeric runtime uid, it
  migrates ownership only after a canonical marker's regular-file type, 0660
  mode, single link, canonical filename and exact path-free JSON payload all
  verify. Thus a verifiable SIGKILL temp cannot permanently block startup, while
  an arbitrary directory entry still does.
- Maintenance isolates an obsolete marker only after separate successful
  queries prove that both its canonical mapping and its Piwigo image row are
  absent. The exact marker is atomically moved to a private quarantine and
  counted in structured output; it is never silently deleted. Database errors,
  ambiguous mappings and generation failures retain the active marker and fail
  closed.
- If Piwigo is missing source dimensions/rotation, the write-side warmer uses
  the native guarded update. Native image-table writes advance the protected
  Piwigo source epoch, so the old catalog generation can no longer be safely
  point-refreshed. Immediate warmup publishes one complete generation; the
  all-scope maintenance command batches every normalization and publishes only
  once after the batch. Family approval and the private QA importer use the
  same explicit write-path rule for newly created native image/category rows.
  A member GET never performs that rebuild.
- Existing fresh files are reused. A second and third run therefore generate
  zero files. Source and generated files must be regular, single-link inodes
  owned by the trusted media-root owner or the active service account and use
  mode 0660. A symlink, hardlink, owner drift or mode drift is unavailable to
  member HTTP and cannot be normalized by that read request.
- The maintenance-only generator receives a fixed minimal environment rather
  than the parent process environment, captures at most 8192 bytes of stderr,
  and is terminated then forcibly killed if its 30-second bound expires. A
  failure retains the durable marker and member delivery stays at generic 503.
- If Piwigo identifies a profile as the same size as its source, maintenance
  uses Piwigo's own `pwg_image` encoder to create a metadata-stripped same-size
  preview. The member request path cannot execute this fallback and never
  substitutes archived original bytes for a preview.
- `--dry-run` performs no resize or permission mutation. `--json` emits only
  aggregate operational data and no source filenames.

The normal maintenance runner warms the bounded first screen and album covers,
then performs a controlled `all` recovery scan over ACTIVE canonical mappings.
That final scan prepares the six product profiles plus Piwigo's fixed square
filmstrip profile. It makes a failed filesystem enqueue or disposable-cache
loss recoverable: fresh files are reused and missing fixed profiles do not
depend on a member request or a marker.
The cost belongs to the scheduled write-side maintenance window, never a member
request.
Operators can also run the command explicitly after an import or an approved
batch:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\warm-photo-cache.ps1 -Scope first-screen -Json
```

```sh
./infra/scripts/warm-photo-cache.sh --scope=all --profiles=thumbnail,xsmall,small,medium,large,preview --json
```

The cache is not an authorization cache. Each product request still performs
fresh ClassIdentity/ClassArchivePolicy authorization and reaches MediaGuard
before nginx serves a protected file. Cache presence therefore cannot grant
access, survive a freeze/revocation as authority, or turn a URL into a bearer
credential.

Read projections have a separate, scoped maintenance command. It supports a
non-mutating dry run and can rebuild one or more aggregates without clearing
the photo catalog or unrelated projections:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\rebuild-photo-read-projection.ps1 -Scope aggregates -Kinds TIMELINE,ALBUMS -DryRun -Json
```

`PHOTO_CATALOG`, `TIMELINE`, `ALBUMS`, `PEOPLE`, `MEMORIES` and `SPOTLIGHT` are durable
MariaDB caches. A missing or stale requested projection fails closed; an HTTP
GET never triggers a whole-library rebuild.

`SPOTLIGHT` persists only the public card shape in separate `FULL` and
`HERITAGE` scopes. Creating, cancelling or expiring a Spotlight, and changing
an album name, description, visibility or cover, invalidates that projection
or rotates the durable native-source epoch before the business write. The
write controller or maintenance runner then performs a bounded Spotlight-only
rebuild. A cached card is also suppressed at its server-side expiry deadline,
without a live-source fallback or a GET-time state mutation.

An interactive archive write uses a narrower path than the maintenance
command. The changed canonical photo UUIDs are looked up directly in Piwigo,
the existing catalog generation is locked, and only those `read_photo` rows are
updated. The catalog digest is then recomputed from the stored row digests and
published in the same MariaDB transaction. Only the aggregate kinds declared
by the mutation dependency map are marked stale and rebuilt. For example, an
archive-date change rebuilds `TIMELINE` and `MEMORIES` but does not rewrite
`ALBUMS`, `PEOPLE`, unrelated `read_photo` rows or any derivative file. A point
lookup/update failure leaves the affected projection stale; it never falls back
to an HTTP-time full scan.

The synthetic-only Runtime acceptance test performs a reversible archive-date
mutation, checks that dependency boundary, restarts the Piwigo/Gateway and
compatibility BFF services, then proves the same projection generations and
derivative inventory are immediately readable:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\read-projection-runtime.ps1 `
  -ConfirmSyntheticMutation -ConfirmServiceRestart
```

The runner mints and revokes an exact short-lived SYSTEM_ADMIN session through
the server-side fixture. It rejects password credential files, never changes a
human administrator password, and writes only aggregate timing/state evidence
below the ignored `.codex-work` directory.
