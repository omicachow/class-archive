/**
 * Process-start network guard for the synthetic V4 Chrome Stable runners.
 *
 * Playwright's context.route() is installed after Chrome starts, so it cannot
 * by itself prove that browser-startup traffic stayed local. These arguments
 * take effect when Chrome is spawned:
 *
 * - non-loopback hostnames fail inside Chromium's resolver;
 * - any raw-IP or otherwise non-resolver HTTP(S) attempt is sent to a local
 *   black-hole proxy with no DIRECT fallback;
 * - localhost / 127.0.0.1 / ::1 bypass that proxy for the two synthetic
 *   services; and
 * - QUIC, extensions, and non-proxied WebRTC UDP are disabled.
 *
 * This is defense in depth for local acceptance. The four runners still add
 * their stricter Playwright per-request 8090/8091 allowlist immediately after
 * launch. Do not add a direct proxy fallback or broaden the bypass list.
 */
export const CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS = Object.freeze([
  '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1, EXCLUDE ::1',
  '--host-resolver-retry-attempts=0',
  '--proxy-server=http://127.0.0.1:9',
  '--proxy-bypass-list=localhost,127.0.0.1,::1',
  '--disable-quic',
  '--disable-extensions',
  '--webrtc-ip-handling-policy=disable_non_proxied_udp',
]);
