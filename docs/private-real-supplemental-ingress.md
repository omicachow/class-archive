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

The tracked wrapper deliberately has only generic source-collection defaults.
When extending an existing private library, an owner-local ignored invocation
must supply the already-approved collection display labels; a mismatch stops
preparation before any import. Do not place those private labels in Git, public
documentation, or a shell transcript.

## Apply boundary

The separately reviewed `apply-private-real-supplemental.ps1` operator defaults
to `validate` against the isolated Restore target. A write additionally needs
`-Action apply -ConfirmSupplementalApply`; selecting the Owner runtime also
needs `-Target owner -ConfirmOwnerRuntime`. These confirmations are required
before the operator changes maintenance state.

The one-shot apply container receives only the verified path-free manifest and
the 26 opaque JPEG presentation objects. It does not receive either source
root, the source journal, the historical full-import manifest, or the old full
staging tree. It joins only an internal MariaDB maintenance network and has no
host port. The normal Piwigo writer must be maintenance-gated and stopped
before it can run.

The operator requires exact ClassIdentity schema v16, checks all 28 source
identities as one batch before the first album/media write, and proves the
terminal `26 APPLIED + 2 DEDUPLICATED + 0 FAILED` journal and presentation
provenance before reopening Piwigo. An exact rerun is a durable 28-item no-op;
an interrupted run resumes through the same item and native-media checkpoints.
Output is aggregate-only and never includes a path or filename.

`validate` is public-safe configuration evidence, not evidence that an apply
was executed. Restore must be exercised successfully before the independent
Owner confirmation is used.

## CI

Public CI remains synthetic-only. The apply operator is covered by static
protocol tests and disposable synthetic schema tests; CI never looks for a
private artifact, environment, Docker volume, or source drive.
