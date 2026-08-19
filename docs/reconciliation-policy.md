# 数据一致性核对策略

状态：localhost 合成基线已验证。该策略用于 Piwigo 的媒体图谱与 ClassIdentity InnoDB 业务图谱之间无法完全原子提交的场景。

## 运行方式

维护入口同时适用于 Windows 本地和未来 Linux/容器定时任务：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\run-maintenance.ps1 -RequireReady -Json
```

```sh
./infra/scripts/run-maintenance.sh --require-ready --json
```

维护程序使用私有文件锁，不能取得锁时返回 `maintenance_already_running`，不会并发执行。默认只读；被拒投稿二进制清理只有显式传入 `--apply-rejected-cleanup` 才可能删除符合保留期的文件。

## 核对范围

每次维护至少检查：

- Piwigo `images` 行与 `upload/`、`galleries/` 中物理原图是否一一可解析；
- 原图模式是否符合私有媒体 `0660` 策略；
- 已批准投稿是否拥有 Piwigo 图片、班级档案映射和“班级历史”关联；
- 投稿 Pending/Rejected 二进制与记录状态是否一致；
- 档案元数据是否引用已存在图片；
- 多相册关联是否完整；
- 未受管原图、缺失原图、异常衍生图、符号链接或不安全模式；
- 过期 Family 邀请、被拒投稿的保留期；
- 媒体访问安全证明和备份新鲜度。

当前合成基线扫描结果为 `PASS`、`issue_count=0`、`checked_images=72`。

## 处置等级

| 等级 | 含义 | 自动行为 |
|---|---|---|
| `SAFE_AUTO_FIX` | 已有精确、可逆或幂等领域操作的状态 | 仅过期邀请与明确达到保留期的被拒投稿可由专用服务处理 |
| `MANUAL_REVIEW` | 可能需要业务判断或可能影响照片关联 | 只记录，绝不自动改相册、图片或身份绑定 |
| `QUARANTINE` | 存在未受管物理媒体或高风险边界 | 只标记隔离候选，绝不自动删除 |

结构性错误不会被维护器“猜测修复”。例如，发现 Piwigo 图片行但原图缺失、Approved 投稿没有关联目标相册、档案元数据指向不存在图片时，系统会要求人工处理。

## 被拒投稿清理

Rejected 投稿默认保留 30 天，保留期可通过受控环境配置调整（最小 7 天、最大 3650 天）。到期后，维护器只在显式启用删除时移除经严格路径校验的 Pending 原文件和安全缩略图；投稿记录与 Audit 永不删除。每次清理会记录 submission id、文件类型、执行时间、结果和失败数，不写入原始 Token 或密码。

## System Health 表达

管理后台将一致性状态显示为：

- `正常`：最近 24 小时内完成、且没有问题；
- `发现 N 个待处理问题`：发现结构性异常；
- `需要重新检查`：记录缺失、脚本变化或超过有效期。

未知、缺失或过期都不是健康状态，并会继续阻止生产放行。
