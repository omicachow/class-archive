# Phase 1.5 生产基础门禁

范围：只覆盖 `127.0.0.1:8090` 的 Piwigo 16.4.0 + MariaDB 合成数据环境。没有真实照片、NAS 或公网配置。

## 当前机器可验证状态

| Gate | 状态 | 证据 |
|---|---|---|
| `MEDIA_ATTESTATION` | PASS | digest 绑定的 Phase 0 MediaGuard 回归；344 个 HTTP 探针，记录包含 Git commit、Policy/Nginx/MediaGuard/schema/test digest、迁移版本和时间戳 |
| `BACKUP_RESTORE` | PASS | 完整 backup → 删除合成持久卷 → restore → Phase 0/1 回归；恢复指纹与 `72/72/8` 基线一致 |
| `CRON_MAINTENANCE` | PASS（本机 runner） | 单实例、幂等、结构化维护记录；最近一次 `-RequireReady` 为 PASS |
| `RECONCILIATION` | PASS | 72 张图片扫描，`issue_count=0`；结构性问题只分类，不危险自动修复 |
| `AUTOMATED_BROWSER_QA` | PASS | 本机 Chrome + Playwright 完成真实 Claim、Family 投稿/审核、匿名解析和桌面/移动端截图；234 项断言、11 张仅合成数据截图 |
| `ADMIN_MFA` | BLOCKED | 官方 Piwigo 16 TOTP/邮件 2FA 是候选，但尚未通过 ClassIdentity 会话、Profile 守卫和 API key 的隔离集成验证；详见 `docs/admin-mfa-decision.md` |

因此当前结论是：

```text
AUTOMATED_BROWSER_QA=PASS
MEDIA_ATTESTATION=PASS
BACKUP_RESTORE=PASS
CRON_MAINTENANCE=PASS
RECONCILIATION=PASS
ADMIN_MFA=BLOCKED
CORE_ALPHA_READY=YES
PRODUCTION_READY=NO
```

媒体 attestation 是生产放行证据，不是鉴权输入：即使它缺失或过期，MediaGuard 也仍以服务器端 Principal/Era 权限 fail closed；但 System Health 会标记为“需要重新验证”，生产仍被阻断。

## 当前媒体安全证明摘要

最近记录使用 `MEDIA_ATTESTATION_VERSION=1`，并包含：

```text
COMMIT=3d51e127486ca5fcfdf8abf33b57c6fa2223695d
PROBES=344
RESULT=PASS
TEST_SUITE_VERSION=phase0-media-guard-v1
```

Policy、Nginx、MediaGuard、schema/migration 或测试源码任一摘要变化后，该记录自动失效，后台显示“相关安全代码或配置已发生变化”。

## 严格顺序

浏览器验收已完成。下一阶段可以先保留本分支的 Phase 1.5 稳定提交，再建立独立的 `codex/immich-photo-frontend-spike` 分支，对只读合成媒体进行前端可行性验证。

## 2026-08-19 最近运行证据

- 浏览器：`AUTOMATED_BROWSER_QA=PASS assertions=234 screenshots=11`，详见 [`browser-qa-report.md`](browser-qa-report.md)；
- 媒体证明：344 个真实 HTTP 探针通过，并写入包含当前安全源码摘要的持久记录；
- 恢复演练：备份包 `class-archive-20260819T092426Z`，恢复指纹 `56058dc80fc7cfb987ee45832acaf7280a846aa267f866584fdc9c26a5473a62`，实际删除本项目合成卷后恢复，RTO 为 40 秒；恢复后的 Phase 0 与 Phase 1 均通过；
- 维护：`result=PASS`，`checked_images=72`，`issue_count=0`，媒体证明状态为 `VERIFIED`，备份状态为 `FRESH`。

上述值只是 localhost 合成环境的可验证事实。管理员 MFA、NAS/异机恢复、HTTPS/反向代理生产部署和真实数据治理仍未完成，因此 `PRODUCTION_READY` 必须保持 `NO`。
