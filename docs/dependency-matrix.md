# Dependency matrix

Versions are locked to the stable HumHub 1.18 line in `infra/modules.lock.json`. Marketplace claims remain subject to installation and runtime verification in `docs/reuse-audit.md`.

| Component | Locked version | Compatibility | License | Maintenance / source | Decision |
|---|---:|---|---|---|---|
| HumHub Core | 1.18.4 | Stable; PHP 8.2-8.4 | AGPL-3.0-only community license; commercial dual-license option | [Official GitHub](https://github.com/humhub/humhub), released 2026-07-21 | Use official image; never fork Core |
| HumHub Docker image | 1.18.4, digest `sha256:8c4bee…1408` | linux/amd64 | Image contains AGPL HumHub plus separately licensed runtime packages | [Official Docker repo](https://github.com/humhub/docker) | Pinned local runtime; ARM NAS caveat |
| MariaDB | 11.8.8, digest `sha256:d9f7eb…5fbf` | Meets recommended MariaDB 11.8+ line | GPL-2.0 family | [Official image](https://hub.docker.com/_/mariadb) | Separate persistent volume; no host port |
| Gallery | 1.7.1, SHA-256 `02454e…de44` | Manifest: HumHub 1.18 only; 1.8.x is for 1.19 | GNU AGPL v3 in `docs/LICENCE.md`; manifest omits it | [Marketplace downloads](https://marketplace.humhub.com/module/gallery/downloads) | Installed; do not install latest-overall 1.8.1 |
| Report Content | 1.2.2, SHA-256 `176c75…5755` | Manifest: HumHub 1.18.1-1.18 | AGPL-3.0-or-later in package manifest | [Marketplace downloads](https://marketplace.humhub.com/module/reportcontent/downloads) | Installed; reporting on, profanity list empty |
| Content Bookmarks | 1.2.0, SHA-256 `746d40…03a5` | Manifest: HumHub 1.18+ | Module license is not declared; compliance review required | [Marketplace downloads](https://marketplace.humhub.com/module/content-bookmarks/downloads) | Installed; flat private bookmarks confirmed by source |
| Share Content (`sharebetween`) | 1.1.1, SHA-256 `884e5b…f98a` | Manifest: HumHub 1.18.1-1.18 | Module license is not declared; compliance review required | [Marketplace downloads](https://marketplace.humhub.com/module/sharebetween/downloads) | Installed; source shows reference-only stream share, not multi-gallery placement |
| Two-Factor Authentication | 1.2.3, SHA-256 `69a47b…04de` | Manifest: HumHub 1.18 line; 1.3+ is for 1.19 | Module license is not declared; compliance review required | [Marketplace downloads](https://marketplace.humhub.com/module/twofa/downloads) | Installed as a free mature security module; admin enforcement deferred until recovery flow is tested |

The Marketplace UI reports latest-overall versions from the 1.19 line. The live HumHub 1.18.4 compatibility resolver instead selected the locked builds above. Both the exact archive URL and official SHA-256 are recorded so a fresh environment cannot silently drift.

License evidence is deliberately package-specific. Gallery ships the AGPL v3 text, Report Content declares `AGPL-3.0-or-later`, while Content Bookmarks, Share Content, and TwoFA do not declare a module-level license in their locked tags or Marketplace ZIPs. Generic HumHub license links and bundled dependencies' licenses are not treated as proof for those three modules; production redistribution or modification requires a compliance decision.

## Explicit exclusions

No beta/upcoming HumHub, Redis, Elasticsearch, external media library, object storage, CDN, SSO, or paid module is part of the locked Phase 0 stack.
