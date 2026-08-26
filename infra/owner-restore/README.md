# Owner full-library isolated restore runtime

This is a recovery-drill control plane, not another owner instance. It restores
one verified `owner-temporary-recovery-v1` bundle into a second Docker daemon
whose complete data root lives inside an ext4 image stored on the temporary M:
target. The ext4 layer is mandatory because exFAT cannot preserve the 0660,
owner/group and link metadata required by MediaGuard.

The second daemon has its own Unix socket, data root, exec root, PID file,
bridge and address pool. It never reads, writes or removes an 8091/8191 volume.
Only `127.0.0.1:8290` and `127.0.0.1:8291` are published. The tool deliberately
has no volume/image cleanup action; recovery evidence is retained for review.

The runtime image is
`<temporary-recovery-target>\runtime\classarchive-owner-restore-v1.ext4`.
Its ext4 filesystem is mounted at `/mnt/classarchive-owner-restore-v1`; the
second daemon listens only on
`unix:///run/classarchive-owner-restore-v1/docker.sock`. The normal daemon must
remain `/var/lib/docker`, and the runner checks that invariant before and after
the drill.

```powershell
# Read-only bundle and host-capability checks.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-full-restore-drill.ps1 validate -BackupBundlePath <verified-bundle>

# One-time M-backed ext4 image and isolated daemon creation.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-full-restore-drill.ps1 prepare-storage -BackupBundlePath <verified-bundle> -ConfirmCreateRestoreStorage

# Stream-decrypt into fresh databases/volumes, then build a new bridge secret.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-full-restore-drill.ps1 restore -BackupBundlePath <verified-bundle> -ConfirmIsolatedRestore

# Read-only aggregate/runtime checks. Browser E2E remains a separate gate.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-full-restore-drill.ps1 verify -BackupBundlePath <verified-bundle>

# Explicit cold restart: indexes must be immediately reusable, with zero jobs.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-full-restore-drill.ps1 cold-restart -BackupBundlePath <verified-bundle> -ConfirmColdRestart
```

Encrypted archives are never expanded onto exFAT. The DPAPI recovery payload
is unprotected only by the same Windows user into ignored, owner-only NTFS
temporary files. GPG decrypts directly to the target database or ext4-backed
volume and every temporary plaintext secret is deleted in `finally`.

The bundle contains the verified ML manifest, not restricted model binaries.
For this local drill, the runner verifies that manifest against the current
private-full model cache, then copies the cache through two `--network none`
containers into the isolated restore volume. Immich ML remains offline. A
successful aggregate check requires the cold-start runtime to report reused
People/Search indexes, zero Face/Search indexing jobs, and non-empty persisted
search probes.

`verify` is aggregate runtime evidence only. It intentionally reports browser
E2E as not run; Chromium Owner/Family acceptance is a separate required gate.

The M: package and runtime remain a **temporary recovery target**, not an
independent disaster backup: the authorized original sources and this drill
still share one physical disk.
