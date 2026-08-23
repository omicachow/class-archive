# Public development and synchronization

Class Archive is developed in a public Git repository while private class
data remains outside Git. This document is the release boundary for future
progress updates.

## What is public

- Project-authored source, tests, synthetic fixtures, architecture decisions,
  dependency locks and reproducible local Docker/PowerShell tooling.
- Historical HumHub and Piwigo spike evidence, including known failures and
  explicit production blockers.
- Current verification results, with the command, date and PASS/FAIL/PENDING
  state recorded honestly.

## What is always private

- Real student, teacher or family names, email addresses, rosters and claim or
  invitation values.
- Real photos, originals, thumbnails, previews, EXIF, database dumps, NAS
  paths, hostnames, credentials, API keys, session material and deployment
  configuration.
- Private-QA inventories, selected filenames, manifests, screenshots, browser
  profiles, embeddings, face indexes, search indexes and per-file results.
- `.env*` files other than the intentionally generic `.env.example`, runtime
  volumes, generated uploads, backups and test artifacts.

The public repository must remain usable with synthetic data and placeholders.
Do not “sanitize” a real photo or roster into Git; keep it in the private
deployment boundary instead. Binary media is denied by default; the only
exceptions are the five fixed, fictional Phase 2 PNG fixtures whose paths and
SHA-256 digests are pinned by the public-boundary gate.

## Before every public push

1. Review `git status --short --branch` and the complete staged diff.
2. Verify the exact staged snapshot without walking ignored directories:

   ```powershell
   pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\verify-public-boundary.ps1 -Mode Index
   ```

3. Verify every commit that will be introduced to the reviewed public base.
   This catches a private blob even when a later commit deletes it:

   ```powershell
   $base = git merge-base HEAD origin/main
   pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\verify-public-boundary.ps1 -Mode Outgoing -BaseRef $base
   ```

4. Run the synthetic gate protocol:

   ```powershell
   pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\public-boundary-protocol.ps1
   ```

5. Confirm no ignored environment file, volume, upload, dump, backup or test
   artifact is staged. The verifier reports only a reason code and count when
   it fails; do not replace it with a command that prints matched paths or
   content into a terminal or CI log.
6. Record the exact verification command and whether it is PASS, FAIL or
   PENDING. A historical green run never overwrites a current failure.
7. Commit source and documentation together, then push the active development
   branch to the configured GitHub remote.

The GitHub public-safety workflow repeats the HEAD and outgoing-history gates,
the protocol test, the pinned fictional-fixture digests and a non-echoing
credential scan. CI is a backstop; it is not permission to skip the local
staged-index review.

## Local-only private photo QA

Private real-photo QA is an explicit local operation, never a default test and
never a GitHub Actions job. Only generic runner source, schemas and synthetic
tests may be tracked. Source media stays outside the checkout. A run may copy a
reviewed sample into a dedicated ignored staging root, but it must not mount or
modify the source collection. Follow the local private-QA runbook; keep its
local env files, staging media and every generated output untracked.

Private QA must use a separate Docker project, network, ports, volumes and
credentials. Its staging mount is read-only. It must not reuse the normal
localhost stack's database, originals, accounts, sessions, audit records or
model indexes, and it must not expose any service beyond loopback.

The public repository receives no path, filename, screenshot, manifest,
embedding, index, EXIF record or media-derived identifier from a private run.
Only an aggregate statement such as `PRIVATE_PHOTO_VALIDATION=PASS`, together
with `EVIDENCE=LOCAL_PRIVATE_UNPUBLISHED`, may appear in a public report. A
failure must likewise remain aggregate and must not disclose the affected
file. Private data, reports and evidence are not uploaded as CI artifacts or
GitHub release assets.

The default public branch is a mirror of the latest reviewed source commit.
Feature and spike branches remain visible so the architecture pivot stays
traceable. Public synchronization does not authorize importing real class
data or opening the localhost-only Docker service to the network.

## Current public-sync note

On 2026-08-19 the coordinated direct ClassIdentity HTTP check returned
`PASS` with 108 probes, including Family submission review and the Anonymous
and Archive Admin routes. The repository still records the separate
production blockers and does not treat a green localhost run as production
approval.
