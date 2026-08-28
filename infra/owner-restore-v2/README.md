# Owner independent restore v2

This directory defines the second-physical-medium recovery runtime. It accepts
only a verified `owner-full-v2-*` backup below the fixed independent recovery
root on the system drive, restores into a new ext4 image below the dedicated
v2 restore root on the external recovery drive, and publishes only the loopback
listeners `127.0.0.1:8390` and `127.0.0.1:8391`.

The v2 runtime has its own Compose projects, gateway network, subnets, volumes,
labels, Git evidence, nginx configuration and state files. It never reuses the
owner runtime or the earlier recovery drill. The ext4 image preserves numeric
owners, modes, ACLs, xattrs and links that exFAT cannot represent directly.

The recovery secret contract is portable: `recovery-kit/portable-key-envelope.gpg`
decrypts to the exact `owner-portable-recovery-secrets-v1` JSON schema. The
operator supplies its passphrase through an owner-only ignored file. Plaintext
is created only on local NTFS, validated, consumed, and removed in `finally`.
No plaintext secret is accepted in command-line arguments, environment
templates, logs or committed files.

The lifecycle is intentionally two-phase and fail-closed:

```powershell
# Static/bundle/host checks only. This action creates no runtime or image.
pwsh.exe -NoProfile -File .\infra\scripts\owner-independent-restore-v2.ps1 validate `
  -BackupBundlePath <independent-recovery-root>\bundles\owner-full-v2-YYYYMMDDTHHMMSSZ

# Explicitly create/mount the new external-drive ext4 image. Named volumes are
# created later by the confirmed restore action, after the fresh-runtime gate.
pwsh.exe -NoProfile -File .\infra\scripts\owner-independent-restore-v2.ps1 prepare-storage `
  -BackupBundlePath <independent-recovery-root>\bundles\owner-full-v2-YYYYMMDDTHHMMSSZ `
  -ConfirmCreateRestoreStorage

# Prompt for the portable-envelope passphrase without echo, stream-restore,
# rebuild authorization-neutral projections and keep the surface closed until
# aggregate verification succeeds.
pwsh.exe -NoProfile -File .\infra\scripts\owner-independent-restore-v2.ps1 restore `
  -BackupBundlePath <independent-recovery-root>\bundles\owner-full-v2-YYYYMMDDTHHMMSSZ `
  -ConfirmIsolatedRestore

pwsh.exe -NoProfile -File .\infra\scripts\owner-independent-restore-v2.ps1 verify `
  -BackupBundlePath <independent-recovery-root>\bundles\owner-full-v2-YYYYMMDDTHHMMSSZ
```

`validate` proves the bundle root and all ancestors are ordinary directories,
the manifest and payload hashes match, the source and target are different
physical disks, all configured listeners are loopback-only, and no v2 identity
already exists. `prepare-storage` and `restore` require explicit switches and
an exclusive workflow lock. Runtime actions fingerprint the normal owner and
engineering projects before and after; any change is a hard failure.

The restricted Immich model binaries are not part of the business archive.
Before restore, provision the manifest-matching cache below the independent
recovery root at `rebuildable/immich-model-cache`. The runner verifies the
tracked model manifest byte-for-byte before copying it into the fresh v2 model
volume. A missing or mismatched cache fails closed and never triggers a network
download.

The compatibility listener remains absent while databases, POSIX state,
canonical media, projections and AI indexes are restored. Core stays in
maintenance through all pre-release checks. After maintenance is finalized,
the direct MediaGuard HTTP probe runs before the compatibility BFF is created;
any failure immediately reasserts maintenance. Cold-start verification uses
the same sequence and never calls the indexing finalizer.

This protocol is local recovery evidence only. It does not make the system
production-ready and does not authorize public networking, NAS cutover or real
user invitations.
