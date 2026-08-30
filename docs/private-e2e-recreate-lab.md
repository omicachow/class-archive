# Private E2E recreate lab

This lab proves the failure path that a random-prefix unit table cannot prove:
the real Owner FQA broker opens a lease through `AdminService`, writes redacted
Audit events, invokes Piwigo Core credential revocation, loses its container to
`SIGKILL`, and then recovers from a plan that survived container recreation.

It is a disposable clone, never an Owner execution shortcut.

## Isolation contract

- The fixed Compose project is `class_archive_private_e2e_recreate_lab` and
  every owned container, network and volume carries
  `com.classarchive.scope=private-e2e-recreate-lab`.
- No service publishes a host port. The only bridge is Docker-internal.
- The Owner database is read through the already-running 8191 MariaDB
  container using `mariadb-dump --quick --lock-all-tables`. The SQL stream goes
  directly into a new lab MariaDB volume; it is never written to the checkout,
  a log, or a host temporary file.
- The sessions and two Piwigo per-user cache tables retain their schema but
  clone no rows. They are volatile/rebuildable state, make a live dump
  nondeterministic, and cloned sessions must never authorize a lab browser.
- Only the small Piwigo control and lifecycle-script volumes are mounted from
  Owner, both read-only, by a one-shot networkless seeder. The lab never mounts
  Owner uploads, galleries, derivatives, canonical originals, Immich state, or
  either private source directory.
- Lab Piwigo, database, empty media stubs, scripts and broker recovery plans
  all use independent named volumes. The recovery-plan volume is intentionally
  durable across `piwigo` container removal/recreation. A networkless,
  least-capability one-shot initializer makes only that volume `nginx:nginx`
  mode `0700`; the web container never needs root to create a credential plan.
- Owner record counts and a deterministic full logical-dump SHA-256 are read
  immediately before and after preparation. Any drift fails the preparation;
  the wrapper has no Owner write or lifecycle command.

## Drill sequence

`prepare` refuses a non-empty lab, attests the exact Owner container labels,
hashes the Owner logical dump, starts an empty lab database, streams a second
locked dump into it, compares the clone digest, copies only the two small
control volumes, installs the current checkout's Class Archive plugins into the
clone, explicitly finalizes the lab-only maintenance gate, and proves the Owner
digest/count vector did not change.

`drill` starts the actual broker as `nginx`, waits for its safe READY record,
and proves in the cloned database that the lease is live, the Identity is
active, the expected security audit rows exist, and credential revocation has
run. It then verifies the target container's exact lab labels before sending
`SIGKILL`. Only the lab lease row is forced past its expiry. The stopped lab
container is removed and recreated, while the named recovery volume remains.
Recovery is run in the new container using the original run id. Success
requires all of the following:

- the plan existed after recreation and is removed only after recovery;
- the Identity is frozen;
- the lease is terminal (`RELEASED`);
- the expired original lease is `ABANDONED`, the recovery lease is `RELEASED`,
  and the latter's `recovered_from_lease_id` points to that exact original;
- all three principals' `auth_epoch` values advanced;
- no active Core session/auth key remains;
- `IDENTITY_UNFREEZE`, `IDENTITY_FREEZE`, lease-open, and lease-close audit
  evidence exists;
- the Owner counts/dump digest still match the pre-lab state.

The wrapper never prints usernames, passwords, password hashes, bearer tokens,
cookies, database secrets, SQL rows, or the recovery document. The ignored
state file contains only project ids, non-secret count vectors, SHA-256 values,
container ids, and the opaque test run marker.

## Commands and confirmations

The PowerShell wrapper supports `validate`, `config`, `prepare`, `drill`,
`verify`, and `cleanup`. Preparation requires
`-ConfirmOwnerReadOnlyClone`; the destructive failure injection requires
`-ConfirmLabSigkill`; cleanup requires `-ConfirmLabCleanup`. These confirmations
do not broaden scope: every mutating Docker operation first proves the fixed
project, service and scope labels.

`cleanup` enumerates both directions (all objects with the lab label and all
objects with the lab name prefix), refuses a mismatch, and removes only the
fixed Compose project plus its labelled volumes. It never runs a Docker-wide
prune and never targets the Owner project, 8091, 8191, 8291, source media, or
an image.

This lab provides a real container-recreate recovery proof but is not a backup
or disaster-recovery environment. It must be rebuilt from the Owner read-only
clone whenever a fresh proof is needed.
