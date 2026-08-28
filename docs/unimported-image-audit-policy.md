# 未导入静态图片审计策略

Class Archive 对完整私有图库采用“先审计，后增量导入”的保守策略。审计器只读取 owner-local inventory 和路径脱敏的 runtime manifest，精确计算未导入条目，不修改源文件，不向终端输出原始目录或文件名。

通用工具为 `infra/scripts/private-real-unimported-audit.py`，owner 本机入口为 `infra/scripts/audit-private-unimported-images.ps1`。逐文件结果只写入 Git 已忽略的 `.codex-work/private-real-qa/reports/unimported-images.json`；可提交的代码和文档不包含私有路径、文件名、哈希或图像内容。

## 分类与处理

- `mpo_multi_picture`：使用 Pillow 的 MPO 解码器读取 frame，并对第一帧执行单帧 JPEG 内存往返验证。通过后可进入“保留原始 MPO 来源 + 托管 JPEG 展示替代物”的增量导入。若第一帧完整可验证而辅助/立体 frame 损坏，单独记录 `mpo_secondary_frame_decode_failure`；展示替代物仍可安全使用主帧，原始 MPO 保留供以后专用工具修复。
- `decoder_failure` / `malformed_image` / `color_profile_issue` / `zero_byte_or_invalid`：延后并保留原因，不为追求数量强行生成展示文件。
- `exact_duplicate_already_represented`：不再创建 Original，但可在后续增量流程中增加来源证明和相册关系。
- 其他未支持格式：默认 `DEFERRED_WITH_REASON`，只有成熟解码器、完整性验证和展示替代物策略同时成立时才放行。

MPO 是 JPEG 容器的多图片变体。固定的 Immich v3.1.0 上游声明支持 `.mpo`，但当前 Class Archive/Piwigo 原图管线只允许 JPEG、PNG 和 WebP，而私有来源可能以 `.jpg/.jpeg` 后缀保存 MPO 字节。因此不依赖后缀伪装，也不把转码文件当作原始文件。

## 安全与幂等边界

1. 审计前后重新校验 size、mtime 和 SHA-256；任一不一致立即 fail closed。
2. 展示替代物的 MPF offset 与 Orientation 不从 MPO 照搬；方向在像素上应用，原始元数据由 Source Provenance 保留。
3. 这一步只产生审计决策，不导入 8191，不创建 Canonical Photo、Album membership 或 AI job。
4. 后续增量导入必须以 source identity + source SHA-256 + Canonical mapping 为幂等键，且仅为新增像素创建 derivative/AI job。
