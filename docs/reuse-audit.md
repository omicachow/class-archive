# Phase 0 reuse audit

Audit date: 2026-08-10 (Asia/Shanghai)

This audit combines official documentation, locked source inspection, a running HumHub 1.18.4 instance, authenticated HTTP smoke requests, and browser verification. A Marketplace compatibility label by itself is not treated as functional acceptance.

| Requirement | Existing Feature | Can Reuse? | Limitations | Custom Code Needed? | Decision |
|---|---|---|---|---|---|
| Private accounts, sessions, password hashing | HumHub Core User/Auth/Profile/Password/Session | Yes | Claim, permanent Identity/Seat ownership, invite lifecycle, freeze cascade and audit are class-specific | Thin `class-identity` domain and transactional provisioning service | Reuse Core records and authentication; never build a second user system |
| No guest content or open registration | HumHub authentication settings and private Spaces | Yes | Claim endpoints must remain the only account-creation surface | Claim controllers only | Guest setting and all public/member invite switches are off; guest requests to both era Spaces redirect to login and `/user/auth/registration` returns 404 |
| HERITAGE/LIVING visibility boundary | Two private/invisible HumHub Spaces and membership | Yes for discovery/read boundary | Space roles are Owner/Admin/Moderator/Member/Network/Guest, not CLASSMATE/TEACHER/FAMILY/ANONYMOUS | Identity-driven membership assignment; no duplicate era ACL | Created `高中档案` and `后来的我们` as private/invisible Spaces; Family is never assigned to LIVING |
| Role-specific actions inside a Space | Core/Module permissions plus global Groups | Partial | A Space member role cannot by itself distinguish Family or Anonymous from Classmate; permission ALLOW from any group wins | Thin server-side role permission bridge and invariant of exactly one business role group per account | Reuse permission managers, but add explicit denial/allow rules for upload, create album, comment, like and post |
| Space Gallery and multiple Community Albums | Gallery 1.7.1 | Yes | Multiple top-level galleries, not an arbitrary nested year/event tree; no per-album collaborator list | Theme/navigation adapter only; thin collaboration layer only if later required | Reuse Gallery CRUD and Space module activation |
| Profile Gallery | Gallery 1.7.1 profile module | Yes | Each user activates it unless provisioning/default configuration enables it | Provisioning setting only | Browser verified that enabling Gallery adds the Profile `Gallery` route and menu |
| Gallery descriptions, ordering and cover | Gallery 1.7.1 | Mostly | Title/description/sort order exist; cover is a gallery thumbnail/first-media presentation, not a rich archive taxonomy | Theme or metadata adapter only | Reuse; do not rebuild album presentation |
| Media upload, validation, preview and file lifecycle | Gallery Media + HumHub File | Yes | Gallery upload immediately creates published Media; no moderation state in the upload controller | Family uses a thin pending submission layer that still stores HumHub File records | Browser-visible authenticated upload of a locally generated PNG succeeded; no custom media library |
| Gallery comments, replies and likes | HumHub Comment/Like on Gallery and Media content | Yes | Role-specific denial is not supplied by Gallery; direct Gallery pages and stream entries expose the controls | Permission hooks for Family/Anonymous only | Browser verified Comment/Like actions on both CustomGallery and Media |
| Gallery activity in stream | Gallery Media `autoAddToWall` + HumHub Stream | Yes | Theme wording/filtering may need product polish | Theme/filter only | Uploaded Media appeared as a normal Space stream entry with gallery, size, image, comment and like actions |
| One physical file per uploaded Media | Gallery Media -> HumHub File relation | Yes | A Media row belongs to exactly one `gallery_id`; Gallery has no many-to-many logical placement | Official archive/collections reference the Media id | Source and runtime model confirm one File object per Media; never copy the blob for logical organization |
| Deny Family original downloads | Gallery/HumHub File download handler | No configurable role toggle | Readable users see an explicit download action and direct file URL | Thin server-side download authorization/preview policy, controlled by Admin setting | Do not hard-code; default Family original download off, while web previews remain readable |
| Private simple bookmarks | Content Bookmarks 1.2.0 | Yes | Flat `(user_id, content_id)` only | None for simple saved content | Browser saved a Gallery Media stream entry and verified it on the owner's private Bookmarks page |
| Multiple named private photo collections | Content Bookmarks 1.2.0 | No | No collection name, category, custom order or grouping | Thin `ClassCollections` relation: owner, name, media id, order | Build only this missing organizational layer; never copy media |
| Reference-only cross-Space stream sharing | Share Content 1.1.1 | Yes, narrowly | Only published content with public content visibility; creates a target stream wrapper, not a second Gallery placement | None for ordinary share; not suitable for Official Archive | Browser shared a sample Post into HERITAGE and rendered the original with source/target and `Share (1)` without copying content |
| Put Gallery Media into Official Archive without copying | Share Content + Gallery | No | Media in the private era Space had no Share control; Share cannot attach a Media row to another Gallery even when shareable | Thin official-archive relation table referencing existing Gallery Media/content | Use an association, not Share Content and never a second file |
| Reporting posts/comments/media/albums | Report Content 1.2.2 + WallEntry/Comment hooks | Partial | Non-author UI needs multi-user verification; grid/lightbox and album pages do not guarantee a report control | Thin report action placement for missing Gallery surfaces; attribution remains in ClassIdentity | Install and reuse central queues; profanity list remains empty and auto-block remains off |
| Spotlight 24h | HumHub native content pin/unpin | Yes as primitive | Ordinary owners cannot use native pin; ownership, one-active rule, TTL and audit are absent | Thin `class-spotlight` endpoint/job/audit | Browser verified native pin and unpin on Gallery Media; wrap it rather than invent ranking |
| Hidden anonymous account | Core `VISIBILITY_HIDDEN` and hidden-user queries | Partial | UserPicker uses `active()`, direct profiles remain reachable, GUID mentions can bypass discovery, and rendered payloads expose the account | Query/profile/mention guards plus complete anonymous comment rendering | Hidden User is necessary but insufficient; REST stays disabled until output filtering is proven |
| Context-scoped anonymous pseudonym | None | No | Core comments always render the HumHub author | HMAC alias service and server-side render/API adapter | Derive alias from secret + context id + anonymous Seat id; admins resolve through Seat ownership |
| Family submission moderation | Gallery/File plus Core content states | Partial | Gallery upload controller publishes immediately and granting Gallery WriteAccess grants too much | Thin pending submission record/review service using HumHub File/Media | Do not build a second uploader or media store |
| Stream, comments, replies, likes, activity and notifications | HumHub Core | Yes | Anonymous display and business-role restrictions remain | Event/permission hooks only | Reuse Core end to end |
| 2FA for administrators | TwoFA 1.2.3 | Yes | Recovery/reset and Claim-created accounts need testing; module-level license is undeclared | Configuration/audit only | Installed; production enforcement deferred until recovery acceptance passes |

