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
SHA-256 验证。

2026-08-19 还完成了一次**可丢弃的 Runtime external-library lifecycle**：在空的
Immich spike 中创建第一个内部 technical user、创建只指向 Piwigo `:ro` originals
的 external library、扫描当前 synthetic originals，并验证 Immich DB 中出现恰好一名
technical user、一座 library 与不少于当前 Piwigo image count 的 asset。随后测试只
销毁本 compose 所有的 `immich_db` 与 `immich_upload` volumes，重建空实例并复验
`user=0 / library=0 / asset=0`、Piwigo originals SHA-256 不变和 internal-only
network。测试凭据只在 owner-only 临时文件中存在，并在 finally 删除；没有保留可复用
Immich 登录、API key 或 library。

这严格是 `RUNTIME_TESTED` 的**内部索引生命周期**，不是 Gateway、Web fork 或浏览器
验收，更不表示浏览器能访问 Immich。

## 当前证据等级

| 项目 | 证据等级 | 当前结论 |
| --- | --- | --- |
| 官方 tag / source archive / Server 与 ML image | `STATIC` | 已校验官方 GitHub / GHCR 固定来源与 digest |
| `ClassArchivePhoto` schema / Gateway policy / 聚合过滤 | `CONTRACT_TESTED` | MariaDB semantic、映射和 39 项 policy/side-channel 合约通过 |
| 同源 Class Archive Gateway HTTP | `RUNTIME_TESTED` | Piwigo + ClassIdentity 的 29 次真实 localhost 请求、584 个断言；Family 的列表、单图、Timeline、Albums、Search 均在聚合前过滤 LIVING |
| Immich Server isolated boot | `RUNTIME_TESTED` | healthy、internal `pong`、无 host port、Piwigo RO mounts、original SHA-256 不变 |
| Immich technical user / external library / asset scan | `RUNTIME_TESTED` | ephemeral internal admin、只读 external-library scan、asset count gate、spike volumes reset 后空状态复验 |
| Immich Adapter / Web fork / Browser | 未开始 | Gateway 仍使用 `NullImmichAdapter`；浏览器无法直达 Immich，也没有 Web E2E 结论 |

可重复执行运行时隔离门：

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime
```

它不创建 Immich user、library、asset、thumbnail、ML index 或浏览器会话；也不会
暴露端口或修改 Piwigo original。

可重复执行可丢弃的 external-library runtime lifecycle：

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime-integration
```

此命令会短暂创建内部 technical user、external library 和 Immich asset index，然后仅
重置本 spike 的数据库与 upload volumes。它不接入 Gateway，不发布端口，也不保留
Immich user/library/asset 作为产品数据。

可重复执行同源 Gateway 的真实 HTTP 门：

```powershell
.\infra\scripts\dev.ps1 test-phase2-gateway-http
```

这个门只会访问 `127.0.0.1` 上的 Piwigo / ClassIdentity：它不会启动 Immich、不会
调用 Immich API，也不会返回媒体 URL 或字节。测试会用现有四个 synthetic Seat fixture
轮换临时密码、建立可撤销的 SYSTEM_ADMIN session lease，并在 finally 中撤销会话与再次
轮换凭据。Canonical 映射是长期 Class Archive 数据，不会被该读 API 测试删除。

## 尚未完成，不能伪称通过

- Hidden Technical User 的受控 provisioning 与仅 Gateway 可用的内部凭据；
- 外部图库扫描、ClassArchivePhoto ↔ Immich asset linkage；
- Gateway → Immich runtime adapter、ClassArchivePhoto ↔ Immich asset linkage；
- Timeline / People / Smart Search 的 Family side-channel 真实运行时验证；
- Immich Web fork、中文品牌、法律通知和 Gateway 媒体 URL 改写；
- ML/CPU 结果、性能和 browser E2E 截图。

因此当前状态为：

```text
IMMICH_RUNTIME=PASS_EPHEMERAL_TECHNICAL_LIBRARY_LIFECYCLE
IMMICH_WEB_FORK_FEASIBLE=PENDING_GATEWAY_WEB_E2E
IMMICH_FRONTEND_FEASIBLE=PENDING_GATEWAY_WEB_E2E
PRODUCTION_READY=NO
```
