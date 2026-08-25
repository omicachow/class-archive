# Private full-library blue/green runtime

This directory defines the local-only candidate used to promote a complete
private photo library without disturbing either the engineering synthetic
baseline or the existing sample-QA runtime.

- `127.0.0.1:8091` remains the synthetic engineering and public-CI baseline.
- `127.0.0.1:8191` remains the old private sample-QA runtime until cutover.
- `127.0.0.1:8291` is the isolated full-library staging compatibility UI.

The runnable candidate is named `class_archive_private_full_v3_*`. It uses
distinct projects, networks, and Docker volumes, with only explicit loopback
listeners. The runner never runs `docker down`, prune, volume removal, or a
delete action during staging/cutover.

## Storage boundary

Writable Piwigo originals, derivatives, and backup state use Docker-managed
local volumes. This is required because Docker Desktop resolves a named volume
inside its own POSIX filesystem; it preserves the required `0660` media mode
and ACL behavior. Raw host filesystem binds are not accepted for writable
runtime media.

The only host bind accepted by the Piwigo candidate is an ignored, opaque
staging copy. It contains hashed filenames plus a path-free layout marker, is
mounted read-only, and is used only while the importer copies verified files
into the Docker-managed gallery. It is neither an original source directory
nor a Piwigo gallery.

The full AI/Immich index is deliberately deferred until photo/album cutover
has succeeded and old sample-QA capacity may be released. The compatibility
BFF remains the policy client for the browse path; browsers still never reach
an Immich media endpoint.

## Local setup

All endpoint env files are ignored and owner-only. Generate them only after a
verified opaque staging copy and manifest exist:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\provision-private-full-storage.ps1 provision
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\prepare-private-full-env.ps1 -StagingPath <owner-local-opaque-staging-path>
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\test-private-full-storage-preflight.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\private-full.ps1 validate
```

The preflight uses the canonical-byte total from the ignored manifest, a
conservative derivative budget, a control-volume budget, and a 10 GiB safety
reserve. It intentionally excludes full ML indexing, which has its own later
capacity gate.

Then bring up the blue candidate and bootstrap only its isolated Piwigo
database and compatibility BFF:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\private-full.ps1 up-staging
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\bootstrap-private-full-piwigo.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\deploy-private-full-class-plugins.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\private-full.ps1 runtime-staging
```

The importer's local manifest and source journal live only below ignored
`.codex-work/private-real-full`. They must never be committed or copied into
CI artifacts. Public CI continues to use synthetic fixtures only.

## Cutover

`private-full.ps1 cutover` requires an ignored owner approval record proving
full import, source integrity, browser E2E, and file-mode policy. Until then,
the old private sample runtime remains intact. A later, separately verified
cleanup may remove only the old private sample runtime data; it must never
remove synthetic baseline fixtures or original sources.

After the owner endpoint has passed its post-cutover smoke test, the separate
retirement command may release the stopped sample-QA containers, their exact
private-QA volumes, and only the ignored sample staging copy. It requires the
same ignored approval record to include `old_sample_qa_retirement=APPROVED`,
validates that the full candidate is actually bound to 8190/8191 (not merely
running as the 8290/8291 staging candidate), and requires an explicit command
line acknowledgement:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\retire-private-qa-after-full-cutover.ps1 validate
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\retire-private-qa-after-full-cutover.ps1 retire -ConfirmRetirement
```

The command has no wildcard delete mode and cannot target the 8091 synthetic
baseline, full-library volumes, or either original source directory.

This remains a local private QA/runtime. It does not claim production-ready
at-rest storage, NAS recovery, public ingress, HTTPS, or administrator MFA.
