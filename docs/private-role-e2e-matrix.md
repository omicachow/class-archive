# Private Real Role E2E 能力矩阵

## 结论与证据边界

本文档是 **STATIC_CODE_AUDIT**，不是浏览器 E2E 通过证明。它从当前代码中的实际路由、角色判定和服务调用生成待测矩阵，不把需求文字中的“应该允许”当成已实现权限。

当前计数规则下：

```text
EXPOSED_PRODUCT_OPERATIONS=200
CAPABILITY_MATRIX_ROLE_ROWS=1400
CAPABILITY_MATRIX_COMPLETE=PASS_STATIC_CODE_AUDIT
```

“一个可独立请求的 route pattern + HTTP method”计为一项操作。Piwigo canonical Gateway 和 Web compatibility BFF 是两个独立可攻击边界，因此分别计数；静态资源、健康检查、重定向和只承载页面外壳的路由不计入产品操作数。

| 代码边界 | 读 | 写 | 小计 |
| --- | ---: | ---: | ---: |
| Piwigo canonical Gateway | 26 | 21 | 47 |
| Web compatibility 的 Class Archive API | 19 | 22 | 41 |
| Immich Web compatibility API | 20 | 2 | 22 |
| ClassIdentity 公开身份入口 | 3 | 5 | 8 |
| Class Archive Admin Console | 11 | 17 | 28 |
| Piwigo WS 精确分类/白名单 | 13（按 guard 类别） | 41 | 54 |
| **合计** | **92** | **108** | **200** |

可重复生成的机器矩阵：

```powershell
node tests/phase3/private-role-capability-inventory.mjs
```

默认输出到 Git ignored 路径：

```text
.codex-work/private-role-e2e/capability-matrix.json
```

JSON 中包含 200 个 operation 和 1,400 个按角色展开的 row，每行均含 `operation_id`、`route`、`http_method`、`role`、`allowed`、`ownership_condition`、`era_condition`、`visibility_scope`、`requires_approval`、`requires_audit`、`mutates_data` 和 `cleanup_strategy`。

## 源契约漂移门禁

当前生成文件为 `schema_version=2`，还包含一个可公开审查的
`source_surface_contract`。它不是另一套权限真相，而是让手工能力表
不会悄悄落后于代码的静态门禁：

- Gateway 的固定读路由、简单路由、动态照片/人物/搜索分支、可接受的媒体 variant 与写契约必须与声明集一致；
- Web compatibility BFF 的每一条公开路径与其 canonical Gateway 目标必须逐对一致，不能只比较数量；
- ClassIdentity 公共页面/动作、内部 member-upload bridge、Admin action 和 Piwigo WS capability 分类都必须与源代码一致；
- 新增、删除或重定向任一上述 surface 时，inventory 会失败，要求同时更新权限矩阵和角色验收计划。

这项检查仅证明 **STATIC_CODE_AUDIT**。它不证明某个角色已经完成 HTTP
或 Chrome E2E；对于内容、数量、封面、搜索和媒体，仍必须用真实会话验证
FULL / HERITAGE_ONLY、UNKNOWN fail-closed 与 MediaGuard 的 GET / HEAD /
Range 行为。

## 角色真相

| 角色 | 当前代码中的真实状态 |
| --- | --- |
| `GUEST` | 不是 Principal；仅允许登录、Claim 和 Family Invite 入口，照片产品 API 全部拒绝。 |
| `CLASSMATE` | FULL 读投影；可评论/回复、Era-first 上传、Spotlight、个人 pin/feedback、保存 Memory 为共享相册。 |
| `TEACHER` | 与 Classmate 相同的 FULL 照片能力，但没有 Family/Anonymous Seat 身份管理入口。 |
| `FAMILY` | HERITAGE_ONLY 读投影；评论只读；公开上传拒绝，只能提交 HERITAGE Pending；支持私人 pin/feedback 和 Memory 私人整理。 |
| `ANONYMOUS` | FULL 读投影（跟随所属 Classmate）；可用 context pseudonym 评论/回复。当前 Gateway **也允许** pin/feedback，不能按 Prompt 假设为全部公开写操作皆拒绝。 |
| `ARCHIVIST` | **未实现**。Schema 仅保留该值；`Access::resolveAuthorizationContext()` 对 SYSTEM_ACCOUNT 只接受 `SYSTEM_ADMIN`，Gateway scope 也没有 Archivist 分支。本矩阵对所有路由均为 DENY。 |
| `SYSTEM_ADMIN` | FULL+Pending 读权限与独立 Admin Console；并非 Classmate/Teacher，因此不允许通过 member Era upload 或普通评论写入路由发布。 |

