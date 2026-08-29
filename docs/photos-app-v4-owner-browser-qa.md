# Photos App V4 Owner-private Chrome role harness

`tests/phase3/photos-app-v4-owner-browser-qa.ps1` is a local-only acceptance harness for the private full-library instance at ports 8190 and 8191. It uses Playwright `launchPersistentContext` with `channel: 'chrome'`, headed Google Chrome Stable, a fresh profile under `.codex-work/private-real-qa/browser/photos-app-v4-owner/`, and an ignored screenshot directory under `.codex-work/private-real-qa/screenshots/photos-app-v4/`.

It never reuses the normal Chrome profile. Chrome startup and every Playwright request are restricted to loopback, and downloads, service workers, extensions, background networking, component updates, sync, QUIC, and non-proxied WebRTC are disabled for the test process.

The harness has no mutate-on-import behavior. It must be explicitly invoked with `-ProvisionTemporaryRoles` before it can create any transient accounts:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-owner-browser-qa.ps1 -ProvisionTemporaryRoles
```

The wrapper mints a short-lived SYSTEM_ADMIN session through the existing owner-only test helper. It writes the cookie only to an ignored file with an owner-only ACL, then revokes the lease in `finally`. The Node runner uses the real browser routes to create one temporary Classmate and Teacher, has the Classmate claim its seat, issue a Family invitation, activate an Anonymous seat, and has the Family/Teacher accounts complete their normal claim journeys. Credentials, claim codes, invite codes, pages, media URLs, identifiers, and screenshots never reach stdout.

The four role journeys cover the V4 Home, Library, Viewer/comment surface, Albums, People, search overlay, and policy-visible capability state. It also checks that visible derivatives use the Class Archive media path and that the Family comment surface remains read-only. The runner does not import, copy, or modify photo source data, albums, managed originals, AI indexes, or real-library curation.

Cleanup is deliberately conservative: it freezes only the two identities created by its own run, which in turn revokes access for their attached Family and Anonymous accounts. It does not delete prior temporary identities, does not touch any existing owner account, and does not start or stop Docker. A cleanup failure is a gate failure.

This harness is not evidence of a known-LIVING private URL denial: a private library may have no suitable test LIVING asset and the harness never creates one. That exact URL/GET/HEAD/Range policy oracle remains owned by the synthetic MediaGuard regression, where the controlled LIVING fixture is known. Likewise, actual upload mutation and comment-write/delete lifecycle tests remain in their dedicated cleanup-aware runners; this role harness only verifies their V4 presentation and server-advertised capability boundaries.

Before execution, run the static contract only:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-owner-browser-qa-protocol.ps1
```
