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
- `.env*` files other than the intentionally generic `.env.example`, runtime
  volumes, generated uploads, backups and test artifacts.

The public repository must remain usable with synthetic data and placeholders.
Do not “sanitize” a real photo or roster into Git; keep it in the private
deployment boundary instead.

## Before every public push

1. Review `git status --short --branch` and the complete staged diff.
2. Run the tracked-name and secret checks from the local release workflow.
3. Confirm no ignored environment file, volume, upload, dump, backup or test
   artifact is staged.
4. Record the exact verification command and whether it is PASS, FAIL or
   PENDING. A historical green run never overwrites a current failure.
5. Commit source and documentation together, then push the active development
   branch to the configured GitHub remote.

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
