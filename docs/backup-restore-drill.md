# 合成环境备份与恢复演练

状态：本机合成环境已验证；不代表 NAS、异地或公网恢复能力。

## 目标与边界

本演练只针对 localhost Docker Compose 中的 Class Archive 合成基线。它绝不导入真实照片，也不连接 NAS。恢复目标是确认一次完整备份能够恢复 Piwigo、MariaDB、ClassIdentity、MediaGuard 运行所需状态和安全启动脚本，而不是仅确认“备份文件生成成功”。

当前确定性基线为：72 张 Piwigo 图片、72 个物理原图、8 张多相册关联图片。

## 备份内容

备份包包含经 SHA-256 manifest 校验的以下组成部分：

- MariaDB 数据库；
- Piwigo 持久数据与上传原图；
- galleries、衍生图缓存和 Class Archive 私有状态；
- 持久化 `user.sh` 安全启动脚本；
- 备份 manifest 与每一项的校验摘要。

因此它覆盖图片、`image_category` 多相册关系、ClassIdentity Principal/Seat/Account/Claim/Invite 状态、班级档案元数据、投稿记录、匿名状态、Audit 和 MediaGuard 运行配置。备份不包含 Docker 镜像、Git 工作树、真实 NAS 数据或任何生产 TLS/反向代理配置。

## 实测流程

受控脚本为：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\backup-restore-drill.ps1 -ConfirmSyntheticRestore
```

脚本先拒绝非 `72/72/8` 的运行态，然后：

1. 记录确定性恢复指纹；
2. 创建并独立审核完整备份；
3. 停止 Piwigo/MariaDB，且只删除本 Compose 带标签的合成卷；
4. 从指定备份恢复，等待 HTTP 与 Docker healthcheck；
5. 比较恢复前后指纹；
6. 跑完整 Phase 0 与 Phase 1 回归；
7. 将运行证据写入被 Git 忽略的 `.codex-work/backup-restore-drill/<timestamp>/`。

2026-08-19 的最近一次完整演练结果为：

| 项目 | 结果 |
|---|---|
| 备份包 | `class-archive-20260819T061833Z` |
| 确定性恢复指纹 | `56058dc80fc7cfb987ee45832acaf7280a846aa267f866584fdc9c26a5473a62` |
| 基线 | `72/72/8` 恢复前后一致 |
| Phase 0 | PASS |
| Phase 1 | PASS |
| 从删除卷开始的粗略 RTO | 36 秒 |

RTO 仅是本机一次合成演练的观测值，不是生产承诺。

## 恢复后的安全检查

恢复演练不以页面返回 200 即结束。它还检查：

- Piwigo 与 MariaDB healthcheck；
- 安全启动脚本 SHA-256 与模式 `0755`；
- 原图/私有媒体模式符合 `0660` 策略；
- MediaGuard 的 HTTP 矩阵、低分辨率安全预览和状态转换；
- ClassIdentity、投稿、匿名呈现、管理控制台与维护门禁的真实 HTTP 回归。

失败时演练证据仍会保留在忽略目录中，便于定位；不会把失败写成成功状态。

## 运行限制

- 此操作具有破坏性，只能在合成环境中加 `-ConfirmSyntheticRestore` 执行。
- 不得用于真实照片、NAS 卷或公网实例。
- NAS 恢复、异机恢复、加密备份、保留周期和灾难演练仍是生产放行前的独立门禁。
