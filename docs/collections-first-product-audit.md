# Collections-first 产品架构审计

日期：2026-08-28
适用范围：Class Archive 本机私有图库；本文不改变 `Piwigo-first`、ClassIdentity、ClassArchivePolicy、MediaGuard 或 Immich 隔离边界。

## 结论

Class Archive 应把 **所有照片**、**来源集合**、**面向成员的相册** 与 **系统整理的回忆** 作为四种不同对象，而不是把磁盘目录、相册卡片和自动推荐混在同一个层级里。首页应是少量、已持久化的精选与集合；完整时间轴应保留在独立的“所有照片”入口。

这是一份产品与复用边界审计，不是把任一参考产品的私有数据模型或权限模型复制进来。下文的“观察”来自官方资料或锁定上游源码；“推断”是针对本仓库的设计结论。

## V4 冻结产品决策

本节是 **Photos App V4** 的实现性决策。它收束本文此前的审计结论；以后新增界面或 API 只能在这些边界内扩展，不能重新把文件夹、媒体 URL、Immich technical user 或 Piwigo 技术对象暴露为普通用户的产品模型。

| 主题 | V4 冻结决策 |
|---|---|
| 资料库与精选集 | `/photos` 是完整、分页的 **资料库**；`/home` 是少量已发布集合组成的 **精选集**。首页不请求、聚合或渲染完整资料库。 |
| 搜索 | 搜索是全局 overlay，不再是长期停留的一级页面。它可在任何照片产品页面打开，保留当前相册范围；旧 `/search` 深链只能兼容重定向至带 search intent 的 `/home`，浏览器返回与 Esc 都关闭 overlay。 |
| 导航 | 桌面主导航仅突出“精选集”和“资料库”；人物、相册、回忆等从内容或搜索进入。账号能力移入 avatar menu，不再把“我的”作为主导航。移动端只保留“资料库 / 精选集 / 搜索”三个高频动作。 |
| 上传 | 成员与教师提交照片时必须在服务端明确选择“班级历史”或“毕业后动态”；Family 只能提交“班级历史”且保持 Pending；Anonymous 一律拒绝上传。前端下拉框不是授权依据。 |
| 范围 | 业务/DTO 文案使用 `FULL` 与 `HERITAGE_ONLY`。为兼容已有数据库与投影，持久层暂以 `HERITAGE` 表示 `HERITAGE_ONLY`；转换只在受控服务层发生，未知 scope 必须 fail closed。 |
| 集合 | 首页、回忆、Spotlight、搜索建议使用固定的、版本化的持久 snapshot；读取时只做当前 policy 过滤，不在 GET 中全库扫描、重建 AI 或重新选集合。 |
| Spotlight | Spotlight 是 24 小时公平轮换的集合，而不是 Feed 置顶。过期、取消与下一候选选择均由服务端持久状态和审计决定，不能由页面计时器决定。 |
| 低元数据照片 | 回忆优先使用档案事件、学期、年份、相册上下文和已确认日期。无可靠日期时显示“时间待整理”或事件上下文，绝不根据上传/文件系统时间伪造“那年今日”。 |

V4 不改变以下真相来源：ClassIdentity 仍是身份真相，ClassArchivePolicy 仍是可见性真相，Piwigo/Class Archive 仍是档案真相，MediaGuard 仍是每一次媒体请求的最终授权点。Immich 仅提供隔离的 AI/People/Search 能力；浏览器不直接访问其媒体端点。

## 证据与限制

- **观察（固定）**：本仓库锁定的 Immich `v3.1.0` / commit
  `8aa95c67470a02a8ddedf03c2e52963af33065ff` 的官方 OpenAPI 描述了 People、Memory、Search 与异步 Jobs。其 `Memory` 是按用户经后台任务生成的专用 asset collection；`Person` 是由人脸识别任务自动生成、可命名/合并的 face collection；Search 支持分页与排序。
  见 [锁定上游 OpenAPI](../infra/immich-spike/source/official-v3.1.0/open-api/immich-openapi-specs.json) 与
  [上游锁定策略](immich-upstream-strategy.md)。
