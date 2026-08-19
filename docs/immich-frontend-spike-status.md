# Phase 2：Immich 前端 Spike 状态

范围：本文件记录独立分支 `codex/immich-photo-frontend-spike` 的实验状态，
绝不代表 Piwigo-first 架构已被替换。

## 入口条件

Phase 1.5 的 `AUTOMATED_BROWSER_QA`、`MEDIA_ATTESTATION`、`BACKUP_RESTORE`、
`CRON_MAINTENANCE`、`RECONCILIATION` 都已经通过；`ADMIN_MFA` 仍是生产放行
阻断项。因此本 spike 只能处理本机合成数据，不能连接 NAS、真实照片或公网。

## 已完成的隔离准备

- 官方 source pin：`v3.1.0` / `8aa95c67470a02a8ddedf03c2e52963af33065ff`；
- 独立 compose 项目与内部网络：不连接 Piwigo compose 网络；
- Piwigo `uploads`、`galleries` 只能作为 `:ro` external volume；
- 禁止挂载 Piwigo MariaDB、`piwigo_data`、derivative、scripts；
- Immich Server 没有 host port：浏览器在 Gateway 完成前不能直达它；
- Immich 自己的 PostgreSQL、cache、ML model cache 都是可丢弃 spike 状态。

运行下面的静态门禁会验证这些约束，但不会下载或启动容器：

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\verify-immich-spike.ps1
```

使用 `-ReadyToStart` 时还会要求 Immich Server 与 Machine Learning 的
OCI digest 都已经写入 lock；这一步现在故意仍会阻断，直到官方镜像在本机被
成功拉取并校验。

2026-08-19 的本机实测中，`git ls-remote` 已核对 tag 的完整 commit；完整
`git clone --depth 1 --branch v3.1.0` 在传输中被远端连接重置，GHCR manifest /
image pull 也没有完成。因此工作树中没有 upstream source checkout，也没有
Immich OCI image；这不是“已可运行”的状态。

## 尚未完成，不能伪称通过

- 官方 OCI image digest 与实际容器运行态；
- Immutable `ClassArchivePhoto` 映射；
- ClassIdentity → Gateway → ClassArchivePolicy 查询过滤；
- Timeline / People / Smart Search 的 Family side-channel 验证；
- Immich Web fork、中文品牌、法律通知和 Gateway 媒体 URL 改写；
- Piwigo original SHA-256 before/after、性能、ML/CPU 结果与浏览器截图。

因此当前状态为：

```text
IMMICH_WEB_FORK_FEASIBLE=PENDING_RUNTIME
IMMICH_FRONTEND_FEASIBLE=PENDING_RUNTIME
PRODUCTION_READY=NO
```
