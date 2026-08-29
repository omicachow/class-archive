# Photos App V4 Owner cold-restart verification

`tests/phase3/photos-app-v4-owner-cold-restart.ps1` is a local-only,
explicitly opt-in persistence check for the already-cut-over private Owner
runtime at `127.0.0.1:8190` and `127.0.0.1:8191`.

It is not part of public CI, not a backup, and not an importer, migration or
AI indexing workflow. It never reads source photo directories, writes media,
creates a real account, prints a credential, or exposes a browser/session
artifact. Its evidence is limited to ignored local aggregate counts and
SHA-256 digests beneath `.codex-work`.

## Scope and safety boundary

The runner requires two explicit confirmations:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-owner-cold-restart.ps1 `
  -ConfirmOwnerPrivateRestart `
  -ConfirmServingContainerRestart
```

Before any restart it calls the existing `private-full.ps1 runtime-owner`
boundary verifier, resolves every target through its exact Docker Compose
project/service labels, checks its fixed container name, and confirms that
only the Piwigo listener pair is bound to loopback `8190`/`8191`. Internal
Immich/Redis/database containers must have no host listener.

The only lifecycle operation is `docker restart` for the existing Owner
serving containers: MariaDB, Immich PostgreSQL, Redis, Immich ML/server/
gateway/web-compat, and Piwigo. It never uses Compose `up`, `down`, `stop`,
`start`, `rm`, `-v`, image pull, build, force-recreate, or volume removal.
The one-shot gateway secret stager is deliberately excluded.

The script validates the Owner boundary and a private aggregate snapshot:

1. before restart;
2. immediately after every allowed container is healthy; and
3. after the temporary SYSTEM_ADMIN read session is exactly revoked.

The snapshot checks V4 read projections, active collection snapshot pointers
and items, comments, Spotlight candidate-set integrity, AI asset/index state,
and AI jobs. It records no photo ID, path, filename, account name, comment
text, face data, or search vector. Open `PENDING`/`RUNNING` AI jobs fail the
gate; the runner does not attempt a reindex or repair.

For immediate availability, an existing short-lived SYSTEM_ADMIN fixture lease
makes only six GET requests through the normal private compatibility endpoint:
home, pins, timeline, people, search suggestions and grouped search. The lease
is revoked in a `finally` block before a PASS record can be emitted. The test
does not call Google Chrome; Chrome owner acceptance remains a separate V4
browser gate. MediaGuard is unchanged by this cold-restart verifier and must
still be tested by its dedicated HTTP/browser regression suite.

## Evidence and result

On success the script emits only:

```text
V4_OWNER_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO scope=OWNER_8190_8191 evidence=PRIVATE_LOCAL_IGNORED
V4_OWNER_COLD_RESTART_COMPLETE=PASS
```

The JSON evidence is intentionally ignored by Git. It is suitable for local
Owner recovery/acceptance evidence only and must never be attached to GitHub,
CI artifacts, issues, pull requests or public reports.

Run the source-only protocol before using the opt-in runner:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-owner-cold-restart-protocol.ps1
```

The protocol executes no Docker, WSL, HTTP, Chrome, PHP container command or
private-data operation. It locks the confirmation surface, exact owner scope,
restart-only lifecycle restriction, ignored evidence destination, read-only
snapshot contract, no reindex behavior and session cleanup ordering.
