# Photos App V4 实现边界

日期：2026-08-28
状态：冻结的实现契约；不是生产放行声明。

本文把 [Collections-first 产品架构审计](collections-first-product-audit.md) 落到可验证的 V4 边界。它不改变 Piwigo-first、ClassIdentity、ClassArchivePolicy、MediaGuard 或隔离 Immich 的职责，也不包含任何私有媒体、账号、路径、备份或凭据。

## 1. 信息架构与路由兼容

| 路由/入口 | V4 业务名称 | 约束 |
|---|---|---|
| `/home` | 精选集 | 只读已发布的精选、回忆、相册、人物等小型 projection；绝不加载完整资料库。 |
| `/photos` | 资料库 | 分页/游标化的完整档案时间轴；不是首页的隐式内容。 |
| `/people`、`/albums`、`/memories` | 人物、相册、回忆 | 保留直接可达与深链能力，但不再是桌面主导航的同权入口。 |
| 全局 search overlay | 搜索 | 在当前页面打开；可继承明确的相册范围；Esc 和浏览器 Back 关闭它。 |
| `/search` | 旧链接兼容 | 规范化重定向到 `/home?search=1` 或等价受控 intent，再由前端打开 overlay。它不再是长期独立页面。 |
| avatar menu | 账户入口 | 承载“我的”、身份、投稿、设置、管理员等按 role 可见的入口；不能只靠隐藏链接保护。 |

`/home?search=1` 是唯一允许的 document intent query。BFF 必须维持精确 allowlist；未知 query、任意 redirect target 或任意静态文件路径继续拒绝。移动端固定为“资料库 / 精选集 / 搜索”三个高频动作，其他能力通过页面上下文、搜索和 avatar menu 进入。

## 2. 前端与 BFF 模块边界

Photos App 使用原生 ES modules，不引入 React、Svelte 或前端权限真相：

| 模块 | 职责 |
|---|---|
| `photo-ui/app.js` | 路由、页面生命周期、仅展示经过 Gateway 投影的数据。 |
| `photo-ui/ui-dom.js` | 小型 DOM 构造与无障碍基础工具；不保存身份或 ACL 决策。 |
| `photo-ui/ui-search-overlay.js` | 对话框、焦点恢复、Esc/Back 协作和查询交互；不自行扩大查询范围。 |
| `photo-ui/i18n.js` | 统一简体中文业务文案；普通界面不出现 `assetId`、`personId`、HERITAGE/LIVING 等技术词。 |
| `web-compat/server.mjs` | 静态模块清单、内容安全策略、路由/query allowlist 与同源 BFF；不代理原始媒体字节。 |

新静态模块必须加入 BFF 的显式 manifest 与 asset revision 计算，不能以目录通配或任意文件读取绕过 CSP/缓存边界。`prefers-reduced-motion`、键盘焦点、dialog 焦点回归与移动端 safe area 是 UI 回归的固定检查项。

## 3. Gateway、媒体与写入合约

1. 浏览器只能请求同源、明确列入 `GatewayHttpController`/BFF allowlist 的 API。`/api/class-archive/home`、timeline、albums、people、memories、hybrid search 与 suggestions 仍先按当前 principal 过滤候选，再计算 count、cover、分页和 DTO。
2. 任何 V4 collection、pin、feedback、Spotlight 或搜索 suggestion API 都必须是同源的显式 route；未知 scope、失效 snapshot、映射缺失、数据库异常或序列化异常一律安全空态/通用错误，绝不回退到全量数据。
3. 媒体仍只能走 opaque `ClassArchivePhoto UUID → Gateway policy → MediaGuard → nginx internal X-Accel-Redirect`。UI、BFF、Collection snapshot 与 Immich 都不得生成直连原图/缩略图/Immich asset URL，也不得 relay 原始字节。
4. mutation 必须具备当前 session、CSRF、role/ownership 与审计；业务写入绝不由页面状态、隐藏按钮、localStorage 或 query 参数授权。

### Era-first 上传

V4 新增的成员/教师上传使用窄的受控写入入口，而不是把 compatibility BFF 变成通用 multipart proxy：

- CLASSMATE、TEACHER：服务端要求明确 Era，且只能取“班级历史”或“毕业后动态”。
- FAMILY：复用现有 HERITAGE `PENDING` Submission 链，只能提交班级历史。
- ANONYMOUS、Guest、role 缺失或 Era 缺失：在写入二进制、Piwigo row、Canonical Photo、Album relation 或 AI job 前拒绝。
- MIME、扩展名、大小、路径、最终文件权限和原有 MediaGuard/Safe upload 规则保持服务器端校验。

任何成功写入只会使相应 scope 的 projection 失效并排入增量工作；不会因页面浏览而开始 derivative、Face 或 Smart Search 全库任务。

## 4. Schema migration v17 / v18 预期

V4 只允许在当前 migration ledger 后**追加**下列迁移；不得重写既有 migration、签名或历史 hash：

