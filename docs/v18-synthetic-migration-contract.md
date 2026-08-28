# Schema v18 synthetic migration contract

本合同只用于最终 `attempt12` 的公开安全合成数据库演练。它验证从精确、固定的
v17 Schema 源码创建的非空 v17 Collections Snapshot 状态，向当前 v18
`spotlight_rotation_state` 追加迁移的行为；它不接触真实照片、Owner 运行时、
8191、既有恢复点或任何媒体卷。

## 固定边界

- 输入：既有的 `72` 张公开安全合成照片的 v16 DB-only 快照。
- 历史源码：本地 Git commit `52ff3a7ba91155efc7bed1572e2b1740973e484c`
  中的 `ClassIdentity/Schema.php`，在 ignored staging 中以 SHA-256
  `aee8ced818747a8f81c816ef5aef112005af280b694ef3bdf8f7ac453e6f7413`
  校验。
- 运行时：独立 Docker project `class_archive_v18_synthetic_migration_attempt12`；
  仅 loopback `9690/9691`，两个内部 bridge、独立 named volumes。
- 媒体：`NOT_MOUNTED`。本合同不构成 MediaGuard、浏览器或真实数据恢复证据。
- 历史 attempt8–attempt11 是 runner 修复过程中保留的独立合成 forensic lab；
  它们不作为本合同的通过证据。脚本没有 cleanup/down/delete 行为。

## 执行顺序

1. `initialize` 创建 ignored env 与经过固定 Git commit 校验的历史 v17 Schema。
2. `restore` 将 v16 DB-only snapshot 恢复进新的 attempt12 数据库。
3. `bootstrap-v17` 用历史源码实际执行 Schema migration，并写入 8 个 active
   snapshot、8 个 item、8 个 pointer 和 2 个 maintenance marker 的合成状态。
4. `migrate` 用当前源码运行 `Schema::migrate()` 追加 v18，并立即再运行一次
   证明 replay 是幂等的。
5. `verify` 分别对 v17 Collections domain 和 `<=17` migration ledger 做
   schema/count/extended-checksum fingerprint 比较，并用 v18 migration ledger 与 table
   schema 建立不受运行期轮换 checkpoint 影响的稳定 fingerprint。首次 v17→v18 迁移
   必须从空的轮换 state 开始；后续只读验证允许至多一个 `FULL` 和一个 `HERITAGE`
   的、受数据库约束验证的运行期 checkpoint。另在 disposable scratch prefix 中验证
   unknown/partial schema 的 `verifyCurrent()` fail-closed。
6. `recover` 创建 format-10 DB-only synthetic bundle，恢复到另一个全新、空的
   MariaDB volume，并比较 v17/v18 fingerprint。它不复用 attempt12 源 DB 卷。

## 格式 10 恢复边界

format 10 包含当前合成 MariaDB logical dump 与 checksum manifest；不包含原图、
uploads、galleries、derivatives、私有路径、真实人物或任何 secret。恢复脚本拒绝：

- 非 `class-archive-v18-synthetic-<UTC>` bundle 名；
- manifest/checksum 不一致；
- 目标数据库非空；
- 恢复后不是 schema v18 或不是 72 张合成照片。

旧 format 9/schema v17 恢复合同保持不可变；它不是 v18 恢复证据。
