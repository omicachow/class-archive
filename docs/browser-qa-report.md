# 浏览器端到端验收报告

状态：已通过。本报告只覆盖本机 `127.0.0.1:8090` 上的合成数据；不代表真实照片、NAS 或公网环境的验收。

## 运行方式

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\phase1\browser-qa.ps1
```

测试使用本机 Google Chrome 与 Playwright 驱动真实浏览器，不以 HTTP 探针替代页面操作。每轮会创建独立的随机测试命名空间和临时高强度凭据；凭据只存在于被忽略的短生命周期文件中，并在 `finally` 中删除。测试结束后精确清理测试管理员、成员、席位、投稿、图片、评论和临时评论配置，随后核对标准合成基线。

最近一次运行：2026-08-19，结果为 `AUTOMATED_BROWSER_QA=PASS`，234 项断言，11 张截图，清理已验证。恢复后的图片基线为 `72 images / 72 physical originals / 8 multi-album images`。

## 已覆盖的真实用户故事

- SYSTEM_ADMIN 从登录页进入仪表盘、班级成员、教师、邀请与认领、投稿审核、匿名管理、班级档案、操作审计和系统状态；
- Classmate 通过一次性 Claim 正式认领账号，并在真实页面中创建家庭邀请、激活匿名席；
- Family 通过正式邀请注册，只能浏览班级历史，不能浏览毕业后动态；上传合成图片后进入待审核状态，已知 Pending 缩略图、原图、HEAD 与 Range 请求均被拒绝；管理员通过后 Family 才能浏览已收录的班级历史图片；另一路投稿被实际拒绝；
- Teacher 通过 Teacher Claim 注册，可浏览两个时期，但不获得 Family/匿名席位操作；
- Anonymous 在两个不同照片 context 发表评论，同一 context 匿名名稳定、不同 context 不复用；普通 HTML 与浏览器 API 不含真实映射；SYSTEM_ADMIN 必须明确点击“查看真实身份”才获得映射，并在操作审计中留下记录；
- 管理员中文导航、业务状态文案、关键表单及直接访问保护页的错误行为；
- 1440px 桌面、390×844 移动端和约 125% 设备比例下的横向溢出检查。

浏览器在刻意访问无权限管理或媒体 URL 后可能收到注销式拒绝；测试会重新走真实登录流程，而不会通过注入会话或放宽服务器端授权继续。

## 截图

截图只包含合成身份和合成图片，保存在 Git 忽略目录：

`C:\Users\Omica\Documents\ChatGPT\班级相册开发\.codex-work\screenshots\phase1.5\`

| 文件 | 内容 |
|---|---|
| `01-login.png` | 登录页 |
| `02-admin-dashboard.png` | 管理员仪表盘 |
| `03-admin-members.png` | 班级成员 |
| `04-family-submit.png` | Family 投稿状态 |
| `05-admin-submission-review.png` | 投稿审核 |
| `06-family-approved-photo.png` | Family 已通过图片 |
| `07-anonymous-public-view.png` | 普通匿名视图 |
| `08-anonymous-admin-resolve.png` | 管理员明确解析匿名身份 |
| `09-archive-management.png` | 班级档案 |
| `10-system-health.png` | 系统状态 |
| `11-mobile-admin-dashboard.png` | 移动端管理员仪表盘 |

截图本体不进入 Git；本报告只记录可复跑的命令、边界和结果。

## 已知边界

- 此测试检查实际页面加载、表单提交、点击、中文文案与无横向溢出，不替代人工无障碍审阅或跨浏览器兼容性矩阵；
- 它不导入真实照片，不连接 NAS，不开放任何公网端口；
- 系统状态中的生产放行仍受媒体证明、备份恢复、维护、数据一致性、MFA、NAS/HTTPS 等独立门禁约束。
