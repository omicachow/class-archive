# Acceptance test register

These are mandatory end-to-end authorization/lifecycle gates. Each row will link to an automated test when implemented.

`Pass` below refers to the final coordinated localhost Phase 1/Phase 0 baseline
unless the row explicitly names a narrower scope. Production deployment gates
remain separate.

| ID | Acceptance criterion | Phase | Status |
|---:|---|---:|---|
| 1 | Guest cannot view formal content, including known media URLs | 1/2 | **Pass (Phase 0 MediaGuard):** Guest is denied HERITAGE/LIVING thumbnails, previews and originals through every tested public media route |
| 2 | Open registration is unavailable | 1 | Pass (Phase 0 baseline) |
| 3 | Classmate claim code is single-use | 1 | **Pass (Phase 1):** reissue invalidates the previous code; successful use consumes the replacement and replay is denied |
| 4 | A classmate cannot claim Seat 1 twice | 1 | **Pass (Phase 1):** one current binding/Principal is enforced by transaction, uniqueness and real HTTP replay tests |
| 5 | Default classmate has at most three Family Seats | 1 | **Pass (Phase 1):** the configurable default materializes exactly three Family Seats; no authorization code relies on ordinals |
| 6 | Teacher ID creates only one account | 1 | **Pass (Phase 1):** one Teacher Seat/current binding/Principal is enforced and Claim replay is denied |
| 7 | Family cannot comment | 2 | **Pass for current Core/direct endpoints:** CapabilityGuard rejects WS and direct picture comment mutations; Community remains inactive |
| 8 | Family cannot like | 2 | **Pass for current Core/direct endpoints:** Core rating and equivalent mutation paths are denied; the final Like feature is not implemented |
| 9 | Family cannot create a public album | 2 | **Pass for current Core/direct endpoints:** album creation is denied; Community remains inactive |
| 10 | Family cannot access LIVING content, including known media URLs | 2 | **Pass for read paths (Phase 0 MediaGuard):** album/API and known LIVING thumbnail/preview/original requests are denied; Family HERITAGE preview remains allowed |
| 11 | Family can upload a HERITAGE submission | 2 | **Pass (Phase 1 real HTTP):** Family-only custom submission boundary accepts validated synthetic images and rejects LIVING/unauthorized roles |
| 12 | Family upload defaults to Pending | 2 | **Pass (Phase 1 real HTTP):** submission row, private original and safe thumbnail are created as `PENDING`; Family receives status metadata only |
| 13 | Submission enters archive only after Admin approval | 2 | **Pass (Phase 1 focused gate):** 75 Pending-media probes cover Family denial, Admin visibility, reject restoration and approve-to-HERITAGE handoff; Community remains inactive |
| 14 | Classmate upload publishes directly | 2 | Pending |
| 15 | Teacher upload publishes directly | 2 | Pending |
| 16 | Classmate can create Community Album | 2 | Pending |
| 17 | Teacher can create Community Album | 2 | Pending |
| 18 | One image record/original file can enter multiple logical albums without another image row/path/file | 2 | Pass (Phase 0 fixture): 72 image rows resolve to 72 referenced physical originals; 8 images have same-Era multi-album relations; cross-Era album association and duplicate image rows sharing one canonical original both fail closed for every actor |
| 19 | Anonymous Seat cannot upload | 1/2 | **Pass for current Core/direct endpoints:** WS upload and Community route are denied |
| 20 | Anonymous Seat cannot create an album | 2 | **Pass for current Core/direct endpoints** |
| 21 | Anonymous Seat cannot like | 3 | **Pass for current Core/direct endpoints:** rating/favorite/preference mutation paths are denied |
| 22 | Anonymous Seat can comment | 3 | **Pass (Phase 1 controlled HTTP):** comment remains denied until presenter attestation, then succeeds with redacted stored/display author |
| 23 | Same Anonymous Seat has stable alias in one context | 3 | **Pass:** pure HMAC contract and two real reads of one photo context match |
| 24 | Same Anonymous Seat has different aliases across contexts | 3 | **Pass:** two real photo contexts produce different aliases |
| 25 | Ordinary user cannot derive anonymous identity | 3 | **Pass for tested HTML/API surfaces:** photo, recent-comment, session, profile, user-list, search and uploader surfaces expose no underlying mapping |
| 26 | Admin can resolve anonymous ownership | 1/3 | **Pass through the dedicated service:** SYSTEM_ADMIN + reason is required and the resolution itself appends a redacted Audit event |
| 27 | Anonymous user is absent from ordinary People Directory | 1 | **Pass for tested discovery/profile/search/API surfaces** |
| 28 | Spotlight accepts only owner-created content | 3 | Pending |
| 29 | Spotlight expires automatically after configured TTL | 3 | Pending |
| 30 | One formal account has at most one active Spotlight | 3 | Pending |
| 31 | Admin freeze immediately prevents login | 1 | **Pass (Phase 1):** Identity freeze invalidates an authenticated page/media session and rejects a new login |
| 32 | Admin reset never stores plaintext password | 1 | **Pass for SYSTEM_ADMIN CLI rotation:** no-echo/STDIN input, Core hash only, auth-epoch/Audit transition and session/auth-key revoke pass live with `sessions=revoked`; member password-reset workflow remains pending |
| 33 | Community activation removes its broad default permission and creates only explicit role/Era rules | 2 | Blocked: isolated spike found unsafe defaults; no tracked regression yet |
| 34 | Community Approve/Reject requires a valid CSRF token, Admin permission and an idempotent pending state | 2 | Blocked: tokenless POST reproduced in isolated spike |
| 35 | Community upload accepts one authorized scalar album id; array/unknown/cross-Era category fails without a partial upload | 2 | Blocked: unsafe array path reproduced in isolated spike |
| 36 | Favorites/Collections cannot add, read, count, cover, export or retain an image after its album access is absent/revoked | 2 | Blocked: User Collections cross-ACL bypass reproduced; Core Favorites unverified |
| 37 | Guest, FAMILY→LIVING, frozen/released accounts and revoked sessions are denied known originals/derivatives through GET, HEAD, Range, cache revalidation and every direct storage path | 1/2 | **Partial:** 290 MediaGuard probes plus Phase 1 Identity-freeze/session tests pass; release of an already-active Family account and its old session is not implemented |
| 38 | Login, Claim, Invite and comment mutations enforce server-side rate limits without logging secrets/raw identifiers | 1/3 | **Partial:** 22 deterministic rate-limit assertions and redacted bucket storage pass; full real-HTTP exhaustion/recovery coverage for every listed mutation is pending |
| 39 | Production Admin accounts have a tested 2FA path and recovery procedure | 5 | Pending; no SMTP/real production credentials in V1 local spike |
| 40 | Session revoke/freeze invalidates active UI, API, auth-key and remember-me paths on the next request | 1 | **Partial:** active UI/media session and new-login denial pass; Remember Me is disabled and Core sessions/auth keys are revoked, but a real Header API-key lifecycle and active Family release remain pending |
| 41 | Database, app/proxy logs, audit and responses contain no plaintext password, Claim/Invite/reset validator, cookie or authorization secret | 1 | **Pass for implemented surfaces / partial future scope:** long-lived administrator key count is zero, the env ACL is restricted, and Claim/Invite/Admin paths pass exact secret scans and Audit canaries; future member reset and business mutations remain uncovered |
| 42 | Upload rejects spoofed MIME, non-image/polyglot payloads and over-limit files before publication | 2 | **Pass for ClassIdentity Family submissions:** finfo/getimagesize, extension allowlist, size/pixel limits, random storage refs and 0660 permissions are enforced; future Community upload remains blocked |
| 43 | Every custom mutation passes CSRF, authorization, XSS output-encoding and parameterized-SQL negative tests | 1-4 | **Partial:** current Admin/public mutations have CSRF, same-origin, authorization, typed input and prepared-statement coverage; complete stored/reflected XSS negatives for every present and future field remain pending |
| 44 | Any enabled impersonation records actor, target, reason, start/end and session revocation in Audit Log | 1 | Pending; impersonation is not enabled in the Piwigo baseline |
| 45 | Backup failure cannot publish a complete-looking bundle; a full restore into empty volumes passes hashes and role/media gates | 5 | Partial: fail-closed bundle creation/checksums pass; empty-volume restore pending |
| 46 | PhotoSwipe opens, navigates, zooms and swipes correctly at desktop/mobile viewports without loading originals in the grid | 4 | Partial: HTTP markers/preview pass; supported-browser and touch QA pending |
| 47 | Release has a reviewed project LICENSE/NOTICE and retrievable offline archive plan for locked OCI images and extension ZIPs | Release | **Partial:** project LICENSE/NOTICE are present; authorized offline OCI/extension retention and retrieval are pending |
| 48 | Family Invitation expiry/revoke releases its Seat; Admin reissue increases generation, returns the replacement only once, and every old token stays invalid | 1 | **Pass (Phase 1 real HTTP/DB):** expiry/revoke/reissue, generation, replay denial, one-time response and exact cleanup all pass |
| 49 | FAILED_MANUAL, COMPENSATION_REQUIRED and stale provisioning rows force PRODUCTION BLOCKED; only proven post-Core failures expose bounded audited compensation | 1 | **Pass (Phase 1 real HTTP/DB):** a durable MANUAL_COMPENSATION_ATTEMPT/COMPENSATING checkpoint precedes Core quarantine; the safe incident is compensated and ambiguous stale state stays blocked/non-repairable |
| 50 | Guest and every Seat role are denied the Class Archive Admin Console; only an active independent SYSTEM_ADMIN is allowed | 1 | **Pass:** Guest, Classmate, Teacher, Family and Anonymous direct routes return 403; SYSTEM_ADMIN succeeds and has no Identity/Seat/account binding |
| 51 | Migration checksum/schema drift, disabled enforcement and unsafe concurrent plugin publication fail closed | 1 | **Pass:** 9 schema-semantic, 8 enforcement-context, 116 enforcement-fault and 12 workflow-lock checks pass |
| 52 | Admin Dashboard cannot imply production readiness without a complete MediaGuard HTTP attestation | 1 | **Partial by design:** the Dashboard shows `PRODUCTION BLOCKED`; persisted digest-bound HTTP attestation is not implemented |
| 53 | A Community `moderation_pending` image is inaccessible by known thumbnail/preview/original URL to every Seat role; only SYSTEM_ADMIN may review it, and malformed/duplicate state denies everyone | 2 | **Pass (real HTTP and complete aggregate):** 75 GET probes pass against the exact pinned Community table, including malformed/duplicate fail-closed and restoration to 72 images; Community remains inactive and no upload/publication workflow is claimed |