`ARCHIVIST` 仅出现于 schema 保留值和前端展示映射，不能为它创建可用验收账号；本轮应输出 `ARCHIVIST_MATRIX=NOT_IMPLEMENTED_RESERVED_ROLE`，而不是伪造 PASS。

## Canonical Gateway：47 项

### 26 项读操作

```text
GET|HEAD /api/product-state
GET|HEAD /api/member-upload/options
GET|HEAD /api/home
GET|HEAD /api/collections/home
GET|HEAD /api/collections/state
GET|HEAD /api/collections/pins
GET|HEAD /api/comments/{photoId}
GET|HEAD /api/albums/{albumId}
GET|HEAD /api/spotlight
GET|HEAD /api/search/grouped
GET|HEAD /api/search/hybrid
GET|HEAD /api/search/suggestions
GET|HEAD /api/manage/people
GET|HEAD /api/manage/options
GET|HEAD /api/manage/duplicates
GET|HEAD /api/photos
GET|HEAD /api/photos/{photoId}
GET|HEAD /api/photos/{photoId}/media/{variant}
GET|HEAD /api/timeline
GET|HEAD /api/albums
GET|HEAD /api/people
GET|HEAD /api/people/{personId}
GET|HEAD /api/memories
GET|HEAD /api/search
GET|HEAD /api/search/smart
GET|HEAD /api/me
```

读权限：

- `manage/*`：仅 SYSTEM_ADMIN。
- `member-upload/options`：仅 CLASSMATE / TEACHER。
- 其他业务读：CLASSMATE / TEACHER / ANONYMOUS 为 FULL，FAMILY 为 HERITAGE_ONLY，SYSTEM_ADMIN 为 FULL+Pending。
- GUEST / ARCHIVIST / 无法解析 Principal / UNKNOWN Era 一律 DENY。
- `media/{variant}` 的 ALLOW 仍需再经 MediaGuard，并以 X-Accel-Redirect 传输；URL 不是授权凭据。

### 21 项写操作

| Route | 真实允许角色 | 附加条件 |
| --- | --- | --- |
| `manage/people/create` | SYSTEM_ADMIN | 审计；显式 target/reason |
| `manage/people/update` | SYSTEM_ADMIN | 全量替换契约；审计 |
| `manage/people/merge` | SYSTEM_ADMIN | 审计；并发需在 E2E 验证 |
| `manage/people/visibility` | SYSTEM_ADMIN | 审计 |
| `manage/people/revert-merge` | SYSTEM_ADMIN | 审计 |
| `manage/people/move-photos` | SYSTEM_ADMIN | 审计 |
| `manage/archive/bulk` | SYSTEM_ADMIN | Era/date/album 精确契约；审计 |
| `manage/albums/cover` | SYSTEM_ADMIN | 审计 |
| `manage/duplicates/consolidate` | SYSTEM_ADMIN | 精确 duplicate candidate；审计 |
| `spotlight/create` | CLASSMATE, TEACHER | 仅自有 active COMMUNITY album；审计 |
| `spotlight/cancel` | SYSTEM_ADMIN | 当前没有成员自行提前结束路由 |
| `comments/create` | CLASSMATE, TEACHER, ANONYMOUS | 照片当前可见；Anonymous 需 context pseudonym；审计不记正文 |
| `comments/reply` | CLASSMATE, TEACHER, ANONYMOUS | 同照片 active parent；审计 |
| `manage/comments/delete` | SYSTEM_ADMIN | 逻辑删除；审计 |
| `collections/pins/create` | 所有已实现的 authenticated role | 仅当前可见 snapshot item |
| `collections/pins/remove` | 所有已实现的 authenticated role | 仅当前 Principal 自身 pin |
| `collections/pins/reorder` | 所有已实现的 authenticated role | 当前 snapshot revision |
| `collections/feedback/set` | 所有已实现的 authenticated role | 当前可见 snapshot item |
| `collections/feedback/clear` | 所有已实现的 authenticated role | 当前 Principal 自身 feedback |
| `collections/memories/save-as-album` | CLASSMATE, TEACHER, FAMILY, SYSTEM_ADMIN | Family 只产生 HERITAGE 私人 pin，不产生共享 album |
| `collections/albums/cover` | CLASSMATE, TEACHER, SYSTEM_ADMIN | 成员仅自有 COMMUNITY album + 可见照片；审计 |

