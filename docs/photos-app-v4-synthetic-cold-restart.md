# V4 synthetic cold-restart evidence

`tests/phase3/photos-app-v4-synthetic-cold-restart.ps1` is the Phase B
runtime gate for the public synthetic service only. It is **not** a private
Owner-library migration or restore command.

## Evidence level and boundary

- `STATIC`: the companion protocol checks the source-side public-only and
  terminal-evidence contract without starting Docker, Chrome, or HTTP probes.
- `RUNTIME_TESTED`: a successful execution performs real public-container
  restarts and authenticated projection reads on `127.0.0.1:8090` and
  `127.0.0.1:8091`.

The runner first delegates to the existing `read-projection-runtime.ps1`
mutation/rollback proof. It then snapshots V18 persistent projection state,
restarts the already-running public Piwigo and compatibility BFF containers,
and compares the post-restart snapshot before reading the Collections home,
pins, search-suggestion, and timeline endpoints through a short-lived,
fixture-minted SYSTEM_ADMIN session.

It rejects a run unless all of the following are true:

- the exact synthetic baseline remains `72 / 72 / 8` before and after;
- all six durable read projections are active;
- the eight FULL/HERITAGE collection snapshot pointers and two completed
  maintenance rows remain valid;
- both Spotlight rotation checkpoints remain valid;
- no AI index job is open, and aggregate AI index/job state is unchanged over
  the clean V4 restart;
- derivative/projection restart behaviour has already passed the independent
  mutation/rollback proof.

The snapshot emits only counts and SHA-256 digests. It never records photo
IDs, names, paths, credentials, cookies, or private-library values.

## Safe invocation

Run only after the public synthetic Docker services are already healthy:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File `
  .\tests\phase3\photos-app-v4-synthetic-cold-restart.ps1 `
  -ConfirmSyntheticMutation -ConfirmServiceRestart
```

The command never runs `docker compose up` or `down`, and it never addresses
`8190`, `8191`, a private compose project, or a real media volume. It may
restart only the existing public Piwigo and compatibility BFF containers.

The raw transcript is attestation-eligible only if it ends with both exact
records below, in this order:

```text
V4_SYNTHETIC_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO baseline=72_72_8
V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS
```

The completion marker is emitted after the `finally` block revokes the
short-lived synthetic SYSTEM_ADMIN session and after the final `72 / 72 / 8`
baseline check. A normalizer must reject an early PASS without this terminal
marker.
