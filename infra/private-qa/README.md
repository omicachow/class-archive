# Private real-data QA runtime boundary

This directory contains tracked, generic runtime definitions only. Actual env
files, staging media, browser profiles, screenshots and reports stay ignored.

Copy `piwigo.env.example` to `.env.piwigo` and `immich.env.example` to
`.env.immich`, generate independent secrets, then restrict both files to the
current Windows user. Both importer paths must use the ignored importer layout:

```text
.codex-work/private-real-qa/
├── staging/
└── selection/private-selection-manifest.json
```

`PRIVATE_QA_STAGING_PATH` and `PRIVATE_QA_SELECTION_MANIFEST_PATH` point to
those prepared artifacts, never to an original source. Piwigo receives them
read-only at `/private-real-qa/staging` and
`/private-real-qa/selection/private-selection-manifest.json`, with
`CLASS_ARCHIVE_PRIVATE_REAL_QA=1` enabling the deliberately gated importer.
The compatibility service receives only the tracked `./photo-ui` tree at
`/photo-ui` read-only and uses `CLASS_ARCHIVE_PHOTO_UI_ROOT=/photo-ui`.

Run the non-mutating preflight first:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\private-qa.ps1 validate
```

The runner uses dedicated project, network, port and volume identities. It has
no `down`, volume-delete, prune, reset or caller-supplied Docker-argument path;
private QA is stopped non-destructively with its `stop` action.
