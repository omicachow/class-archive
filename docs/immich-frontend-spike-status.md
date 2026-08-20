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

这严格是 `RUNTIME_TESTED` 的**内部索引生命周期**，不是 Web fork 或浏览器
验收，更不表示浏览器能访问 Immich。随后已在同一 synthetic-only 隔离模型中完成一次
真实 Gateway→Immich metadata bridge gate；具体边界见下表。

## 当前证据等级

| 项目 | 证据等级 | 当前结论 |
| --- | --- | --- |
| 官方 tag / source archive / Server 与 ML image | `STATIC` | 已校验官方 GitHub / GHCR 固定来源与 digest |
| 固定上游 `immich-web` build | `STATIC` | 使用上游声明的 pnpm 11.13.1、冻结 lockfile，先构建 `@immich/sdk` 后成功构建 Web；产物仅在 ignored source 工作副本 |
| `ClassArchivePhoto` schema / Gateway policy / 聚合过滤 | `CONTRACT_TESTED` | MariaDB semantic、映射和 42 项 policy/side-channel / canonical-delivery 合约通过 |
| 同源 Class Archive Gateway HTTP | `RUNTIME_TESTED` | Piwigo + ClassIdentity 的 37 次真实 localhost 请求、631 个断言；Family 的列表、单图、Timeline、Albums、Search 均在聚合前过滤 LIVING，canonical UUID thumbnail / preview / original 继续由 MediaGuard 交付 |
| Immich Server isolated boot | `RUNTIME_TESTED` | healthy、internal `pong`、无 host port、Piwigo RO mounts、original SHA-256 不变 |
| Immich technical user / external library / asset scan | `RUNTIME_TESTED` | ephemeral internal admin、只读 external-library scan、asset count gate、spike volumes reset 后空状态复验 |
| Immich Adapter / Gateway runtime query | `RUNTIME_TESTED` | temporary `BridgeImmichAdapter` 通过固定 internal-only bridge 查询真实 Immich；651 个断言 / 5 个 HTTP probes 验证 Classmate=2、Family=1 的 memory 聚合、People 空结果、无 media route、DTO 脱敏、原图 SHA-256 与完整 cleanup |
| Immich Web compatibility HTTP | `RUNTIME_TESTED` | 固定 upstream Web build 经 `127.0.0.1:8091 -> Piwigo nginx -> internal BFF -> canonical Gateway -> MediaGuard` 的 34 个真实 HTTP probes / 325 项断言；无 Immich host port、无原图 byte relay、无 Immich 登录 |
| Immich Web compatibility Browser | `BROWSER_E2E_TESTED`（有限） | 真 Chromium 打开合成 Classmate 时间线与照片查看器：桌面 19 张、390×844 移动端 27 张缩略图均成功加载；无可见 LIVING/媒体错误、横向溢出或受限入口。到期 session 自动回到 Class Archive 登录页。Family 浏览器路径尚未单独重新录制；其 ACL 已由 runtime HTTP gate 覆盖 |

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
调用 Immich API。metadata DTO 不返回后台媒体 URL；canonical UUID 媒体入口只会重新进入
已有 MediaGuard / Nginx X-Accel 路径。测试会用现有四个 synthetic Seat fixture 轮换临时
密码、建立可撤销的 SYSTEM_ADMIN session lease，并在 finally 中撤销会话与再次轮换凭据。
Canonical 映射是长期 Class Archive 数据，不会被该读 API 测试删除。

可重复执行真实但可丢弃的 Gateway bridge runtime gate：

```powershell
.\infra\scripts\dev.ps1 test-phase2-immich-gateway-bridge
```

它会在 internal-only Docker network 中短暂启动 bridge。bridge 没有 host port、没有
Piwigo original mount、没有 Piwigo/Immich database mount，也没有媒体路由。Class Archive
只在 `GatewayPolicy` 过滤之后发送已绑定的 opaque canonical UUID；bridge 已启用时可见
集合必须拥有完整有效 binding，任何缺失或异常都会以 generic 503 fail closed，而不会返回
部分 People/Memory 聚合。Immich asset id、Piwigo image id、路径和 checksum 永不进入浏览器 DTO。测试结束后 bridge config/token、
两条临时 asset binding、technical user/library/index 和 spike volumes 都会被精确撤销，
并再次核对 72 张 Piwigo synthetic originals 的 SHA-256。

## 已完成的 Web compatibility 边界

Web 并非把浏览器交给 Immich Server。已验证的路径是：

```text
Browser (127.0.0.1:8091)
  -> Piwigo nginx :8081
  -> internal Web compatibility process :3000
  -> internal Gateway :8088
  -> ClassIdentity / ClassArchivePolicy / MediaGuard
  -> nginx X-Accel-Redirect
```

compatibility process 仅把 policy-filtered canonical UUID DTO 投影为上游 Web
所需的只读响应。它没有 Piwigo/Immich DB、Piwigo original/derivative 或
credential mount，也不加入 Immich internal network。媒体成功时必须带安全
`X-Accel-Redirect`，由外层 nginx 传输；Node 不读取或缓存媒体字节。

官方 upstream build 保持未修改。响应注入仅做可逆 presentation compatibility：
中文“班级相册”品牌、受限写入/账号入口隐藏、过期 Piwigo session 返回真实登录页，及
含 AGPL-3.0-only 与固定 commit 的“开源许可”页面。它没有 service worker/offline
cache 或 realtime socket，不能把这些未实现能力描述为通过。

真实浏览器截图只含合成素材，存于 ignored 路径：

- `.codex-work/screenshots/phase2-web-compat/02-immich-timeline-desktop.png`
- `.codex-work/screenshots/phase2-web-compat/03-immich-timeline-mobile.png`
- `.codex-work/screenshots/phase2-web-compat/04-immich-viewer-desktop.png`

## 尚未完成，不能伪称通过

- 真实非空 People / Memories / ML / CLIP / face clustering；
- Smart Search 的 Immich index 结果接入 canonical aggregate adapter；
- Family 浏览器交互录像级验收（runtime ACL、count、thumbnail、Search 过滤已通过）；
- 性能基线、离线缓存与 realtime 设计（当前刻意不启用）。

因此当前状态为：

```text
IMMICH_RUNTIME=PASS_ISOLATED_GATEWAY_BRIDGE
CANONICAL_PHOTO_MAPPING=PASS
GATEWAY_CONTRACT=PASS
ACL_AGGREGATION_FILTERING=PASS
IMMICH_WEB_COMPAT_RUNTIME=PASS
IMMICH_WEB_BROWSER_E2E=PARTIAL_PASS_CLASSMATE_TIMELINE_VIEWER
IMMICH_WEB_FORK_FEASIBLE=YES_FOR_ISOLATED_READ_ONLY_COMPAT_SPIKE
IMMICH_FRONTEND_FEASIBLE=YES_FOR_ISOLATED_READ_ONLY_TIMELINE_SPIKE
PRODUCTION_READY=NO
```
