# Private Role Shadow v1

This directory defines the disabled-by-default control-plane Shadow used for
Phase 3.4.1 destructive role tests. It is not a second Owner runtime and it is
not a full media clone.

Fixed boundary:

- Piwigo project: `class_archive_private_role_shadow_v1_piwigo`
- Immich project: `class_archive_private_role_shadow_v1_immich`
- loopback ports: `11990` and `11991`
- scope label: `com.classarchive.scope=private-role-shadow`
- schema: V18 only
- media: independent empty fixture volumes
- Owner source: logical database/control-volume reads only

The Shadow copies MariaDB using a global-read-lock logical dump because the
Owner database contains both InnoDB and MyISAM tables. Immich PostgreSQL uses a
custom-format logical dump. Before/after database and copied-control-volume
digests must match, otherwise the clone is rejected before the application is
started.

The regular Owner media volumes are neither mounted nor copied. This keeps the
Shadow small and prevents an active Owner volume from becoming an unstable
OverlayFS lower layer. Positive upload fixtures write only to the independent
Shadow volumes. Consequently this runtime may prove high-risk mutations,
revoke/recovery and container-recreation behavior, but it may not be cited as
Owner media, Viewer, People, Search or MediaGuard browser evidence.

`start` brings up only the cloned MariaDB, Piwigo and compatibility BFF. The
PostgreSQL clone remains internal and its cloned sessions/API keys are revoked;
Immich Server, ML and the metadata bridge are not started by this v1 operator.

## Commands

Static validation is always safe and performs no runtime mutation:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\infra\scripts\private-role-shadow.ps1 -Action validate
```

Every mutating action is disabled unless the operator sets the process-local
gate and supplies the explicit switch:

```powershell
$env:CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED = '1'

powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\infra\scripts\private-role-shadow.ps1 `
  -Action initialize -ConfirmPrivateRoleShadow

powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\infra\scripts\private-role-shadow.ps1 `
  -Action clone -ConfirmPrivateRoleShadow

powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\infra\scripts\private-role-shadow.ps1 `
  -Action start -ConfirmPrivateRoleShadow
```

`recreate-piwigo` verifies that the container ID changes while the dedicated
recovery-plan volume remains mounted:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\infra\scripts\private-role-shadow.ps1 `
  -Action recreate-piwigo -ConfirmPrivateRoleShadow
```

Cleanup is constrained by both the exact scope/version labels and the fixed
resource-name prefix. It does not use a prune command and preserves local
ignored evidence files:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File .\infra\scripts\private-role-shadow.ps1 `
  -Action cleanup -ConfirmPrivateRoleShadow -ConfirmCleanup
```

Do not use the Shadow until the project-specific broker/watchdog runtime test
has also proven the real `AdminService`, audit and Core revoke path.
