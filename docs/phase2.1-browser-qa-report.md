# Phase 2.1 浏览器验收记录

证据等级：`BROWSER_E2E_TESTED`。本记录只覆盖 `127.0.0.1:8090` 与
`127.0.0.1:8091`，只使用仓库的合成照片和固定 synthetic 席位。

## 本次真实 Chromium 操作

| 角色 | 已验证行为 | 结果 |
| --- | --- | --- |
| CLASSMATE | 登录、兼容照片页、档案时间轴、班级历史和毕业后动态可见 | PASS |
| FAMILY | 登录、36 张班级历史、档案时间轴、相册、受保护预览、照片查看器 | PASS |
| FAMILY | 已知毕业后动态 UUID 的 thumbnail、HEAD、Range、查看器页面 | DENY / 404 |
| TEACHER | 登录、班级历史和毕业后动态可见；没有家庭/匿名席位 UI | PASS |
| ANONYMOUS | 登录、浏览范围跟随 Classmate；兼容 BFF HTML/DTO 不含底层 fixture、Identity、Account、Seat 或 Piwigo/Immich mapping 字段 | PASS |
| SYSTEM_ADMIN | 真实管理台冻结合成同学身份 | PASS |
| CLASSMATE + FAMILY | 冻结后的旧浏览器会话再次请求档案时间轴，均被送回登录页 | PASS |
| SYSTEM_ADMIN | 解除冻结后重新登录 Family | PASS |

People 与 Smart Search 在此轮不是“空结果即通过”：ML 离线模型制品尚未提供，BFF 的
People 投影保持空、Smart Search 返回 fail-closed 503。该行为在 Family 浏览器中也已
验证；不能据此声称 Face/CLIP runtime 已通过。

## 媒体路径

家庭查看器请求的是：

```text
127.0.0.1:8091
  -> /api/assets/{ClassArchivePhoto UUID}/thumbnail?size=preview
  -> canonical Gateway
  -> ClassArchivePolicy + MediaGuard
  -> nginx X-Accel-Redirect
```

浏览器请求中没有 `:2283` Immich Server、Immich asset/original URL、Piwigo image id
或存储路径。BFF 不传递原图字节。

## 时间轴和中文显示

- 档案时间轴显示“日期未知”而不是 upload/import 日期；
- 预览页显示“档案时间、日期精度、日期来源”，没有伪造年月日；
- Family 390×844 和 Classmate 约 125% device-scale 检查都无横向溢出；
- People 在界面中使用“人物”，不使用“人们”。

当前 canonical 72 张基线的 archive metadata 都是 `UNKNOWN`，因此本次截图主要显示
“日期未知”。月、年、活动、经人工核验的 EXIF 年份和未知日期 bucket 的排序/精度规则还通过了独立的真实
localhost BFF projection fixture：它仅暂时改动五条既有 synthetic HERITAGE metadata
记录，并在 finally 中逐条还原。该门输出 `ARCHIVE_TIMELINE_RUNTIME=PASS`、
`DATE_PRECISION=PASS`、`EVENT_TIMELINE=PASS`、`EXIF_TIMELINE_SOURCE=PASS`，不改变任何 original 或相册关系。

## 截图（ignored，不提交）

所有截图只包含合成资料，位于 `.codex-work/screenshots/phase2-1/`：

- `01-classmate-desktop.png`
- `02-classmate-archive-timeline.png`
- `03-family-archive-timeline.png`
- `04-teacher-archive-timeline.png`
- `05-anonymous-archive-timeline.png`
- `06-admin-freeze-revoke.png`
- `07-family-mobile-archive-timeline.png`
- `08-classmate-125pct-archive-timeline.png`
- `09-family-viewer.png`

## 临时凭据与清理

本次使用的新随机 fixture 密码仅在 owner-only ignored 临时文件与 Chromium/测试进程
内存中短暂存在。验收后已：撤销 SYSTEM_ADMIN test session、解除测试冻结、轮换四个
fixture 密码、删除全部临时 secret 文件。没有将密码、cookie、Claim 或 Invite token
写入 Git、`.env`、Audit 或本文件。
