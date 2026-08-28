Class Archive Owner Full Backup v2 - Portable Recovery Kit

This kit provides a recovery path that does not use Windows DPAPI and does not
depend on the Windows profile that created the backup. The encrypted envelope
contains the archive data key and the Class Archive secrets that must remain
stable across recovery. It is protected by GnuPG AES-256 with iterated salted
S2K using SHA-512.

The recovery phrase is held only by the owner. It is not contained in this kit,
the bundle manifest, Git, logs, or environment variables. Losing both the
phrase and the original Windows DPAPI profile makes the encrypted data
unrecoverable.

Before recovery, verify the bundle and recovery-kit checksums. Then run one of:

  PowerShell:
    ./restore.ps1 -BundlePath <bundle> -OutputSecretPath <private-empty-path>

  Linux:
    ./restore.sh <bundle> <private-empty-path>

Both commands prompt without echo and write a restricted temporary JSON secret
payload for the restore orchestrator. Delete that plaintext file immediately
after the restore process has consumed it. Never place it on exFAT, in Git, in
a transcript, or in an environment variable. The scripts print only a safe
PASS/FAIL status and never print secret values.

This local kit is not an offsite backup and is not a complete 3-2-1 strategy.