上述所有 POST 还必须通过固定 BFF internal marker、精确 body allowlist 和 CSRF；直接调用 Gateway mutation 必须拒绝。

## Web compatibility API：63 项

### 41 项 Class Archive 公开 BFF 边界

- 19 项 GET/HEAD：11 项固定 map，加上 album detail、comment list、3 种搜索、timeline、memories 和 person detail。
- 21 项 POST：与 Gateway mutation 一一对应。
- 1 项独立 multipart POST：`/api/class-archive/member-upload`，仅 CLASSMATE/TEACHER，必须显式选择 HERITAGE 或 LIVING。

BFF 只能代理固定 upstream 路径；它不是通用 Piwigo/Gateway proxy。此层和 canonical Gateway 都要各自测试，不能用一边 PASS 代替另一边。

### 22 项 Immich Web compatibility 边界

```text
GET|HEAD /api/users/me
GET|HEAD /api/users/me/preferences
GET|HEAD /api/server/about
GET|HEAD /api/server/version-history
GET|HEAD /api/server/features
GET|HEAD /api/server/config
GET|HEAD /api/server/media-types
GET|HEAD /api/server/storage
GET|HEAD /api/notifications
GET|HEAD /api/timeline/buckets
GET|HEAD /api/timeline/bucket
GET|HEAD /api/albums
GET|HEAD /api/memories
GET|HEAD /api/people
POST     /api/search/metadata
POST     /api/search/smart
GET|HEAD /api/people/{personId}/statistics
GET|HEAD /api/people/{personId}/thumbnail
GET|HEAD /api/people/{personId}
GET|HEAD /api/assets/{photoId}
GET|HEAD /api/assets/{photoId}/thumbnail
GET|HEAD /api/assets/{photoId}/original
```

这 22 项对所有已实现 authenticated role 可请求，但照片、数量、人物、封面和搜索结果仍由 GatewayPolicy 按 FULL/HERITAGE_ONLY 裁剪。`asset/person thumbnail/original` 不传递 Immich 原始 URL，每次请求仍进入 MediaGuard。`/api/memories` 是安全空的 compatibility placeholder，不是真实 Memory 投影。

## 身份与 Admin：36 项

### 8 项公开身份操作

| 操作 | 允许角色 | 主要条件 |
| --- | --- | --- |
| Claim 页 GET | GUEST | 已登录时拒绝 |
| Claim POST | GUEST | 一次性 Claim + CSRF + 限流；不能创建 SYSTEM_ADMIN |
| Family Invite 页 GET | GUEST | 已登录时拒绝 |
| Family Invite 接受 POST | GUEST | 一次性 invite + CSRF + 限流 |
| 我的身份 GET | CLASSMATE, TEACHER, FAMILY, ANONYMOUS | Anonymous 只返回脱敏视图；SYSTEM_ADMIN 显式拒绝 |
| 生成 Family Invite POST | CLASSMATE | 仅自身 AVAILABLE Family Seat |
| 激活 Anonymous Seat POST | CLASSMATE | 仅自身 AVAILABLE Anonymous Seat |
| Family 投稿 POST | FAMILY | HERITAGE_ONLY；Pending；需 Admin 审核 |

