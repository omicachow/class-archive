# Phase 3.4.1 Private Role Shadow Runtime

## Decision

High-risk private-role mutations run in a disposable V18 Shadow. Normal Owner
role browsing remains on the private Owner endpoint. The Shadow supplements
that acceptance; it never replaces it.

The implementation deliberately rejects a live Owner-media lower layer. A
live lower can change after the database snapshot and violates OverlayFS's
stable-lower assumption. A verified full media copy would need roughly the
size of the private media set. The current destructive control-plane tests do
not require those bytes, so v1 instead creates independent empty media volumes
and clones only:

- MariaDB business/Core state;
- Immich PostgreSQL state;
- filtered Piwigo control data;
- Piwigo lifecycle scripts.

Piwigo sessions, the Owner database credential file, the Owner bridge secret
and generated caches are excluded. The Shadow receives new database passwords,
a new Claim pepper, a new anonymous pseudonym secret, an empty session state,
an empty gateway-secret volume and an independent broker-recovery volume.

## Consistency contract

MariaDB still contains MyISAM tables, so `--single-transaction` is not a valid
complete snapshot. The helper uses:

```text
mariadb-dump --quick --lock-all-tables --routines --events --triggers
```

PostgreSQL uses:

```text
pg_dump --format=custom --no-owner --no-acl --serializable-deferrable
```

The sequence is:

1. calculate deterministic MariaDB, PostgreSQL, filtered Piwigo-data and
   scripts digests;
2. create both logical dumps inside an isolated Docker seed volume;
3. copy the two small control volumes from read-only mounts;
4. calculate all source digests again;
5. reject any source drift;
6. verify copied control-volume digests;
7. restore into independent Shadow database volumes;
8. revoke cloned Piwigo/Immich sessions and API keys, then install a fresh
   Shadow database configuration;
9. publish the ignored `CLONE_COMPLETE` marker;
10. remove the plaintext seed volume.

The Owner application is never stopped. The only possible Writer impact is
the brief MariaDB read lock needed for a consistent MyISAM dump. If the Owner
state changes during the guarded interval, the Shadow is not exposed and the
run fails.

The normal `start` action intentionally starts only MariaDB, Piwigo and the
compatibility BFF. It does not start Immich Server, ML or the metadata bridge.
The PostgreSQL clone exists to preserve and test control-plane recovery state,
but every cloned Immich session/API key is revoked. Enabling the full Immich
path would require a separately reviewed Shadow-only bridge-key provisioning
step.

## Isolation

Only `127.0.0.1:11990` and `127.0.0.1:11991` are published. The Gateway,
MariaDB, PostgreSQL, Redis, Immich and ML networks have no host ports. Immich
networks and the Gateway network are internal. The application bridge is
non-internal only because Docker needs it for the two explicit loopback
listeners.

The fixed subnets are `10.180.0.0/24` through `10.180.4.0/24`. The operator
rejects runtime IPAM overlap before creating a Shadow.

Secrets and clone evidence live only under the ignored local Shadow directory.
No private source directory, importer staging path or real-media manifest is
mounted into the runtime.

## Broker to container-recreate proof

The complete high-risk recovery proof must run in this order:

1. the fixture broker acquires its V18 lease and creates Shadow-only fixture
   accounts;
2. it invokes the real `AdminService` mutation path;
3. the same transaction emits the structured security Audit event;
4. the Core session/API-key revoke path runs;
5. the broker writes its 0600 recovery plan into
   `/var/lib/class-archive-private-e2e`;
6. `private-role-shadow.ps1 -Action recreate-piwigo` replaces the Piwigo
   container while retaining only the dedicated recovery volume;
7. the watchdog reads the durable plan, freezes first, verifies CAS state,
   closes credentials, revokes Core sessions again idempotently and releases
   the lease;
8. assertions prove no active fixture credential, lease or visible resource
   remains and that Audit is append-only.

The static Shadow protocol proves the isolated persistent recovery-volume and
container-recreation boundary. It does not by itself prove steps 1-4 or 7;
those require the real broker/watchdog runtime suite and must remain a separate
gate.

## Cleanup

Cleanup enumerates only resources with both:

```text
com.classarchive.scope=private-role-shadow
com.classarchive.shadow-version=1
```

Each resource name must also begin with
`class_archive_private_role_shadow_v1_`. Any mismatched label or name aborts
cleanup. There is no wildcard project deletion, no `docker system prune`, and
no operation against the engineering, Owner or preserved restore projects.

## Evidence levels

After static validation only:

```text
PRIVATE_ROLE_SHADOW_STATIC=PASS
PRIVATE_ROLE_SHADOW_COMPOSE_CONFIG=PASS
PRIVATE_ROLE_SHADOW_CLONE=NOT_RUN
PRIVATE_ROLE_SHADOW_BROKER_RECOVERY=NOT_RUN
PRIVATE_ROLE_SHADOW_MEDIA_BROWSER=NOT_APPLICABLE
```

Runtime gates must not be promoted until the clone and broker/watchdog suites
have actually run.
