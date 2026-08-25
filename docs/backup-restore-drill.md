# 合成环境备份与恢复演练

状态：ClassIdentity v14、fixture v6、manifest v7 的本机合成破坏恢复演练已于 2026-08-26 重新通过；旧 v4/v6 演练证据已按版本失效。本结果不代表 NAS、异地或公网恢复能力。

## 目标与边界

本演练只针对 localhost Docker Compose 中的 Class Archive 合成基线。它绝不导入真实照片，也不连接 NAS。恢复目标是确认一次完整备份能够恢复 Piwigo、MariaDB、ClassIdentity、MediaGuard 运行所需状态和安全启动脚本，而不是仅确认“备份文件生成成功”。

当前确定性基线为：72 张 Piwigo 图片、72 个物理原图、8 张多相册关联图片。

## 备份内容

备份包包含经 SHA-256 manifest 校验的以下组成部分：

- MariaDB 业务数据库，以及 `read_projection` / `read_photo` 的表结构（不包含投影缓存行）；
- Piwigo 持久数据与上传原图；
- galleries 与 Class Archive 私有状态；
- 持久化 `user.sh` 安全启动脚本；
- 格式 7 备份 manifest、ClassIdentity schema v14 的业务表清单、可重建投影表清单、18 个原生 Piwigo 投影/持久 source-epoch 保护触发器与每一项的校验摘要。

因此它覆盖图片、`image_category` 多相册关系、ClassIdentity Principal/Seat/Account/Claim/Invite 状态、班级档案元数据、投稿记录、匿名状态、Audit 和 MediaGuard 运行配置，也明确覆盖人物整理、人物修正规则、稳定相册、精选、来源证明、重复关系、批量操作日志，以及 MyISAM `native_source_epoch` 的唯一 `PIWIGO_NATIVE` 持久哨兵行。备份不包含衍生图缓存、Gateway `PHOTO_CATALOG`、时间轴、相册、人物、回忆、精选投影 payload、浏览器缓存、Docker 镜像、Git 工作树、真实 NAS 数据或任何生产 TLS/反向代理配置。

`read_projection` 和 `read_photo` 是授权中立的可丢弃读模型，不是业务真相。备份保留其 v14 DDL 以验证 schema，但通过 `mariadb-dump --ignore-table-data` 排除所有缓存行，包括 FULL/HERITAGE 角色范围的 aggregate payload。恢复器先确认两表均为空，再种入六条带独立 16-byte generation 的 `STALE` 控制记录；此时 `PHOTO_CATALOG.native_source_generation` 必须仍为 `NULL`，只能由后续重建绑定已恢复的 source-epoch 哨兵。Piwigo 健康后再由受限 CLI 以 `--scope=all` 根据 Piwigo/Class Archive 业务表确定性重建 `PHOTO_CATALOG`、时间轴、相册、人物、回忆和精选投影。验收要求 `PHOTO_CATALOG=ACTIVE/72`，且 `TIMELINE`、`ALBUMS`、`PEOPLE`、`MEMORIES`、`SPOTLIGHT` 五种 scope-aware aggregate 全部为 `ACTIVE`。浏览器缓存从不进入备份。

Piwigo 衍生图同样是可重建缓存：恢复先得到空的 derivatives 卷，再由既有 Piwigo derivative 管线显式预热。恢复门禁覆盖 72 张图的 `square`、`thumbnail`、`xsmall`、`small`、`medium`、`large`、`preview` 七种固定规格，共 504 项；`square` 仅服务 Piwigo Core 照片页胶片栏，不扩张 Class Archive 的六种产品媒体 API。任何生成、文件模式、队列或可信路径校验失败都会中止恢复，不会让成员 HTTP 请求临时生成文件。原图、媒体映射与权限策略才属于必须恢复的业务/媒体真相。

## 实测流程

