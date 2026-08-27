# Owner full-library isolated restore runtime

This is a recovery-drill runtime, not another owner instance. It restores one
verified `owner-temporary-recovery-v1` bundle into fresh Compose projects,
networks and named volumes. The local Docker daemon is only a shared control
plane; every restore volume is bind-backed inside an ext4 image stored on the
temporary M: target. The ext4 layer is mandatory because exFAT cannot preserve
the 0660, owner/group and link metadata required by MediaGuard.

The restore projects and M-backed volume names are all separate from 8091/8191.
Only `127.0.0.1:8290` and `127.0.0.1:8291` are published. The tool deliberately
has no volume/image cleanup action; recovery evidence is retained for review.

The runtime image is
`<temporary-recovery-target>/runtime/classarchive-owner-restore-v1.ext4`.
Its ext4 filesystem is mounted at `/mnt/classarchive-owner-restore-v1`; restore
volume backing directories live below
`/mnt/classarchive-owner-restore-v1/volumes`. The normal daemon must remain
`/var/lib/docker`, and the runner checks both the volume backing paths and the
unchanged 8191 container fingerprints. A second dockerd in the same WSL network
namespace is explicitly rejected because it can rewrite global iptables and
interrupt the owner runtime.

```powershell
# Read-only bundle and host-capability checks.
pwsh.exe -NoProfile -File .\infra\scripts\owner-full-restore-drill.ps1 validate -BackupBundlePath <verified-bundle>

# One-time M-backed ext4 image mount and control-plane preflight.
pwsh.exe -NoProfile -File .\infra\scripts\owner-full-restore-drill.ps1 prepare-storage -BackupBundlePath <verified-bundle> -ConfirmCreateRestoreStorage

# Stream-decrypt into fresh databases/volumes, then build a new bridge secret.
pwsh.exe -NoProfile -File .\infra\scripts\owner-full-restore-drill.ps1 restore -BackupBundlePath <verified-bundle> -ConfirmIsolatedRestore

# Read-only aggregate/runtime checks. Browser E2E remains a separate gate.
pwsh.exe -NoProfile -File .\infra\scripts\owner-full-restore-drill.ps1 verify -BackupBundlePath <verified-bundle>

# Explicit cold restart: indexes must be immediately reusable, with zero jobs.
pwsh.exe -NoProfile -File .\infra\scripts\owner-full-restore-drill.ps1 cold-restart -BackupBundlePath <verified-bundle> -ConfirmColdRestart

# Repeat the exact aggregate check after the cold restart, before browser QA.
pwsh.exe -NoProfile -File .\infra\scripts\owner-full-restore-drill.ps1 verify -BackupBundlePath <verified-bundle>

# Real Chromium acceptance against the isolated restore projects on 8290/8291.
pwsh.exe -NoProfile -File .\tests\phase3\full-real-browser-qa.ps1 -Mode restore
pwsh.exe -NoProfile -File .\tests\phase3\full-real-family-browser-qa.ps1 -Mode restore
```

## Forward-only restored schema deployment

When a verified restore bundle contains ClassIdentity schema v15 but the
current reviewed checkout requires v16, use the dedicated restore endpoint.
It does not share the retired private-full staging selector even though both
historically used ports 8290/8291.

```powershell
# Read-only identity/schema/topology validation.
pwsh.exe -NoProfile -File .\infra\scripts\deploy-owner-restore-class-plugins.ps1 validate

# Explicit v15 -> v16 migration (v16 is an idempotent no-op deployment).
pwsh.exe -NoProfile -File .\infra\scripts\deploy-owner-restore-class-plugins.ps1 migrate -ConfirmRestoreMigration
```

The migration first closes the restore HTTP surface with the persistent
maintenance marker. Exact v15 receives a database-only rollback snapshot in
the M:-backed restore backup volume; any version other than exact v15/v16 is
rejected. The runner then recreates only restore Piwigo from the current clean
checkout, rebuilds read projections, recreates only the restore compatibility
BFF, and finalizes maintenance. It fingerprints the 8091 synthetic and 8191
owner projects before/after, and leaves the restore runtime fail-closed on any
error. The ignored restore `BuildCommit` HEAD and nginx configuration are
atomically advanced to the current reviewed checkout before Piwigo is
recreated, preventing old-source attestation from being paired with new code.

Encrypted archives are never expanded onto exFAT. The DPAPI recovery payload
is unprotected only by the same Windows user into ignored, owner-only NTFS
temporary files. GPG decrypts directly to the target database or ext4-backed
volume and every temporary plaintext secret is deleted in `finally`.

The bundle keeps its immutable `source_head`. A reviewed recovery-only follow-up
commit is recorded separately as `restore_tool_head`; it must descend from the
source commit through a linear, merge-free history and may change only the exact
recovery-tool allowlist enforced by the runner. Every commit is inspected, so a
forbidden change cannot be hidden by reverting it later. The worktree must
remain clean. Application, plugin, schema, base Compose and shared runtime
changes fail closed instead of being mislabeled as the source snapshot.

The bundle contains the verified ML manifest, not restricted model binaries.
For this local drill, the runner verifies that manifest against the current
private-full model cache, then copies the cache through two `--network none`
containers into the isolated M-backed restore volume. Immich ML remains offline. A
successful aggregate check requires the cold-start runtime to report reused
People/Search indexes, zero Face/Search indexing jobs, and non-empty persisted
search probes.

`verify` is aggregate runtime evidence only. It intentionally reports browser
E2E as not run; Chromium Owner/Family acceptance is a separate required gate.
Run both exact-count `verify` calls before the Family browser suite: that suite
intentionally exercises real claim, invitation, comment and freeze workflows,
so it appends local-only audit/test state after restore equality is proven.

The M: package and runtime remain a **temporary recovery target**, not an
independent disaster backup: the authorized original sources and this drill
still share one physical disk.
