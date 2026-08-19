# Immich 前端 Spike：上游与升级策略

## 当前锁定目标

| 项目 | 值 |
|---|---|
| 上游 | `immich-app/immich` |
| release tag | `v3.1.0` |
| source commit | `8aa95c67470a02a8ddedf03c2e52963af33065ff` |
| 许可证 | GNU AGPL-3.0-only |
| 本地状态 | 官方 source archive、Server 与 ML 镜像已验证；isolated Server 与 ephemeral technical-user/external-library lifecycle runtime 已通过 |

精确镜像引用和摘要记录于
[`infra/immich-spike/immich-upstream.lock.json`](../infra/immich-spike/immich-upstream.lock.json)。
其中任一尚未取得的 digest 都是启动阻断条件，而不是可接受的浮动依赖。

## 官方供应链证据（2026-08-19）

`git clone` 不是唯一来源确认方式。本机已从 **官方 GitHub** 取得固定 tag
archive，并从 **官方 GHCR** 取得本机 `linux/amd64` 平台的固定镜像：

| 对象 | 官方来源 / 固定值 | 本机验证 |
|---|---|---|
| Tag ref | GitHub REST `refs/tags/v3.1.0` → `8aa95c67470a02a8ddedf03c2e52963af33065ff` | `STATIC` |
| Source archive | `codeload.github.com/.../refs/tags/v3.1.0` | 74,961,232 bytes; SHA-256 `af0fba69cc5830093392de4f5576eeb7f2ccf28ba55154b7598e10f596fdfb40`; tar validation and local extraction passed |
| Immich Server | `ghcr.io/immich-app/immich-server:v3.1.0@sha256:079cc990b26a88d71f96027341c67329cb11829d4c341ce33b3718fe0f84cbfa` | GHCR manifest and local Docker pull verified |
| Machine Learning | `ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05` | GHCR manifest and local Docker pull verified |

archive 本体、GHCR manifest 与 Docker image inspect 证据在 ignored 的
`.codex-work/immich-supply-chain/`；完整、机器可读字段在 upstream lock 中。
source archive 的 tag→commit 对应关系来自 GitHub 的官方 tag-ref API，archive
内部 `package.json`、`web/package.json`、`server/package.json` 都是 `3.1.0`。

这部分只是 `STATIC` 供应链证据，不等同于 Gateway、ACL 或浏览器验证。隔离
dependency images 已取得；真实 Server runtime 已另由两个 runtime gates 验证：

- `test-phase2-runtime`：isolated boot、internal ping、无 host port、read-only mount
  和 original SHA-256；
- `test-phase2-runtime-integration`：短暂创建内部 technical user 与只读 external
  library，扫描 synthetic originals，确认 asset count，再销毁本 spike volumes 并复验
  空状态与 original SHA-256。

第二项只证明上游 v3.1.0 可在本隔离模型下执行 external-library 生命周期；其技术用户
和 asset index 都已随 spike volume reset 清除。它不证明 Gateway ACL、Web integration
或 Photo UI 已通过。
可重复运行 [`verify-supply-chain.ps1`](../infra/immich-spike/verify-supply-chain.ps1)
以验证本机 archive SHA-256、source 版本、compose digest pin 和本地 Docker
repo digest；该脚本不下载或启动容器。

## 架构边界

Immich 不会成为 Class Archive 的身份、权限或媒体真相：

```text
ClassIdentity Principal
        ↓
Class Archive Gateway
        ↓
ClassArchivePolicy / MediaGuard
        ↓
Piwigo originals and derivatives

Immich Web / index / ML cache
        ↑
presentation compatibility only
```

- 技术用户只允许作为 Immich 的内部兼容账号；不向浏览器暴露 Immich 登录、
  注册、用户管理、API key、Partner Sharing 或管理员界面。
- 已建立不可公开的 `ClassArchivePhoto UUID → piwigo_image_id / nullable immich_asset_id /
  canonical physical path / SHA-256` 映射。Piwigo ID 和 Immich asset ID 都不是
  公共 canonical identity；Immich link 仍为 nullable，且没有 Gateway→Immich adapter。
- 所有 Timeline、People、Search、Memories、计数和缩略图结果都要在 Gateway
  进入浏览器前按 ClassArchivePolicy 过滤。Family 永远不得通过 count、People
  或 Search 侧信道得知 LIVING。
- 浏览器媒体继续走已验证的 MediaGuard；Immich 原图、缩略图与 asset endpoint
  不对浏览器开放。当前 Immich Server 连 localhost host port 也没有；之后只由
  经过 Gateway 的受控 localhost listener 提供 Web shell。

## 上游变更分类

| 类别 | 允许范围 |
|---|---|
| `UPSTREAM_UNCHANGED` | Immich Server、数据库 schema、ML、Timeline、Search、Person clustering |
| `WEB_PATCH` | 隐藏 Immich 账户页面、中文产品文案、入口与合法通知 |
| `GATEWAY_ADAPTER` | ClassIdentity session / ClassArchivePolicy 过滤 / MediaGuard URL 交付 |
| `BRANDING_PATCH` | “班级相册”呈现；保留版权、许可证与 Appropriate Legal Notices |
| `AUTH_COMPAT_PATCH` | 仅内部技术用户兼容对象，绝不成为授权真相 |

任何 Web fork 必须保留 AGPL 所需的对应源码与显著法律通知。公开运行前需另外
完成许可证、源码提供和适当法律通知审查。

## 升级流程

1. 保留当前 lock 和可复现的测试结果；
2. 从官方 GitHub tag ref / git 或官方 codeload archive 获取 candidate tag 的完整 commit；
3. 更新 Web patch / Gateway adapter，不能修改 Immich DB schema；
4. 重建隔离的 synthetic-only stack；
5. 重新跑 Classmate / Teacher / Family ACL、People/Search side-channel、MediaGuard
   delivery、original SHA-256、不复制 original 的兼容性套件；
6. 重新评估 AGPL 对应源码和法律通知；
7. 只有全部通过才更新 lock。

## 当前结论

`IMMICH_WEB_FORK_FEASIBLE=PENDING_GATEWAY_WEB_E2E`。官方 source archive 与
Server / ML 镜像已取得并校验，isolated Server 以及 ephemeral technical-user /
external-library runtime lifecycle 已通过；Class Archive 自有同源 Gateway 已对
Piwigo + ClassIdentity 跑过真实 ACL/聚合 HTTP 回归，但 Gateway runtime adapter、
Immich asset linkage、Web integration 和浏览器仍未验证。当前不暴露 Immich 端口给
浏览器，也不接触真实照片或 NAS。
