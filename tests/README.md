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

`probe-known-media-gap.ps1` is deliberately a spike probe, not a production
acceptance test: it records that Piwigo 16.4's directly served originals and
derivatives bypass album ACL when a URL is known. It must be replaced by a
Guest/FAMILY HTTP 403 regression test when the `ClassArchivePolicy` plugin's
internal MediaGuard service introduces the authorized media delivery boundary.

The archived HumHub spike remains evidence, not a supported runtime target.
