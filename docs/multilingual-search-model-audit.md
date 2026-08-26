# 多语言照片搜索模型审计

日期：2026-08-26
状态：**设计审计，不下载、不导入、不替换当前模型**。

## 当前结论

Class Archive 当前锁定的 Immich `v3.1.0` / commit
`8aa95c67470a02a8ddedf03c2e52963af33065ff` 使用 `ViT-B-32__openai` 的固定 ONNX 闭包。现有本地 runtime 证据为：英文 smart search `FAIR`、中文 `POOR`。这不能由中文 UI 文案掩盖。

本轮不应替换模型。先交付结构化中文搜索（人物、相册、活动、年份/学期、档案日期、标签/说明/OCR），语义结果只标为“智能匹配 Beta”。任何候选模型都必须先通过供应链、许可、容量、离线 cold start、全量重索引、ACL 和真实本地语料质量门禁，才允许进入实现 spike。

当前模型闭包、哈希与受限再分发结论以
[ml-artifact-policy](ml-artifact-policy.md) 与
[artifact manifest](../infra/immich-spike/ml-artifacts/manifest.json) 为准：模型二进制不进入 Git、Docker image、Release 或业务 backup。

## 评价口径

| 维度 | 本审计如何判断 |
|---|---|
| 中文效果 | 不是模型名称或 UI 语言；必须在同一 private-local、synthetic benchmark 上测 Precision@5、Recall@K、Top-K hit rate，并与英文对照。 |
| 架构兼容 | 能否由**锁定 Immich v3.1.0** 的 ML cache/layout 直接加载；不能把当前官方文档中较新版本的能力倒灌到 v3.1.0。 |
| 许可/再分发 | 分别审计模型权重、tokenizer、转换产物和上游 image encoder；“代码是 MIT/Apache”不自动覆盖权重。 |
| 体积 | 下面的 GB/MB 是官方仓库报道的磁盘制品下界，不是实际内存基准。运行内存至少还要包含模型、ONNX/PyTorch runtime、tokenizer、batch 与向量工作集。 |
| CPU/RAM | 本项目尚未对候选模型跑 CPU/RAM benchmark，故只记录相对风险；任何数字化结论必须由同机 cold/warm 运行测得。 |
| 重索引 | 任何改变 visual encoder、embedding dimension 或 embedding semantic space 的方案，必须全量重新生成 search embedding，并在切换前保留可回退的旧 index。 |

## 候选对比

