# Acceptance test register

These are mandatory end-to-end authorization/lifecycle gates. Each row will link to an automated test when implemented.

| ID | Acceptance criterion | Phase | Status |
|---:|---|---:|---|
| 1 | Guest cannot view formal content, including known media URLs | 1/2 | Blocked: UI/API pass; direct original/derivative URLs still leak |
| 2 | Open registration is unavailable | 1 | Pass (Phase 0 baseline) |
| 3 | Classmate claim code is single-use | 1 | Pending |
| 4 | A classmate cannot claim Seat 1 twice | 1 | Pending |
| 5 | Default classmate has at most three Family Seats | 1 | Pending |
| 6 | Teacher ID creates only one account | 1 | Pending |
| 7 | Family cannot comment | 2 | Pending |
| 8 | Family cannot like | 2 | Pending |
| 9 | Family cannot create a public album | 2 | Pending |
| 10 | Family cannot access LIVING content, including known media URLs | 2 | Blocked: album/API pass; direct media authorization pending |
| 11 | Family can upload a HERITAGE submission | 2 | Pending |
| 12 | Family upload defaults to Pending | 2 | Pending |
| 13 | Submission enters archive only after Admin approval | 2 | Pending |
| 14 | Classmate upload publishes directly | 2 | Pending |
| 15 | Teacher upload publishes directly | 2 | Pending |
| 16 | Classmate can create Community Album | 2 | Pending |
| 17 | Teacher can create Community Album | 2 | Pending |
| 18 | One image record/original file can enter multiple logical albums without another image row/path/file | 2 | Pass (Phase 0 fixture): 72 image rows resolve to 72 referenced physical originals; 8 images have multiple album relations |
| 19 | Anonymous Seat cannot upload | 1/2 | Pending |
| 20 | Anonymous Seat cannot create an album | 2 | Pending |
| 21 | Anonymous Seat cannot like | 3 | Pending |
| 22 | Anonymous Seat can comment | 3 | Pending |
| 23 | Same Anonymous Seat has stable alias in one context | 3 | Pending |
| 24 | Same Anonymous Seat has different aliases across contexts | 3 | Pending |
| 25 | Ordinary user cannot derive anonymous identity | 3 | Pending |
| 26 | Admin can resolve anonymous ownership | 1/3 | Pending |
| 27 | Anonymous user is absent from ordinary People Directory | 1 | Pending |
| 28 | Spotlight accepts only owner-created content | 3 | Pending |
| 29 | Spotlight expires automatically after configured TTL | 3 | Pending |
| 30 | One formal account has at most one active Spotlight | 3 | Pending |
| 31 | Admin freeze immediately prevents login | 1 | Pending |
| 32 | Admin reset never stores plaintext password | 1 | Partial: Piwigo bootstrap/fixture hashes verified; ClassIdentity reset pending |
| 33 | Community activation removes its broad default permission and creates only explicit role/Era rules | 2 | Blocked: isolated spike found unsafe defaults; no tracked regression yet |
| 34 | Community Approve/Reject requires a valid CSRF token, Admin permission and an idempotent pending state | 2 | Blocked: tokenless POST reproduced in isolated spike |
| 35 | Community upload accepts one authorized scalar album id; array/unknown/cross-Era category fails without a partial upload | 2 | Blocked: unsafe array path reproduced in isolated spike |
| 36 | Favorites/Collections cannot add, read, count, cover, export or retain an image after its album access is absent/revoked | 2 | Blocked: User Collections cross-ACL bypass reproduced; Core Favorites unverified |
| 37 | Guest, FAMILY→LIVING, frozen accounts and expired signatures get 403 for known originals/derivatives through GET, HEAD, Range, cache-hit/miss and every direct storage path | 1/2 | Blocked: current Guest probe returns 200 |
| 38 | Login, Claim, Invite and comment mutations enforce server-side rate limits without logging secrets/raw identifiers | 1/3 | Pending |
| 39 | Production Admin accounts have a tested 2FA path and recovery procedure | 5 | Pending; no SMTP/real production credentials in V1 local spike |
| 40 | Session revoke/freeze invalidates active UI, API, auth-key and remember-me paths on the next request | 1 | Pending |
| 41 | Database, app/proxy logs, audit and responses contain no plaintext password, Claim/Invite/reset validator, cookie or authorization secret | 1 | Pending |
| 42 | Upload rejects spoofed MIME, non-image/polyglot payloads and over-limit files before publication | 2 | Pending |
| 43 | Every custom mutation passes CSRF, authorization, XSS output-encoding and parameterized-SQL negative tests | 1-4 | Pending |
| 44 | Any enabled impersonation records actor, target, reason, start/end and session revocation in Audit Log | 1 | Pending; impersonation is not enabled in the Piwigo baseline |
| 45 | Backup failure cannot publish a complete-looking bundle; a full restore into empty volumes passes hashes and role/media gates | 5 | Partial: fail-closed bundle creation/checksums pass; empty-volume restore pending |
| 46 | PhotoSwipe opens, navigates, zooms and swipes correctly at desktop/mobile viewports without loading originals in the grid | 4 | Partial: HTTP markers/preview pass; supported-browser and touch QA pending |
| 47 | Release has a reviewed project LICENSE/NOTICE and retrievable offline archive plan for locked OCI images and extension ZIPs | Release | Pending |
