# Private E2E fixture lease

This boundary exists only for bounded, localhost-only browser QA against an
already provisioned private fixture. It is disabled by default and is not a
product feature, registration path, administrator API, or HTTP endpoint.

## Enablement boundary

- Acquisition and abandoned-lease recovery require a CLI process with
  `CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1` and a loopback or empty remote address.
- No Compose file, environment template, public route, or browser request sets
  that flag.
- An ordinary web administrator never receives a lease capability. Once a
  durable active row exists, the normal identity mutation path enforces it even
  though the web process does not have the enable flag.
- Absence of the test table preserves normal product behavior. An unreadable,
  malformed, ambiguous, expired, or conflicting lease fails closed.

## Resource CAS binding

The current adapter leases exactly one target kind: `IDENTITY`. A lease binds:

- `test_run_id` and `fixture_owner`;
- target kind and target id;
- an opaque random capability whose digest alone is stored;
- the Identity aggregate `lock_version`;
- an independent lease revision;
- heartbeat and database-clock expiry.

Acquisition and administrator mutation use the same per-identity MariaDB named
lock. A lease-authorized mutation must present its in-process context and match
both revisions. The business update uses `WHERE id = ? AND state = ? AND
lock_version = ?`, and the durable lease revision advances in the same InnoDB
transaction. Cleanup is one exact-version CAS; it never retries by overwriting a
newer state.

The opaque in-memory revision advances only after the caller's business
transaction commits. A rollback therefore leaves both the durable row and the
capability at the previous revision. Fixture password installation and cleanup
use exact user-id, binary username and verifier CAS statements. A concurrent
username rebind or administrator password wins; cleanup preserves it and
records a conflict instead of replacing it.

`activeIdentityLeaseMetadata()` exposes only non-secret registry fields. It
never returns the bearer token or its digest. A future fixture registry can use
that lookup for reconciliation. Its `version_token` combines the aggregate and
lease revisions for optimistic comparisons; it is not an authorization secret.
Adding another target kind requires a separate mutation adapter and its own CAS
proof.

## Expiry and recovery

Heartbeat extends the lease using the database clock. Expiry does not silently
release the Identity: all participating mutations remain denied until explicit
abandoned recovery. Recovery is permitted only after expiry and only if the
Identity still has the exact version recorded by the abandoned lease. Version
drift records `CONFLICT` and does not roll back business state.

Every Seat-account authorization passes through the same lease check used by
MediaGuard. A live lease is the bounded test window. An expired `ACTIVE` lease
or any `CONFLICT` row denies pages, APIs, thumbnails, previews and originals;
the SYSTEM_ADMIN principal remains outside the leased Identity so it can
investigate. A conflicted row also blocks a new lease until explicit
reconciliation.

The broker writes a 0600 recovery plan before the first password CAS, flushes
and `fsync`s the file, then `fsync`s its parent directory. The
private Owner deployment keeps it in a dedicated, non-web-served Docker volume
so a Piwigo container restart cannot discard it. It stores only the digest of
the pre-existing verifier, the temporary verifier digest, and a verifier for a
discarded random closed secret—never the pre-existing verifier or plaintext
closed secret. The first digest lets crash recovery distinguish a role whose
CAS never ran from a concurrent administrator change. Normal close and TTL
recovery replace a password only when the exact user id, binary username and
current verifier still match this run. On topology drift, recovery first
removes any such broker-owned verifiers, preserves any administrator change,
keeps the Identity frozen where safely possible, and marks the lease
`CONFLICT`; it never releases the lease. Missing plans and username/password
drift stay denied and require reconciliation.

The host wrapper keeps a separate ignored watchdog which waits beyond the TTL
before attempting recovery. It must not reclaim a live broker merely because a
host process becomes temporarily unresponsive.

## Evidence boundary

The protocol test statically proves enablement, schema, CAS and absence of a
plugin HTTP acquisition call. The disposable runtime test uses random-prefix
tables in the synthetic MariaDB instance and removes them in `finally`. It also
executes the exact durable write path, a helper-throw/outer-finally close, and a
real disposable Principal topology change. Neither test mutates the Owner
runtime or private media.