- **观察（当前官方文档）**：Apple 将按日期的 Library 和已整理的 Collections 分开；Collections 内含 Memories、Albums、Recent Days 等。Apple 的 Memory 可围绕人物、地点、活动或事件；Shared Album 支持持久评论/点赞与创建者删除。见文末来源。
- **观察（当前官方文档）**：PhotoPrism 分开呈现手工 Albums、源目录 Folders 与自动 Moments；Nextcloud Memories 依赖后台 metadata index 与预生成预览，但其 timeline 在 EXIF 缺失时会回退服务器时间。
- **限制**：参考产品的当前文档不等于本仓库锁定版本或本项目的安全要求。特别是，Class Archive 已证明聊天工具转存照片的文件时间并不可靠，不能沿用 Nextcloud Memories 的服务器时间回退。

## 参考产品审计

| 参考产品 | 已观察到的模式 | 对本项目的结论 |
|---|---|---|
| Apple Photos | Library 是完整按日期浏览；Collections 是独立的整理入口；Albums 可以放入 folders，删除 album 不删除 Library 内照片；Memories 是人物/地点/活动/事件的精选集合；搜索在输入时给出建议。 | **REUSE（产品语义）**：分开“首页 / 所有照片 / 相册 / 回忆 / 搜索”。**ADAPT**：使用 Class Archive 档案时间而非 Apple 的照片日期假设。**REJECT**：iCloud identity、公共网页 URL 与 Apple 的访问模型。 |
| Immich v3.1.0 | People 由脸部任务创建；Face detection/embedding 进入数据库且聚类可增量进行；Memory、Search、Jobs 都是后端对象/异步任务。 | **REUSE（隔离能力）**：用 Immich 做内部 People/Search 索引与后台工作。**ADAPT**：以 Canonical Photo 和 ClassArchivePerson 作为业务映射。**REJECT**：Immich user、asset id、memory/ACL 或媒体 endpoint 成为浏览器真相。 |
| PhotoPrism | Albums（人工）、Folders（原始目录）与 Moments（自动）是并列产品对象；索引源目录可保留 folder structure，import 则会重排文件并去重。 | **REUSE（分类原则）**：来源目录和用户相册不能混为一类。**ADAPT**：保留 SourceCollection + source provenance，向成员只投影 leaf albums。**REJECT**：直接把本机原目录暴露为成员文件浏览器或写入 sidecar。 |
| Nextcloud Memories | Timeline/rewind 面向时间浏览；后台 index 负责提取 EXIF，Preview Generator 用于性能；相册由另一层照片能力提供。 | **REUSE（运行方式）**：后台建立元数据/预览/索引，普通 GET 只读投影。**ADAPT**：以 archive truth 排序。**REJECT**：EXIF 缺失时回退 server/上传时间，以及把来源路径当普通导航。 |

## 正式领域边界

### A. SourceCollection（来源集合）

**定义**：保存来源根、相对路径、导入批次与 provenance 的业务对象；例如“来源集合 A”“来源集合 B”。它不是成员需要逐级点击的 folder card。

**必须保留**：`source_collection_id`、源相对路径、源文件 checksum、导入状态、管理员可见的来源显示名/别名。绝不把 Windows 绝对路径投影到普通 API、HTML 或前端状态。

**投影规则**：SourceCollection 可成为相册筛选条件和低权重副标题（例如“来自来源集合 A”），但不能让普通用户重新进入文件树。

### B. Album（用户相册）

**定义**：成员能在“相册”页直接打开的内容集合。默认只有具有 **direct photo membership** 的最终节点生成 Album Card。

**Leaf album projection 规则**：

