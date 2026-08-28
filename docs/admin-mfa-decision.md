# 管理员多因素认证决策

状态：`ADMIN_MFA_IMPLEMENTED=NO`，因此继续是生产放行阻断项。

## 已核查的成熟候选

Piwigo 16 官方提供 **Two Factor Authentication (2FA)** 插件。官方文档说明它支持认证器应用的 TOTP 和邮件验证码、恢复码、失败尝试锁定，以及配合 API key 的第三方应用接入。扩展目录当前列出的 16.c 修订由 Piwigo Team 发布，标注兼容 Piwigo 16，并带简体中文语言包。

这比旧的邮件验证码插件可靠：旧插件在扩展目录中仍标注为 beta 且不兼容最新 Piwigo，不能作为本项目的生产方案。

## 为什么本轮不直接启用

ClassIdentity 有独立的 SYSTEM_ADMIN Principal 和严格的身份/资料页防绕过保护。官方 2FA 插件要求使用 Piwigo 的标准登录与 Profile 页面完成启用；而本项目禁止原生 Profile 管理页直接改动受管账号，以免绕开 Principal、Audit、会话撤销与业务权限约束。

直接安装并激活 2FA，会留下至少三个未经验证的风险：

1. SYSTEM_ADMIN 是否能完成 TOTP enrollment 而不开放被限制的原生 Profile 业务修改；
2. Piwigo 2FA 登录完成后的 Session 与 ClassIdentity `auth_epoch`、冻结和强制登出是否仍保持 fail closed；
3. API key 与现有 ClassIdentity 对 API key 的限制是否会形成绕过面。

这些问题不应通过自行实现 OTP、修改 Piwigo Core 或临时放宽 ClassIdentity 守卫来“解决”。

## 后续正确集成点

应在独立、本机合成数据 Spike 中执行以下步骤：

1. 固定官方 2FA 插件 revision 与 SHA-256，并通过现有受控插件发布流程安装；
2. 仅为一个独立的 synthetic SYSTEM_ADMIN 启用 **TOTP**，不采用邮件作为主因素；
3. 保留 Piwigo 标准登录/2FA challenge，但用 ClassIdentity 适配器只开放必需的 enrollment/change 页面和字段；
4. 测试 password → TOTP → SYSTEM_ADMIN Console，错误 TOTP、冻结、auth epoch 变更、session revoke、API key、recovery code 和插件失活；
5. 测试所有普通 Seat 账号和匿名账号仍不能获得 SYSTEM_ADMIN 或管理后台权限；
6. 通过后才把 SYSTEM_ADMIN 的 MFA 要求升级为生产 gate，并将恢复码交由离线管理员保管。

在上述隔离 Spike 和真实 HTTP 回归完成前：

```text
ADMIN_MFA=BLOCKED
PRODUCTION_READY=NO
```

## 参考

- Piwigo 官方文档：Two Factor Authentication。
- Piwigo 扩展目录：Two Factor Authentication (2FA)，Piwigo 16.c。
- Piwigo 16 release note：标准页面、API key 与新的 2FA 插件。