### 28 项 Admin Console 操作

- 9 项 GET/HEAD 页面：`dashboard`、`identities`、`teachers`、`invitations`、`submissions`、`anonymous`、`archive`、`audit`、`system`。
- 2 项 GET 媒体：Pending/Rejected submission thumbnail 与 original（controller 的 stream 分支仅接受 GET）。
- 17 项 POST action：

```text
create_classmate
create_teacher
issue_claim
reissue_claim
revoke_claim
reissue_family_invitation
revoke_family_invitation
compensate_provisioning
freeze_identity
unfreeze_identity
approve_submission
reject_submission
save_archive_metadata
create_archive_album
disable_anonymous
enable_anonymous
resolve_anonymous
```

全部 28 项仅 SYSTEM_ADMIN；POST 必须通过 Piwigo CSRF、显式理由和 domain-level SYSTEM_ADMIN 校验。`resolve_anonymous` 本身必须写 Audit。Admin 页面不是 Archivist 权限来源。

## Piwigo WS：54 项

`CapabilityGuard` 精确分类 41 个写方法，另外显式放行 11 个 common-read 方法、`pwg.users.favorites.getList` 和 Guest 登录，合计 54 个精确方法。“common-read”是 guard 的分类名，不表示绝对无副作用：`pwg.session.logout`、`pwg.images.filteredSearch.create`、`pwg.history.log` 以及 Guest login 在机器矩阵中均标记为有 session/history 状态变化。`reflection.*` 是 wildcard route family，在文档中记录但不计入 54。

| Capability | 方法数 | CLASSMATE | TEACHER | FAMILY | ANONYMOUS | SYSTEM_ADMIN |
| --- | ---: | :---: | :---: | :---: | :---: | :---: |
| `COMMENT_IMAGE` | 3 | ALLOW | ALLOW | DENY | ALLOW* | ALLOW |
| `RATE_IMAGE` | 2 | ALLOW | ALLOW | DENY | DENY | ALLOW |
| `UPLOAD_PHOTO` | 10 | ALLOW | ALLOW | DENY | DENY | ALLOW |
| `MANAGE_PHOTO` | 10 | ALLOW | ALLOW | DENY | DENY | ALLOW |
| `CREATE_ALBUM` | 1 | ALLOW | ALLOW | DENY | DENY | ALLOW |
| `MANAGE_ALBUM` | 7 | ALLOW | ALLOW | DENY | DENY | ALLOW |
| `MANAGE_TAG` | 5 | ALLOW | ALLOW | DENY | DENY | ALLOW |
| `PRIVATE_COLLECTION` | 2 | ALLOW | ALLOW | ALLOW | DENY | ALLOW |
| `ACCOUNT_PREFERENCE` | 1 | ALLOW | ALLOW | ALLOW | DENY | ALLOW |

`ANONYMOUS COMMENT_IMAGE` 只在 AnonymousPresenter attestation 成功时由粗粒度 guard 放行。

**重要：表中 ALLOW 只表示“继续进入 Piwigo Core 检查”，不是最终业务授权。** 每个方法仍须通过 Core CSRF、相册权限、图片所有权和对应 plugin 约束。未分类 WS 对成员默认 DENY，SYSTEM_ADMIN 保留 Core 技术后台能力。

## 不计入 200 的可达路由族

以下需要做负面 E2E，但不是独立业务 API，因此不纳入 `EXPOSED_PRODUCT_OPERATIONS`：