- `0017_photos_app_v4_collection_snapshots`：版本化 Collections 读投影；
- `0018_photos_app_v4_spotlight_rotation_state`：多 Spotlight 的服务端公平轮换 checkpoint。

迁移目标是持久化以下业务状态：

- `collection_snapshot`：按 scope、projection kind、输入 revision 与 payload digest 写入的 immutable build/publish record；
- `collection_snapshot_item`：snapshot 的有序卡片/封面/已投影成员；
- `collection_snapshot_pointer`：每个 scope + projection kind 的唯一 active snapshot；
- `collection_pin`、`collection_feedback`：principal-scoped 的固定集合偏好，带撤回状态；
- `collection_maintenance_state`：后台 build、失败与 watermark 的可恢复状态。
- `spotlight_rotation_state`：每个可见 scope 的当前 hero、候选 digest、服务器 deadline 与轮换 revision；它不保存浏览器选择，也不把 Audit 当作查询状态。

业务 scope 是 `FULL` / `HERITAGE_ONLY`。为不破坏已有 `FULL` / `HERITAGE` 存储值，schema 可继续持久化 `HERITAGE`，但 Repository/Service/DTO 必须在边界处将其规范化为 `HERITAGE_ONLY`。非受控值绝不映射为 Full；必须 fail closed。

部署顺序固定为：声明精确 `v16 → v17 → v18` target → 生成并校验 pre-migration 数据库快照 → synthetic migration/rollback-style restore 验证 → 受控 owner migration → schema digest、projection 和 MediaGuard 回归。既有 v16 环境不能被“试跑”脚本隐式迁移。

## 5. Projection lifecycle

```text
archive / album / person / approved upload / Spotlight 生命周期变更
                         ↓
              scope-bound background build
                         ↓
       validate candidate + payload digest + policy inputs
                         ↓
 immutable snapshot ──atomic pointer publish──> active read projection
                         ↓
            retained superseded snapshot / audit / maintenance state
```

- 读取 Home、Memory、Spotlight、搜索建议只读取 active snapshot；不在 GET 中扫描全库、调用 ML、重算排序或替换 active data。
- 当前 policy 仍在读取时过滤 cover、item、count、person 和媒体 URL，因此 snapshot 不是授权缓存。
- 新照片、像素/checksum 改变、删除、模型 revision 变更或管理员明确重索引才可创建 AI job。改相册名、评论、pin、feedback、Spotlight 展示不重跑 Face/CLIP。
- 每个 snapshot 失败时只允许保留最近一个**完整且仍为 active pointer** 的闭合集合；Home、个人固定和搜索建议必须逐项重新按当前 policy 计算数量与封面。写入、完整 grouped search、混合 pointer、SUPERSEDED 历史行、缺失 pointer 或不一致 revision 一律 fail closed，不能把 retained snapshot 当成历史浏览功能。
- Spotlight 独立遵循持久化 24 小时 active/expired/cancelled lifecycle 与公平轮换。轮换只由后台服务器时钟推进；页面无权本地续期、选择下一候选或以浏览次数改变顺序。
- 每晚只刷新 dirty projection、过期 Spotlight 与完整性；每周仅轮换推荐卡顺序；每月只做多样性/健康审计。三者均使用 UTC watermark 与单实例 lease，且周/月任务不得触发 Face/Search AI 重建。

低元数据图片的 Memory 只使用已确认档案日期、年/学期、活动、Album context、People 和去重 Canonical Photo；上传时间、filesystem time 与不可信 EXIF 不能生成“某年今日”。

## 6. 验收门

以下门全部需要自动化证据；文档本身不把任何门标为通过：

```text
V4_ROUTE_COMPATIBILITY
GLOBAL_SEARCH_OVERLAY
SEARCH_BACK_ESC_SEMANTICS
HOME_NO_FULL_LIBRARY
LIBRARY_CURSOR_PAGINATION
AVATAR_MENU_ROLE_GATING
ERA_FIRST_UPLOAD_SERVER_SIDE
FAMILY_PENDING_HERITAGE_ONLY
ANONYMOUS_UPLOAD_DENY
COLLECTION_SCOPE_FAIL_CLOSED
COLLECTION_SNAPSHOT_ATOMIC_PUBLISH
COLLECTION_SNAPSHOT_RETENTION
SPOTLIGHT_24H_FAIR_ROTATION
AI_READ_NO_RECOMPUTE
MEDIAGUARD_ONLY
SYNTHETIC_V17_MIGRATION
OWNER_PRE_MIGRATION_SNAPSHOT
PRIVATE_BROWSER_QA
PUBLIC_BOUNDARY
PRODUCTION_READY=NO
```

最小验证矩阵覆盖 synthetic migration、Classmate/Teacher/FAMILY/Anonymous/System Admin 的 HTTP 与 Chromium 流程、guest/old URL/HEAD/Range/冻结/撤权的 MediaGuard 回归，以及真实私有图库的 local-only smoke。所有私有媒体、截图、数据库导出、embedding、来源清单和凭据必须留在 ignored local 区域；公开 CI 继续只使用 synthetic fixture。
