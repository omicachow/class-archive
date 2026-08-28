# V4 Synthetic People lifecycle prerequisite

This is a small, reversible prerequisite for the V4 scoped-projection Chrome
gate. It exists because the synthetic 8091 baseline intentionally does not
retain operator-curated People rows between tests, while the scoped browser
gate must prove non-empty People counts, covers, details and Family filtering.

Evidence levels are deliberately separate:

- **STATIC**: the lifecycle protocol checks source boundaries and PowerShell
  syntax only.
- **RUNTIME_TESTED**: requires an actual successful run against SYNTHETIC 8091.
- **BROWSER_E2E_TESTED**: requires the delegated Google Chrome Stable scope
  runner to complete after the fixture has prepared its projection.

It creates exactly two temporary `MANUAL` ClassArchivePerson rows, each with
one HERITAGE and one LIVING synthetic photo rule. The cover is always the
HERITAGE photo, so Family must receive a safe cover and a smaller count. The
fixture is not Immich face detection, face clustering, or a claim about any AI
runtime.

Use it only after the public synthetic services are already healthy; it does not start Docker, restart services, open a private endpoint, or contact 8191.
The credential file remains in ignored `.codex-work` storage and is passed to
the delegated runner without being read or printed here.

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File `
  tests/phase3/photos-app-v4-scope-people-lifecycle.ps1 `
  -CredentialFile .codex-work/.../synthetic-credentials.json `
  -ConfirmSyntheticMutation
```

The fixture uses a per-run protected state file and the shared synthetic
projection mutation lock under the public container's `/tmp`; this is the same
lock used by the UNKNOWN-era scope fixture. A host-side Phase-A lease spans the
People-to-scope handoff: it prevents another V4 synthetic mutation from
starting while the delegated UNKNOWN fixture temporarily owns that container
lock. The child scope runner verifies the exact live lease token but cannot
remove it. The wrapper's outer `finally` asserts the 72 / 72 / 8 baseline
before preparation and after cleanup, releases its own lease, and only then forwards the existing
`V4_SCOPE_PROJECTION=PASS` and terminal completion record.

If preparation is interrupted, invoke the same command again only after
performing the fixture's bounded cleanup for the recorded run; do not delete
the lock/state manually. A non-empty pre-existing `MANUAL` person overlay is a
fail-closed prerequisite error rather than something this test tries to merge
or erase.
