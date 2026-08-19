# Immich 前端 Spike：上游与升级策略

## 当前锁定目标

| 项目 | 值 |
|---|---|
| 上游 | `immich-app/immich` |
| release tag | `v3.1.0` |
| source commit | `8aa95c67470a02a8ddedf03c2e52963af33065ff` |
| 许可证 | GNU AGPL-3.0-only |
| 本地状态 | 仅隔离 spike；尚未启动 Immich 服务 |

精确镜像引用和摘要记录于
[`infra/immich-spike/immich-upstream.lock.json`](../infra/immich-spike/immich-upstream.lock.json)。
其中任一尚未取得的 digest 都是启动阻断条件，而不是可接受的浮动依赖。

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
- 将建立不可公开的 `ClassArchivePhoto UUID → piwigo_image_id / immich_asset_id /
  canonical physical path / SHA-256` 映射。Piwigo ID 和 Immich asset ID 都不是
  公共 canonical identity。
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
2. `git fetch upstream --tags`，获取 candidate tag 的完整 commit；
3. 更新 Web patch / Gateway adapter，不能修改 Immich DB schema；
4. 重建隔离的 synthetic-only stack；
5. 重新跑 Classmate / Teacher / Family ACL、People/Search side-channel、MediaGuard
   delivery、original SHA-256、不复制 original 的兼容性套件；
6. 重新评估 AGPL 对应源码和法律通知；
7. 只有全部通过才更新 lock。

## 当前结论

`IMMICH_WEB_FORK_FEASIBLE` 尚未确定。上游 source commit 已固定，但本机尚未
验证官方镜像 digest、运行态、Gateway ACL 和 Web integration。当前不启动、
不暴露端口给非 localhost，也不接触真实照片或 NAS。
