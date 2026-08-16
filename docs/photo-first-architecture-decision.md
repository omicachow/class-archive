# Photo-first 架构决策：Piwigo-first

决策日期：2026-08-16（Asia/Shanghai）

状态：**Piwigo-first 已通过媒体可行性门禁并正式冻结；整体生产仍受后续安全门禁阻断**
替代：此前的 HumHub-first 方向；HumHub 实验与文档保留为可追溯证据

## 决策

Class Archive V1 采用 **Piwigo-first 单运行时**：Piwigo 负责照片、派生图、相册、日期、标签、搜索、媒体元数据和媒体关系；Class 插件只负责班级身份与业务规则；Class Archive Theme 只负责 photo-first 呈现。

V1 **不运行 HumHub，也不做 Piwigo + HumHub Hybrid**。保留下来的 HumHub 1.18.4、Gallery 1.7.1 等实测成果是比较基线和未来参考，不进入登录链路、权限链路、数据库、备份或最终用户界面。若未来确实需要通用社区能力，应另开 ADR，不能在 V1 中悄悄增加第二套用户、会话和内容模型。

这仍不是对当前系统的生产放行。此前已知 URL 可绕过页面 ACL 的 **P0 媒体阻断项已经解决**：独立 `ClassArchivePolicy` 插件在每次请求中读取当前 session、显式 ClassIdentity Principal、有效 Era、Piwigo Core ACL 与 original policy；只有授权成功才交给 nginx `X-Accel-Redirect` 发送文件。Piwigo Core 未修改。Piwigo-first 因而通过媒体可行性硬门槛并正式冻结。Phase 1 已实现 Identity freeze/session revoke、独立 SYSTEM_ADMIN、Claim/Invite、最小 Admin Console 与匿名输出底座；active Family account release/member password reset、Community、Collections、持久 HTTP attestation、Admin MFA、cron/恢复、NAS 与 HTTPS 仍阻止真实数据和网络开放。

## 证据边界

本决策来自两个真实本地 spike，而不是根据产品截图或功能清单推断：

- HumHub 1.18.4 已安装运行，Gallery 1.7.1、Report Content 1.2.2、Content Bookmarks 1.2.0、Share Content 1.1.1、TwoFA 1.2.3 已锁定并实测；其快照和研究保存在仓库历史及 `docs/evaluations/humhub/`。
- Piwigo 16.4.0 主栈已运行；Bootstrap Darkroom 16.d 已启用，包内 PhotoSwipe 4.1.3 已由真实响应标记验证；主栈中有 72 张合成测试图，未使用真实班级照片。
- 72 个 Piwigo image 记录对应 72 个不同 original path；至少一张照片通过 `image_category` 关系进入多个逻辑相册，没有产生第二个 image 记录或另一个 original path。文件系统层的去重/硬链接不是本结论的一部分。
- 通过真实登录会话和 Web API 验证：Guest 相册 API 被拒绝；FAMILY 只列出 HERITAGE；CLASSMATE、TEACHER、ANONYMOUS 可列出 HERITAGE 和 LIVING；FAMILY 直接请求 LIVING album id 也得不到图片。
- 通过真实 HTTP/HTML 响应验证：相册网格使用缩略/封面派生图，不直接加载 original；照片页默认使用 `medium` preview、预取相邻图片、提供显式 original download，并初始化 PhotoSwipe。当前轮次没有完成受支持浏览器中的截图/触屏视觉验收，因此这里不宣称像素级 UI 或真机手势 QA 已通过。
- `ClassArchivePolicy` + nginx MediaGuard 已通过 290 个真实 HTTP probes：Guest 的两个 Era 全部拒绝；FAMILY 的 LIVING 全部拒绝且 HERITAGE preview 允许、original 默认拒绝；CLASSMATE/TEACHER 两个 Era 允许；ANONYMOUS 两个 Era preview 允许、original 默认拒绝；独立 SYSTEM_ADMIN 全部允许。GET/HEAD/Range、logout、account switch、直接 backing path、猜测、path normalization、query tamper 与 cache revalidation 均通过；跨 Era 关联对所有角色 fail closed；受控数据库中断返回无媒体字节、无诊断细节的通用 503。
- 当前树的真实 Piwigo/MariaDB/HTTP 总回归已通过：`test-phase1` 与 `test-phase0` 都 exit 0；ClassIdentity HTTP 87 probes；独立 SYSTEM_ADMIN 不是 Seat；Guest 与四种 Seat 角色直链 Admin Console 均 403；Identity freeze 使现有页面/媒体 session 和新登录立即失效；匿名输出/解析分别由普通成员不可关联和管理员敏感查看写 Audit 的门禁保护。Pending-media 75 GET 同时证明 Seat 全拒绝、SYSTEM_ADMIN 审核允许、异常/重复状态全拒绝并恢复到 72 images。Phase 0 的 72/72/8 媒体模型、权限与 290 + 16 + 38 probes 再次通过，数据库断连 opt-in 40 probes 也通过。这进一步确认已经冻结的 Piwigo-first 架构决定，但不等于生产放行。
- Community 16.f 的低信任 Pending → Admin Approve 与高信任直接发布流程可复用，但实测还发现三个门禁：默认权限过宽、`category` 必须拒绝数组形态、管理员审核端点接受无 CSRF token 的 POST。该插件在受支持主栈中保持 inactive。
- User Collections 16.a 实测存在跨 ACL 路径：仅能访问 HERITAGE 的 FAMILY 可在猜中 LIVING image id 后把它加入/呈现到 collection，并取得派生图。它没有安装进受支持主栈；Core Favorites 只是范围更窄的候选退路，也必须通过猜 id、权限撤销、呈现和媒体 URL 回归，不能先称为安全。
- 未在真实绿联 NAS、真实照片库、真实移动设备或高并发环境上测试。NAS 结论来自官方 UGOS/Piwigo 行为、源代码和本地容器边界，仍需真机只读验收。