受控脚本为：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\backup-restore-drill.ps1 -ConfirmSyntheticRestore
```

脚本先拒绝非 `72/72/8` 的运行态，然后：

1. 记录确定性业务恢复指纹（fixture v6），纳入 schema v14、持久 source epoch 与“全部读投影从业务真相重建”契约，但不把投影行写入指纹；
2. 创建并独立审核完整备份；
3. 若 isolated Immich server 正在只读挂载 Piwigo 原图，先验证其 compose 标签、目标路径和 `:ro` 挂载后，仅移除该可丢弃 server container；随后停止 Piwigo/MariaDB，且只删除本 Compose 带标签的合成卷；
4. 从指定备份恢复，等待 HTTP 与 Docker healthcheck；
5. 执行 `rebuild-photo-read-projection.php --scope=all`，验证 `PHOTO_CATALOG=ACTIVE/72`，且时间轴、相册、人物、回忆、精选五种物化 aggregate 均为 `ACTIVE`；
6. 执行 `warm-photo-cache.php --scope=all`，验证 72 张合成图的七种固定 Piwigo 规格共 504 项全部可用，并保存 `derivative-warmup.json`；
7. 恢复 Piwigo healthcheck 后按原先运行状态重建 Immich server container（不删除其 PostgreSQL/upload/model volumes）；
8. 比较恢复前后业务指纹（投影 generation/built_at 不参与比较）；
9. 跑完整 Phase 0 与 Phase 1 回归；
10. 在 HTTP 回归后再次重建投影，确认最终运行态仍为 `ACTIVE`；将运行证据、两次 projection rebuild 和 `derivative-warmup.json` 写入被 Git 忽略的 `.codex-work/backup-restore-drill/<timestamp>/`。

2026-08-26（本机，UTC 备份时间 2026-08-25）的当前 v6/v7 演练结果为：

| 项目 | 结果 |
|---|---|
| 备份包 | `class-archive-20260825T195019Z`；manifest 7/7 文件 SHA-256 通过 |
| 确定性恢复指纹 | `eeb3727e032664904ed1474144e3a5105a053fbaed89dbde9740395fc364eda4` |
| 基线 | `72/72/8` 恢复前后一致 |
| 读取投影 | `PHOTO_CATALOG=ACTIVE/72`；五种 aggregate 全部 `ACTIVE`；Phase 0/1 回归后再次重建并复核 |
| 衍生图恢复 | 空卷重建 `504/504`；七种固定规格；0 个隔离/残留队列项 |
| Phase 0 | PASS |
| Phase 1 | PASS |
| 从删除卷开始到服务、投影、衍生图和 Immich 只读挂载恢复的粗略 RTO | 132 秒 |

当前运行证据保存在被 Git 忽略的 `.codex-work/backup-restore-drill/20260826-035007/`。System Health 已重新验证为当前恢复契约版本。

2026-08-25（本机，UTC 备份时间 2026-08-24）的 v4 演练结果仅作历史记录；其不满足当前 v6/v7 恢复契约。

| 项目 | 结果 |
|---|---|
| 备份包 | `class-archive-20260824T231005Z`；manifest 7/7 文件 SHA-256 通过 |
| 确定性恢复指纹 | `7289b58a85ca53f0d931ff6604affcab0e0d768b22d377bf5a30e96e367c35cd` |
| 基线 | `72/72/8` 恢复前后一致 |
| 读取投影 | `PHOTO_CATALOG=ACTIVE/72`；五种 aggregate 全部 `ACTIVE` |
| 衍生图恢复 | 空卷重建 `504/504`；七种固定规格；0 个隔离/残留队列项 |
| Phase 0 | PASS |
| Phase 1 | PASS |
| 从删除卷开始到服务、投影、衍生图和 Immich 只读挂载恢复的粗略 RTO | 148 秒 |

RTO 仅是本机一次合成演练的观测值，不是生产承诺。
该历史证据保存在被 Git 忽略的 `.codex-work/backup-restore-drill/20260825-070953/`。代码升级仍会主动使 System Health 的旧证明失效；实现摘要不一致时必须重新执行受控演练，不能沿用旧绿色状态。

## 恢复后的安全检查

恢复演练不以页面返回 200 即结束。它还检查：

- Piwigo 与 MariaDB healthcheck；
- 安全启动脚本 SHA-256 与模式 `0755`；
- 原图/私有媒体模式符合 `0660` 策略；
- 空衍生图卷只能通过受限 CLI 维护边界重建，504 个固定规格均验证为可信文件；
- MediaGuard 的 HTTP 矩阵、低分辨率安全预览和状态转换；
- ClassIdentity、投稿、匿名呈现、管理控制台与维护门禁的真实 HTTP 回归。

失败时演练证据仍会保留在忽略目录中，便于定位；不会把失败写成成功状态。

恢复器在清空任何合成目标卷之前，必须先验证 `MANIFEST.json` 的格式 7、schema version 14、业务表清单、可重建投影表清单与 `projection_rebuild=ALL`；格式 6 等旧包不能作为当前恢复证明。恢复前后指纹逐个比较业务表；投影缓存不进入指纹，而是以恢复后全量重建的状态、数量和独立 JSON 证据验收。

备份器不会仅靠固定 JSON 声称 schema v14：在 `mariadb-dump` 前，它会解析唯一的 ClassIdentity migration 表，核对 migration 14、全部声明表、MyISAM/唯一行持久 source-epoch 哨兵和 18 个原生 Piwigo 投影/source-epoch 保护触发器；恢复 SQL 后、解包应用卷前，恢复器会再次核对同一组数据库对象，并拒绝任何夹带 catalog 或 aggregate 投影缓存数据的 v7 包。

System Health 的恢复证明版本同步提升为 v6，并绑定 Compose 备份定义、fixture、恢复器、全投影重建器与演练脚本摘要；其中任一实现变化，旧证明会自动变为“需要演练”，不会沿用一个只覆盖旧 schema 或部分投影的绿色状态。

## 运行限制

- 此操作具有破坏性，只能在合成环境中加 `-ConfirmSyntheticRestore` 执行。
- 不得用于真实照片、NAS 卷或公网实例。
- NAS 恢复、异机恢复、加密备份、保留周期和灾难演练仍是生产放行前的独立门禁。