| 候选 | 官方说明与中文相关性 | 制品/许可观察 | CPU/RAM 与重索引影响 | 与 v3.1.0 的适配状态 | 结论 |
|---|---|---|---|---|---|
| 当前 `immich-app/ViT-B-32__openai` | 当前本地实测中文 `POOR`、英文 `FAIR`；英文导向。 | 当前 8-file ONNX 闭包约 800.8 MB；其权重再分发状态已经是 `UNKNOWN` / 不打包。 | 已跑通本地 runtime；无需重建。 | 已支持。 | **保留为当前 Beta 语义补充，不冒充中文搜索。** |
| `sentence-transformers/clip-ViT-B-32-multilingual-v1` | 官方 model card 说明它把 50+ 语言文本映射到原始 CLIP ViT-B/32 的共同向量空间，适合多语言 image search；中文是应实测的语言之一，而不是已由本项目验收的结论。 | model card 标为 Apache-2.0；当前 safetensors 文档大小 539 MB，且其外部 image encoder/转换产物仍需分别审计。 | 仅替换 text side **可能**不需要重做 visual embedding，但前提是向量维度/归一化/跨实现兼容性在固定 corpus 上证明成立；否则必须全量重索引。PyTorch/SentenceTransformers runtime 会与当前 ONNX 闭包不同。 | v3.1.0 没有已验证的直接 cache/ONNX adapter。 | **ADAPT 候选，暂不实施。** 最小后续 spike 应先证明 text-to-existing-image-vector compatibility，再做许可/离线/ACL gate。 |
| `M-CLIP/XLM-Roberta-Large-Vit-B-32`（XLM-R） | 官方 card 称仅提供多语言 text encoder，覆盖 48 languages，并需要另配 ViT-B/32 image encoder；其自报的翻译 COCO benchmark 包含中文，但这不是班级照片 benchmark。 | 官方仓库列出单个 PyTorch/safetensors 权重约 2.24 GB，仓库约 4.5 GB（有额外 variants 时可更大）；页面没有可接受的明确权重 license metadata。 | text encoder 很大，CPU 首次加载、RAM 与缓存压力显著；只有在严格兼容已存 image vector 时才可能避免全量 visual reindex，不能预先假定。 | 不在 v3.1.0 的已验证模型闭包内；需自建转换/adapter。 | **REJECT（当前）**：license 证据不足、容量过高、兼容性未证实。仅可在单独 research spike 中重新审计。 |
| `facebook/nllb-200-distilled-600M`（NLLB） | 官方 card 是 196-language translation model，不是 image-text embedding model。它只能作为“中文 query → 英文 query”的可选本地 normalization/translation 实验，不能直接替换 CLIP。 | 官方仓库约 2.48 GB，`pytorch_model.bin` 约 2.46 GB；许可证为 CC-BY-NC-4.0。其 card 明示主要面向研究、非 production deployment。 | 作为 query-only translation 不必重建现有 visual embedding，但每次查询会增加本地 translation latency/RAM，且翻译错误可能损害检索。 | 不是 Immich v3.1.0 Smart Search encoder，必须在 Gateway 前增加独立的受控服务。 | **REJECT（产品路径）**：非商业/研究用途限制与额外隐私/运维面不符合后续生产方向。可保留为本机研究对照，但不自动启用。 |
| `google/siglip2-base-patch16-224` | 官方 card 说明可做 image-text retrieval 与 zero-shot image classification；它没有在本审计中提供可以取代中文 benchmark 的项目级证据。不能因 SigLIP2 名称而假设中文质量。 | Google 官方仓库标 Apache-2.0；`model.safetensors` 约 1.5 GB。 | 作为新的双塔 image-text model，需整库全量生成 visual embeddings；CPU/RAM、GPU 与 cache 必须实测。 | 当前锁定 v3.1.0 的 model layout 不支持该 Hugging Face PyTorch bundle；近期 Immich 版本对 SigLIP2 的讨论不能反推 v3.1.0 已支持。 | **ADAPT 候选，暂不实施。** 许可证相对清晰，但必须固定 revision、转换闭包、离线验证与全库重索引预算。 |

## 已审计的事实与不应作出的推断

1. M-CLIP 的官方 model card 说明它是多语言文本编码器并与独立 ViT-B/32 image encoder 配对；这支持“有潜在中文检索能力”，**不支持**“可直接替换当前 Immich ONNX 模型”。
2. `clip-ViT-B-32-multilingual-v1` 的 model card 声称与原始 CLIP ViT-B/32 共享向量空间；这支持建立兼容性实验，**不支持**跳过 vector-dimension、normalization、rank quality 和 ACL 回归。
3. NLLB 是翻译模型且标 CC-BY-NC-4.0；它可以启发 query normalization，**不支持**在本项目生产方向中作为默认依赖或把其许可当作可商用。
4. SigLIP2 的官方公开模型卡标 Apache-2.0 且声明 image-text retrieval 用途；这只解决部分 weight-license 证据，**不支持**省略该模型、tokenizer、转换产物、性能和中文现实语料审计。
5. 当前官方 Immich 文档说明更换 CLIP model 后应对全部图片重新执行 Smart Search job；本项目必须更严格：先生成新 index、验证 Gateway ACL 和 rollback，再切换 query pointer。

## 后续候选 Spike 的硬性门禁

