# Photos App V4 Chrome performance gate

This synthetic-only local gate measures the V4 acceptance targets in Google Chrome Stable. It launches Playwright with `channel: 'chrome'`, a fresh ignored
profile, blocked Service Workers, and a process-start localhost network guard.
It never opens the Owner/private instance.

The wrapper prepares the existing synthetic browser-fixture contract under the
shared Phase-A lease. It performs two warmups followed by seven measured samples
and reports the median for:

- `SEARCH_OVERLAY_OPEN_P50_MS` (< 100 ms)
- `SEARCH_SUGGESTIONS_VISIBLE_P50_MS` (< 150 ms)
- `STRUCTURED_SEARCH_P50_MS` (< 300 ms)
- `COLLECTIONS_HOME_WARM_P50_MS` (< 400 ms)

The home measurement never requests the full timeline; the gate fails if the
Collections page does so. It therefore cannot meet the target by preloading the
synthetic library. Search
timings stop only at browser-visible UI milestones, and structured search also
requires the real grouped-search response.

Run locally with the already-running 8090/8091 synthetic services:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-chrome-performance.ps1
```

The ephemeral Chrome profile is removed in `finally`; the wrapper rotates the temporary fixture password and removes its ignored credential file before it
prints PASS. Only bounded metrics are printed. The detailed, identifier-free
record remains as ignored local evidence below `.codex-work/evidence/`.

Public CI runs only the static protocol. It starts no browser or service, reads
no credential, and never accesses ignored local evidence.
