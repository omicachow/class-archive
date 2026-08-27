# Private supplemental photo ingress

This boundary closes a small, already-audited remainder of an owner-local
library without reopening the full-library importer. It is intentionally split
into two trust zones:

1. the Windows host prepares presentation surrogates from an ignored inventory
   and ignored audit report;
2. Docker can see only a path-free supplemental manifest and opaque presentation
   files with hash-derived names.

The operator supports only **prepare / verify / compose-validate**. It **does not import**,
apply database migrations, start a container, or write to the owner runtime. A
later apply boundary must be separately reviewed and explicitly confirmed after
isolated restore validation.

## Local artifacts

All generated artifacts stay below the ignored private-QA supplemental work
root. The wrapper rejects paths outside that root, Git-tracked paths, paths not
covered by ignore rules, and every symlink, junction, or other reparse point.
Before preparation it restricts private input reports and output directories to
the current owner, SYSTEM, and Administrators. It then applies and verifies an
explicit owner-only DACL on every generated directory and file.

Preparation requires `-ConfirmPrivateSourceRead`. The underlying Python tool
checks source size, mtime, and SHA-256 before and after decoding; source files
are never moved or modified. Terminal output is limited to aggregate source and
presentation counts. Local paths and filenames are captured and suppressed.

## Compose ingress boundary

`docker-compose.supplemental-ingress.override.yml` is standalone. Do not merge
it with the historical full-library overlay. Its single service has:

- no network and no published or exposed port;
- a read-only root filesystem, all Linux capabilities dropped, and
  `no-new-privileges`;
- exactly two read-only bind mounts with `create_host_path: false`;
- the supplemental runtime manifest at its fixed path;
- the opaque supplemental staging directory at its fixed path.

It has no original-source mount, no source inventory, no source journal, no
historical full-import manifest, and no owner database/media volume. The
`compose-validate` action resolves the Compose model and fails closed unless all
of those invariants hold. It never calls `up`, `run`, or `exec`.

## Operator shape

The owner-local command pattern is:

```powershell
pwsh.exe -NoProfile -File .\infra\scripts\private-real-supplemental.ps1 prepare `
  -ConfirmPrivateSourceRead

pwsh.exe -NoProfile -File .\infra\scripts\private-real-supplemental.ps1 verify

pwsh.exe -NoProfile -File .\infra\scripts\private-real-supplemental.ps1 compose-validate
```

These commands are examples of the gated workflow, not evidence that private
preparation or import has been run. `compose-validate` requires the ignored,
owner-only owner environment only to resolve the fixed Piwigo image and numeric
UID/GID; none of its secrets enters the service environment.

## CI and future apply boundary

Public CI remains synthetic-only. It runs source-level protocol checks and the
synthetic MPO preparation fixture, never looks for a private drive or local
manifest, and never carries a private artifact as a CI output.

A future import/apply operator must be a different, explicit command. Before it
may write, it must prove the restored schema version, bind only the verified
supplemental ingress, preflight all source identities in one batch, preserve
transformed-source provenance, and enqueue derivative/AI work only for newly
applied canonical photos. This document does not authorize that action.