```text
fixed official model revision
        ↓
artifact manifest + SHA-256 + license audit
        ↓
offline cache import + cold start
        ↓
synthetic Chinese/English relevance fixture
        ↓
private-local evaluation (no cloud, no public export)
        ↓
CPU/RAM/index-time capacity measurement
        ↓
new index generation beside old index
        ↓
Gateway count/thumbnail/pagination/People+Search ACL regression
        ↓
explicit rollback pointer change
```

失败条件包括：unknown/unsuitable license、无法固定 revision、cache layout 未被锁定 runtime 支持、模型需要把真实照片或 query 送到外部、中文质量没有实测改善、Family 可从 count/thumbnail/semantic ranking 侧信道得到 LIVING。

## 推荐的 Search 产品策略（不等模型替换）

1. 将人名、相册名、活动、年份/学期、archive date、来源、标签、说明和 OCR 做成可解释的结构化索引；这些应永远排在语义结果前。
2. 当前 semantic CLIP 仅作为“智能匹配 Beta”，不要声称中文自然语言已经可靠。
3. 对中文 query 建立可复现 query set：`操场上的合照`、`教室里的照片`、`毕业时拍的照片`、`晚上的集体照`，并按 expected relevant / acceptable / irrelevant 标注；同时保留英文等价 query 作为对照。
4. 不把 NLLB 或任一 translation model 偷偷接入用户请求。任何 query rewrite 都是新数据流，必须单独列入隐私、许可、offline 和质量 acceptance。
5. 对候选双塔模型，先以 synthetic corpus 做一次完整 reindex；在真实本地资料上只做 private-local QA，绝不把真实图片、embedding、query 或截图入 Git/CI/外网。

## 来源

- 当前项目锁定模型与受限再分发结论：[ml-artifact-policy](ml-artifact-policy.md)、[Immich ML manifest](../infra/immich-spike/ml-artifacts/manifest.json)、[Immich upstream strategy](immich-upstream-strategy.md)。
- Immich official docs, [Searching](https://docs.immich.app/features/searching/)；Postgres metadata/contextual search、模型取舍与搜索功能。
- Immich official docs, [System Settings](https://docs.immich.app/administration/system-settings/)；更换 CLIP model 后重新运行 Smart Search 的要求。该页是当前 docs，不能替代 v3.1.0 compatibility test。
- Sentence Transformers official Hugging Face card, [clip-ViT-B-32-multilingual-v1](https://huggingface.co/sentence-transformers/clip-ViT-B-32-multilingual-v1) 及 [539 MB safetensors artifact](https://huggingface.co/sentence-transformers/clip-ViT-B-32-multilingual-v1/blob/main/model.safetensors)；50+ language text/image common vector space 与 Apache-2.0 标签。
- Multilingual-CLIP official Hugging Face card, [XLM-Roberta-Large-Vit-B-32](https://huggingface.co/M-CLIP/XLM-Roberta-Large-Vit-B-32) 及 [fixed-file listing example](https://huggingface.co/M-CLIP/XLM-Roberta-Large-Vit-B-32/tree/11440dd109633286a08d965e052d25fcab27b399)；48 languages、text-only encoder、约 2.24 GB weight。页面未提供可接受的明确 weight license metadata，故本审计将其视为 unknown，而非推断为允许。
- Meta official Hugging Face card, [NLLB-200 distilled 600M](https://huggingface.co/facebook/nllb-200-distilled-600M) 及 [file listing](https://huggingface.co/facebook/nllb-200-distilled-600M/tree/main)；translation、196 languages、CC-BY-NC-4.0、约 2.48 GB。
- Google official Hugging Face card, [SigLIP 2 Base](https://huggingface.co/google/siglip2-base-patch16-224) 及 [1.5 GB safetensors artifact](https://huggingface.co/google/siglip2-base-patch16-224/blob/main/model.safetensors)；Apache-2.0 与 image-text retrieval 用途。
