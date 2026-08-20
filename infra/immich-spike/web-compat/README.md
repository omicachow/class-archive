# Immich Web compatibility boundary

`server.mjs` serves the verified, **unmodified** Immich Web v3.1.0 static
build through a narrow read-only compatibility boundary. It is neither an
Immich reverse proxy nor a second authorization service.

```text
Browser (127.0.0.1:8091)
  -> Piwigo nginx :8081
  -> internal Web compatibility process :3000
  -> internal Piwigo Gateway :8088
  -> ClassIdentity + ClassArchivePolicy + MediaGuard
  -> nginx X-Accel-Redirect file delivery
```

Only the first listener is loopback-published. The compatibility container has
no host port, no Piwigo/Immich database mount, no original/derivative mount,
and joins only `class_archive_immich_gateway`. Its three mounts are read-only:
the adapter code, verified upstream static Web build, and an intentionally empty
replacement for the upstream image's `/data` volume.

The outer nginx preserves the browser's source address so Piwigo's IP-bound
session check remains active. The BFF accepts only the fixed nginx ingress
header and a single validated source address; it cannot be used as a generic
cookie relay. Every browser request is mapped to a public `ClassArchivePhoto`
UUID. Piwigo IDs, Immich asset IDs, checksums and physical paths never enter
the compatible browser DTO.

`/api/assets/{class_photo_uuid}/thumbnail` and `/original` are compatibility
names only. They issue a fresh canonical media authorization request. Successful
media responses must contain a safe internal `X-Accel-Redirect` target; the
outer nginx transfers the file, so Node never buffers a derivative or original.
Missing or malformed targets fail closed with a generic 503. The final media
decision remains MediaGuard.

The BFF permits GET/HEAD plus the upstream SDK's bounded read-only POST search.
All write/account/admin routes are denied. People and Memories intentionally
return empty results until a canonical membership adapter can prove aggregate
filtering; the spike does not fabricate or leak a count. Sharing, favorites,
utilities, archive, locked-folder, upload, account-management, purchase and
Immich administration affordances are hidden for usability and denied on the
server for authorization.

The injected presentation layer changes only the response document: it brands
the visible shell as **班级相册**, redirects an expired/revoked Piwigo session
to the real Class Archive sign-in route, and adds an **开源许可** notice. It
does not modify the verified upstream build. The notice records Immich,
AGPL-3.0-only and the fixed v3.1.0 commit. The spike deliberately has no
service worker/offline cache and no real-time socket service.

This is `RUNTIME_TESTED` only after
`tests/phase2/immich-web-compat-http.ps1` passes. Browser interactions are a
separate `BROWSER_E2E_TESTED` evidence level and do not turn this experimental
boundary into a production delivery architecture.
