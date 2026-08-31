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
only once through the already-authenticated broker control pipe. The broker
validates its private 0600 recovery document, emits one bounded base64 record
to its redirected parent pipe, and the wrapper immediately writes it to an
ignored, owner-only 0600 host file. It does not use a second `docker exec` or
`docker cp` transport. Passwords, usernames, paths, and the export record
never appear in terminal output.

Opening order is fail-closed:

1. create the exclusive 0600 credential/recovery plan;
2. install each temporary verifier with an exact user/topology CAS while the
   Identity is still frozen;
3. revoke credentials and append `PRINCIPAL_SECURITY_CHANGE` audit events;
4. unfreeze the Identity as the final opening action.

EOF, `STOP`, timeout, a handled signal, or any exception enters the same
cleanup. Cleanup freezes first, increments principal authorization epochs and
revokes sessions, then replaces only verifiers whose digest proves they were
installed by this run. An administrator's concurrent verifier is preserved and
the lease becomes `CONFLICT`; expired/conflicted access stays denied by Access
and MediaGuard. The recovery plan is retained until exact `CLOSED` or
`RECOVERED` attestation in a dedicated non-web-served volume that survives a
Piwigo container restart. Open and close/recovery security changes each append
`PRINCIPAL_SECURITY_CHANGE` evidence. Audit rows are retained. No identity, seat, account, token,
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

## Runtime gate

The durable lease, administrator mutation guard, HTTP authorization denial,
password CAS and independent TTL watchdog are exercised first against random
synthetic tables. Owner execution remains separately opt-in and requires:

```powershell
pwsh.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass `
  -File .\tests\phase3\photos-app-v4-owner-browser-qa.ps1 `
  -ConfirmFqaCredentialLease
```

This gate remains deliberately narrow: it does not create fixtures and does not
test Teacher or owner-private uploads. Those remain separate gates and must not
be reported as passing from this run.
