# Piwigo-first dependency matrix

Lock reviewed: 2026-08-16 (Asia/Shanghai)

The machine-readable source of truth for downloaded Piwigo extensions is
`infra/piwigo-extensions.lock.json`. A Marketplace “compatible” label is only a
research input; activation requires source inspection and a runtime security
test against the exact Core and role matrix.

## Supported locked runtime stack

| Component | Exact version / integrity | Compatibility | License | Maintenance evidence | Effective runtime state | Decision / security gate |
|---|---|---|---|---|---|---|
| Piwigo Core | 16.4.0 in image tag `16.4.0a`; image `piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84` | PHP 8.2+ recommended; MySQL 5.6+ or MariaDB 10.1+; Nginx/Apache; GD or ImageMagick | GPL-2.0-or-later | [Stable 16.4.0 release, 2026-05-03](https://piwigo.org/release-16.4.0); [official source](https://github.com/Piwigo/Piwigo) | Active; locally reports 16.4.0 on PHP 8.4.20 | Sole V1 platform/media Core. Never fork or patch Core. |
| Official Piwigo Docker package | Tag `16.4.0a`, same immutable digest above | Local image includes Nginx 1.28.3, PHP 8.4.20, ImageMagick 7.1.2-19 and GD | Component licenses apply; Piwigo Core GPL-2.0-or-later | [Official image repository](https://github.com/Piwigo/piwigo-docker) | Active; port is bound only to `127.0.0.1:8090` | Keep exact digest. Test startup ownership/upgrade cost on a large library before NAS production. |
| MariaDB | `11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf` | Exceeds Piwigo's MariaDB 10.1+ minimum | GPL-2.0 | [Official MariaDB image](https://hub.docker.com/_/mariadb) | Active on the private Compose network; no host port | Separate persistent volume. Backup is quiesced and uses `--lock-all-tables` because MyISAM exists. |
| Community | 16.f; ZIP SHA-256 `c7f59e107c5230271352dcf251b9b925436d45a0018bfd52dc8258602025409f`; source commit `552c3cb5342c45aa395c992e77736ada862cd9b3` | Declares Piwigo 16 compatibility | GNU GPL; package/source header does not state a version | [Marketplace 16.f, released 2026-04-21](https://piwigo.org/ext/index.php?eid=303); [source](https://github.com/plegall/Piwigo-community) | **Installed, inactive** | Moderation behavior is reusable in principle, but activation is blocked by the observed tokenless moderation POST, unsafe category-array path, explicit upload mode and Community-specific role/Era tests. |
| Bootstrap Darkroom | 16.d; ZIP SHA-256 `e01aa11b9609431b6c438f4d079a4afe180dd6e30c814813f4769e73c9401f92`; source commit `1b9f3e4f6253deb135ae677faf75ab42528e35fe` | Declares Piwigo 16 compatibility | Apache-2.0 | [Marketplace 16.d, released 2026-01-06](https://piwigo.org/ext/index.php?eid=831); [source](https://github.com/Piwigo/piwigo-bootstrap-darkroom) | Active and default | **Spike only.** Validates derivative-first markup and PhotoSwipe integration markers; browser layout/touch behavior remains unverified. |
| PhotoSwipe bundled by Darkroom | 4.1.3 | Integrated by Bootstrap Darkroom 16.d | MIT | Frozen old major bundled in the locked theme | Active only through the spike theme | Do not extend as the final viewer. Preserve only until the Class Archive Theme adopts pinned v5. |
| User Collections | 16.a; known archive SHA-256 `d80da68f6d8196dfaa986aae7ae32e5415423ccf24eb5540080ecf4ebdd686c0`; source commit `6b853658afd5791306b17cfa264063672af9dd0d` | Declares Piwigo 16 compatibility | GPL-2.0 | [Marketplace 16.a, released 2026-01-15](https://piwigo.org/ext/index.php?eid=615); [source](https://github.com/Piwigo/Piwigo-User-Collections) | **Not installed, inactive, quarantined** | Runtime evaluation exposed a private-album ACL bypass by known image id. Core Favorites is only a narrower candidate until it passes equivalent ACL/revocation tests. |

## Project-authored runtime components

| Component | Version | License | Runtime state | Boundary |
|---|---:|---|---|---|
| ClassArchivePolicy | 0.1.0 | GPL-2.0-or-later | Active; final Phase 0/Phase 1 regressions pass | HERITAGE/LIVING MediaGuard, Community Pending-state fail-closed handling and nginx internal delivery; no Core patch |
| ClassIdentity | 0.1.0 | GPL-2.0-or-later | Active; final Phase 1 regression passes | Explicit Principal/Identity/Seat/account lifecycle, Claims/Invites, SYSTEM_ADMIN, minimum Admin Console, CapabilityGuard and AnonymousPresenter |
| Class plugin publication/FPM wrapper | repository version | GPL-2.0-or-later | Active | Workflow mutex, maintenance fail-closed protocol, atomic publish, runtime verification and PHP-FPM umask `0007` |

These components are installed from the tracked workspace, not downloaded from
the Marketplace. Their source and migrations are part of the same reviewed Git
release. Community remains inactive; User Collections remains absent.

## Evaluated but not locked into the runtime

| Candidate | Evaluated version | License / maintenance | Why it is not installed |
|---|---:|---|---|
| PhotoSwipe | 5.4.4 | MIT; latest stable v5 release is 2024-05-24; v6 remains under development | Planned final viewer. It will be pinned with exact local asset hashes when the Class Archive Theme begins; Phase 0 does not silently fetch it from a CDN. |
| [Comments on Albums](https://piwigo.org/ext/index.php?eid=512) | 14.a | GPL-2.0; declares Piwigo 16/15/14 compatibility; 2024 release, README says unmaintained | Open Piwigo 16 regression and stale maintenance. Album comments need a maintained release or a thin project adapter. |
| [Reply To](https://piwigo.org/ext/index.php?eid=556) | 12.a | GPL-2.0; declares Piwigo 16 compatibility; last release 2022 | Produces `@name`-style text, not a real thread/reply relation; stale maintenance. |
| [Subscribe to Comments](https://piwigo.org/ext/index.php?eid=587) | 14.a | GPL-2.0; compatible with Piwigo 16/15/14; 2024 release | Potentially useful after SMTP exists, but recipient ACL and era leakage are not tested. |
| [Like / Dislike](https://piwigo.org/ext/index.php?eid=1019) | 1.0 | Marketplace claims Piwigo 16/15; package licensing/upstream maturity not established | Cookie-oriented voting is not the required account-bound, role-controlled Like. |
| [Smileys & Votes](https://piwigo.org/ext/index.php?eid=1021) | 1.6 | Marketplace claims Piwigo 16/15; package licensing/upstream maturity not established | Hash/IP-oriented reactions and anonymous support conflict with the required account/role policy. |

## Persistent-data matrix

| Named volume | Purpose | Backup treatment |
|---|---|---|
| `class_archive_piwigo_data` | Piwigo application tree, local configuration, installed pinned extensions and runtime state | Included in the application archive |
| `class_archive_piwigo_uploads` | Piwigo-managed uploaded originals | Included separately |
| `class_archive_piwigo_galleries` | Synchronized/local gallery originals | Included separately |
| `class_archive_piwigo_derivatives` | Thumbnail/preview cache | Not in the backup bundle; reproducible cache, but capacity must be planned |
| `class_archive_piwigo_db` | MariaDB data | Quiesced logical dump |
| `class_archive_piwigo_scripts` | Image startup script volume | Recreated from tracked infrastructure; contains no deployment secrets |
| `class_archive_piwigo_backups` | Local backup bundles and SHA-256 manifests | Must be exported off-device; a local volume is not disaster recovery |

The ignored `.env.piwigo` is a separate recovery artifact and must be stored in
an encrypted off-device backup. It is intentionally not included in the Docker
backup volume.

## Upgrade rule

Do not update Core or an extension because a future-major release exists. A lock
change requires all of the following: stable Core confirmation, declared
compatibility, package/source license review, exact archive SHA-256, source
commit recording, clean installation, access-matrix tests, known-media denial
 tests, ClassIdentity migration/lifecycle/Admin gates, moderation/CSRF tests
 where applicable, photo UI smoke and a backup plus restore drill. Before public
 release, add a license-compliant offline retention
plan for the exact OCI manifests/blobs and extension source/ZIPs; hashes alone
do not protect against upstream disappearance. Until then, the immutable
versions above remain fixed.
