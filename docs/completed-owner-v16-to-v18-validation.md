# Completed Owner V16-to-V18 validation

`infra/scripts/validate-completed-owner-v16-to-v18.ps1` is a separate,
read-only validator for an Owner database that has already completed the
historical V16-to-V18 upgrade. It intentionally does **not** relax or alter
the original migration adapter's exact-current-HEAD plan contract.

## What it proves

The validator accepts only an ignored, owner-only historical plan leaf and a
current V4 Synthetic 8091 Chrome/MediaGuard attestation leaf. It verifies that:

- the plan's historical Git commit still exists and is an ancestor of current
  `HEAD`;
- `Schema.php` at that historical commit, the plan's SHA-256, and current
  `Schema.php` have identical bytes and still declare the reviewed V17/V18
  additive ledger;
- the private plan, numeric baseline, database-only snapshot checksum record,
  historical V4 gate, and direct V16-to-V18 synthetic proof are internally
  hash-bound and structurally valid;
- the Owner loopback services are healthy, the ClassIdentity ledger is exactly
  V18, and the normal runtime only executes the two `--verify-only` checks;
- a current, head-bound V4 Phase A/B attestation is valid;
- stable Owner counts and opaque semantic fingerprints still match the V16
  baseline, including canonical media, albums, comments, person curation,
  collections, AI control, and Immich index state.

The snapshot check uses a disposable `run --rm` checksum reader that mounts
only the existing DB-only rollback bundle. It never opens the Owner database
or any managed media.

## Audit drift rule

The historical V16 baseline contains an `identity_and_audit` fingerprint.
Normal recovery and verification legitimately append audit records, so a later
completed-state check cannot require byte-for-byte equality of that combined
fingerprint. The completed validator keeps the original immediate
post-migration comparator unchanged and applies a narrower later rule:

- every non-audit baseline count is exact;
- the V18 ledger is exact;
- every independent non-audit semantic fingerprint is exact;
- `audit_events` may only increase, never decrease.

The result exposes only `audit_drift=APPEND_ONLY`, never audit rows, account
identifiers, media paths, or private filenames. If a future need requires
stronger historical identity-state comparison, capture a separately scoped,
immutable identity-only fingerprint before making user or operational changes.

## Explicit non-actions

This validator never enters or finalizes maintenance, runs migrations,
rebuilds projections, starts/stops/recreates services, imports media,
generates derivatives, or queues/reindexes AI. It is not a rollback tool and
does not replace the DB-only rollback snapshot or an independent backup.

Run its static contract before live use:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\completed-owner-v16-to-v18-validator-protocol.ps1
```

The live command requires the historical plan leaf and a fresh current V4
acceptance gate. Both names are local opaque leaves; never place absolute
paths, credentials, database output, or private media data in shell history
or a report.
