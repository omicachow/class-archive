# Photos App V4 owner-private existing-fixture Chrome gate

`tests/phase3/photos-app-v4-owner-browser-qa.ps1` is a local-only, read-only
product acceptance harness for the full-v3 owner instance on ports 8190/8191.
It uses only the existing bound fixture principals:

- `fixture-classmate`
- `fixture-family`
- `fixture-teacher`
- `fixture-anonymous`

The harness does not create identities, seats, claims, invitations, accounts,
or tokens. It does not upload media, write comments, alter albums, modify AI
state, start/stop containers, or touch the private source folders.

## Credential lifecycle

Execution requires an explicit acknowledgement because even a password
rotation changes owner runtime state:

```powershell
pwsh.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass `
  -File .\tests\phase3\photos-app-v4-owner-browser-qa.ps1 `
  -ConfirmExistingFixtureCredentialRotation
```

The wrapper generates a per-run secret and passes it through an ignored,
owner-only file to the existing `provision-access-users.php` helper. That
helper refuses missing or incorrectly bound principals, stores only Core
password hashes, and revokes existing credentials. In `finally`, the wrapper
rotates all four fixture accounts again to a second unknown random secret,
revokes the Chrome sessions, removes the temporary credential/profile files,
and never prints either secret.

## Browser and privacy boundary

The runner launches installed Google Chrome Stable with Playwright
`channel: 'chrome'`, a fresh ignored profile, disabled extensions/service
workers/background networking, and both process-level and request-level
localhost guards. Real-library screenshots remain only under the ignored
private screenshot root. Stdout is restricted to aggregate stages and a final
record containing assertion/screenshot/photo counts and `writes=0`; it never
contains account credentials, photo identifiers, URLs, page text, filenames,
or screenshot paths.

## Read-only coverage

The browser gate compares the role-scoped timeline, Home, pins, albums,
People, Spotlight, search suggestions/results, album/person details, and
Viewer media paths:

- Classmate, Teacher, and Anonymous must receive the same `FULL` catalog.
- Family must receive the exact `HERITAGE_ONLY` timeline.
- Family responses, counts, covers, People details, search results, viewer,
  known-LIVING GET/HEAD/Range probes, and Spotlight are checked for LIVING
  leakage.
- Anonymous API/HTML is checked for account, identity, seat, principal, and
  fixture-username disclosure.
- Family has no comment composer. One deliberately denied comment request is
  allowed through the network guard, must return 403, and the comments payload
  must remain byte-for-byte equivalent before/after. No successful business
  mutation is permitted.

The gate intentionally performs no upload lifecycle. The synthetic instance
owns upload/browser mutation evidence; in-place private uploads remain blocked
until exact cleanup can restore every immutable projection and audit reference
without risking owner data.

Run the non-mutating contract first:

```powershell
pwsh.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass `
  -File .\tests\phase3\photos-app-v4-owner-browser-qa-protocol.ps1
```