Piwigo 16.4.0 是 2026-05-03 发布的稳定修复版本；官方 changelog 将 16.4.0 列在稳定版本线上。[Piwigo 16.4.0 release note](https://piwigo.org/release-16.4.0)、[stable changelog](https://piwigo.org/changelogs)。HumHub 比较栈依据其官方 1.18 发布线和 Marketplace Gallery：[HumHub 1.18 release notes](https://docs.humhub.org/docs/about/releasenotes/release_notes_1_18/)、[HumHub Gallery](https://marketplace.humhub.com/module/gallery)。

## 17 项工程比较

| Requirement | HumHub-first | Piwigo-first | Existing reusable component | Custom code required | Upgrade risk | Decision |
|---|---|---|---|---|---|---|
| 1. 达成 Apple Photos / Immich 风格 photo-first UI | 信息模型以 Stream、Space、作者和内容容器为中心。即使 Gallery 可显示网格，仍需重写首页、全局照片聚合、导航和照片详情语义。 | Core 的一级对象就是照片、相册、日期和元数据；当前 Bootstrap Darkroom 响应已经是相册网格 → 照片页 → 全屏 viewer 路径。仍不是最终视觉，但方向一致。 | HumHub Gallery；Piwigo album/photo/calendar；Bootstrap Darkroom。 | HumHub：应用级首页、聚合层和大量 view override。Piwigo：连续时间轴、产品导航和克制的 child Theme。 | HumHub 高：每个 Core/Gallery 模板升级都可能冲突。Piwigo 中：少量模板/hook，媒体模型不变。 | **Piwigo-first**；照片是页面骨架，不是帖子附件。 |
| 2. 新增自研代码量 | 除 Identity/Policy/Anonymous/Submission/Spotlight 外，还要新增 photo home、跨 Gallery 查询、viewer 适配、社交 UI 隐藏和兼容测试。 | 媒体基础全部复用；新增范围集中在 Identity、业务权限、媒体 URL 授权、匿名、collections、Spotlight、少量互动和 Theme。 | 两边都复用认证/用户记录；Piwigo 额外复用完整图库管线。 | 用“升级敏感面”估算而不伪造 LOC：HumHub 至少 10 类跨 Core/Gallery UI/数据接点；Piwigo 是 3 个可部署 Class 插件、约 6 个内部 domain/service 边界加一个 child Theme。 | HumHub 高；Piwigo 中，主要风险在自定义授权插件而非媒体 CRUD。 | 选择更少、更窄的 Piwigo 扩展面。 |
| 3. 是否需要修改 Core | 无需 fork，但若用重度 Theme 改变产品模型，会覆盖大量 Core/模块视图。 | 已实证无需修改 Core：独立 plugin、nginx public gateway/internal locations 与 `X-Accel-Redirect` 闭合 original/derivative 授权。 | HumHub module/theme API；Piwigo plugin hooks/theme parent fallback；nginx internal delivery。 | 保持薄 MediaGuard 并在每次 Core/URL 约定升级时重跑 HTTP 矩阵；不得 patch `i.php`、`picture.php` 或 Core 表定义。 | 大量 HumHub view override 等同持续 merge 负担；独立 Piwigo 插件/代理配置升级面较窄但必须回归。 | **Piwigo 胜出：不改 Core 的媒体 403 spike 已通过。** |
| 4. HERITAGE/LIVING 与角色权限 | 两个 private/invisible Space 很适合读边界；但 Space 角色不能直接表达 CLASSMATE/TEACHER/FAMILY/ANONYMOUS 的动作差异，仍需 policy bridge。 | 两个 private root album + group ACL 负责目录/API 基础，MediaGuard 对每个 original/derivative 叠加显式 Principal、当前角色、有效 Era、Core ACL 与下载策略；跨 Era 关联对所有角色拒绝。ClassIdentity CapabilityGuard 已覆盖当前 Family/Anonymous 负面动作，未来端点仍需同样门禁。 | HumHub Space membership；Piwigo private albums、groups、album inheritance；ClassArchivePolicy MediaGuard；ClassIdentity Principal/CapabilityGuard。官方说明 private album 可按用户/组授权：[album permissions](https://doc.piwigo.org/organizing-albums/permissions-and-album-visibility)。 | 保持中央 Principal/policy resolver；补 active Family release、Community upload/album、Archive/Collection 等未来端点的服务器端负面测试。 | HumHub 中；Piwigo 中，主要风险转为自定义 policy 与 Core URL/ACL 组合的升级回归。 | Piwigo ACL + MediaGuard 已闭合当前读路径；**不能把 Core album ACL 单独当完整安全边界**。 |
| 5. 用户自建 Community Album | Gallery 支持 Space/Profile Gallery，但用户相册仍依附 Space/Profile；官方档案层级和协作关系需适配。 | Piwigo 原生 album/sub-album CRUD 和封面/说明天然匹配；需限制只有 CLASSMATE/TEACHER 可在 Community root 下创建。 | HumHub Gallery；Piwigo album tree。官方把 album 作为图库结构中心：[albums and sub-albums](https://doc.piwigo.org/organizing-albums/albums-and-sub-albums-piwigo)。 | Piwigo 只需 owner、Community root、Era、成员动作检查；不写相册 CRUD。 | HumHub 中高；Piwigo 低到中。 | 复用 Piwigo album CRUD，Class 插件只加所有权与边界。 |
| 6. FAMILY 投稿审核 | Gallery 上传立即发布；需要另建 pending submission 层，虽可继续用 HumHub File。 | Community 16.f 已验证低信任 Pending 和高信任直接发布，功能更接近需求，但当前安全门禁阻止激活。 | Piwigo Community 的上传、待审和发布流程；[Community workflow](https://doc.piwigo.org/managing-users/community-plugin-piwigo)、[Community extension](https://piwigo.org/ext/index.php?eid=303)。 | 首选给 Community 提交上游修复并加 `ClassSubmissionPolicy` 封装默认权限、标量 category、CSRF、Era 和 audit；若不能安全封装，再写薄 Submission Layer，媒体仍交给 Piwigo。 | 当前 Community 16.f 为高，门禁通过后中。 | 保留复用价值但默认 inactive；**安全测试通过前不启用**。 |
| 7. 评论、回复、点赞 | Core 评论、回复、Like、Notification 非常成熟，HumHub 明显领先。 | Core 提供照片评论和审核，但不是业务角色权限系统；album comments 不是 Core；Core rating 是加权星级评分，不是 Like。当前 ClassIdentity CapabilityGuard/AnonymousPresenter 已验证 Family 拒绝和匿名照片评论/输出边界，普通 baseline 仍关闭全局评论。官方边界见 [comments](https://doc.piwigo.org/comments-and-ratings/managing-comments)、[ratings](https://doc.piwigo.org/comments-and-ratings/managing-ratings-votes)。 | Piwigo photo comments；ClassIdentity CapabilityGuard/AnonymousPresenter；候选 Comments on Albums / Reply To / Subscribe 尚未通过本项目安全回归；Core rating 保持关闭。 | 保留已实现的 role/output guard；真正 reply、账号级唯一 Like、Report 与生产评论启用策略仍需薄适配。Notification/Activity 延后且只能围绕照片。 | HumHub 低；Piwigo 中。 | 接受 Piwigo 的小型互动缺口，避免为复用社交后台引入整个 HumHub。 |
| 8. 私人 Collections | Content Bookmarks 只提供平面私有书签；命名集合仍需 ClassCollections。 | Core Favorites 是范围较窄的单列表候选，但尚未通过本项目的猜 id、权限撤销、呈现/导出和媒体直链回归；User Collections 16.a 已确认跨 ACL，必须排除。 | HumHub Content Bookmarks；Piwigo Core Favorites；[User Collections documented behavior](https://doc.piwigo.org/browsing-your-piwigo-gallery/user-collections-plugin-piwigo)。 | 薄 `ClassCollections`：owner、name、image_id、sort；add/list/render/export 每一步都与当前用户可见 image ids 求交，永不复制媒体。 | 两边中；未经修复的 User Collections 为不可接受。 | Piwigo + 自研薄集合关系；**User Collections 16.a 不安装，Favorites 先过 ACL 门禁**。 |
| 9. 一张照片进入多个相册但不复制原文件 | Gallery Media 只属于一个 Gallery；Official Archive 需要自定义关联。Share Content 是流包装，不是 Gallery placement。 | 原生 `images` ↔ `image_category` 多对多。主栈已验证 72 个 original path 无重复且存在多相册关联。官方也明确一个文件可位于多个相册：[photo metadata / linked albums](https://doc.piwigo.org/import-and-manage-photos/properties-metadata-photos-piwigo)、[batch association](https://doc.piwigo.org/import-and-manage-photos/batch-manager-piwigo)。 | Piwigo Core 关系表和批量关联。 | 仅增加 Official/Community/Era 关联守卫与审计；禁止跨 Era 关联。 | Piwigo 低；HumHub 中。 | **Piwigo 显著胜出**。 |
| 10. 照片时间轴 | 需要自定义跨 Gallery/Space 查询，并把 Gallery Media 从内容流重新组织为日期流。 | Core 保存/搜索 `date_creation`，支持按拍摄时间或上传时间的 Calendar；还缺 Apple Photos 式连续时间轴、月/事件分段与无限加载。[calendar modes](https://doc.piwigo.org/browsing-your-piwigo-gallery/albums-in-your-gallery)、[date search](https://doc.piwigo.org/browsing-your-piwigo-gallery/search-for-a-photo-in-your-gallery)。 | Piwigo metadata、search、calendar、derivatives。 | Theme 中新增 ACL-filtered timeline query/API adapter、月份/事件 grouping、分页游标和 lazy loading；不写媒体索引。 | HumHub 高；Piwigo 中。 | Piwigo 提供数据基础，Theme 只补产品级时间轴。 |
| 11. 全屏照片浏览 | Gallery 有 lightbox，但要与新 photo home 和详情信息重新统一；也可能再引入 viewer。 | Bootstrap Darkroom 16.d 已集成 PhotoSwipe 4.1.3；真实响应验证了初始化标记、相邻预取和 screen preview，尚未在受支持浏览器中实际操作全屏/缩放/滑动。[Bootstrap Darkroom 16.d](https://piwigo.org/ext/index.php?eid=831)、[theme documentation](https://doc.piwigo.org/piwigo-themes/bootstrap-darkroom-theme-piwigo)。 | Darkroom + PhotoSwipe 4 用于 spike；最终 Theme 锁定稳定的 PhotoSwipe 5.4.4；Piwigo derivative sizes。[PhotoSwipe 5.4.4](https://github.com/dimsemenov/PhotoSwipe/releases/tag/v5.4.4) | 只做成熟 viewer 的 Piwigo 适配、Class controls/信息抽屉与媒体授权 URL 接入，不重写 zoom/swipe/lightbox。 | Piwigo 低到中；viewer 升级需 HTTP 与真机手势 smoke。 | **直接复用成熟 viewer；Darkroom 仅是验证基线，不是最终视觉依赖。** |
| 12. 手机触控体验 | HumHub 自适应，但主交互仍是 feed/cards；photo viewer 需额外适配。 | 父主题声明 mobile-ready/PhotoSwipe swipe，响应式资源已存在；仍未在本轮真机上做手势、safe-area 和低带宽验收。 | Bootstrap Darkroom/PhotoSwipe、响应式 derivatives。 | child Theme 的底部导航、触控目标、viewport/safe-area；真机回归，不写手势引擎。 | HumHub 中高；Piwigo 中。 | Piwigo 路线更接近目标，但把真机验证列为 Phase 4 gate。 |
| 13. Core 升级冲突风险 | 为隐藏 Stream/Space/Profile 并重建照片首页，需要覆盖许多 Core/Marketplace views；Gallery 与 Core 版本还需成组锁定。 | Core/镜像、父主题和插件均可精确锁版本/哈希；Class 表/插件独立。主要风险来自插件维护质量和媒体 guard 与 URL 生成约定。 | 两边的模块系统；Piwigo 官方镜像和插件 hook。 | 每次升级跑 ACL、media URL、Community、collections、viewer、no-copy 和 migration 回归；先备份恢复演练。 | HumHub 高；Piwigo 中。 | 选择 Piwigo；不使用自动漂移版本，不在后台直接更新生产插件。 |
| 14. NAS 部署难度 | 当前 HumHub 官方 1.18.4 评估镜像在本地为 amd64 路线，且包含更多常驻队列/社区能力。 | 单 PHP 图库 + MariaDB + cron/derivative volume；本地 Compose 已按数据库、Piwigo data、uploads、galleries、derivatives 分卷。目标 NAS 架构和权限仍需逐机型验收。 | Piwigo 官方 Docker；UGOS Pro 官方支持 Docker 项目与 Compose：[UGOS Docker](https://support.ugnas.com/detail/article/en-US/59)、[Compose project example](https://support.ugnas.com/detail/article/en-US/364)。 | NAS 文档、UID/GID、反代 HTTPS、cron、备份/恢复、磁盘容量与启动耗时测试。 | Piwigo 低到中；硬件/固件差异不可忽略。 | Piwigo 的运行栈更小，更适合低并发 NAS。 |
| 15. 与绿联 NAS 现有照片目录共存 | HumHub File/Gallery 以应用上传为中心，直接复用外部照片树并不自然。 | Piwigo self-hosted physical galleries 支持把目录放进 `./galleries` 后同步，但有命名、重命名/移动和重新同步约束；不是任意外部库的安全只读索引器。[filesystem synchronization](https://doc.piwigo.org/self-hosting-piwigo/importing-and-synchronizing-ftp-photos)。 | UGOS 共享目录可 bind mount；绿联相册支持把共享文件夹加入共享图库：[UGOS shared library update](https://www.ugnas.com/news-detail/id-33.html)。 | 先做真机路径/ACL/文件不变性 spike；使用 Class Archive 专用 master，Piwigo 为唯一写入者，绿联相册只索引同一共享目录。既有绿联主库不直接挂到 Piwigo 根。 | 中高：两个应用共写、容器启动改权限、源文件消失会破坏索引。 | 可共存，但 **禁止双写和未经验证的既有目录直挂**；见下文 NAS 决策。 |
| 16. 未来开源发布复杂度 | HumHub Core 为 AGPL-3.0-only，外加多个模块许可证与重 Theme 适配；Hybrid 还会扩大安装/支持矩阵。 | Piwigo Core GPL-2.0-or-later；Bootstrap Darkroom Apache-2.0、bundled PhotoSwipe MIT；Class 插件可独立仓库/目录发布，私人配置与数据不进入 Git。 | Piwigo/PHP 插件生态、Compose、迁移。 | 清晰 license/NOTICE、无真实数据 seed、`.env.example`、可重复 bootstrap、升级矩阵与安全说明。 | HumHub/Hybrid 高；Piwigo 中。 | 单平台开源发布；不要把私有部署数据写进插件或 Theme。 |
| 17. 10–20 年长期维护成本 | 社交能力复用最多，但产品外壳长期与论坛/feed 信息模型对抗；每次升级都需确认被隐藏的入口没有重新出现。 | 媒体模型与产品目标同向；可让 Piwigo 持续拥有最难维护的上传、格式、派生图、元数据、相册和搜索。代价是少量 Class policy 与 security guard 由项目负责。 | 成熟 Piwigo media core + 隔离的 Class domain。 | 版本锁、年度升级演练、季度备份恢复、ACL 安全回归、NAS 健康检查；不扩张 Activity/通知直到有真实需求。 | HumHub 高；Piwigo 中。 | **Piwigo-first 的总维护面更小，且不会随 UI 纠偏持续重写。** |

## 为什么不是 Hybrid

Hybrid 会新增两套账户、密码重置、session、冻结状态、内容 id、授权缓存和备份恢复顺序，还要解决以下一致性问题：

1. Claim/Seat 到两个 user id 的幂等创建与失败补偿；
2. FAMILY/ANONYMOUS 的 Era 与动作权限在两个系统间同步；
3. 照片评论、通知和举报指向 Piwigo photo id 时的跨系统链接与删除一致性；
4. 账号冻结后两个 session 是否同时立即失效；
5. 恢复到不同时间点后，关系 id 是否仍一致。

这会把 V1 变成集成项目。Piwigo 的评论/Like/Activity 缺口比维护第二个平台更小，因此 HumHub 不作为“隐藏后台”运行。

## 选定的职责边界

### Piwigo Core 和成熟组件拥有

- original、thumbnail、preview 的媒体生命周期与 derivative 生成；
- photo、album/sub-album、tag、creation date、filename、author/uploader metadata；
- `image_category` 多相册关联，不复制原文件；
- groups、private albums、album inheritance 和基础用户账户；
- Core Favorites 数据/单列表 UI（启用前仍需 Class policy ACL 回归）；
- 照片评论的数据与基础管理 UI（启用前由 Class policy 包裹）；
- Bootstrap Darkroom + PhotoSwipe 4 作为已验证的 spike viewer；最终 Theme
  复用独立锁定的 PhotoSwipe 5.4.4，不自行实现缩放、滑动和 lightbox。

### Class 插件边界

- `ClassIdentity`（已部署）：Identity → Seat → Account binding → Principal → Piwigo Account、Claim/Invite hash、Identity freeze/session revoke、审计、幂等 provisioning、SYSTEM_ADMIN、最小 Admin Console、CapabilityGuard、AnonymousPresenter 与敏感解析；自定义表一律 InnoDB；
- `ClassArchivePolicy`（已部署）：HERITAGE/LIVING 根与跨 Era 关联不变量；其 MediaGuard 负责每次 original/preview/thumbnail/derivative 的 session、显式 Principal、角色、Era、冻结状态授权和 cache header；未来的 Collections/SubmissionPolicy 仍只是规划边界；
- `ClassSpotlight`（规划）：本人公开相册/内容、同时一个 active、TTL、自动过期、Admin 撤销、audit；

这些内部服务是代码边界，不代表另外部署五个插件；只有后来出现独立升级/权限边界的强证据才拆分。

### Class Archive Theme 拥有

- 默认 Photos 时间轴、Albums、Search、My、二级 Activity 导航；
- 月/事件分组、justified/masonry grid、lazy loading；
- viewer 的 Class 操作抽屉与 Featured/Spotlight 大图卡；
- 隐藏 Piwigo 的传统图库/后台痕迹，但不承担授权、不复制媒体、不重写 PhotoSwipe。

## 已确认的安全门禁

### P0（已通过）：媒体 URL 受服务器端 ACL 保护

原始 spike 曾证明：从授权会话取得 LIVING `i.php` derivative 或直接 `/upload/...` original path 后，Guest 可得到 HTTP 200。该失败证明页面/API ACL 不等于文件 ACL。当前实现用独立插件 + Web Server 边界解决它，不修改 Core：所有 public `/upload/`、`/galleries/`、`/_data/i/`、`i.php`、`action.php` 媒体请求进入 PHP MediaGuard；Guard 读取实时 session、显式 Principal/Identity/Seat/account、有效 Era、Core forbidden category/image ACL 与 original policy；ALLOW 后才发出 nginx internal redirect。PHP 不承担 200GB 文件的 userspace 流式传输。

当前实现满足：

1. Web Server 默认拒绝直接访问 original 和 derivative backing paths；
2. Theme/Core 生成的媒体 URL 经过 `ClassArchivePolicy` MediaGuard；URL 不是 bearer credential；
3. Guard 从 path/image id 反查 Piwigo 可见 album 集，并叠加显式 Principal、Identity/Seat/account 状态、role、Era、Family 下载设置；
4. thumbnail、preview、original、Range、HEAD、cache-hit/cache-miss、相邻预取全部经过同一授权；
5. URL 复制给 Guest/FAMILY、logout 后重放或切换到低权限账号时被拒绝；私有缓存必须 revalidate；
6. 不以“路径难猜”作为安全控制，不 patch Piwigo Core。

290-probe HTTP 矩阵已覆盖角色/Era/variant、GET/HEAD/Range、logout/account switch、backing paths、guessing、path/query tamper 与 cache revalidation。另有 38-probe 可恢复状态套件证明：旧 session 权限撤销立即生效、跨 Era 关联对 Admin 也拒绝、两个 image row 指向同一 canonical original path 时 source/derivative/action 对所有角色 fail closed；40-probe 数据库断连版本返回 generic 503 且无 bytes/diagnostics。exact roots 和 internal locations 返回 404。完整规则见 `docs/media-access-policy.md`。

这项通过只冻结“Piwigo 能否安全承担媒体 Core”的架构判断。Identity freeze/session revoke 已通过状态转换测试；释放已激活 Family account 仍未实现。API、搜索、分享、Archive、viewer/download 与未来导出端点也必须继续复用同一中央 policy，不能另开旁路。真实照片与网络开放仍禁止。

### P0：Community 16.f 只能在包装后启用

Community 的成熟上传/待审流程值得复用，但必须先完成：

- 删除/禁止插件默认创建的宽权限/public Community album；
- 仅按 group + 目标 Era 下发 upload permission；FAMILY 只能 HERITAGE low-trust，CLASSMATE/TEACHER 可对应 Era high-trust；
- 请求 schema 把 `category` 约束为一个整数标量，数组或未知 album fail closed；
- 管理审核 POST 必须验证 CSRF token、Admin 权限、submission 状态机和 audit；Approve/Reject 可重试且幂等；
- 审核前图片及其所有 derivative URL 都不可见。

在上游版本或本项目无 Core 修改的 guard 未通过这些测试前，锁文件允许下载审计但 `activate=false`。

### P0：User Collections 16.a 排除

此次不是“功能不足”，而是已确认的跨 ACL 失败，因此不能通过隐藏按钮缓解。受支持 runtime 不包含该插件代码。`ClassCollections` 的最低验收必须包括：

- FAMILY 无法用猜测 id 添加 LIVING 图片；
- 已被移出可见 album 的图片立刻从集合结果消失；
- list、detail、count、cover、export/download 和 viewer API 均重新校验；
- collection link 不能绕过 `ClassArchivePolicy` MediaGuard；
- 管理员的审计查看与普通 owner 的媒体响应分离。

### 评论、匿名与 Like

Piwigo Core comment 是照片评论，不是完整的 reply/notification 社交系统；album comments 需要插件，Core rating 是星级投票而不是 Like。服务端 role guard、匿名输出和受审计解析的 Phase 1 门禁已通过受控真实评论测试，但 ordinary private baseline 仍关闭 comments/rating。正式启用还必须确定生产评论策略和举报/审核面；Like 使用一个 `(user_id, image_id)` 唯一关系，不把 rating 改文案冒充 Like。

## NAS 与绿联相册共存决策

UGOS Pro 官方确认 Docker/Compose 项目可以把 NAS 共享目录 bind mount 到容器；实际路径和 UID/GID 权限必须按设备确认。[UGOS Compose volume example](https://support.ugnas.com/detail/article/en-US/364)、[shared-folder mount guidance](https://support.ugnas.com/detail/article/fr-FR/611)。绿联官方更新说明也确认相册可把共享文件夹作为图库内容。[UGREEN Photos shared library](https://www.ugnas.com/news-detail/id-33.html)。这证明“同一共享目录被容器与绿联相册看到”在产品能力上可行，但没有证明两个应用可以安全共同写入。

Piwigo 的 filesystem synchronization 要求源位于 `./galleries/` 并在文件变化后同步；文档明确指出这是一套稳定但受命名、移动/重命名约束的高级工作流。[Piwigo filesystem sync](https://doc.piwigo.org/self-hosting-piwigo/importing-and-synchronizing-ftp-photos)。进一步的源代码风险是：

- 官方 Docker 启动脚本会递归设置 Piwigo 根目录 ACL/ownership，因此不能把既有绿联照片主库直接挂到 Piwigo 根并假设“绝不改动”。[official init script](https://github.com/Piwigo/piwigo-docker/blob/972d7eabaff0c1c22746c291bc24cb1367077c90/config/init-script.sh#L47-L54)
- filesystem sync 发现源文件缺失时会移除数据库关系，网络盘短暂不可见不能被当成普通空目录。[site update removal path](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/admin/site_update.php#L715-L733)
- 当前 Core 明确不支持把任意 remote site 当作新同步站点。[site manager](https://github.com/Piwigo/Piwigo/blob/bef1a4ac424b4e986589e4cfc9f4d134f1b16f15/admin/site_manager.php#L52-L58)

因此 V1 最安全的共存拓扑是：

1. 新建 **Class Archive 专用共享 master**，不把同学现有绿联相册主目录直接交给容器；
2. Piwigo 是该 master 的唯一写入者，数据库、uploads/galleries、derivatives 和 backup 分开挂载；
3. 绿联相册只把该 master 加入共享图库进行索引/AI，先在合成副本上验证它不会改名、移动、删除、写 sidecar 或改时间；
4. derivatives 独立存放，绿联相册只扫描 original master，避免 AI 对缩略图重复索引；
5. 既有绿联照片需要导入时，使用 read-only source → 校验哈希/manifest → 受控单向导入。只有同一文件系统、两端都保证不改写/删除并完成恢复演练后，才评估 hardlink/reflink；默认不以 symlink/hardlink 节省空间换取数据耦合；
6. 在真机验证容器重启、UGOS 更新、Piwigo 更新、源暂时离线、权限变化、备份恢复后，才允许真实照片迁移。

该方案可能让现有绿联库与 Class Archive master 各保留一份 original；这是 `<200GB` 规模下用存储换数据边界的可接受保守选择。对于由 Class Archive 新增的照片，Piwigo 写一份 master、绿联只索引该份，不再复制第二份 original；Piwigo 的 preview/thumbnail 是必要 derivative，不视为原图重复。

## 分阶段门禁

### Phase 1：ClassIdentity（安全底座已实现）

- 已完成四个 checksum-attested migrations、十张 InnoDB 表、Identity/Seat/Account/Principal、Claim/Family Invite/Teacher Claim、Anonymous Seat、exact group projection、Identity freeze/session revoke、Audit、SYSTEM_ADMIN 与最小 Admin Console；
- 87-probe ClassIdentity HTTP、75-probe Pending-media 与 maintenance/enforcement/capability/anonymous/schema/credential gates 通过；随后 Phase 0 媒体回归再次通过；
- active Family account release、member password reset、account-level governance、Submissions/Anonymous governance/Archive/Spotlight 页面仍待实现；
- 不先做漂亮 Theme。

MediaGuard 已使用 active Principal/Account/Seat/Identity resolver，且不再存在只看 Piwigo group 的受支持 fallback。尚未实现的 active Family release 必须沿用同一 resolver 和状态转换回归，不能另开授权路径。

### Phase 2：Archive / Policy

- HERITAGE/LIVING、Official/Community ownership 与跨 Era 关联守卫；
- Community 安全包装后才启用 Family moderation；
- `ClassCollections` 替代 User Collections；
- original download setting 和 EXIF preview stripping。

### Phase 3：Photo-context Social

- photo/album comments、anonymous alias、Like、Report、Spotlight；
- Activity 只做二级聚合，且仅在不引入通用 Feed 子系统时实现。

### Phase 4：Class Archive Theme

- Photos 时间轴为首页；Albums / Search / My 为一级导航；
- Darkroom/PhotoSwipe 4 只保留为 spike 参照；最终 Theme 锁定
  PhotoSwipe 5.4.4，并先完成功能和安全回归，再做视觉收敛；
- 桌面与真机触控截图、低带宽瀑布流、可访问性和缓存行为成为验收项。

### Phase 5：NAS-ready

- 真机架构、RAM、UID/GID、Compose、cron、HTTPS 反代；
- shared master 共存只读验证；
- database + original + derivative + config 的一致性备份和完整恢复演练；
- 升级前后运行全套 media URL 与 Era ACL 回归。

## 最终结论

Piwigo-first 不是因为 Piwigo 的社交能力更强；相反，HumHub 在评论、回复、Like、通知、举报和通用 Activity 上明显更成熟。选择 Piwigo 是因为本产品的不可妥协一级模型是照片、时间与相册，而 Piwigo 已经复用了最昂贵、最容易丢数据的那组轮子：上传与文件生命周期、派生图、元数据、相册树、日期/搜索、多相册无复制关联、私有相册查询/目录 ACL、Favorites 数据能力、照片评论和 PhotoSwipe viewer。

相较从零开发，本路线避免重写媒体库、缩略/preview 管线、EXIF/日期索引、相册 CRUD、搜索、批量管理、多相册关系、viewer 手势和基础评论管理。项目自研被限制在“身份与地下秩序”：Identity/Seat、Era/role policy、媒体 URL 授权、匿名、薄 collections、Spotlight，以及少量 photo-context interaction。

因此正式判断是：

> **Piwigo-first，非 Hybrid，媒体可行性已冻结；ClassIdentity 安全底座已落地，先补业务治理与生产门禁，Theme 最后；Community、Collections、Admin MFA、attestation、恢复/cron、NAS 与部署安全门禁不过，绝不导入真实数据或开放网络。**
