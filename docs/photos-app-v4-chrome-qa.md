# V4 Chrome Stable synthetic QA

This local-only runner is intentionally separate from the earlier Phase 3 browser suite. It launches a **headed** Google Chrome Stable process with Playwright `channel: 'chrome'` and a persistent, fresh profile under `.codex-work/browser/photos-app-v4-chrome/<run>/`; it never uses the user's Chrome profile. The profile is deleted when the run ends. Screenshots remain in the ignored `.codex-work/screenshots/photos-app-v4-chrome/<run>/` directory. A CDP result of `HeadlessChrome` is rejected.

Prepare the ignored synthetic fixture first, then pass the returned credential-file path to:

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\private-browser-fixture.ps1 -Environment synthetic
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-chrome-qa.ps1 -CredentialFile <ignored-credential-path>
```

The runner neither starts Docker nor changes runtime data. It tests V4 desktop, wide-desktop, and mobile navigation; role-specific FULL/HERITAGE_ONLY projection totals; Chrome search-overlay keyboard, focus, history, legacy-search, empty-state, and semantic-partial behavior. It also opens the real account-avatar menu on the desktop Classmate journey, verifies its modal semantics, the role-appropriate member links, close behavior, and focus return. “我的” is intentionally reachable from that account menu rather than retained as a primary navigation link.

## Search-overlay evidence

The synthetic Classmate journey uses the actual V4 surface and verifies all of the following with a new headed Chrome Stable profile:

- the visible search trigger, `Ctrl+K`, and non-editable `/` all open the native modal; the declared `Meta+K` shortcut remains part of the trigger's accessibility contract;
- modal dialog naming, `aria-modal`, background pointer blocking, initial input focus, Tab/Shift+Tab containment, Escape, and exact focus restoration to the triggering button;
- lightweight empty state only: no legacy “找到一段记忆” card, no results container, and no empty-result card before a query;
- bounded combobox/listbox suggestions have keyboard selection, while richer grouped results contain no `role="option"` nodes;
- browser Back closes the pushed overlay state before navigating the underlying page, and a legacy `/search` document canonicalizes to `/home?search=1`, opens the overlay, then cleans that compatibility intent on close;
- a leaf-album search exposes exactly the typed `ALBUM:<opaque-id>` scope alongside `ALL`; actual grouped requests carry the scoped opaque context, and switching back to `ALL` removes that context rather than retaining a hidden album filter;
- a deliberately delayed first grouped request is superseded by a second input. The run fails if Chromium neither reports cancellation nor rejects the delayed fulfillment, and it also fails if an old response repaints the newer result surface;
- a browser-local semantic outage injection preserves a real server-provided structured grouped response and changes only the optional semantic section. The UI must show the partial-state copy and keep structured results visible. This is a presentation-failure check, not evidence that the Immich semantic service itself was stopped.

The Family mobile pass independently opens the bottom-navigation search control at `390×844`, verifies the native modal/input focus and lightweight empty state, then verifies Escape returns focus to that mobile control. It is intentionally a narrow mobile overlay smoke; keyboard-only desktop behavior remains proven in the Classmate desktop pass.

The runner toggles `prefers-reduced-motion: reduce` and requires the loaded design token rule to take effect. It does not claim that a screen-reader product was manually operated; the runtime assertions are limited to DOM semantics and bounded live-region behavior.

The final bounded status line includes the running browser's CDP-reported product and version, rather than a host-file version. The fresh run blocks service workers, downloads, background networking, component updates, sync, and non-loopback document resources. On failure it prints only a stage and a fixed gate code; it deliberately does not print credentials, media identifiers, raw browser diagnostics, or screenshot contents. Rotate/remove the fixture with the fixture script after a completed acceptance run.

This runner deliberately does **not** claim Viewer, MediaGuard GET/HEAD/Range, or Era-upload coverage. The dedicated deep and upload-lifecycle Chrome modules own those gates. The pre-V4 `photo-ui-browser-qa.mjs` suite has broader role flows, but it launches by executable path and does not create a persistent Chrome Stable profile; it is therefore useful regression coverage, not final V4 Chrome evidence.
