# Private Owner V16 to V18 Migration

This is the narrowly scoped, localhost-only upgrade protocol for a private
Owner database whose ClassIdentity migration ledger is exactly V16. It does not
broaden the existing V17-to-V18 adapter: the two source-version boundaries are
separate on purpose.

## Scope and preconditions

Current checked-out ClassIdentity source must declare V18 and contain the
reviewed additive migrations:

~~~text
V16
  -> 0017_photos_app_v4_collection_snapshots
  -> 0018_photos_app_v4_spotlight_rotation_state
  -> V18
~~~

Before a state-changing action the adapter verifies:

- the endpoint is exactly owner on the private localhost listener pair;
- the checkout is clean;
- the current source and a pinned V16 rollback source are both available;
- the Owner runtime, containers, volumes, and loopback endpoints are healthy;
- a current, hash-bound Synthetic 8091 V4 Phase A/B Chrome and MediaGuard
  attestation is valid;
- a separate direct V16-to-V18 synthetic runtime proof was produced from the
  exact current Git commit and source digest, then independently attested;
- the database ledger is exactly V16;
- the aggregate-only V16 baseline and database-only rollback snapshot are
  hash-bound in a private ignored plan.

The normal lifecycle is Probe, then Snapshot, then Migrate, then Validate.
Snapshot and Migrate require explicit confirmation. Probe and Validate do not
enter maintenance or write database, media, or AI state.

## Preservation model

The V16 baseline records only counts and opaque deterministic hashes. It
preserves Canonical Photo and source-provenance state, album membership,
comments, person curation, Spotlight/Memories business state, identities,
audit, AI control state, and Immich asset/face/person/search counts without
exporting row data, paths, filenames, identifiers, media, or secrets.

The only intentional changes are:

- the contiguous migration ledger advances from 16 to 18;
- V17 collection projection tables are created and structurally validated;
- the V18 Spotlight rotation table is created and checked for a bounded valid
  operational shape.

Canonical Originals, source folders, derivatives, Piwigo image records, and
Immich ML/index workloads are not copied, regenerated, or reindexed by this
migration. The database-only rollback snapshot never mounts media; the normal
private runtime may continue to have its existing read-only media mounts. Only
the bounded compatibility BFF is refreshed after the Class Archive read
projection is rebuilt.

## Rollback boundary

The pre-migration bundle is a database-only rollback checkpoint, not a
disaster-recovery backup. It must never be restored implicitly in a catch or
finally block.

If migration fails, keep the system in maintenance mode. A separately reviewed
manual recovery must verify the snapshot hashes, restore the database, stage
the plan-bound V16 source commit, confirm ledger 1 through 16, and only then
reopen the private runtime. Running a restored V16 database against V18 plugin
bytes is forbidden.

## Evidence

The static contract tests source only. The synthetic direct migration proof is
a distinct, isolated runtime gate: its report stores the producing commit and
deterministic source digest, and the attester refuses to bless it when either
changes. Neither is evidence that a private Owner migration has occurred. Any
code change invalidates the source-bound acceptance evidence and requires a
fresh Synthetic Phase A/B run before the private Snapshot action.
