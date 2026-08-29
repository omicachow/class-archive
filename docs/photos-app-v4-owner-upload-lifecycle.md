# V4 私有 Owner 上传生命周期：安全封堵

此前设计的 8191 原地上传后精确删除方案无法在 Piwigo Core、ClassIdentity InnoDB、媒体文件、读投影、持久集合快照、AI job、临时身份和 Audit 之间提供原子恢复。它可能留下悬空媒体或 AI 对象、冻结但未删除的测试身份、历史集合引用，或让读投影停留在 `STALE`。

因此该入口已经安全封堵。无论是否传入兼容参数 `-ConfirmPrivateOwnerMutation`，它都只输出：

```text
V4_OWNER_UPLOAD_LIFECYCLE=BLOCKED code=BLOCKED_UNSAFE_ORIGIN_CLEANUP runtime=not_executed
```

并以非零状态退出。它不会读取项目或私有环境文件，不会创建 `.codex-work` 内容，不会访问浏览器、网络、Docker、WSL、8190/8191、数据库、媒体或凭据。

```text
EVIDENCE_STATUS=STATIC_PROTOCOL_ONLY
IN_PLACE_8191_MUTATION=PROHIBITED
DIRECT_AUDIT_DELETE=PROHIBITED
DB_BEFORE_CORE_OR_FILE_DELETE=PROHIBITED
DANGEROUS_RECURSIVE_REMOVE=PROHIBITED
NO_PRIVATE_RUNTIME_ACCESS=YES
NO_DOCKER_SERVICE_CONTROL=YES
NO_FILE_OR_CREDENTIAL_ACCESS=YES
```

## 为什么必须封堵

- 普通成员上传会刷新读投影并发布持久集合快照；历史 `SUPERSEDED` 快照仍可能引用测试照片。
- Piwigo Core、ClassIdentity InnoDB 和文件系统无法由一次数据库事务原子删除。先删任意一侧都会在后续步骤失败时留下无法精确重试的半完成状态。
- 增量 AI worker 可能在检查与清理之间认领 job，人工约定“暂时不要运行”不能构成互斥锁。
- Claim、Family Invite 和 Anonymous Seat 等真实流程会留下身份、席位和审计业务状态；冻结不等于恢复基线。
- Audit 是安全证据，不能为了恢复计数而直接删除。
- 仅比较图片和映射数量不能证明投影 epoch、快照指针、JSON 引用、身份和文件状态已恢复。

原浏览器写入 runner 与 PHP 清理助手已经删除，避免被环境变量或直接命令绕过。

## 当前允许的验证

只允许执行静态协议：

```powershell
pwsh -NoProfile -ExecutionPolicy Bypass -File .\tests\phase3\photos-app-v4-owner-upload-lifecycle-protocol.ps1
```

协议会在读取任何私有环境之前验证：

- wrapper 的 AST 只包含 `Set-StrictMode` 和阻断输出；
- 默认调用与带 `-ConfirmPrivateOwnerMutation` 调用得到完全相同的阻断结果；
- 不存在 8191、Docker、凭据、文件写入或删除调度；
- 不存在直接 `DELETE audit_event`；
- 不存在数据库先删、Core/文件后删的清理助手；
- 不存在危险递归 `Remove-Item`；
- 旧浏览器与 PHP helper 已从 Git 中移除。

静态协议通过不代表 Owner 上传 E2E 通过，只证明危险的原地清理路径已经不可执行。

## 未来重新开放的最低条件

Owner 上传生命周期验收应改在从 8191 受控快照创建的 disposable isolated clone 中进行，并在验收后整体销毁克隆。不得再通过对真实 Owner 实例逐表、逐文件反向删除来模拟回滚。

如果未来确需原地维护，必须先独立完成并审计：全局 mutation lease、AI worker 排他锁、持久 cleanup saga、集合快照 generation 清理、投影完整重建、文件最终路径 containment，以及覆盖全部身份/审计/投影/媒体状态的精确基线证明。
