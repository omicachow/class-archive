# Test strategy

Automated tests are added alongside each phase. Final acceptance uses the real
Piwigo application, MariaDB, installed extensions, generated derivatives and
browser-visible behavior. Mocks are not accepted as the final gate.

Phase 0 tracked gates cover private-by-default UI/API access,
HERITAGE/LIVING album isolation, media-tree filesystem permissions,
multi-album association without a second image/original path, extension
integrity, derivative-first pages and PhotoSwipe integration markers. Community
moderation and the User Collections attack were demonstrated in an isolated
evaluation runtime, but do not yet have sanitized mainline regression tests;
they remain explicit blocked gates in `docs/acceptance-tests.md`.

`probe-known-media-gap.ps1` is a fast regression for the exact bypass found by
the architecture spike. `media-guard-http.ps1` is the production-facing gate:
it exercises the role/Era/variant matrix and known-path, logout, account-switch,
HEAD, Range, query, encoding, normalization, guessing and cache boundaries with
290 real HTTP requests. That matrix passes on the current localhost synthetic
stack. Allowed GET/Range bodies must contain image magic, denied bodies must
not, Range must be an exact 32-byte `206`, and HEAD must return no body. A
controlled database-outage probe also returned a generic 503 with no media
bytes or diagnostics.

`media-guard-tiny-preview.ps1` adds 16 HTTP probes around Piwigo's
same-as-source derivative branch. It proves a no-download action is a
metadata-stripped, separately hashed SAFE_PREVIEW, while adding `download`
remains an ORIGINAL request and Family/Anonymous cannot obtain those bytes.
Its synthetic database row, original and derivative are physically removed in
`finally`, and the image count returns to 72.

`media-guard-state-transitions.ps1` covers mutable authorization state such as
managed-group/album permission removal, temporary cross-Era association, and
Piwigo's non-unique `images.path`. Its default 38-probe run copies one image
row onto the same physical original under the other Era and proves raw source,
derivative, and both action ids through `part=e` and `part=r` fail closed for
Family, Classmate, and Admin.
The row and association are removed exactly and the 72-image unique-path model
is rechecked in `finally`. The opt-in database-outage run adds two probes and
requires a generic 503. ClassIdentity freeze/release/explicit session
revocation remains a separate production gate
even though Core ACL revocation, logout and account-switch revalidation pass.

The archived HumHub spike remains evidence, not a supported runtime target.