1. 纯容器节点（没有直接照片）不生成普通 Album Card；底层 parent relation 仍保留，用于 provenance、管理员核查和投影重建。
2. 父目录既有直接照片又有子目录时，父目录自己的直接照片必须生成可见 Album；不能为了扁平化而遗失。
3. 不向所有 ancestor 复制 photo membership。父相册的递归可见计数由 projection 计算，并始终经过 ClassArchivePolicy。
4. 重名 leaf album 保留简短主标题；仅以低视觉权重副标题区分来源/父上下文，例如“来自来源集合 A · 高三”。
5. display alias 是 Album 的通用字段，不能通过改源目录实现。公开产品只读取数据库中的已批准展示别名；副标题继续从持久 source provenance 生成，不能为了匹配期望文案把实际来源伪装成另一个来源集合，source path 与管理员 provenance 始终不变。

### C. AutoCollection / Memory（自动回忆）

**定义**：由 archive event、学期、年份、可用的 archive date、album context、People 和去重后的 Canonical Photo 生成的只读或可轻量策展集合。

**不可采用的捷径**：把 Immich technical user 的 memory 直接给浏览器。锁定上游的 Memory 明确是 per-user background result，而本项目的身份/可见范围不属于 Immich user；直接转发会破坏 ClassIdentity、匿名与 FAMILY 的筛选边界。

**V4 冻结的持久投影**：

```text
archive / album / people / import 变更
            ↓
    background projection builder
            ↓
 immutable snapshot + members + cover + revision
            ↓
 Gateway policy filter / MediaGuard URLs
            ↓
          Home read
```

最小字段：`memory_id`、`title`、`subtitle`、`cover_photo_id`、成员 photo projection、`source_reason`、`archive_date_precision`、`generated_at`、`projection_revision`、保守的 visibility scope。一个 snapshot 一经发布不可原位编辑；active pointer 与 retained superseded revision 使后台重建失败时仍可读取上一有效版本。投影不是授权缓存：每次读取 cover、count 和成员仍必须经当前 ClassArchivePolicy；无法确认时返回空集合/安全错误而不是扩大结果。

**允许的主题**：毕业、运动会、某个学期、班级活动、已有可靠事件上下文的“高三的夏天”、人物共现。没有可靠档案日期时，不生成“一年前的今天”。

### D. Library / All Photos（所有照片）

**定义**：完整档案时间轴，独立路由（建议 `/photos` 或 `/library`）。它不是首页的无限列表，也不是 SourceCollection folder browser。

时间优先级继续为：确认的 `archive_date` / event-date inference / 被认可的 EXIF / unknown；上传时间和 filesystem time 不成为拍摄日期真相。

## 首页与导航决策

`/home` 在 V4 中正式命名为 **精选集**，只读少量已发布投影：精选、回忆、最近整理、少量相册、少量人物及“查看资料库”入口；不得加载或聚合完整图库。`/photos` 正式命名为 **资料库**，承载完整档案时间轴。

搜索从长期页面改为全局 overlay：任何照片产品页面均可打开；若调用者有当前相册上下文，默认在该范围内查询并允许清楚切换回全部资料库。`/search` 仅保留为旧书签/历史记录兼容入口，应规范化到 `/home?search=1` 或等价的显式 intent；返回历史与 Esc 必须关闭 overlay，而不是把用户困在一个独立搜索页。BFF 的 document query allowlist 必须只接受这一受控 intent，未知 query 继续拒绝。

桌面主导航只突出“精选集”和“资料库”。人物、相册、回忆通过首页内容、资料库上下文与搜索到达；账户、身份、投稿、设置和管理员入口放在 avatar menu。移动端保持三个高频动作：“资料库 / 精选集 / 搜索”。这不是删除能力：所有非技术页面仍必须有可发现的、安全的到达路径。

这是 Apple Collections 的产品结构复用，而不是 UI 仿制。移动端可以减少 tab 数，但“所有照片”必须始终可达；回忆可在首页显示并提供“查看全部回忆”。

## Era-first 上传边界

