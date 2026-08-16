# ClassArchivePolicy

This Piwigo plugin owns Class Archive server-side policy adapters. Its first
shipping boundary is MediaGuard:

- public source/derivative paths are routed through a session-aware gateway;
- Piwigo album/image ACL and the HERITAGE/LIVING role policy are both checked;
- ambiguous, unmapped and cross-era media fail closed;
- PHP authorizes, while nginx transfers authorized bytes with
  `X-Accel-Redirect`;
- no Piwigo Core file is modified.

`SYSTEM_ADMIN` is represented by the Piwigo administrator status only during
the Phase 0 media spike. ClassIdentity will replace Admin Console authorization
with an explicit system-account binding; an administrator is never a Seat.
