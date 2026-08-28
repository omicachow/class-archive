# 本地 AI 模型制品政策

## 结论先行

Class Archive 不把任何模型二进制提交到 Git、Docker image、GitHub Release 或业务备份。
本项目保存的是可审计的固定 manifest、来源、摘要、许可证记录与导入工具。每一台运行
Immich ML 的机器都必须先取得符合其用途许可的制品，校验通过后才可导入本地模型缓存。

本政策仅覆盖当前 `Immich v3.1.0` / commit
`8aa95c67470a02a8ddedf03c2e52963af33065ff` 的 CPU 最小模型闭包，精确文件见
[`infra/immich-spike/ml-artifacts/manifest.json`](../infra/immich-spike/ml-artifacts/manifest.json)。

## 运行所需闭包

| 能力 | 固定上游模型 | CPU 必需文件 | 缓存目录 |
|---|---|---|---|
| 人脸检测 | `immich-app/buffalo_l` | `detection/model.onnx` | `/cache/facial-recognition/buffalo_l/` |
| 人脸嵌入 | `immich-app/buffalo_l` | `recognition/model.onnx` | `/cache/facial-recognition/buffalo_l/` |
| 照片搜索文本 | `immich-app/ViT-B-32__openai` | `config.json`、`textual/model.onnx`、`textual/tokenizer.json`、`textual/tokenizer_config.json` | `/cache/clip/ViT-B-32__openai/` |
| 照片搜索视觉 | `immich-app/ViT-B-32__openai` | `config.json`、`visual/model.onnx`、`visual/preprocess_cfg.json` | `/cache/clip/ViT-B-32__openai/` |

这是从固定 Immich source 的实际加载路径导出的闭包，不是对整个 Hugging Face 仓库的
复制。默认 `ViT-B-32__openai` 是英文导向的模型；中文检索必须单独测试其质量，不能
由 UI 文案推断为多语言支持。

当前闭包含 8 个文件、共 `800758009` bytes，manifest SHA-256 为
`46380b30910608a8f0226d6ed14e3535cdd3f43c6080115e19842a8eaeda7e7a`。固定 revision：

- `buffalo_l`：`d09715916a0778919a770c343533641e250b8699`；
- `ViT-B-32__openai`：`a857c8de2c07bbcfa6646adfcf31b798845afa1e`。

## 许可证审计

| 制品族 | 上游声明 | 本项目可做什么 | 重新分发 | 本项目策略 |
|---|---|---|---|---|
| InsightFace `buffalo_l` | InsightFace 明确说明其预训练模型仅供非商业研究；Immich 也说明其取得的使用许可不延伸给第三方重新分发或商业使用 | 仅当前 synthetic、localhost、非商业研究 spike | 禁止 | **不打包、不发布、不随备份传输**；真实使用前需取得适用的单独授权并重新审计 |
| OpenCLIP `ViT-B-32__openai` ONNX export | OpenCLIP 源码为 MIT，但 Immich 的模型制品仓库没有为该权重导出给出明确的 model-weight 重新分发条款 | 受控本地评估可使用；每次来源/版本必须锁定 | 未知，不假定允许 | **不打包**；由 operator 受控获取、校验后导入，正式发布前完成权重来源与再分发审查 |

这不是法律意见。`ML_LICENSE_AUDIT=PASS` 仅表示审计记录、限制和工具门禁已落实；它
不把受限制的人脸模型变成可用于生产或可重新分发的资产。

```text
ML_LICENSE_AUDIT=PASS
ML_LICENSE_STATUS=REVIEWED_RESTRICTED
ML_MODEL_REDISTRIBUTION=BLOCKED
```

许可证限制仍是生产门禁，但不否定 localhost、synthetic-only、非商业研究范围内已经
取得的技术运行证据。

## 获取与导入规则

1. 唯一允许的模型来源是 manifest 指向的 Immich 官方 Hugging Face 组织、固定 revision。
2. 获取过程可临时使用已经存在的网络代理作为运输通道，但最终 URL 必须是官方
   `huggingface.co` / Hugging Face 自有的下载域；不得改用镜像站、网盘或重打包文件。
3. 下载完成后必须先执行 `verify-immich-ml-artifacts`。缺失、额外文件、大小或
   SHA-256 不符均为拒绝。
4. 只有 `VERIFY=PASS` 才允许执行 import。运行时 ML 容器位于 Docker internal 网络并
   设置离线环境变量，cache miss 必须失败而不是联网补齐。
5. 业务备份只备份 Piwigo、MariaDB、ClassIdentity、Archive、Audit 和映射；模型制品
   独立按 manifest 重建。模型 cache、Immich embedding、face index 与 vector index 都是
   可再生 spike 状态，不进入 Class Archive 的业务备份承诺。

## 升级流程

```text
固定 Immich 新版本 / commit
        ↓
分析 source 的真实加载模型与路径
        ↓
创建新的 manifest（绝不覆盖旧证据）
        ↓
来源与许可证审计
        ↓
受控下载 + SHA-256 校验
        ↓
离线 cold start
        ↓
synthetic People/Search + ACL 回归
```

任何步骤失败都会让模型状态保持“未就绪”，不会降级为在线下载，也不会扩大 Family 等
角色的可见范围。
