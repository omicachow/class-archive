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

使用 `-ReadyToStart` 时会要求 Immich Server 与 Machine Learning 的 OCI
digest 都已经写入 lock。2026-08-19 的本机验证已满足这一前置条件，命令返回
`READY_FOR_ISOLATED_START`；它只是允许受控隔离启动，绝不代表 Web 集成已经通过。

2026-08-19 的首次 `git clone --depth 1 --branch v3.1.0` 在传输中被远端连接
重置，但不把 clone 视为唯一来源。随后本机以 GitHub 官方 tag-ref API 验证
完整 commit，并从官方 codeload 取得、校验并解压 v3.1.0 source archive；Server
与 Machine Learning 的官方 GHCR `linux/amd64` digest 也已完成 manifest 与本地
pull 校验。完整 immutable evidence 记录在 upstream lock，compose 只使用
`tag@digest`。

这使 `IMMICH_SOURCE_AVAILABLE=YES` 和核心 `IMMICH_IMAGE_AVAILABLE=YES`。随后
真实 pinned Server、PostgreSQL 与 Valkey 已在独立 internal network 启动，并通过
`/api/server/ping`、digest、无 host port、只读 Piwigo originals 及 before/after
SHA-256 验证。该证据严格是 `RUNTIME_TESTED` 的**隔离启动**，不是 Gateway、
技术用户、外部图库、Web 或浏览器验收。

## 当前证据等级

| 项目 | 证据等级 | 当前结论 |
| --- | --- | --- |
| 官方 tag / source archive / Server 与 ML image | `STATIC` | 已校验官方 GitHub / GHCR 固定来源与 digest |
| `ClassArchivePhoto` schema / Gateway policy / 聚合过滤 | `CONTRACT_TESTED` | MariaDB semantic、映射和 39 项 policy/side-channel 合约通过 |
| Immich Server isolated boot | `RUNTIME_TESTED` | healthy、internal `pong`、无 host port、Piwigo RO mounts、original SHA-256 不变 |
| Immich technical user / external library / asset import | 未开始 | 不存在隐藏技术用户、库或 asset，不能称 Runtime integration |
| Immich Web fork / Gateway HTTP / Browser | 未开始 | `/api` 合约尚未绑定 HTTP，浏览器无法直达 Immich |

可重复执行运行时隔离门：

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime
```

它不创建 Immich user、library、asset、thumbnail、ML index 或浏览器会话；也不会
暴露端口或修改 Piwigo original。

## 尚未完成，不能伪称通过

- Hidden Technical User 的受控 provisioning 与仅 Gateway 可用的内部凭据；
- 外部图库扫描、ClassArchivePhoto ↔ Immich asset linkage；
- ClassIdentity → Gateway → ClassArchivePolicy 的真实 runtime 查询过滤；
- Timeline / People / Smart Search 的 Family side-channel 真实运行时验证；
- Immich Web fork、中文品牌、法律通知和 Gateway 媒体 URL 改写；
- ML/CPU 结果、性能和 browser E2E 截图。

因此当前状态为：

```text
IMMICH_RUNTIME=PASS_ISOLATED_BOOT
IMMICH_WEB_FORK_FEASIBLE=PENDING_RUNTIME_INTEGRATION
IMMICH_FRONTEND_FEASIBLE=PENDING_RUNTIME_INTEGRATION
PRODUCTION_READY=NO
```
