# 班级相册本地体验入口

Class Archive 保留两个语义完全不同的 localhost 环境：

```text
PUBLIC_SAFE_SYNTHETIC_UI=http://127.0.0.1:8091/photos
PRIVATE_REAL_QA_UI=http://127.0.0.1:8191/photos
```

- `8091` 只含可公开复现的合成数据，用于自动化回归和 Public CI。
- `8191` 是隔离的本机私有 QA 环境。其数据库、媒体、人物索引、截图和临时凭据均与合成基线分开，且不得进入 Git、CI 或公网。
- 两个入口都只绑定 `127.0.0.1`。Immich Server、机器学习服务和内部 Gateway 没有浏览器可访问端口；媒体仍由 ClassArchivePolicy、MediaGuard 与 nginx 内部传输链逐次授权。

在仓库目录运行：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\infra\scripts\start-private-qa.ps1 status
```

需要启动已经配置好的私有 QA 服务时使用 `start`。需要一次性测试凭据时使用 `credentials`；脚本只返回受所有者 ACL 保护且被 Git 忽略的本地文件路径，不在终端、`.env` 或文档中打印密码。测试结束后继续使用现有 fixture rotate 流程撤销该批凭据。

真实照片源不属于任何运行卷。私有实例只读取先前复制到 ignored staging 的样本，绝不直接挂载或写入原始目录。
