# Photos App V4：Chrome 上传成功生命周期（合成环境）

本说明对应 `tests/phase3/photos-app-v4-chrome-upload-lifecycle.ps1`。它是与只读 Chrome 验收分开的、会写入数据的本地合成测试；只允许已有的 `8090/8091` synthetic 服务和 ignored 的 synthetic 凭据文件参与。它不会启动或停止容器，不会创建账号，不会访问 `8191`，也不会读取真实照片或真实凭据。

测试以全新的 ignored Chrome user-data 目录启动**已安装的 Google Chrome Stable**（`channel: 'chrome'`、`headless: false`）。因此它需要交互式桌面；不使用用户日常 Chrome profile、Cookie、扩展、Service Worker 或缓存。每次运行在 ignored 工作目录生成六张互不相同的 1×1 微型 PNG：五张是成功路径素材，一张只用于 Family LIVING 篡改拒绝。runner 自身强制这些文件位于 `.codex-work/runtime/phase3-upload-lifecycle/<random-run>/fixtures/`，并核验 PNG 签名、尺寸和本轮 marker；不能直接把任意本地照片作为测试输入。

成功路径通过真实 `<input type=file>` 的 file chooser 选择文件，而不是设置 DOM value：

- Classmate：班级历史、毕业后动态各成功发布一张；
- Teacher：班级历史、毕业后动态各成功发布一张；
- Family：先将隐藏 `era` 篡改为 `LIVING`，带真实合成文件提交并验证服务器拒绝且没有 Pending 写入；随后重新打开干净表单，只提交班级历史，进入 Pending。

每一个 Classmate / Teacher 成功响应都必须提供 `PUBLISHED`、不透明 `photoId` UUID、已选 album UUID 和正确 Era。测试将 response UUID 与本地计算的 fixture SHA-256 写入 ignored journal，绝不打印、按文件名、路径模式或随机名称进行清理。若浏览器在服务端写入后、读取 response UUID 前中断，journal 的预写入意图只允许 helper 用该已预检的唯一 checksum 解析一个 Active UUID，再按 UUID + checksum 双重核验并清理；查无对象时安全返回，歧义或依赖异常则拒绝删除。Family Pending 仅由同一 SHA-256 找到唯一的 `submission_id + class_photo_id` 后清理。

`photos-app-v4-upload-lifecycle-fixture.php` 只能在 Piwigo 容器中以非 root CLI 且设置显式测试 gate 时运行。它在删除前验证 UUID、SHA-256、状态、Piwigo mapping、全表唯一的 managed media path、原始媒体 SHA、AI 外部资产未绑定，以及不会删除人工评论、人物整理、来源记录、相册封面或其他业务依赖。Pending 的 original/thumbnail 还必须互不相同且未被其他投稿记录引用，才会 unlink。可重建的 synthetic 投影、AI job 和 derivative warmup marker 仅按该 UUID 清理；随后 wrapper 全量重建 synthetic read projection。

无论浏览器成功或失败，PowerShell `finally` 都会尝试对 journal 中已确认的精确目标清理，然后重新验证：

```text
images=72
active canonical originals=72
physical originals=72
multi-album images=8
```

如果发生任何无法证明安全性的清理条件（例如外部 AI asset 已绑定、非测试业务依赖、UUID/SHA 不一致或基线漂移），测试必须失败并保留明确的人工核对信号；它不会尝试猜测或广泛删除数据。该 runner 尚未运行时，源代码和静态协议只构成 `STATIC` 证据，不构成 Chrome 或 HTTP 运行时通过。
