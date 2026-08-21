# Immich ML 离线模型门禁

本项目的 Immich Machine Learning 容器位于仅内部 Docker 网络。它不能、也不应在
运行时自行从互联网下载模型。这样可以避免本机照片索引、浏览器请求或测试过程变成一个
未审计的外网依赖。

当前状态：`IMMICH_ML_MODEL_ARTIFACTS=BLOCKED_OFFLINE_MODEL_ARTIFACTS`。这不是
容器故障；当前 model cache 为空，且没有经过校验的离线模型清单。因此 Face Detection、
Face Embedding、Facial Recognition、人物聚类和 Smart Search 都不得声称运行时通过。

## 以后允许放行的唯一流程

1. 在**不接触 Class Archive 原图**的受控获取环境中确定 Immich v3.1.0 所需的准确模型
   文件、许可证、上游来源和 SHA-256。
2. 将这些文件组成不可变的离线制品；扫描其内容，并生成 `version: 1` 的
   `class-archive-model-manifest.json`。每个文件必须有 `/cache/...` 路径、SHA-256 和
   `source_lock`。
3. 通过一次性离线导入把制品放入 disposable `immich_model_cache` volume。不得为此向
   `immich_internal` 开放 egress，也不得让 ML 容器在运行时下载。
4. 运行：

   ```powershell
   .\infra\scripts\dev.ps1 phase2-ml-readiness
   ```

   它会检查 ML 容器健康、internal-only network、manifest 结构和 container 内实际
   SHA-256。只有需要将其用于 People/Search 验收时才使用 `-RequireReady` 的等价调用。
5. 在新的、synthetic-only fixture 上重跑 face/person/search runtime、Family ACL、MediaGuard
   和浏览器 E2E。模型 cache 仍是可丢弃 spike state，不得包含 Piwigo 原图、账号凭据或
   ClassIdentity 数据。

## 不允许的替代方案

- 让 ML 容器直接访问模型站点；
- 以“容器 healthy”替代模型、索引或聚类成功；
- 在 Browser/BFF 里调用第三方云人脸或图像 API；
- 将 Immich person id 当作 Classmate Identity；
- 因模型缺失而返回未过滤的 Immich Search/People 结果。

在没有离线制品之前，Gateway/BFF 保持 People 空投影或 Smart Search 不可用，并继续
fail closed。媒体仍只经 ClassArchivePolicy、MediaGuard 和 nginx X-Accel-Redirect 交付。
