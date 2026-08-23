# Immich ML 离线模型门禁

本项目的 Immich Machine Learning 容器位于仅内部 Docker 网络。它不能、也不应在
运行时自行从互联网下载模型。这样可以避免本机照片索引、浏览器请求或测试过程变成一个
未审计的外网依赖。

当前正式制品说明、精确 revision、SHA-256 与许可证结论在
[`ml-artifact-policy.md`](ml-artifact-policy.md) 和
[`manifest.json`](../infra/immich-spike/ml-artifacts/manifest.json)。模型二进制仍只允许
存在于 ignored 的本地 staging / Docker cache；Git、业务备份、Docker image 和 Release
均不得携带它们。

## 当前受控流程与验证结果

1. 在**不接触 Class Archive 原图**的受控获取环境中，从 manifest 固定的官方来源获取
   文件；不得把容器缓存或第三方文件当作来源。
2. 运行 `verify-immich-ml-artifacts.ps1`。它拒绝缺失、额外、路径、大小、revision、
   SHA-256 或许可证元数据不符的制品。
3. 仅在 `VERIFY=PASS` 后，使用 `prepare-immich-ml-artifacts.ps1 -Import -ReplaceCache`
   导入固定 named volume。导入器无网络、无 Piwigo 原图、无数据库、无浏览器挂载。
4. ML 容器始终位于 Docker `internal: true` 网络，显式设置 Hugging Face 离线变量，并在
   restart 后预加载当前模型。cache miss 必须失败，而不是联网补齐。
5. 运行静态与容器内制品检查：

   ```powershell
   .\infra\scripts\dev.ps1 phase2-ml-readiness
   ```

   它会检查 ML 容器健康、internal-only network、离线变量、manifest 结构、缓存只读模式和
   container 内实际 SHA-256。随后依次运行：

   ```powershell
   .\tests\phase2\immich-ml-offline-cold-start.ps1
   .\tests\phase2\immich-people-search-runtime.ps1
   ```

   只有**全新 ML 进程 + external network 隔离 + cache-only load** 的 cold start 才算离线
   运行证据。
6. 在新的、synthetic-only fixture 上重跑 face/person/search runtime、Family ACL、MediaGuard
   和浏览器 E2E。模型 cache 仍是可丢弃 spike state，不得包含 Piwigo 原图、账号凭据或
   ClassIdentity 数据。最后在 clean working tree 上运行：

   ```powershell
   .\infra\scripts\attest-immich-ml.ps1
   ```

   runner 会绑定当前 Git commit、manifest、compose、完整 ClassIdentity PHP source、BFF、
   nginx、fixture 与测试摘要；任一变化都会让系统状态显示“需要重新验证”。

当前已验证结果：

```text
ML_ARTIFACT_VERIFY=PASS artifacts=8 bytes=800758009
ML_OFFLINE_COLD_START=PASS evidence=RUNTIME_TESTED assertions=10
IMMICH_PEOPLE_SEARCH_RUNTIME=PASS evidence=RUNTIME_TESTED assets=32
IMMICH_LIBRARY_ASSETS=104  # canonical 72 + temporary synthetic 32
IMMICH_DETECTED_FACES=12
IMMICH_FACE_EMBEDDINGS=12
IMMICH_PERSON_CLUSTERS=3
IMMICH_SMART_EMBEDDINGS=104
```

32 张运行 fixture 含 18 张班级历史、14 张毕业后动态；结束后精确清理回 72 张 canonical
original，Immich disposable index 归零，仅保留已验证模型 cache。

## 不允许的替代方案

- 让 ML 容器直接访问模型站点；
- 以“容器 healthy”替代模型、索引或聚类成功；
- 在 Browser/BFF 里调用第三方云人脸或图像 API；
- 将 Immich person id 当作 Classmate Identity；
- 因模型缺失而返回未过滤的 Immich Search/People 结果。

模型/cache/Immich runtime 真正不可用时，Gateway/BFF 必须返回安全空结果或明确 503，不能
回退到未过滤的 Immich 全库。Attestation 缺失、过期或 digest 变化只会让系统状态标记
“需要重新验证”并保持 `PRODUCTION_READY=NO`；它不是每次请求的授权输入。MediaGuard
始终独立 fail closed，媒体仍只经 ClassArchivePolicy、MediaGuard 和 nginx
X-Accel-Redirect 交付。
