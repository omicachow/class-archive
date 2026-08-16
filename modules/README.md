# Project-owned HumHub modules

Only thin, class-specific extensions belong here:

- `class-identity`: Identity, Seat, Claim/Invite, anonymous-seat mapping, governance, and audit log.
- `class-archive`: era metadata, family submission review, and reference-only official archive relationships when existing modules cannot cover them.
- `class-spotlight`: permission checks and expiring use of HumHub's native pin mechanism.

These modules load from an independent module loader path. They must never patch HumHub Core or copy media files already managed by HumHub/Gallery.
