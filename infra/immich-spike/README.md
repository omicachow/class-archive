# Immich 前端 Spike（隔离环境）

此目录不是生产部署，也不是 Piwigo 的替代品。它只用于验证 Immich Web
是否能作为 Class Archive 的照片前台候选。

在开始前必须满足：

- `immich-upstream.lock.json` 中的上游 tag、完整 commit 和镜像 digest 都已验证；
- 只使用当前 72 张合成照片；
- Piwigo 的 `uploads` / `galleries` 仅以 `:ro` 挂载；
- 没有 Piwigo 数据库、`piwigo_data`、derivative 或 scripts 挂载；
- 映射端口只能是 `127.0.0.1`；
- 浏览器不能直达任何 Immich original/thumbnail endpoint。媒体必须继续经过
  Class Archive Gateway 与 MediaGuard。

本 compose 项目位于独立网络 `immich_internal`，不接入 Piwigo 的 compose
网络。`immich_upload`、`immich_db` 和 `immich_model_cache` 都是可丢弃的
spike 状态；它们不得承载 Piwigo 原图。

Immich Server 刻意没有 host port，连 `127.0.0.1` 也不直接发布。这可以防止
浏览器意外访问 Immich asset/original endpoint。后续只有经 Class Archive
Gateway 的 localhost listener 才能提供 Web shell；它必须先完成
ClassIdentity/Policy 过滤和 MediaGuard URL 改写。

不要直接执行 `docker compose up`。使用后续的受控验证脚本；它会在任何
来源、媒体只读或 localhost 约束不满足时拒绝启动。
