# Collections 生命周期维护策略

日期：2026-08-28
状态：V4 本地维护策略；不代表生产放行。

本文约束 `/home` 精选集、回忆、精选和搜索建议的**后台**维护节奏。它不改变
Piwigo-first、ClassIdentity、ClassArchivePolicy、MediaGuard 或隔离 Immich 的职责；普通
GET 永远不会执行这里的任何工作。

## 目标与边界

Collections 是由已持久化的读投影构成的产品层，而不是一次访问时临时扫描全库的
页面。维护器只使用已有的 `collection_maintenance_state`、read projection、Collection
snapshot 和 AutoCollection 数据；不增加 schema，不读取浏览器参数，也不使用某个成员
的身份、pin 或反馈来改变全局可见范围。

`run-maintenance.php` 已有的单实例文件锁防止同一运行时并发执行；每个日历窗口再以
`collection_maintenance_state` 中独立的 idempotency key 保存完成状态。服务器统一以 UTC
计算窗口：

| 节奏 | watermark | 工作 | 明确不做 |
|---|---|---|---|
| 每日 | `COLLECTION_LIFECYCLE_NIGHTLY` + `YYYY-MM-DD` | 从受控原生源刷新持久资料库/聚合投影，并验证完整 Collection snapshot bundle | 不由 GET 触发，不创建 Face/CLIP/Smart Search job |
| 每周 | `COLLECTION_LIFECYCLE_WEEKLY` + ISO `YYYY-Www` | 仅在已发布的 HOME snapshot 内轮换“值得再看”卡片的顺序，然后原子发布完整四类 bundle | 不重扫 Piwigo，不重建资料库/聚合，不运行 AI |
| 每月 | `COLLECTION_LIFECYCLE_MONTHLY` + `YYYY-MM` | 只读检查 active projection、AutoCollection mirror、snapshot 结构和 AI index 健康状态；输出宽泛的 section diversity 指标 | 不触发人脸检测、embedding、聚类、Smart Search indexing 或修复写入 |

已到期的 Spotlight 仍由现有每次维护开始时的服务器端 deadline 处理；随后马上进行
窄的 Spotlight projection 修复。这样不会因为等待下一日窗口而继续展示已到期卡片。
Piwigo derivative warmup 仍是显式维护动作，普通浏览不会 resize 原图。

## 每周推荐轮换

每周轮换不会修改 Memory、Album、People、Spotlight 或任何照片 membership。它只在已验证
的 HOME snapshot 中找出 `section=RECOMMENDATION` 的固定槽位，并以 `scope + ISO week` 的
服务器端确定性偏移重新排列这些卡片：

- `FULL` 与 `HERITAGE_ONLY` 独立计算、独立发布；
- 非推荐卡片的位置完全不变；
- recommendation 的 opaque item key、封面、照片成员和 payload 完全不变；
- 同一周已完成的 watermark 会返回 `CURRENT`，不会再轮换一次；
- `RUNNING` watermark 不会被自动夺取，维护状态变为需要人工关注；
- 发布前后均比对当前 presentation epoch。并发 archive/ACL 变动会让该次发布失败关闭，
  而不是发布过期内容。

四类 snapshot（HOME、MEMORY、SPOTLIGHT、SEARCH_SUGGESTION）通过既有 atomic bundle
publish 一起切换，因此不会出现“新的推荐顺序配上旧的搜索建议”的半发布状态。

## 低元数据照片的候选原则

低元数据并不是让系统猜测拍摄时间或地点的许可。自动回忆/推荐候选只能使用当前已有、
已持久化且可由 Class Archive 解释的业务信息：

1. 已确认的 `archive_date` 与 `date_precision`；
2. 已确认的活动、年级或学期上下文；
3. 已映射的 Album / SourceCollection 展示上下文；
4. 已持久化、已通过 policy 过滤的人物 projection；
5. 管理员已明确整理的 Canonical Photo、相册、回忆或人物 override。

以下内容不能单独制造“那一年”“某年今日”“某地”或精确日期：上传时间、文件创建/修改
时间、转存窗口、不可信 EXIF、文件名、原始绝对路径、模型猜测和某位用户的 click/pin/
feedback。日期未知时应保留“日期未知”或只显示活动/相册语义；不能补造日、地点或人物。

本轮的月度 audit 仅把 `BALANCED` / `LIMITED_METADATA` 作为内容健康提示。`LIMITED_METADATA`
对小图库、未整理图库或日期未知图库是合法结果，不会自动伪造候选，也不会把健康 audit
本身当作授权条件。

## System Health 与失败行为

维护记录以 `tasks.collection_lifecycle` 保存的字段仅包括：版本、UTC 时钟、cadence、窗口、
状态、有限计数和通用失败代码。它不包含 snapshot/photo/person/account/principal 标识、文件
路径、来源文件名、错误原文或任何 secret。现有 System Health 已通过 MaintenanceStatus 的
总体 `PASS` / `ATTENTION` 状态反映 lifecycle 问题；细化的中文卡片是后续控制台呈现层工作，
不在本次维护策略改动范围内。

如果某个 action 失败，或每月 audit 发现 AutoCollection/AI index 的完整性问题，runner 会将
该窗口写为 `FAILED`，MaintenanceStatus 标为 `ATTENTION`，而现有 Gateway revision 绑定继续
拒绝过期 Collection snapshot。这样一个已发现的健康问题不会在同月下一次运行时被已完成
watermark 静默掩盖。维护器不会回退到 Piwigo 全库查询、Immich 全库结果或浏览器本地缓存。
下一次显式维护可重试 FAILED 窗口；`RUNNING` 状态则必须先调查，不能自动覆盖。

如果机器错过了某个日/周/月，下一次运行只完成当前 UTC 窗口，不伪造历史执行记录。这是
一个有意的、可审计的 local-first 维护策略，不是连续事件调度系统。

## 验证

公开安全的纯 schedule contract 位于
[`tests/phase3/collection-lifecycle-maintenance.php`](../tests/phase3/collection-lifecycle-maintenance.php)。
它不访问真实照片、私有运行时、网络或数据库，覆盖：UTC 日/ISO 周/月边界、稳定 watermark、
推荐成员不变与槽位不变、非法输入拒绝、无浏览器输入、每周/每月不触发 projection/AI rebuild
以及安全的 System Health 结果形状。

真实运行前仍需要在独立 synthetic migration runtime 上验证 V18 schema、Collection snapshot
bundle 和完整 maintenance runner；8191 私有真实图库不应因该静态/纯测试而被触碰。
