# Photos App V4 owner-private FQA lease gate

`tests/phase3/photos-app-v4-owner-browser-qa.ps1` is a local-only acceptance
harness for ports 8190/8191. It leases one historical QA aggregate that was
already frozen after its original test run:

- roster marker: `FQA-C-99CA3B3B6AF1`;
- roles covered: Classmate, Family, Anonymous;
- Teacher is deliberately not covered because the owner runtime has no
  existing FQA/V4 Teacher fixture. The harness never substitutes a Classmate
  for a Teacher and never uses a real Teacher identity.

## Bounded lease

The broker refuses to open unless every expected invariant still holds:

- the exact marker identity is `CLASSMATE/FROZEN`;
- exactly one active Classmate, Family, and Anonymous current account is bound;
- every seat, account, principal, Core status, and managed group is valid;
- no issued token, submission, active pin, unfinished provisioning operation,
  live fixture comment, or active auth key exists;
- ClassIdentity schema attestation and fail-closed enforcement are active;
- exactly one active SYSTEM_ADMIN can author the security audit.

The PowerShell wrapper holds an exclusive ignored host lock. The PHP broker
holds a MariaDB advisory lock for the whole run and has a 15-minute maximum
TTL. Passwords are generated inside the broker and cross the process boundary
only in a one-link 0600 file copied to an ignored, owner-only path. Passwords,
usernames, and paths never appear in stdout.

Opening order is fail-closed:

1. rotate and revoke credentials while the Identity is still frozen;
2. append `PRINCIPAL_SECURITY_CHANGE` audit events;
3. unfreeze the Identity as the final opening action.

EOF, `STOP`, timeout, a handled signal, or any exception enters the same
cleanup. Cleanup freezes first, increments principal authorization epochs and
revokes sessions, then rotates every account to a second unknown secret while
still frozen. Audit rows are retained. No identity, seat, account, token,
content, comment, media, album, or AI record is created or deleted.

The post-run state is security-equivalent rather than byte-identical:
`lock_version`, `auth_epoch`, password hashes, and append-only audit history
advance intentionally.

## Browser boundary

Chrome Stable launches with fresh ignored profiles and localhost-only network
guards. The browser performs only read journeys plus one Family comment request
that must be denied with 403 and leave the payload unchanged. Its successful
content-write count must remain zero.

Classmate and Anonymous must see the same FULL projection. Family must see the
exact HERITAGE_ONLY projection, including safe counts, covers, People, search,
albums, Spotlight, Viewer, and known-LIVING GET/HEAD/Range denial. Anonymous
responses and markup are checked against all three leased usernames as well as
identity/account/seat/principal fields.

## Current runtime gate

The executable lease is deliberately **blocked** in this revision. The broker
advisory lock serializes brokers, but the ordinary administrator mutation path
does not yet participate in that lock. A concurrent administrator could race
the final freeze verifier. The wrapper therefore returns
`lease_runtime_disabled_pending_mutation_exclusion` even when its confirmation
switch is supplied. This is a fail-closed protocol implementation, not 8191
browser evidence. The PHP broker carries the same hard-coded false gate, so a
direct container invocation cannot bypass the wrapper block.

After a lease-aware administrator mutation exclusion/CAS is implemented and
reviewed, the intended local command is:

```powershell
pwsh.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass `
  -File .\tests\phase3\photos-app-v4-owner-browser-qa.ps1 `
  -ConfirmFqaCredentialLease
```

This gate does not test Teacher or owner-private uploads. Those remain separate
gates and must not be reported as passing from this run.
