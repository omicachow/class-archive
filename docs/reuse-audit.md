# Piwigo-first Phase 0 reuse audit

Audit refreshed: 2026-08-16 (Asia/Shanghai)

This table records what can be delegated to Piwigo or a mature extension and
what remains class-specific. “Yes” means the feature was either exercised in
the local runtime or is a narrow upstream primitive; it never overrides a
failed security test.

| Requirement | Existing Feature | Can Reuse? | Limitations | Custom Code Needed? | Decision |
|---|---|---|---|---|---|
| Photo storage, metadata and image lifecycle | Piwigo Core images/uploads | Yes | External NAS source ownership and import reconciliation are not proven | No second media library; later thin import adapter only if needed | Piwigo is the sole V1 media system |
| Thumbnail, preview and original tiers | Core derivative generation/cache plus ClassArchivePolicy MediaGuard and nginx internal delivery | **Yes for Phase 0 media confidentiality** | Final Theme representative/format choices and browser/touch UX still need QA; identity freeze/revoke transitions remain a later gate | Maintain the thin server-side MediaGuard and same-size safe-preview fallback; do not replace Core generation or stream 200GB through PHP | 290 matrix probes, 38 mutable/path-alias probes and 16 small-photo probes pass; PHP authorizes, Piwigo re-encodes/strips when needed, and nginx sends bytes with no Core patch |
| Photo-first album grid | Core album pages + Bootstrap Darkroom | Yes for spike | Darkroom is not Apple Photos/Immich-like and is not the product theme | Photo-first child theme and timeline templates only | Keep media/query primitives; replace presentation after policy gates |
| Full-screen swipe/zoom viewer | Darkroom-bundled PhotoSwipe 4.1.3; PhotoSwipe 5.4.4 upstream | Partial | HTML/assets/preview/prefetch markers pass, but browser fullscreen, swipe, zoom and mobile touch are not yet interactively verified | Thin PhotoSwipe 5 integration plus browser/touch QA in the Class Archive Theme | Never implement gestures, zoom or lightbox from scratch |
| Chronological photo home | Core `date_creation`, calendar and image API | Partial | No continuous, event-labelled Apple Photos-style timeline by default | Theme-level grouped query/pagination; no new media table | Default route becomes Photos, not activity/feed |
| Album CRUD, trees, names, descriptions and covers | Piwigo Core albums | Yes | Role-specific create/publish policy is not the default | ClassArchivePolicy hooks | Reuse Core album model and admin tools |
| Official archive vs Community albums | Core album hierarchy and many-to-many image association | Yes as primitives | “Official” governance, ownership and admissible Era require policy | Thin archive metadata/policy relation only where Core fields are insufficient | Keep one image/original; associate it with logical albums |
| One photo in several albums without copying | Core `image_category` many-to-many relation | **Yes, verified** | Cross-Era association would leak content if allowed | Era invariant validation on every association | 72-image test found 8 multi-album images and one original path per image |
| Private gallery and no open signup | Core config, private albums/groups + MediaGuard | Yes for Phase 0 read paths | Claim-only provisioning, freeze/release/session revoke and future endpoints are not complete | ClassIdentity account lifecycle next; keep MediaGuard at every media path | Guest UI/API/media are denied; open registration, guest album access and guest comments remain disabled |
| HERITAGE/LIVING read isolation | Private root albums + group ACL/inheritance + effective-Era MediaGuard | **Yes for current album/API/media paths** | Upload, comments, collections and future endpoints still need their own action policy; ClassIdentity freeze/release is not implemented yet | Keep one central ClassArchivePolicy resolver and negative endpoint tests | Family gets HERITAGE preview only; Classmate/Teacher/Anonymous get permitted both-era previews; old-session Core ACL revocation passes and a cross-Era association denies every role |
| Identity -> Seat -> Account | None in Core | No | Piwigo accounts do not model permanent class identities or fixed seats | `ClassIdentity` plugin, InnoDB migrations, claim/invite hashes, audit and reconciliation | First custom implementation phase; do not replace Core login/password hashing |
| Session/login/password hashing | Piwigo Core users/auth | Yes | Freeze cascade, session revoke and Claim provisioning are missing | Identity lifecycle adapter around Core accounts | Reuse authentication; never store plaintext passwords |
| Role groups | Core groups and private-album ACL | Yes | Core groups do not enforce every action in the requested role matrix | Server-side policy hook; exactly one business group per account | Reuse groups as ACL inputs, not as the entire domain model |
| Community Album creation by Classmate/Teacher | Community 16.f + Core album CRUD | Potentially | Community is inactive due CSRF/input findings and defaults must be fail-closed | Small guarded adapter or upstream-fixed activation | Do not activate until the exact archive passes security and role tests |
| Family Pending -> Admin Approve | Community 16.f moderation | Potentially; workflow demonstrated in evaluation | Supported runtime keeps it inactive; moderation endpoint accepted tokenless POST | Guarded moderation action/audit; no new uploader or blob store | Reuse only after CSRF/input gate; otherwise a thin pending relation around Core upload |
| Direct Classmate/Teacher upload | Community high-trust contributor permission | Potentially | Same activation/security gate; album/Era ownership still needs enforcement | ClassArchivePolicy permission bootstrap | No bespoke uploader |
| Photo comments and admin moderation | Piwigo Core comments | Yes as storage/UI primitive | Baseline is fail-closed; Family denial, Anonymous aliasing and context rules are absent | Comment policy/render hooks | Reuse Core comment records and moderation, activate only behind role tests |
| Album comments | Comments on Albums 14.a | Not for supported lock | Unmaintained README and open Piwigo 16 regression | Thin album-comment adapter only if requirement survives Phase 1 | Do not install stale plugin merely to avoid small bounded code |
| Replies | Reply To 12.a | No | `@name` text is not a real parent relation; stale release | Minimal parent-comment relation/rendering | Preserve Core comment lifecycle; add only thread semantics |
| Comment subscriptions | Subscribe to Comments 14.a | Later | Real SMTP absent; recipient authorization and LIVING leakage untested | Configuration/ACL adapter if enabled | Optional after private notification tests, not Phase 0 |
| Likes | Core star ratings; Like/Dislike; Smileys & Votes | No exact fit | Ratings are not Likes; candidate plugins do not match account/role policy and lack sufficient licensing/maturity evidence | Small `(user_id, image_or_album_id)` Like relation and permission hook | Build only this narrow gap if Likes remain required |
| Content reporting | Core admin comment moderation | Partial | No verified user-facing report queue for photos/albums/comments | Thin report relation/admin queue attached to photo/album/comment ids | Do not import a community/feed system for reporting |
| Private simple favorites | Piwigo Core Favorites | Candidate | One flat list, no names/order; guessed-id add/read, permission revocation, export and known-media behavior are not yet tested | ACL regression first; otherwise use guarded ClassCollections | Do not call it safe until it passes the same visibility and media gates |
| Multiple named private collections | User Collections 16.a | No in current version | Confirmed ACL bypass for a known LIVING image id; optional public/email sharing is unsafe by default | Thin guarded ClassCollections relation if no upstream fix | Plugin stays absent and quarantined; never copy originals |
| Anonymous Seat account | Core account plus non-public gallery orientation | Partial | Identity ownership, hidden profile, action restrictions and admin trace are absent | `ClassIdentity` + ClassArchivePolicy's internal AnonymousPresenter | One independent account per anonymous Seat; no user directory/profile surface |
| Context-scoped anonymous pseudonym | None | No | Core renders account author identity | HMAC alias service and photo/album-context render hook | Never expose account/identity ids in ordinary HTML/API |
| Search by date/year/album/uploader/file/tag | Core search, metadata and tags | Mostly | Product search UI and role-safe uploader facet need verification | Theme/search adapter; central policy filter | Reuse Core index/query; no Elasticsearch in V1 |
| Spotlight / Featured 24h | No matching photo-first primitive | No | Forum-style pin is irrelevant; owner/TTL/audit rules are class-specific | `ClassSpotlight` InnoDB table, idempotent expiry job and theme component | Large photo/album card on Photos home; no ranking algorithm |
| Activity and notifications | History/admin events and optional comment email | Partial | Piwigo has no HumHub-like member feed; a feed is intentionally secondary | Optional projection from class-domain events after core product works | Do not add HumHub solely for Activity in V1 |
| EXIF privacy | Core metadata plus derivative pipeline | Yes as primitive | Original may retain GPS; preview metadata stripping and the final download policy still need dedicated regression | Preview metadata regression and download policy | Keep EXIF in originals if configured; do not render it in web preview (`show_exif=false`) |
| Backup and NAS portability | Docker volumes, MariaDB dump, file archives | Partial | Local backup volume is not off-device recovery; restore and external-photo coexistence need drills | Operator scripts/docs, not a second storage service | Quiesced MyISAM-safe backup now; vendor-neutral NAS work remains later |

## Phase 0 reuse outcome

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

The justified custom boundary is therefore limited to `ClassIdentity`,
`ClassArchivePolicy` with internal MediaGuard/AnonymousPresenter/collections,
`ClassSpotlight`, a small Like/Report adapter if retained, and the photo-first
Theme. Community and User Collections are not counted as reused until their
recorded security gates pass. Piwigo-first is therefore frozen for media
feasibility, while production remains blocked on ClassIdentity lifecycle,
independent admin control, Community/collections safety, NAS and deployment
gates.