## Runtime evidence

- Administration showed exactly five active locked modules: Gallery 1.7.1, Report Content 1.2.2, Content Bookmarks 1.2.0, Share Content 1.1.1 and TwoFA 1.2.3.
- Both era Spaces are private/invisible. Their Space menus use the enabled Gallery and Share Content modules.
- A LIVING Community Album named `昨晚我们又打 MC 了` was created through Gallery. A deterministic synthetic PNG was uploaded through the real authenticated Gallery endpoint, then verified in the browser in the album and Space stream.
- The Gallery Media entry was bookmarked and appeared on the user's private Bookmarks page. The same Media was pinned and unpinned using the native HumHub control.
- Profile Gallery activation was verified through the user's native Profile Modules page.
- Share Content successfully displayed one original Post in HERITAGE as a share wrapper. Source inspection and the UI both show that it references the original; it does not create a Gallery Media membership or file copy.
- Report Content configuration is active with an empty profanity list and automatic blocking unchecked.
- Unauthenticated HTTP requests to both era Space URLs returned `302` to `/user/auth/login`; the ordinary registration URL returned `404`.

Test media is generated locally by `infra/scripts/generate-test-images.php`. It contains geometric graphics and fixture labels only, never real class photos or people, and generated files remain outside Git.

## Phase 0 gate

The reuse decisions are now specific enough to begin ClassIdentity without building ClassArchive media features prematurely. Multi-role and anonymous end-to-end checks remain acceptance tests for Phase 1/2 because their accounts must be created through the new Identity/Seat invariant, not as unbound temporary HumHub users.
