# First local installation

This procedure applies only when both persistent Docker volumes are new. It deliberately uses HumHub's upstream installer rather than maintaining a second installer that would track internal setup forms and migrations.

## 1. Start the pinned stack

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\init-dev-env.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 up
```

Open <http://localhost:8088>. Docker already supplies the MariaDB host, database, username and password to HumHub. Do not replace them with host-local credentials.

## 2. Complete the upstream HumHub installer

Use these project settings:

- Network name: `Class Archive / 班级数字档案馆`
- Language: Simplified Chinese (`zh-CN`)
- Time zone: `Asia/Shanghai`
- Use case/configuration: manual or minimal
- Guest access: disabled
- Anonymous/open registration: disabled
- Approval-based public registration: disabled
- Member invitations by email: disabled
- Member invitations by link: disabled
- Group choice during registration: disabled
- Sample content: disabled

Create the local administrator using `HUMHUB_ADMIN_USERNAME`, `HUMHUB_ADMIN_EMAIL` and `HUMHUB_ADMIN_PASSWORD` from the ignored `.env`. HumHub stores its password hash in MariaDB; neither this repository nor an administrator can recover a plaintext password from the database.

The installer may still create an upstream welcome Space or welcome Post. They are local bootstrap artifacts and must not be confused with seeded archive data.

## 3. Install the locked Marketplace modules

Only after the installer reaches the signed-in home page, run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 modules
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 modules-verify
```

The installer fetches the exact archives in `infra/modules.lock.json`, verifies SHA-256 and embedded manifests, rejects unsafe archive paths, and refuses to overwrite a different module version.

## 4. Verify the private boundary

```powershell
curl.exe --noproxy "*" -I http://localhost:8088/
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\dev.ps1 yii module/list
```

The only published application address must remain `127.0.0.1:8088`; MariaDB has no host port. Before any public or NAS deployment, follow the later production and restore-drill gates instead of reusing the development settings.
