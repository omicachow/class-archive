# Independent owner recovery v2

## Scope and evidence level

Phase 3.3E defines a second-physical-medium recovery protocol for the private
owner library. It is a new isolated runtime, not an upgrade of the earlier
recovery drill. This change contains code, Compose rendering and static
protocol evidence only: no large archive was created, no restore was executed,
and no owner or earlier-restore runtime was changed.

The read-only hardware audit observed two distinct physical disks. The NTFS
system volume had 11,764,920,320 free bytes; the external exFAT volume had
1,131,197,497,344 free bytes. The system-volume margin is not sufficient to
claim a completed full owner archive in this change. A later operator run must
repeat the capacity preflight against the actual immutable bundle size.

## Fixed boundaries

| Boundary | v2 contract |
| --- | --- |
| Bundle | one `owner-full-v2-*` bundle below the fixed independent-recovery root on the NTFS system volume |
| Secret recovery | portable GPG envelope only; the retained machine-profile envelope is checksum inventory and is never opened |
| Restore storage | a newly created ext4 image on the external recovery volume |
| Piwigo project | `class_archive_owner_restore_v2_piwigo` |
| Immich project | `class_archive_owner_restore_v2_immich` |
| Compatibility network | `class_archive_owner_restore_v2_gateway` |
| Core / compatibility listeners | loopback only, ports 8390 and 8391 |
| Runtime state | new v2 labels, volumes, networks, Git evidence and ignored owner-only state |

The restore runner rejects a missing second-media marker, another bundle
format, schema other than 16, changed payload inventory, changed SHA-256,
container-lock drift, migration-contract drift, tracked ML/upstream drift,
nonportable secret input, existing v2 runtime identity, occupied listeners,
overlapping subnets, same physical source/target disk, or unexpected non-v2
container changes.

## Release sequence

1. `validate` performs bundle, supply-chain, disk, port, network and freshness
   checks without creating storage or runtime objects.
2. `prepare-storage` requires explicit confirmation, creates or remounts only
   the dedicated v2 ext4 image, and verifies all protected runtimes unchanged.
3. `restore` requires separate confirmation and an exclusive lock. It creates
   only fresh v2 volumes and restores databases and POSIX archives by stream.
4. Piwigo starts with the persistent maintenance marker already present.
   Projections, counts, reconciliation, file modes, network isolation and AI
   index reuse are verified while the compatibility listener is absent.
5. Maintenance is finalized, the direct Guest MediaGuard probe runs, and only
   then is the compatibility BFF created on its loopback listener.
6. Aggregate verification checks exact manifest counts, immediate AI results,
   MediaGuard, volume identities and HTTP health. Failure reasserts maintenance.
7. `cold-restart` repeats the maintenance-first release sequence and must prove
   that Face/Search jobs remain zero and existing results are immediately
   available.

## Portable secret boundary

The operator enters the owner-held recovery phrase twice through hidden console
input. The mature portable helper decrypts an exact
`owner-portable-recovery-secrets-v1` payload into an owner-only ignored
directory. Only the archive passphrase, Piwigo database password, anonymous
pseudonym secret and claim-code pepper are accepted. Temporary plaintext is
removed in `finally`; the earlier machine-profile envelope is never read.

## Current gates

```text
SECOND_PHYSICAL_MEDIA=CONFIRMED_READ_ONLY
SYSTEM_VOLUME_CAPACITY=LOW_FOR_FULL_ARCHIVE
OWNER_RESTORE_V2_PROTOCOL=PASS_STATIC
OWNER_RESTORE_V2_LIFECYCLE=PASS_STATIC
OWNER_RESTORE_V2_RUNTIME=NOT_RUN
OWNER_RESTORE_V2_BROWSER_E2E=NOT_RUN
PRODUCTION_READY=NO
```
