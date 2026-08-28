# Owner v2 portable recovery

Owner full backup v2 retains the established POSIX tar plus GnuPG AES-256
archive format and the Windows `CurrentUser` DPAPI envelope used by v1. It adds
a second, independent recovery envelope so loss of the creating Windows profile
does not make the backup unusable.

## Envelope model

The backup publisher still generates a random 512-bit archive passphrase. That
passphrase encrypts database dumps and POSIX tar streams using GnuPG AES-256,
iterated salted S2K mode 3, SHA-512, and the fixed reviewed S2K count. The same
archive passphrase and the three identity-critical recovery secrets are placed
in a small JSON payload and encrypted again with an owner-entered portable
recovery phrase using the same mature GnuPG mechanism.

No custom cipher, direct password hashing, or home-grown KDF is used. The
portable recovery phrase is entered twice through `ReadLineAsSecureString`; it
is never accepted as a command-line argument, environment variable, manifest
field, or persistent configuration value. Plaintext scratch files receive the
existing owner/SYSTEM/Administrators-only ACL and are removed on success or
failure. The portable verification path does not read the DPAPI envelope.

## Creating v2

Use the normal owner backup command with the additional switch:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-temporary-backup.ps1 `
  backup `
  -TargetRoot <temporary-recovery-root> `
  -ProtectedSourceRootPath <source-a>,<source-b> `
  -ConfirmOwnerTemporaryBackup `
  -AcceptSameDiskTemporaryRecoveryLimitation `
  -CreatePortableRecoveryEnvelope
```

The command prompts twice without echo. It refuses a short/common phrase and
publishes only after the root checksums, GPG payloads, DPAPI envelope, portable
envelope, and kit checksums all pass. Published IDs use
`owner-full-v2-YYYYMMDDTHHMMSSZ`; v1 IDs and verification remain supported and
are never rewritten in place.

Run the independent portable check with:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\owner-temporary-backup.ps1 `
  verify-portable `
  -TargetRoot <temporary-recovery-root> `
  -ProtectedSourceRootPath <source-a>,<source-b> `
  -BackupId owner-full-v2-YYYYMMDDTHHMMSSZ
```

This action prompts once, decrypts the portable envelope, authenticates every
encrypted archive with the recovered archive passphrase, and reports
`dpapi_used=NO`. It removes the temporary plaintext payload and passphrase file
before returning.

## Recovery kit contract

Every v2 bundle includes:

```text
recovery-kit/
  README-PORTABLE-RESTORE.txt
  manifest.json
  checksums.sha256
  restore.ps1
  restore.sh
  container-lock.json
  migration-info.json
  ml-artifact-manifest.json
  portable-key-envelope.gpg
```

The root `SHA256SUMS` covers every recovery-kit file, including the kit checksum
file. `recovery-kit/checksums.sha256` covers the other eight files and excludes
itself to avoid a self-referential digest. The decrypted payload format is
`owner-portable-recovery-secrets-v1`; recovery tools validate its bundle ID,
scope, version, and exact key inventory before accepting it.

The kit `restore.ps1` and `restore.sh` scripts write the decrypted payload only
to an explicit new private path outside the backup. The restore orchestrator
must consume and delete that path immediately. Neither script restores into an
existing runtime or chooses a destination volume by itself.

## Operational limits

A portable envelope removes the Windows-profile dependency; it does not make a
same-disk copy an offsite or 3-2-1 backup. The owner must keep the recovery
phrase separately from both backup media. Loss of both the phrase and the DPAPI
profile is intentionally unrecoverable. Restricted ML model binaries remain
excluded; their reviewed manifest, revision, hashes, and rebuild procedure are
included instead.
