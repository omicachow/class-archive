# AI 索引持久化策略

## 目的与边界

Class Archive 的照片、身份和访问策略仍是唯一业务真相。Immich 只提供本地 AI 运行时能力；它不决定任何成员可见范围，也不向浏览器直接提供原图或缩略图。所有最终媒体请求仍经过 ClassArchivePolicy、MediaGuard 和 nginx `X-Accel-Redirect`。

本策略只定义本地 AI 工作的持久化控制面。它不会在普通照片读取、时间轴、人物页或搜索 GET 请求中启动模型、下载模型、读取原图，或调用 Immich HTTP 接口。

## 持久化对象

Schema v15 使用两个 MariaDB 表：

- `ai_asset_index`：每个 canonical `class_photo_id` 的当前源校验和、可选 Immich asset 绑定、face/search 状态、模型名称和 revision、索引时间与受控错误代码。
- `ai_index_job`：校验和绑定的后台任务，不存储媒体路径、原始文件名、访问令牌、向量或人脸 embedding。

状态受限为：

- asset：`PENDING`、`INDEXED`、`UNAVAILABLE`、`FAILED`、`STALE`、`REMOVED`；
- job：`PENDING`、`RUNNING`、`UNAVAILABLE`、`FAILED`、`COMPLETE`、`CANCELLED`。

一个活动照片与任务类型只能保留一个活动任务。重复导入、重复恢复或重复维护扫描会复用该任务；不会反复创建相同的 AI 工作。

## 何时创建工作

仅以下受控写入路径可排队：

| Trigger | Job | 说明 |
| --- | --- | --- |
| `NEW_PHOTO` | `INDEX_ASSET` | 新发布 canonical photo。 |
| `PIXEL_CHANGED` | `INDEX_ASSET` | 像素/源校验和变化；旧完成状态变为 stale。 |
| `PHOTO_DELETED` | `DELETE_ASSET` | 照片退役后请求隔离运行时清理其 AI 资产。 |
| `MODEL_CHANGED` | `REINDEX_MODEL` | 已审计的模型名称或 revision 变化。 |
| `ADMIN_REINDEX` | `REINDEX_MODEL` | SYSTEM_ADMIN 明确请求，写入 Audit。 |
| `RECONCILIATION` | `INDEX_ASSET` | 管理员确认后修复已识别的状态漂移。 |

Private full-library importer 只在导入 journal 已终结并完成读投影 rebuild 后调用可重复的 post-import catch-up。该 hook 仅读取 canonical ID 与校验和，缺少私有 Immich worker 不会让照片导入失败。

## Worker 与失败关闭

独立的私有 worker 可以 claim `PENDING` job，并在执行前再次验证目标 canonical photo 仍为 `ACTIVE` 且 `media_checksum` 等于 `expected_checksum`。校验和变化、照片退役或缺少映射时，job 被取消或标为 stale；晚到任务不能覆盖新版照片，也不能删除替代资产。

未明确配置 private AI worker 时，维护报告状态为 `UNAVAILABLE`；它绝不尝试联网补模型、发现端点或把 Immich 全库结果回退给用户。worker 完成后才可将 asset 行写为 `INDEXED`。失败、模型 cache 不完整或隔离 runtime 不可达，必须保留受控失败状态，而不是扩大搜索或人物结果范围。

搜索、人物、封面、计数和分页在 Gateway 内仍按 ClassArchivePolicy 过滤。AI 索引完成不等于得到访问权，Family 等角色不能从 AI 索引、总数、缩略图或 asset ID 侧信道获得 LIVING 内容。

## 维护与一致性检查

`run-maintenance` 只报告 AI queue/index 状态：缺失 index row、checksum drift、失败任务与无法运行的 worker。它不自动 enqueue、重试、下载模型或运行推理。`ReconciliationService` 同样只产生 `MANUAL_REVIEW` finding，不删除任何 AI 或照片数据。

因此状态意义明确：

- `IN_PROGRESS`：worker 已配置且有待处理/运行工作；
- `UNAVAILABLE`：worker 未配置或已显式报告不可用；
- `PASS`：控制面没有结构性漂移；
- `REVIEW_REQUIRED`：映射、校验和或任务状态需要人工处理。

## 备份、恢复与重建

业务备份必须包含 Schema v15 的 `ai_asset_index`、`ai_index_job`，以及人物整理、相册、评论和自动回忆的业务表。恢复 fixture 只指纹化 AI 状态和被散列的 technical identifier；它不导出 comment 原文、媒体路径、原文件名、embedding 或私有截图。

Immich Postgres 中的 face/search vector 与 asset 索引属于隔离 runtime state，不能替代业务备份。生产方向采用两层恢复：

1. 若受控 Immich/Postgres backup 可用，连同其固定模型 revision 恢复，可更快恢复 face/search 查询；
2. 若该 runtime backup 不可用，以已校验的本地模型 artifact、canonical checksum 和 `ai_index_job`/`ai_asset_index` 做确定性重建。

无论使用哪一种，ClassArchivePerson 的名称、合并/拆分、隐藏和封面等业务覆盖必须从 Class Archive 备份恢复，不能由 Immich 自动重新推断。模型二进制、vector cache、私有照片和 private runtime dump 不进入 Git 或 public CI artifact。

## 模型升级流程

固定 Immich 版本升级或模型 revision 变化时：

1. 审核新模型 artifact、许可证、hash 与离线可用性；
2. 记录名称/revision，生成 `MODEL_CHANGED` job；
3. 由隔离 worker 以 checksum 验证完成重索引；
4. 运行 Gateway ACL、MediaGuard 与重启后即时查询回归；
5. 仅在全部通过后将旧模型状态视为可退役。

更改相册名、评论、Spotlight 或显示文案不触发 AI 重算。只有新照片、像素变化、退役、模型变化或明确管理员请求会创建工作。
