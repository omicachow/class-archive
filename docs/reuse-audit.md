# Piwigo-first reuse audit through Phase 1

Audit refreshed: 2026-08-16 (Asia/Shanghai)

This table records what can be delegated to Piwigo or a mature extension and
what remains class-specific. “Yes” means the feature was either exercised in
the local runtime or is a narrow upstream primitive; it never overrides a
failed security test.

| Requirement | Existing Feature | Can Reuse? | Limitations | Custom Code Needed? | Decision |
|---|---|---|---|---|---|
| Photo storage, metadata and image lifecycle | Piwigo Core images/uploads | Yes | External NAS source ownership and import reconciliation are not proven | No second media library; later thin import adapter only if needed | Piwigo is the sole V1 media system |
| Thumbnail, preview and original tiers | Core derivative generation/cache plus ClassArchivePolicy MediaGuard and nginx internal delivery | **Yes for the final localhost baseline** | Final Theme representative/format choices and browser/touch UX still need QA | Maintain the thin server-side MediaGuard and same-size safe-preview fallback; do not replace Core generation or stream 200GB through PHP | Final 290 matrix + 38 mutable/path-alias + 16 small-photo + 75 Pending-state probes pass and restore 72 images |
| Photo-first album grid | Core album pages + Bootstrap Darkroom | Yes for spike | Darkroom is not Apple Photos/Immich-like and is not the product theme | Photo-first child theme and timeline templates only | Keep media/query primitives; replace presentation after policy gates |
| Full-screen swipe/zoom viewer | Darkroom-bundled PhotoSwipe 4.1.3; PhotoSwipe 5.4.4 upstream | Partial | HTML/assets/preview/prefetch markers pass, but browser fullscreen, swipe, zoom and mobile touch are not yet interactively verified | Thin PhotoSwipe 5 integration plus browser/touch QA in the Class Archive Theme | Never implement gestures, zoom or lightbox from scratch |
| Chronological photo home | Core `date_creation`, calendar and image API | Partial | No continuous, event-labelled Apple Photos-style timeline by default | Theme-level grouped query/pagination; no new media table | Default route becomes Photos, not activity/feed |
| Album CRUD, trees, names, descriptions and covers | Piwigo Core albums | Yes | Role-specific create/publish policy is not the default | ClassArchivePolicy hooks | Reuse Core album model and admin tools |
| Official archive vs Community albums | Core album hierarchy and many-to-many image association | Yes as primitives | “Official” governance, ownership and admissible Era require policy | Thin archive metadata/policy relation only where Core fields are insufficient | Keep one image/original; associate it with logical albums |
| One photo in several albums without copying | Core `image_category` many-to-many relation | **Yes, verified** | Cross-Era association would leak content if allowed | Era invariant validation on every association | 72-image test found 8 multi-album images and one original path per image |
| Private gallery and no open signup | Core config, private albums/groups + MediaGuard + ClassIdentity Claim provisioning | Yes for current read/registration paths | Member password reset and future endpoints are not complete | Keep the thin ClassIdentity lifecycle adapter and MediaGuard at every media path | Guest UI/API/media are denied; open registration remains disabled; one-time Classmate/Teacher Claims are the supported account-creation path |
| HERITAGE/LIVING read isolation | Private root albums + group ACL/inheritance + effective-Era MediaGuard | **Yes for current album/API/media paths** | Collections and future endpoints still need their own action policy | Keep one central MediaGuard resolver backed by explicit ClassIdentity Principals and negative endpoint tests | Family gets HERITAGE preview only; Family Pending bytes are Admin-only; Classmate/Teacher/Anonymous get permitted both-era previews; freeze/session/Core ACL revocation pass and a cross-Era association denies every role |
| Identity -> Seat -> Account | No Core equivalent; ClassIdentity 0.1.0 | Custom, implemented foundation | Piwigo accounts do not model permanent class identities or fixed seats; later account-level governance actions remain | Maintain the isolated InnoDB plugin, hashed Claims/Invites, audit and saga compensation | Four checksum-attested migrations and ten tables pass exact semantic drift checks; Core still owns credentials/login |
| Session/login/password hashing | Piwigo Core users/auth + ClassIdentity hooks | Yes | Active Family release, member password reset and Header API-key lifecycle are not fully accepted; Admin MFA and fresh empty-volume rehearsal remain | Thin Identity/Principal lifecycle adapter around Core accounts | Claim provisioning, Identity freeze and session/key revocation reuse Core functions; SYSTEM_ADMIN live rotation returns `sessions=revoked` and no long-lived plaintext remains in environment/test configuration |
| Role groups | Core groups and private-album ACL | Yes | Core groups do not enforce every action in the requested role matrix | Server-side policy hook; exactly one business group per account | Reuse groups as ACL inputs, not as the entire domain model |
| Independent System Admin and business console | Piwigo Core admin authentication/shell + ClassIdentity SYSTEM_ACCOUNT/Admin Console | Yes as a bounded composition | Core admin status alone cannot model a Seat-less system identity or preserve business audit; Spotlight and some account governance remain absent | Thin explicit Principal, server-side route guard and nine business tabs | Reuse Core login/CSRF/templates while keeping SYSTEM_ADMIN outside every Identity/Seat and keeping technical Core Admin separate |
| Role-specific mutation boundary | Piwigo Web API/direct controllers + ClassIdentity CapabilityGuard | Partial reuse | Core/plugin method availability does not imply a Class Archive business permission | Maintain a method classifier and explicit role policy | Known actions follow their role rule; unknown state-changing WS methods fail closed even for Classmate/Teacher |
| Community Album creation by Classmate/Teacher | Community 16.f + Core album CRUD | Potentially | Community is inactive due CSRF/input findings and defaults must be fail-closed | Small guarded adapter or upstream-fixed activation | Do not activate until the exact archive passes security and role tests |
| Family Pending -> Admin Approve | Community 16.f moderation | **Implemented with a thin custom boundary** | Community remains inactive because its CSRF, array-parameter and broad default-permission risks are not accepted; custom uploader/review state machine and 75-probe Pending gate pass | ClassIdentitySubmissionService validates MIME/size/path/permissions, stores private Pending bytes, audits review, then calls Core only on approval | One original enters Piwigo once; Family cannot read Pending bytes; rejected bytes remain Admin-only pending cleanup policy |
| Direct Classmate/Teacher upload | Community high-trust contributor permission | Potentially | Same activation/security gate; album/Era ownership still needs enforcement | ClassArchivePolicy permission bootstrap | No bespoke uploader |
| Photo comments and admin moderation | Piwigo Core comments + ClassIdentity CapabilityGuard/AnonymousPresenter | Yes as storage/UI primitive | Ordinary baseline remains fail-closed; reports, replies, Likes and final enablement policy are absent | Keep the narrow role guard, context alias and redacted moderation adapter | Family denial and a controlled Anonymous comment/alias/audited-resolution flow pass; reuse Core comment records |
| Album comments | Comments on Albums 14.a | Not for supported lock | Unmaintained README and open Piwigo 16 regression | Thin album-comment adapter only if requirement survives Phase 1 | Do not install stale plugin merely to avoid small bounded code |
| Replies | Reply To 12.a | No | `@name` text is not a real parent relation; stale release | Minimal parent-comment relation/rendering | Preserve Core comment lifecycle; add only thread semantics |
| Comment subscriptions | Subscribe to Comments 14.a | Later | Real SMTP absent; recipient authorization and LIVING leakage untested | Configuration/ACL adapter if enabled | Optional after private notification tests, not Phase 0 |
| Likes | Core star ratings; Like/Dislike; Smileys & Votes | No exact fit | Ratings are not Likes; candidate plugins do not match account/role policy and lack sufficient licensing/maturity evidence | Small `(user_id, image_or_album_id)` Like relation and permission hook | Build only this narrow gap if Likes remain required |
| Content reporting | Core admin comment moderation | Partial | No verified user-facing report queue for photos/albums/comments | Thin report relation/admin queue attached to photo/album/comment ids | Do not import a community/feed system for reporting |
| Private simple favorites | Piwigo Core Favorites | Candidate | One flat list, no names/order; guessed-id add/read, permission revocation, export and known-media behavior are not yet tested | ACL regression first; otherwise use guarded ClassCollections | Do not call it safe until it passes the same visibility and media gates |
| Multiple named private collections | User Collections 16.a | No in current version | Confirmed ACL bypass for a known LIVING image id; optional public/email sharing is unsafe by default | Thin guarded ClassCollections relation if no upstream fix | Plugin stays absent and quarantined; never copy originals |
| Anonymous Seat account | Core account + ClassIdentity Seat/Principal/CapabilityGuard | **Implemented** | Reporting queue is not yet connected | Maintain one independent account per Anonymous Seat and the fail-closed action guard | Hidden profile/discovery, no upload/album/rating/favorite, independent credentials, Admin enable/disable and audit trace are verified |
| Context-scoped anonymous pseudonym | ClassIdentity AnonymousPresenter and resolution service | **Implemented** | Reporting is not yet connected; ordinary UI remains deliberately narrow | Maintain HMAC alias derivation plus HTML/API output filtering and audited admin resolution | Same-context stability, cross-context unlinkability, redaction, governance page and 211 real HTTP assertions pass |
| Search by date/year/album/uploader/file/tag | Core search, metadata and tags | Mostly | Product search UI and role-safe uploader facet need verification | Theme/search adapter; central policy filter | Reuse Core index/query; no Elasticsearch in V1 |
| Spotlight / Featured 24h | No matching photo-first primitive | No | Forum-style pin is irrelevant; owner/TTL/audit rules are class-specific | `ClassSpotlight` InnoDB table, idempotent expiry job and theme component | Large photo/album card on Photos home; no ranking algorithm |
| Activity and notifications | History/admin events and optional comment email | Partial | Piwigo has no HumHub-like member feed; a feed is intentionally secondary | Optional projection from class-domain events after core product works | Do not add HumHub solely for Activity in V1 |
| EXIF privacy | Core metadata plus derivative pipeline | Yes as primitive | Original may retain GPS; preview metadata stripping and the final download policy still need dedicated regression | Preview metadata regression and download policy | Keep EXIF in originals if configured; do not render it in web preview (`show_exif=false`) |
| Backup and NAS portability | Docker volumes, MariaDB dump, file archives | Partial | Local backup volume is not off-device recovery; restore and external-photo coexistence need drills | Operator scripts/docs, not a second storage service | Quiesced MyISAM-safe backup now; vendor-neutral NAS work remains later |

