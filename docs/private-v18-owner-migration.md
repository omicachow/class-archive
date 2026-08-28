# Private Owner V17 to V18 Migration

This document describes the controlled, localhost-only migration of the private
Owner runtime from ClassIdentity schema 17 to schema 18. It is an operational
protocol for code and aggregate validation only. It intentionally contains no
private endpoint environment, account, media path, filename, snapshot location,
or secret.

## Scope

Schema 18 adds the persisted Spotlight rotation state. The migration is
additive: it creates the role-scoped rotation-state table and appends its
migration-ledger entry. It does not modify Canonical Originals, source
provenance, Piwigo image records, comments, person curation, or Immich search
and face data.

The normal adapter surface is deliberately limited to:

```text
Probe -> Snapshot -> Migrate -> Validate
```

It accepts the `owner` endpoint only and binds its Piwigo and compatibility
checks to localhost ports 8190 and 8191. Staging, synthetic, NAS, and public
endpoints are outside this protocol.

## Preconditions

Before a state-changing action, the adapter must verify all of the following:

- the selected endpoint is exactly `owner`;
- the checkout is clean (ignored local evidence is allowed, but staged,
  unstaged, or untracked tracked-state changes are rejected);
- the checked-out ClassIdentity source declares schema 18 and its expected
  migration ledger;
- `runtime-owner` proves the running Owner containers, volumes, loopback
  bindings, and compatibility BFF health rather than only reading deployment
  configuration;
- the real Owner listeners are healthy on their owner-only loopback pair before
  the workflow lock or maintenance can be entered;
- an ignored, hash-bound V4 Synthetic Phase A/B acceptance report proves the
  required Google Chrome, MediaGuard, scope, upload, and cold-restart gates for
  the current checked-out source;
- the current database is explicitly classified as either exact schema 17 or
  exact schema 18 before the workflow lock is acquired;
- the existing pre-migration snapshot service recognizes the exact 17 to 18
  transition; and
- a safe storage location for the database-only snapshot is available.

`Snapshot` and `Migrate` require an explicit owner confirmation switch.
`Probe` and `Validate` must not change database, media, projection, or runtime
state.

The V4 acceptance record is intentionally local and ignored. It contains only
safe transcript leaf names, SHA-256 values, current source revision/digests, and
narrow pass records; it contains no account, credential, private path, filename,
media, or screenshot. `Snapshot` records its leaf name and SHA-256 in a private
migration plan. `Migrate` and `Validate` require the same leaf name, re-verify
the report, and reject it if the checked-out source or its bound test sources
have changed.

The schema classification is an idempotence boundary:

- `Snapshot` is allowed only from exact schema 17.
- `Migrate` from exact schema 17 performs the additive transition.
- `Migrate` from exact schema 18 is a validation-only replay. It compares the
  retained plan/baseline and returns without lock, maintenance, extension
  installation, projection rebuild, or BFF refresh.

Neither branch may obtain a maintenance lock until its runtime, V4 gate, schema,
plan, baseline, and snapshot preflight has passed.

## Required evidence before migration

The snapshot contains an existing, integrity-checked database-only rollback
dump. It explicitly records `media=NOT_INCLUDED`; it does not mount or copy
originals, derivatives, source directories, or Immich media.

Before plugin installation, the adapter captures a hash-bound, aggregate-only
baseline. The evidence includes counts for the migration ledger, source
records, Canonical Photos, album relationships, comments, replies, collection
projections, Spotlight state, audit state, AI job states, and Immich
asset/face/person/search records. In addition, it stores only opaque SHA-256
fingerprints of deterministic in-container database query streams for Canonical
media, submission state, exact-dedup decisions, source/provenance and
folder-import mappings, album membership/batch archival state, comments, person
curation, Spotlight and collection projections, AI control state, and
identity/audit state. No row values leave the database container. The baseline
and every later use of it are bound to the exact baseline-file SHA-256.

The database-only snapshot is verified before it can be referenced by the
migration plan. The plan stores the snapshot leaf name plus the snapshot
manifest SHA-256, dump SHA-256, and dump byte count. Before migration it
re-verifies `COMPLETE`, `SHA256SUMS`, the manifest transition boundary, and the
dump binding. A stale, replaced, or incomplete snapshot therefore fails before
schema-changing work begins.

The local migration plan binds all of the following immutable evidence to one
exact source checkout: the source commit and Schema.php digest, the baseline
leaf/hash, the snapshot leaf/manifest/dump hashes, and the V4 Phase A/B gate
leaf/hash. It must remain ignored and owner-only. It contains no media, source
paths, identifiers, filenames, or secrets.

The order is mandatory:

