# V4 synthetic Chrome localhost launch guard

The V4 synthetic Chrome acceptance runners use a fresh, ignored profile and
only target the engineering services on `127.0.0.1:8090` and
`127.0.0.1:8091`. Playwright's request route is still the application-level
allowlist, but it is registered after Chrome starts. The launch guard adds a
process-start boundary for the interval before that route exists.

## Process-start rules

`tests/phase3/photos-app-v4-chrome-localhost-guard.mjs` is shared by the main,
deep, scope, and upload Chrome runners. It supplies these fixed flags:

- `--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1, EXCLUDE ::1`
- `--host-resolver-retry-attempts=0`
- a `127.0.0.1:9` manual proxy with only loopback bypasses and no `DIRECT`
  fallback
- `--disable-quic`, `--disable-extensions`, and a non-proxied-UDP WebRTC
  policy

The resolver rule denies non-loopback hostnames before normal Chrome host
resolution. The local black-hole proxy catches non-resolver HTTP(S) attempts,
including literal external IP targets, while the bypass list preserves only the
loopback synthetic services. QUIC and non-proxied WebRTC UDP are disabled so
they cannot introduce a separate direct UDP path.

Chromium documents manual proxy fallback/bypass behavior in its
[`net/docs/proxy.md`](https://chromium.googlesource.com/chromium/src/+/HEAD/net/docs/proxy.md)
and host resolver mappings in
[`net/dns/mapped_host_resolver.h`](https://chromium.googlesource.com/chromium/src/+/HEAD/net/dns/mapped_host_resolver.h).

## Evidence boundary

`PHOTOS_APP_V4_CHROME_LOCALHOST_GUARD_PROTOCOL` is a static check: it proves
that all four runners import the same launch guard, retain their `context.route`
allowlist, and do not include a direct-proxy fallback. It starts neither Chrome
nor Docker.

This is defense in depth, not a claim that an operating-system firewall or a
packet capture ran. A future browser acceptance run may add local net-log or
firewall evidence if an OS-level no-egress proof is required. The guard does not
change the existing localhost-only product boundary, and it never permits
private `8190`/`8191` endpoints.
