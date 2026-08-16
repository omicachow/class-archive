# Class Archive / 班级数字档案馆

Class Archive is a long-lived, private digital community for one high-school class, its teachers, and invited family members. HumHub is the platform core; this repository contains deployment configuration, thin custom modules, tests, and documentation. It does **not** fork or vendor HumHub Core.

## Engineering rule

`Reuse > Adapter > Extension > Rewrite`

- HumHub Core and mature Marketplace modules own accounts, Spaces, content, stream, comments, likes, notifications, files, and Gallery CRUD.
- Local code is limited to the class-specific identity, submission, anonymity, archive-linking, and Spotlight rules that cannot be configured safely.
- All authorization is enforced server-side and covered by automated tests.
- Database data and uploads live outside container layers in independent Docker volumes.

## Current status

Phase 0 is complete. Versions are locked in [docs/dependency-matrix.md](docs/dependency-matrix.md), and every reuse decision is tracked in [docs/reuse-audit.md](docs/reuse-audit.md).

## Local prerequisites

- Windows 11 with WSL2 Ubuntu, or a standard Linux host
- Docker Engine 20.10.13+ and Docker Compose v2
- A Chromium-based browser, Firefox, or Edge

On this workstation Docker Engine and Compose run inside the existing Ubuntu WSL2 distribution. The application binds only to `127.0.0.1`.

The `up` helper keeps that WSL distribution alive with a project-scoped hidden process while the site is running. `stop` or `down` terminates only that keepalive process after stopping this Compose project; it does not terminate the Ubuntu distribution or unrelated WSL workloads.

## Local start

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\init-dev-env.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 up
```

On a brand-new pair of Docker volumes, open <http://localhost:8088> and complete the upstream HumHub web installer before installing Marketplace modules. Use the database settings already supplied by Docker, choose a manual/minimal setup, and apply the exact private-network settings in [docs/first-run.md](docs/first-run.md). The generated administrator password is in the ignored local `.env`; it is never printed or committed.

After the installer reaches the signed-in home page, install and enable the locked modules:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 modules
```

Subsequent starts need only `dev.ps1 up`. The generated local secrets remain in `.env`, which Git ignores. The module command intentionally fails if HumHub Core is not yet installed instead of silently claiming a complete setup.

Useful commands:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 ps
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 logs
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 yii module/list
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 modules-verify
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 stop
```

Marketplace modules are pinned in `infra/modules.lock.json`. The installer downloads only those exact archives, verifies their official SHA-256 values, validates the embedded module manifest and archive paths, and refuses to overwrite a different installed version.

## Data boundaries

- `class_archive_humhub_data`: uploads, generated assets, Marketplace modules, runtime configuration, and logs.
- `class_archive_mariadb_data`: MariaDB files.
- `class_archive_backups`: explicit backup output; it is not a substitute for an off-device backup.
- `modules/`: only project-owned HumHub modules, bind-mounted read-only through an independent module loader path.

No real class photos, names, claim codes, database dumps, or credentials belong in Git.