## Reuse outcome through Phase 1

Piwigo already owns the expensive, failure-prone wheels: photo ingest, original
records, metadata, derivative generation, caches, album CRUD/tree, album ACL,
many-to-many album placement, favorites, basic photo comments, admin tools,
search primitives and Web API. PhotoSwipe owns gesture navigation and zoom.

The previously open direct-media boundary is now closed without a Core patch:
PHP resolves the live session, managed role, effective Era, Core image/album ACL
and original-download setting on every request, then nginx performs the actual
file transfer. The 290-probe HTTP matrix passes, including logout,
account-switch, HEAD, Range, path/query tampering and cache revalidation. A
controlled database outage fails with a generic 503 and no media bytes.

The justified custom boundary is therefore limited to `ClassIdentity` with its
Principal/lifecycle/Admin/CapabilityGuard/AnonymousPresenter,
Submission/Governance/Archive services, `ClassArchivePolicy` with MediaGuard,
`ClassSpotlight`, a small Like/Report adapter if retained, and the
photo-first Theme. Community and User Collections are not counted as reused
until their recorded security gates pass. Piwigo-first remains frozen for
media feasibility. Production remains blocked on the unimplemented lifecycle
remaining lifecycle/business safety gates, persisted MediaGuard HTTP attestation, Admin MFA,
Community/upload chmod and mutation-audit safety, collections, restore/cron,
NAS and public-deployment gates.