```text
Synthetic Phase A/B report verify
-> Owner runtime proof
-> exact V17/V18 schema preflight
-> (V18: validation-only replay and stop)
-> bind migration plan, baseline and database-only snapshot
-> workflow lock
-> maintenance gate
-> exact source-schema-17 recheck
-> source baseline and snapshot recheck
-> install schema-18 plugin bytes / migrate
-> exact schema-18 verify
-> rebuild Class Archive read projections
-> refresh compatibility BFF only
-> compare count and semantic baseline
-> finalize maintenance
```

Any unexpected count or semantic fingerprint change, ledger gap, source/gate
drift, snapshot checksum mismatch, or inability to prove the target schema is a
failure. The runtime remains in its maintenance-safe state rather than
continuing with a partial release.

The new V18 Spotlight rotation table is intentionally not compared as an empty
table. It is an operational scheduling checkpoint: zero rows is idle, while one
or two rows are allowed only for distinct `FULL` and/or `HERITAGE` scopes with
valid digest/revision and timestamp shape. This prevents a real scheduling
update from being misreported as migration corruption while still failing closed
on an invalid state.

## Media and AI invariants

The V17 to V18 operation must preserve all current Owner media and AI state:

- no Canonical Original, derivative, source provenance, or source folder is
  mounted, copied, renamed, deleted, or rewritten by the migration adapter;
- no Piwigo media import or derivative regeneration is started;
- no Immich Server or Immich Machine Learning container is restarted;
- no face detection, face embedding, clustering, or Smart Search full index
  job is requested; and
- only the compatibility BFF may be refreshed after projection rebuilding.

The post-migration validation must prove that persisted people/search results
and completed AI jobs remain present without a new full-library computation.

## Rollback boundary

The pre-migration snapshot is a database rollback checkpoint, not a complete
disaster-recovery package. It must never be restored automatically from a
catch/finally block. A schema-17 database cannot safely run against current
schema-18 plugin bytes.

If rollback is necessary, stop at maintenance and use a separately reviewed,
explicitly confirmed recovery procedure that verifies the snapshot hash and
stages the pinned schema-17 plugin source before exposing the restored database
to users. This procedure must preserve media and Immich state and must not use
an unrelated older restore environment as evidence.

`manual_rollback_required` is therefore a deliberate fail-closed outcome, not
a successful migration result.

## Static contract

The public-safe static check is:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\v4-synthetic-phase-ab-attestation-protocol.ps1
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\private-v18-owner-migration-protocol.ps1
```

They read only tracked source files and check the exact V17-to-V18 boundary,
owner-only loopback mapping, Phase A/B attestation shape, pre-lock runtime and
schema ordering, hash-bound baseline/snapshot/plan evidence, replay behavior,
operational rotation-state validation, media/AI exclusions, and manual rollback
boundary. They do not start containers or access private data.

The isolated synthetic owner-equivalent migration sequence remains a separate
runtime gate. Its runner performs `bootstrap-v17`, `migrate`, target `verify`,
and a validation-only idempotent replay in a dedicated synthetic topology. The
static contract ensures that sequence remains present, but a source-level pass
is not evidence that a Chrome gate, an Owner runtime proof, or a private
migration has run.

## Operator evidence lifecycle

The private operation uses short leaf names rather than host paths:

```text
1. Run the real Synthetic 8091 Phase A Chrome, MediaGuard and Phase B
   cold-restart checks.
2. Keep each runner's raw stdout only under ignored `.codex-work`; it may
   contain local screenshot paths and must never be copied into a gate record.
3. Run `normalize-v4-synthetic-phase-ab-evidence.ps1` against those five raw
   transcripts. It rejects any `FAIL` record and writes eleven exact,
   redaction-safe PASS protocol lines into a fresh owner-only ignored evidence
   directory: each runner's result record plus a terminal post-cleanup
   completion record (and the deep-run MediaGuard record).
4. Record a V4 Phase A/B attestation from that normalized evidence directory.
   The attestation binds the evidence to a clean current source commit and
   hashes of the V4 implementation/test files. It is not a substitute for
   actually running Chrome, MediaGuard, or the cold restart.
5. Run Owner Snapshot with that attestation leaf name and explicit confirmation.
6. Retain the emitted migration plan leaf name and SHA-256.
7. Run Owner Migrate with the same plan and acceptance-gate leaf names.
8. Run Owner Validate with the same two leaf names.
```

Every operation re-verifies what it consumes. If a source checkout changes,
the V4 report and migration plan are intentionally stale and a new Synthetic
verification plus a new pre-migration snapshot are required. This is a safety
property, not a request to rewrite or overwrite an old recovery point.

## Non-goals

This protocol does not create an independent disaster backup, migrate a NAS,
expose a public service, add real-user invitations, or change Photo App
information architecture. The private Owner runtime stays localhost-only and
production readiness remains unchanged.