照片上传不是一个无分类的文件动作。V4 的受控写入服务在保存任何二进制、创建 Piwigo 图片、关联 Album 或安排 AI job **之前**，必须验证 principal、CSRF、MIME/大小/路径安全和 Era：

| 角色 | 允许的业务 Era | 初始状态 | 说明 |
|---|---|---|---|
| CLASSMATE / TEACHER | 班级历史或毕业后动态，必须显式选择 | 受既有受控发布流程约束 | 不能由文件名、相册名或客户端默认值推断 Era。 |
| FAMILY | 仅班级历史 | `PENDING` | 继续走现有 Submission 审核链；Family 不能读取 Pending 原图或衍生图。 |
| ANONYMOUS / 缺失 / 异常 principal | 无 | 拒绝 | 不创建文件、Piwigo image、Canonical Photo 或 AI job。 |

前端只负责采集用户选择和解释业务语言；Gateway/ClassArchivePolicy/Submission 域负责最终写入决策。失败、缺少 Era、映射歧义或数据库错误均不允许退化为“可见”或“默认班级历史”。

## V4 Collection Snapshot 与 Spotlight

V4 的集合投影必须版本化、可回退且按可见范围分开发布。一次构建先写入完整候选 snapshot，经校验后才原子移动 active pointer；旧 active snapshot 仅在新 snapshot 已发布后变为 retained/superseded。读取方只能读取 active pointer，失败时返回最后一个有效 snapshot 或安全空态，不能现场全表扫描补救。

业务 scope 为 `FULL` / `HERITAGE_ONLY`；持久 schema 为兼容已有数据暂存 `FULL` / `HERITAGE`。任何 service、API 或缓存键在边界处都必须完成一对一转换；既非 `FULL` 也非受控 heritage 值时拒绝读取/发布。principal 的 pin 与 feedback 也是 scope-bound：它们不能让 Family 从 Full snapshot 取得 LIVING 候选。

Spotlight 使用独立的 24 小时 lifecycle：服务端记录开始、到期、取消、候选与轮换理由；后台维护工作发布下一条候选，并产生 Audit。页面只渲染当前已授权的 active Spotlight，不拥有排程权，也不能通过本地时钟延长展示。

## Viewer 与评论审计

### 现有能力

本仓库已经审计 Piwigo Core comments、`CapabilityGuard` 与 `AnonymousPresenter`：Core 的平面评论记录可以承载普通图库留言，但不能同时满足本产品所需的真实回复关系、ClassIdentity 角色写权限、Family 只读、匿名 context pseudonym 与统一审核审计。现有审计也已确认 Piwigo 生态的 `Reply To` 扩展不是可靠的 parent-reply 模型且维护陈旧，因此不能作为 V1 回复能力基础。见 [reuse-audit](reuse-audit.md) 与
[ClassIdentity README](../plugins/ClassIdentity/README.md)。

### 决策：保留 Core 技术能力，建立薄 ClassArchiveComment 业务域

| 层 | 结论 |
|---|---|
| Piwigo 图片、相册、媒体 ACL 与技术后台 | **REUSE**；评论目标始终先通过 Gateway / ClassArchivePolicy / MediaGuard 对应的照片可见性判断。 |
| 评论正文、回复树、photo discussion context、产品 moderation 状态 | **ADAPT** 为薄 `ClassArchiveComment` 业务域；它只保存产品评论所需的正文、parent、状态与审计关联，不复制图片、相册、账号或匿名身份真相。Piwigo Core comments 继续保留给技术兼容场景，但不同时向成员开放，避免出现两套可写评论真相。 |
| 匿名显示 | **REUSE** 当前 `AnonymousPresenter` 的 `PHOTO:<image_id>` context pseudonym；绝不把 underlying account/seat/classmate id 放入普通 DTO。 |
| Piwigo Core 与 ClassArchiveComment 同时对成员开放写入、陈旧回复插件、无身份 public comment URL、仅靠前端隐藏的 Family 限制 | **REJECT**。 |

