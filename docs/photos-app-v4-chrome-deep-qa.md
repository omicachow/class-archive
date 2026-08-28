# V4 Chrome Stable deep-acceptance companion

`tests/phase3/photos-app-v4-chrome-deep-qa.ps1` is a local, synthetic-only companion to the V4 Chrome navigation/search runner. It uses a **headed** Google Chrome Stable process through Playwright's `channel: 'chrome'` and a new persistent profile under `.codex-work/browser/photos-app-v4-chrome-deep/<random-run>/`; it never uses the user's Chrome profile. The profile is deleted after the run, while synthetic screenshots remain only under `.codex-work/screenshots/photos-app-v4-chrome-deep/<random-run>/`. A CDP result of `HeadlessChrome` is rejected.

Prepare the ignored synthetic browser fixture separately, then run the companion against already-running 8090/8091 services:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\private-browser-fixture.ps1 -Environment synthetic
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-chrome-deep-qa.ps1 -CredentialFile <ignored-credential-path>
```

The bounded result line contains the CDP-reported Chrome product/version. The runner blocks service workers, downloads, background networking, component updates, sync, pings, and every non-loopback request. It never reads or writes real/private media, user Chrome cookies, a normal Chrome profile, or a private 8191 endpoint.

Before launching Chrome, the wrapper calls `photos-app-v4-viewer-fixture.php` by a tightly bounded `docker compose exec` into the **already-running synthetic** Piwigo container as its unprivileged service user. The helper first performs a read-only V18 schema attestation, then uses the existing `PhotoCommentService` to create exactly two run-scoped Anonymous comments on distinct visible class-history photos. It writes only opaque synthetic UUIDs to an ignored, owner-only fixture document. The `finally` block removes only those exact marker comments and their matching create-audit rows in a transaction, even when Chrome fails. It never starts, stops, seeds, or resets Docker/services.

## Evidence provided when it is run

- Viewer: real grid-to-viewer navigation, MediaGuard BFF preview URL shape, decoded image, filmstrip, adjacent preview preloading, keyboard forward/back/escape, zoom, collapsed photo information, desktop comment panel, and mobile comment-sheet anchoring/visibility behavior.
- Known LIVING denial: as Family, a fixture-provided LIVING UUID is checked against asset metadata, thumbnail, preview-range, and original endpoints using GET, HEAD, and Range. The same ID must be absent from the timeline and must not open a decoded viewer or a BFF preview request. This is direct media authorization evidence, not a visual hide-only check.
- Comments: Family has no composer and receives a real browser `403` for a forged comment request; Classmate/Anonymous composers are visible. The two fixture comments make Anonymous verification non-vacuous: both API and rendered HTML must show context pseudonyms, the two photo contexts must have different aliases, and public DTO/HTML must contain neither backing identity/account/seat/principal keys nor fixture usernames. The browser runner does not create a comment itself.
- Era-first upload: Classmate and Teacher receive the two explicit Chinese Era choices and role-filtered album options; a client submit with no Era makes no request; a no-Era multipart request is server-rejected without changing the timeline total. Family keeps only the pending history-submission entry and cannot query/direct-post the member endpoint; Anonymous has no upload entry and receives the same direct endpoint `403`. It does not upload a file or assert a successful publication lifecycle.

## Deliberate boundary

The runner does not upload a file, does not create a comment itself, and does not start or stop Docker. Its controlled, synthetic-only fixture/comment cleanup is the sole bounded state mutation. Successful upload publication needs its own controlled fixture/cleanup and post-projection checks before it can count as V4 acceptance evidence.

Pass `-RunMediaGuardRegression` only after this Chrome companion passes and only while synthetic 8090 is already running. That opt-in invokes `infra/scripts/dev.ps1 test-phase0` for known original/derivative URLs, GET, HEAD, Range, logout, account switch, path/query normalization and fail-closed behavior, then `test-phase1` for freeze/revoke session invalidation. Both are separate HTTP evidence; neither is browser evidence, and this wrapper never starts a service for them.

This companion is intentionally not final V4 acceptance evidence on its own. It covers the formerly omitted Viewer/comment/upload-boundary surface; the full Phase A gate still needs the main Chrome search/navigation run, a controlled successful synthetic-upload lifecycle, and the Phase 0 MediaGuard regression.
