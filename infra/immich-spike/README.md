# Immich 前端 Spike（隔离环境）

此目录不是生产部署，也不是 Piwigo 的替代品。它只用于验证 Immich Web
是否能作为 Class Archive 的照片前台候选。

在开始前必须满足：

- `immich-upstream.lock.json` 中的上游 tag、完整 commit 和镜像 digest 都已验证；
- 只使用当前 72 张合成照片；
- Piwigo 的 `uploads` / `galleries` 仅以 `:ro` 挂载；
- 没有 Piwigo 数据库、`piwigo_data`、derivative 或 scripts 挂载；
- Immich Server、ML、PostgreSQL、Valkey 与 compatibility process 都不映射 host
  port；Web shell 仅由 Piwigo nginx 的 `127.0.0.1:8091` 入口转发；
- 浏览器不能直达任何 Immich original/thumbnail endpoint。媒体必须继续经过
  Class Archive Gateway 与 MediaGuard。

Immich Server、ML、PostgreSQL、Valkey 位于独立的 internal-only
`immich_internal` 网络，不接入 Piwigo compose 网络。只读 Web compatibility
process 不接入 `immich_internal`；它仅加入单独的 `class_archive_immich_gateway`
网络，并且只能到 Piwigo 的窄 `:8088` Gateway。`immich_upload`、`immich_db`
和 `immich_model_cache` 都是可丢弃 spike 状态；它们不得承载 Piwigo 原图。

Immich Server 刻意没有 host port，连 `127.0.0.1` 也不直接发布。这可以防止
浏览器意外访问 Immich asset/original endpoint。当前已验证的 Web shell 仍不
暴露 Immich：浏览器只到 Piwigo nginx `127.0.0.1:8091`，再经过 Class Archive
Gateway、ClassIdentity/Policy 和 MediaGuard。详见
[`web-compat/README.md`](web-compat/README.md)。

不要直接执行 `docker compose up`。使用后续的受控验证脚本；它会在任何
来源、媒体只读或 localhost 约束不满足时拒绝启动。

Server 与 Machine Learning 均在 compose 内用 `tag@sha256:digest` 固定，
不能通过 `.env` 改成浮动 tag。升级必须同时更新上游 lock、官方 provenance
证据和兼容性测试，不能只替换版本字符串。

以下命令只验证本地 lock、官方 source archive 与本地 Docker image，不下载、
不启动或暴露任何容器：

```powershell
pwsh.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\immich-spike\verify-supply-chain.ps1 -RequireLocal
```

在受控 compose 已启动后，以下门只验证实际 Server 的 internal health、无 host
port、Piwigo read-only mounts 和 originals SHA-256 不变；它不创建 Immich user、
library 或 asset：

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime
```

下面的第二个 runtime gate 会在**空的、可丢弃的** spike 中短暂创建第一个内部
technical user 和指向两座 Piwigo read-only original volumes 的 external library，扫描
synthetic originals 后确认 asset count。它在 finally 中只销毁并重建本 compose 的
`immich_db` 与 `immich_upload` volumes，确认 runtime 再次为空，并复验 Piwigo
original SHA-256。它不保留 Immich 凭据、API key、user、library 或 asset，也不打开
host port：

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime-integration
```

这仍不是 Gateway、Web fork、ML 或 browser E2E 验收；浏览器仍不能直接访问 Immich。

下面的第三个受控门会短暂建立一个**metadata-only** Class Archive bridge。它只接收
已经过 Gateway policy 过滤且已绑定的 canonical photo UUID，向固定的 internal-only
Immich bridge 查询 People/Memory candidate memberships；它没有媒体 route、没有 host
port、没有 Piwigo original/数据库 mount。测试会验证同一 Immich memory 对 Classmate 与
Family 的重算可见数量不同，不会泄露 Piwigo/Immich internal ID、path 或 checksum，并在
finally 清除 bridge credential/config/binding 与 spike volumes：

```powershell
.\infra\scripts\dev.ps1 test-phase2-immich-gateway-bridge
```

这已经是 `RUNTIME_TESTED` 的 adapter integration，但仍不是 Immich Web fork、ML 或
browser E2E；浏览器仍不能直接访问 Immich。

受控的 Web compatibility 边界使用官方未修改的 Web build、Class Archive 的
canonical UUID API 与 MediaGuard。它没有 Immich 浏览器登录、用户管理、原图挂载或
host port；它的实际 localhost HTTP 回归如下：

```powershell
.\infra\scripts\dev.ps1 test-phase2-immich-web-compat
```

该脚本只证明 `RUNTIME_TESTED` 兼容协议、隔离与 ACL；浏览器交互截图和 visual QA
必须单独标记为 `BROWSER_E2E_TESTED`。