权限必须在服务器端执行：CLASSMATE/TEACHER 可读、评论、回复；ANONYMOUS 仅在 presenter-ready 时以 context pseudonym 评论/回复；FAMILY 只读且写入 API 直接拒绝；SYSTEM_ADMIN 可审核/删除/看举报，任何去匿名解析都有 Audit。普通 viewer 的主栏只显示克制的 album/source/event context；“照片信息”折叠显示 archive date、precision、来源集合、相册归属、规格。日期未知时只显示一次“时间待整理”或省略，不能连续展示多个 unknown 字段。

评论及 metadata adapter 是业务数据：纳入 backup/restore/reconciliation；读取照片时不得重建评论投影。

## People / Search / AI 持久化边界

| 数据 | 真相与保存位置 | 读取规则 | 何时更新 |
|---|---|---|---|
| Canonical Photo、archive metadata、album/provenance | Class Archive / Piwigo + ClassIdentity MariaDB | Gateway 按 policy 查询 | import、archive 或 album 变更 |
| face box、embedding、cluster、search embedding | isolated Immich PostgreSQL/index，内部服务专用 | Gateway 只接收已映射、已筛选的 metadata 结果 | 新照片、像素/checksum 改变、删除、模型 revision 变更、管理员明确重索引 |
| ClassArchivePerson 名称、merge/split/cover/hidden 等业务 override | Class Archive mapping store | 与 Immich Person 解耦；不会把 cluster 自动写成真实成员 | 人工整理或 source mapping 变更 |
| AutoCollection / memory / album card / home projection | Class Archive persistent projection | 常规 GET 只读；cover/count 再过 policy | 上游数据、person 或 archive event 变更 |

Immich 的索引应纳入私有运行环境的恢复策略（PostgreSQL/向量状态快照），同时保留 checksum + model revision 驱动的确定性重建兜底。业务人物整理状态必须被 Class Archive backup 覆盖，不能只存在 Immich person id 中。服务重启后 People/Search 必须直接查询持久 index；普通浏览、相册改名、评论或 Spotlight 不能触发 face/CLIP 重算。

## 搜索信息架构

搜索入口应为“搜索照片、人物、相册和活动”。未输入查询时只显示轻量 suggestions：人物、年份/学期、活动、相册、来源及常用词；输入后按以下顺序分区并排序：

1. 人物精确命中；
2. 相册名；
3. 活动；
4. 年份、学期、档案日期；
5. 标签、说明、OCR；
6. “智能匹配 Beta”。

支持 source filter 与“在这个相册中搜索”，但不恢复 folder tree。Structured result 必须优先于语义匹配；语义只作为补充。People/Search/cover/count/pagination 在聚合前后都必须是 policy-aware，不能先返回 LIVING 计数再隐藏卡片。

## 实施顺序与验收映射

1. 先建立 SourceCollection、Album display alias 与 leaf album projection；对 source provenance、重名副标题、direct/recursive count 做 synthetic contract test。
2. 通过 append-only schema migration v17 建立 scope-bound、版本化的 Collection Snapshot/Pointer/Pin/Feedback/Maintenance 状态；先做 synthetic migration 与快照恢复验证，再做受控 owner migration。旧 migration ledger 不修改。
3. 建立 Home/AutoCollection persistent projection，随后将精选集与 `/photos` 资料库分路由，并接入全局 search overlay；断言首页请求不会加载完整 library，旧 `/search` 只作为兼容入口。
4. 以薄 ClassArchiveComment 业务域交付回复、匿名、Family read-only、审核与 backup/restore 回归；Piwigo Core comment 写入口保持关闭，避免双写真相。
5. 为 Classmate/Teacher 接入 Era-first controlled upload，复用现有 Family HERITAGE Pending 提交流程；覆盖缺少 Era、Anonymous、伪造参数、CSRF 与 MediaGuard 回归。
6. 固化 AI index state、checksum/model-revision jobs 与 restart read-only test；任何 AI 查询继续经过 Gateway + MediaGuard。
7. 最后做 private-real Chromium QA；真实截图和 source provenance 只能留在 ignored private-local 路径。

