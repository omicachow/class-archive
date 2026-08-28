# V4 FULL / HERITAGE_ONLY projection browser gate

`tests/phase3/photos-app-v4-chrome-scope-projection.ps1` is a synthetic-only
Google Chrome Stable acceptance gate for the V4 read surface. It is separate
from the normal V4 navigation/search, Viewer, and Era-upload runners so that
scope evidence remains easy to audit.

It is intentionally **not** a MediaGuard GET/HEAD/Range test and does not
replace the dedicated Viewer or Era-upload browser modules.

## Evidence boundary

The companion protocol is `STATIC`: it checks the runner's isolation contract
and the server policy source. A successful Chrome execution is
`BROWSER_E2E_TESTED`. It is only valid when the PowerShell wrapper reports the
post-run `72 / 72 / 8` synthetic baseline.

The runner opens a fresh headed Chrome Stable persistent context with
`channel: 'chrome'`, blocks service workers, permits only the two synthetic
loopback origins, verifies the CDP-reported product and version, then removes
only its own generated profile. It never uses the user's Chrome profile.
Screenshots stay in the ignored `.codex-work` evidence root.

The wrapper does not start Docker, stop Docker, provision accounts, create
photos, or access 8191. Credentials must be the ignored, owner-only
synthetic-role file produced by the existing fixture lifecycle. That fixture
also creates an ignored authority-side catalog truth document; it is distinct
from the compatibility BFF being tested and contains no private-library data.

## What a successful run proves

The Chrome run signs in separately as Classmate, Family, Teacher, and
Anonymous. It reads every cursor page of the public timeline and compares it
with a separately captured, authority-side synthetic catalog truth. It never
uses a browser-visible `era` field as the expected answer. It verifies:

- Classmate, Teacher, and Anonymous have exactly the same `FULL` catalog.
- Family has exactly the Classmate `HERITAGE` catalog, never merely a
  front-end-hidden subset.
- The fixture-selected known Living UUID returns not-found for Family.
- A controlled `UNKNOWN` fault removes the selected photo's exact
  LIVING-root memberships, then Chrome verifies it is hidden even from Full
  roles and Family. The wrapper restores those exact LIVING-root memberships,
  rebuilds projections, and rechecks the 72 / 72 / 8 baseline.
- Family responses contain no exact known Living UUID across timeline, home,
  pins, albums and album details, people and person details, Spotlight,
  search suggestions, and grouped search.
- Home and pin cards preserve the server contract: every `photoIds` member,
  `photoCount`, and `coverPhotoId` remains inside the role's allowed catalog.
- Album, People, search, and Spotlight covers/counts are read from the
  role-scoped projection. Family person counts cannot exceed their Full
  counterpart.

The test does not put photo access policy in JavaScript. It only verifies
browser-visible HTTP responses issued by the Gateway.

## People prerequisite

The gate defaults to requiring a non-empty People projection for both
Classmate and Family. An empty People response stops the gate with
`scope_people_fixture_missing` or `scope_family_people_fixture_missing`; it is
not converted into a pass.

For a controlled synthetic People fixture, reuse the existing
`tests/phase2/immich-people-fixture.php` preparation/cleanup protocol only
after its explicit synthetic preconditions have been reviewed. It uses the
committed fictional fixture assets and records its exact rows for cleanup.
Do not run it automatically from this gate: its isolated Immich setup has its
own lifecycle, and its cleanup must restore the 72 / 72 / 8 baseline before
this gate can be cited. In particular, do not use that fixture when the
existing synthetic People state is non-empty unless its own baseline
preconditions are satisfied.

## UNKNOWN policy evidence

The source policy has a static fail-closed guard: an `UNKNOWN`/missing Era is
denied before the Family-specific `HERITAGE` comparison. The protocol verifies
that source relationship and the current-policy recheck of every snapshot
photo id.

The source relationship remains `STATIC` evidence. The companion
`photos-app-v4-scope-unknown-fixture.php` supplies the additional
`BROWSER_E2E_TESTED` evidence: it runs only inside the synthetic Piwigo
container with an explicit gate, persists a 0600 run-scoped repair record,
removes and later restores the exact LIVING-root memberships, rebuilds the
read projection, and cannot delete media or create a photo. A failed cleanup
is a hard gate failure, not a skipped test.

## Invocation when the synthetic runtime is healthy

Prepare the normal ignored synthetic credential file first, then run:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File `
  tests/phase3/photos-app-v4-chrome-scope-projection.ps1 `
  -CredentialFile .codex-work/private-browser-fixture/synthetic/credentials.json
```

The command is deliberately omitted from public CI. Public CI validates the
static protocol; the headed Chrome execution remains a local synthetic
acceptance artifact.