- Photo UI document shells：`/home`、`/photos`、`/people`、`/search`、`/albums`、`/memories`、`/my`、`/people/manage` 及 photo/person/album detail。
- 兼容/法律页：`/class-archive-about`、`/class-archive-timeline`、`/class-archive-person/{id}`、`/class-archive-photo/{id}`。
- `picture.php` 的 rate/favorite/comment mutation family。
- `comments.php` 的 create/edit/delete/validate mutation family。
- Community `add_photos` / `edit_photos` legacy HTML family（Community 仍 inactive）。
- `reflection.*` WS wildcard family。

页面外壳可返回不等于媒体已授权；Viewer 中每张 thumbnail/preview/original 均必须再次进入 MediaGuard。

## 与需求清单不同的当前事实

下列项不能在 E2E 中按需求文字直接预期 PASS：

1. `ARCHIVIST` 仅保留，未有可用 Principal/policy。
2. Classmate 没有“撤销自己的 Family Invite”公开 route；当前撤销/重发只是 SYSTEM_ADMIN action。
3. 自有评论编辑/删除没有 Class Archive comment route；当前仅 SYSTEM_ADMIN 逻辑删除。
4. 成员提前结束自己 Spotlight 没有 route；`spotlight/cancel` 仅 SYSTEM_ADMIN。
5. 产品自有 API 没有通用 community album 创建/标题编辑/删除 route。Piwigo WS 的 coarse ALLOW 不能代替产品 API 和所有权验收。
6. Family 没有真正的私人 Album domain；当前是 pin 和 Memory-as-private-pin。
7. SYSTEM_ADMIN 不能经 member Era upload 路由或普通评论 create route 写入；管理员上传只能使用现有 Core 技术路径，并不是需求中所述的 Era-first 产品流程。
8. Anonymous 当前可用 Gateway pin/feedback；如果产品意图是全部拒绝，需要先改服务端 policy，不能只改测试预期。
9. `AutoCollectionService` 的 sync/status/reconciliation、`BulkArchiveService` journal 读、`PersonCurationService` 的若干底层 rule 方法是 internal/service-only，并非当前产品 route。

## 清理与验收路由

机器 JSON 中对每个写操作已标记清理类型，但静态矩阵不会执行任何写入。私有 E2E 必须在另行验证的 Lease/CAS 层中遵循：

- 只清理当前 `test_run_id` 登记的资源。
- 对去重命中既有 Canonical Photo 的上传，只删 fixture source/membership，不删既有 original。
- Audit 是 append-only，不得为了“干净”删除；用 `PRIVATE_REAL_E2E:<test_run_id>` 标记。
- CAS 发现管理员并发修改时必须停止回滚该对象，不覆盖新状态。
- 本文档的 ALLOW 是“应进入正向测试”；DENY 是“必须由服务端负向证明”，不是前端隐藏。

## 代码来源

- `plugins/ClassIdentity/src/Access.php`
- `plugins/ClassIdentity/src/CapabilityGuard.php`
- `plugins/ClassIdentity/src/Gateway/GatewayPolicy.php`
- `plugins/ClassIdentity/src/Gateway/GatewayHttpController.php`
- `plugins/ClassIdentity/src/Gateway/GatewayService.php`
- `plugins/ClassIdentity/public.php`
- `plugins/ClassIdentity/admin.php`
- `plugins/ClassIdentity/src/AdminService.php`
- `plugins/ClassIdentity/src/SubmissionService.php`
- `plugins/ClassIdentity/src/PhotoCommentService.php`
- `plugins/ClassIdentity/src/SpotlightService.php`
- `plugins/ClassIdentity/src/AutoCollectionService.php`
- `plugins/ClassIdentity/src/AlbumService.php`
- `plugins/ClassIdentity/src/BulkArchiveService.php`
- `plugins/ClassIdentity/src/PersonCurationService.php`
- `plugins/ClassIdentity/src/AnonymousGovernanceService.php`
- `plugins/ClassIdentity/src/ProvisioningService.php`
- `infra/immich-spike/web-compat/server.mjs`