## REUSE / ADAPT / REJECT 总表

| 决策 | REUSE | ADAPT | REJECT |
|---|---|---|---|
| Collections-first | Apple 的 Library/Collections 分离 | Home + leaf album projection + Class Archive archive truth | 将源目录树当用户导航 |
| Memories | Apple/PhotoPrism 的“主题集合”概念；Immich 后台 job 观念 | Class Archive persistent AutoCollection、policy-aware covers/counts | Immich technical-user memory 直出；不可靠 on-this-day |
| Albums/Folders | PhotoPrism 的 Albums/Folders 区分 | SourceCollection + user-facing leaf Album | 改写源路径、ancestor membership 复制、文件管理器式层层导航 |
| Comments | Piwigo 的图片/相册目标与现有 anonymous presentation | 单一可写的 ClassArchiveComment 薄业务域，以支持真实 reply/moderation/audit | Core 与业务域双写、stale Reply To plugin、public URL 授权、Family 前端-only 禁止 |
| AI | Immich People/Search/jobs/index 持久化能力 | Canonical mapping、ClassArchivePerson、Gateway policy filtering、备份策略 | Immich identity/ACL/media endpoint、页面读取现场 AI |
| Search | Apple 的 suggestions + type grouping；Immich metadata/semantic search | structured-before-semantic、中文 “智能匹配 Beta” | 让当前英语模型压过明确档案结果或宣称中文质量已解决 |

## 官方来源

- Apple Support, [Browse your photo collections on iPhone](https://support.apple.com/en-gb/guide/iphone/iph4f36c4148/ios)；Library 与 Collections 的分离、Memories/Albums/Recent Days 的 collection 结构。
- Apple Support, [Create and work with photo albums on iPhone](https://support.apple.com/en-ie/guide/iphone/iphc0fc668ab/ios)；albums、folders 以及删除 album 不删除 library photo。
- Apple Support, [View your memories in Photos on iPhone](https://support.apple.com/guide/iphone/view-your-memories-iphd4f70e68f/ios)；Memory 的人物、地点、活动、事件主题。
- Apple Support, [Search for photos and videos on Mac](https://support.apple.com/guide/photos/search-for-photos-and-videos-pht64de33e5a/mac)；typed suggestions、caption/keyword/date/text search。
- Apple Support, [Create or join shared albums on Mac](https://support.apple.com/en-ie/guide/photos/phtf7fe6394/mac) 与 [add/delete shared-album items on iPhone](https://support.apple.com/en-ca/guide/iphone/iph10caf6f49/ios)；评论、参与者与创建者审核删除模式。
- Immich official docs, [Facial Recognition](https://immich.app/docs/features/facial-recognition/)；face preview、embedding、People、merge/hide 与 clustering 行为。
- Immich official docs, [Jobs and Workers](https://docs.immich.app/administration/jobs-workers/)；新 asset 的异步 metadata/thumbnail/ML jobs 与 nightly tasks。
- Immich official docs, [Searching](https://docs.immich.app/features/searching/)；metadata/contextual search、pagination 与模型取舍。当前文档仅作产品参考；版本行为以锁定 v3.1.0 source 为准。
- PhotoPrism docs, [Browsing and Searching Your Library](https://docs.photoprism.app/user-guide/search/)；Albums、Folders、Moments 的并列语义；[Indexing Your Library](https://docs.photoprism.app/user-guide/library/)；源目录保留与 import/dedup 的差异；[Moments](https://docs.photoprism.app/user-guide/organize/moments/)；自动集合语义。
- Nextcloud Memories official repository, [README](https://github.com/pulsejet/memories) 与 [configuration](https://github.com/pulsejet/memories/blob/master/docs/config.md)；timeline/rewind、background EXIF index、preview-generation 与 EXIF 缺失回退限制。
