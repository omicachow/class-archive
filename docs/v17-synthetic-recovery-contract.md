# Schema v17 synthetic recovery contract

状态：已完成静态/合同门禁，以及一次完全隔离的 Docker DB-only 运行时恢复演练。此文档只描述合成 V4 migration sandbox 的 DB-only 验证，不能作为 Owner、私有真实图库、媒体卷、MediaGuard 或浏览器恢复的通过证据。

## 版本边界

恢复格式按 manifest 的 `format` 和 `class_identity_schema` 同时选择，未知组合在任何恢复目标被清空或查询前拒绝：

| 备份格式 | ClassIdentity schema | 合同 | 业务表 |
| --- | ---: | --- | --- |
| 8 | 16 | `FORMAT_8_SCHEMA_16` | 历史 v16 表集；不包含 Collections-first 表 |
| 9 | 17 | `FORMAT_9_SCHEMA_17` | format 8 表集加六张 Collections-first 业务表 |

format 8 的表集和 manifest JSON 保持不变，避免把旧 synthetic evidence 重新解释为 schema 17。format 9 只额外包含：

- `collection_snapshot`
- `collection_snapshot_item`
- `collection_snapshot_pointer`
- `collection_pin`
- `collection_feedback`
- `collection_maintenance_state`

`read_projection` 与 `read_photo` 仍是可重建的缓存；恢复包保存 DDL、不保存行数据。未知 format/schema、缺少表、manifest 不精确匹配或 checksum 错误一律失败关闭。

## DB-only v17 演练范围

V17 演练使用 opt-in `v17-synthetic-recovery` Compose profile。其备份、恢复和 fingerprint 服务只连接 synthetic migration MariaDB，且没有原图、上传、galleries、derivatives、Owner、私有路径或来源媒体挂载。

恢复目标是第二个空 MariaDB volume。该 volume 名由 ignored sandbox env 中的 `V17_SYNTHETIC_RECOVERY_DB_VOLUME` 提供；每个恢复尝试必须使用新的名称。恢复脚本绝不清空目标数据库：只有严格 manifest 检查已经通过、目标数据库被确认零表后，才允许导入。

备份包只含 SQL dump、manifest、checksum 和完成标记，明确标注：

```text
scope=DB_ONLY_SYNTHETIC_V17_RECOVERY
media=NOT_MOUNTED
media_guard=NOT_CLAIMED
```

因此它验证 schema/business-state 可恢复性，而不是媒体可恢复性。

## 非敏感 fixture

`capture-v17-synthetic-recovery-fixture.sh` 只输出六张新增表的行数和 SHA-256 摘要。JSON payload、item key、photo ID 列表和 principal identifier 均先在数据库端哈希；fixture 不输出原始 JSON、评论、文件名、绝对路径、媒体内容或私有清单。

运行时顺序如下，所有具体输入/输出路径都必须位于 Git ignored synthetic sandbox：

1. 将 V4 synthetic sandbox 迁移到 schema 17，并通过既有 migration verification。
2. 以受限 marker 脚本创建幂等的 collection pin、feedback 和 maintenance state，使新增六表不是空壳。
3. 捕获源数据库 v17 fixture。
4. 运行 `v17-synthetic-db-backup`，记录其 emitted bundle name。
5. 使用新 attempt-specific target volume 启动 `v17-synthetic-recovery-db`。
6. 仅把刚才的 bundle 名传给 `v17-synthetic-db-restore`。
7. 用 `DB_HOST=v17-synthetic-recovery-db` 捕获目标 fixture，并比较 `fixture_sha256`。
8. 确认恢复目标从未使用原 migration DB volume，且 read projection 缓存保持空/STALE。

当前 System Health 恢复 evidence 仍只接受既有完整媒体的 format 8/schema 16 演练。DB-only v17 proof 不会写入该状态，也不会伪装成 MediaGuard 或浏览器恢复证据；完整 v17 media drill 完成前，这是刻意的 fail-closed 边界。

## 已完成运行时证据

2026-08-28，在 `attempt7` 的全新 synthetic-only V16→V17 lab 中完成了 format 9 恢复演练：

- source 与 second-empty-DB target 的 opaque fixture SHA-256 相等；六张 Collections-first 表均包含受控 marker 状态；
- source 与 target 使用不同的 named DB volumes；target 无 host port；
- 恢复目标从未挂载 original、upload、gallery 或 derivative media；8191、8291、真实来源与 Owner runtime 均未挂载或访问；
- target 的 `read_projection` / `read_photo` 数据未被导入，恢复脚本只 seed 安全的 `STALE` projection metadata；
- 未知 format/schema、checksum、manifest shape、非空 target 的 fail-closed 检查继续由 `v17-backup-restore-contract.php` 覆盖。

因此可以准确标记 `V17_DB_ONLY_RECOVERY=RUNTIME_TESTED`。这仍不等同于 format 9 的全媒体恢复、MediaGuard 或 Chromium Browser E2E；那些证据必须在拥有安全完整媒体 fixture 的独立演练中完成。
