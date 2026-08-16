# Architecture decision: Photo First, Social Second

> Historical 2026-08-10 spike draft. The current decision record, including
> the reproduced static-media ACL blocker and updated extension gates, is
> `docs/photo-first-architecture-decision.md`.

Decision date: 2026-08-10 (Asia/Shanghai)

Status: **Superseded evidence draft; not the current ADR**

## Decision

Class Archive V1 uses **Piwigo as the sole user-facing platform and media system**. HumHub is retained only as a tested comparison and possible future community engine; it is not a V1 runtime dependency and none of its forum/feed-oriented pages may become the product home.

The first signed-in route must lead to photos, dates and albums. Comments, anonymous interaction, collection and Spotlight controls appear on a photo or album surface. A general-purpose member feed and a second identity store are deliberately excluded from V1.

This changes the earlier HumHub-first decision. It does not authorize a separate React/Next.js frontend, a Piwigo fork, or a home-grown image pipeline.

## What was compared

Both candidates were installed locally with immutable container digests, configured as private systems, populated with synthetic media and inspected through source plus authenticated HTTP/API responses. The current run did not complete a supported-browser screenshot, lightbox interaction or touch QA.

| Dimension | HumHub 1.18.4 + Gallery 1.7.1 + heavy Theme | Piwigo 16.4.0 + class plugins + photo Theme |
|---|---|---|
| First-screen information model | Stream entries, Spaces, authors and social containers are primary | Albums, photos, dates and image metadata are primary |
| Photo grid and navigation | Gallery provides grids, but they remain subordinate to Space/Profile/content surfaces | Core is a gallery; album and photo routes are the normal navigation model |
| Full-screen viewer | Requires Gallery-specific UI adaptation or another integration | Bootstrap Darkroom 16.d ships PhotoSwipe; local HTTP checks proved integration markers/preview paths, not interactive responsive/touch behavior |
| Time-oriented archive | Would need a custom cross-Gallery query, grouping and navigation layer | Core stores creation dates and already exposes calendar/date browsing; the product Theme still needs a better continuous timeline |
| One original in several logical albums | Gallery media has one Gallery ownership; Official Archive needs a custom relation | Native `image_category` many-to-many association; verified without another image row or file |
| HERITAGE/LIVING read boundary | Private Spaces work well | Private root albums plus group access work well; verified for Guest, Family, Classmate and Anonymous |
| Family upload review | Requires a thin submission layer around Gallery/File | Community 16.f has low-trust moderation and high-trust direct publishing |
| Comments | Mature comments/replies/notifications | Mature photo comments; role restrictions and true nested replies need thin policy work |
| Like | Native | Core rating is not a Like; a very small account-bound photo Like feature is required if retained |
| General activity feed / in-app notification centre | Native and mature | Not a member feed; building one would be a new subsystem |
| Identity/Seat Claim | Custom HumHub module, but Core tables are transactional | Custom Piwigo plugin; Core MyISAM writes require idempotent provisioning/reconciliation |
| Anonymous account | Hidden-user gaps require query, mention, profile and response guards | No public People directory/profile surface, but context alias rendering and admin traceability are still custom |
| Theme work needed for the required UX | Large: replace home, navigation, Gallery aggregation and many social defaults | Medium: child Theme/timeline plus product navigation; basic gallery/viewer remain upstream |
| Upgrade surface | High if many Core/Gallery views are overridden to suppress the content-stream model | Lower for the photo shell when using hooks/child Theme; custom identity/policy plugins remain isolated |
| NAS portability | Official 1.18.4 image is amd64-only | Official 16.4.0a image is multi-architecture; amd64 is locally verified |

## Actual custom scope

### HumHub-first route

The original functional gaps were already six thin domains: Identity/Seat, business-role policy, anonymous rendering, Family submission, Official Archive reference and Spotlight. Making the result photo-first would add another large presentation layer:

- a new photo home and cross-Gallery timeline query;
- album-first navigation replacing Stream/Space/Profile entry points;
- viewer and photo-detail integration across Gallery surfaces;
- extensive suppression or overriding of Space cards, wall entries, user discovery and social chrome;
- compatibility tests for each overridden HumHub and Gallery view after upgrades.

This preserves the best social backend but makes the product shell fight the platform's primary model. A Theme alone cannot change that data/navigation model without becoming an application-sized adapter.

### Piwigo-first route

Piwigo eliminates custom work for upload storage, derivative generation, photo metadata, album CRUD, album trees, multi-album association, album/query ACL primitives, Favorites data, photo-comment records and viewer mechanics. Favorites/media delivery and interactive viewer behavior still require project gates. The remaining V1 code is bounded to class-specific rules:

- `ClassIdentity`: Identity, Seat, hashed Claim/Invite, account provisioning and audit;
- `ClassArchivePolicy`: role action rules, HERITAGE/LIVING invariants and Community permission bootstrap;
- `ClassArchivePolicy` internal AnonymousPresenter: context-scoped comment aliases and administrator resolution;
- `ClassArchivePolicy` internal Collections service: only if upstream User Collections cannot pass album-ACL tests;
- `ClassSpotlight`: owner check, one active item, TTL and a photo-home component;
- `ClassArchive` child Theme: photo timeline, album-first navigation and restrained photo details;
- a small account-bound photo Like/Report adapter only if Piwigo's maintained extensions cannot satisfy the final acceptance tests.

A generic social feed, generic notification centre, chat, user directory and post composer are not part of this route. If they later become necessary, they are separate optional capabilities and must not displace the photo home.

## Why Piwigo wins under the revised product invariant

For the complete original community feature list, HumHub reuses more social infrastructure. For the revised non-negotiable user experience, however, the correct comparison is not “which backend has more features”; it is “which platform requires less code to remain a photo product for 10–20 years.”

Piwigo starts on the correct side of that boundary. The locally verified Bootstrap Darkroom HTTP response is already organized as an album/photo grid and includes PhotoSwipe integration markers, while Piwigo's native relation model solves Official Archive inclusion without another image row/path. Replacing those media foundations in HumHub would create more upgrade-sensitive code than the small, photo-context social gaps in Piwigo.

Therefore the selected principle is:

> Piwigo owns photos, albums, derivative generation, dates, comments and album/query ACL primitives. Class plugins own class identity plus end-to-end class/media authorization. The Theme owns presentation, never media storage or authorization.

## Rejected alternatives

### HumHub-first with a heavy Theme

Rejected as the product route because a heavy Theme would need to replace navigation and aggregation semantics, not merely CSS. It remains useful evidence for future community capability.

### Piwigo frontend plus hidden HumHub in V1

Rejected because two user/session/permission/content models would require account synchronization, cross-system authorization, link integrity, backup ordering and failure recovery. That is a microservice-like expansion with no V1 benefit.

### Separate React/Next.js photo frontend

Rejected by scope. It would duplicate routing, session handling, ACL-sensitive APIs, viewer integration and long-term frontend maintenance.

### Piwigo User Collections 16.a without a guard

Not approved. Source inspection found that add/read/render paths do not consistently re-check private-album access. The extension remains locked but quarantined until a runtime security test and an upstream-fixed release pass.

## Mandatory safety gates

Piwigo-first is accepted with these explicit gates:

1. Community's activation defaults must be replaced immediately. Its automatically created public Community album and broad registered-user permission are unsafe for this product.
2. Family low-trust and Classmate/Teacher high-trust uploads must be proven through real endpoints, including pre-approval invisibility.
3. Every photo read, collection action and album association must preserve the HERITAGE/LIVING boundary on the server.
4. `ClassIdentity` must use InnoDB for its own tables and an idempotent provisioning state machine because Piwigo Core still uses MyISAM tables that cannot join a transaction.
5. Core registration, guest access to formal albums, guest comments and rating stay disabled.
6. No Core file is modified. Plugins use event hooks and migrations; the Theme uses parent-theme fallback and the smallest possible set of template overrides.
7. User Collections stays disabled until the private-album ACL test passes. Core Favorites is only a narrower candidate and must pass the same guessed-id, revocation, render/export and media URL gates before use.
8. The official image's startup ownership scan and upgrade behaviour must be measured against a large synthetic library before NAS production.

## Evidence

- [Piwigo 16.4.0 stable release](https://github.com/Piwigo/Piwigo/releases/tag/16.4.0)
- [Piwigo server requirements](https://piwigo.org/guides/install/requirements)
- [Official Piwigo Docker image repository](https://github.com/Piwigo/piwigo-docker)
- [Community 16.f](https://piwigo.org/ext/index.php?eid=303) and [official Community workflow documentation](https://doc.piwigo.org/managing-users/community-plugin-piwigo)
- [User Collections](https://piwigo.org/ext/index.php?eid=615)
- [Bootstrap Darkroom](https://piwigo.org/ext/index.php?eid=831)
- [HumHub 1.18 release notes](https://docs.humhub.org/docs/about/releasenotes/release_notes_1_18/)
- [HumHub Gallery](https://marketplace.humhub.com/module/gallery)
