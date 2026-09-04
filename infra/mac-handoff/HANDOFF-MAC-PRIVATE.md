# Class Archive 完整私有 Mac 迁移交接

本文是 macOS 迁移的**通用恢复契约**，不包含照片、账号、密钥、数据库或任何
本机绝对路径。最终交付包必须把本文复制到包根目录，并生成与包本身一致的
`manifest.json`、`checksums.sha256` 和 `COMPLETE`。

## 证据边界

- `PACKAGE_VERIFIED=PASS` 只说明包内文件、清单和 SHA-256 一致。
- `MAC_PREFLIGHT=PASS_STATIC_ONLY` 只说明 Mac 主机工具、空项目名、空端口和空间满足静态条件。
- 上述两项都**不等于** `MAC_RUNTIME_TESTED=YES`。
- 只有在目标 Mac 的全新 Docker volumes 中完成恢复、角色 ACL、MediaGuard、People、Search、评论和浏览器验收，才可以声明 Mac runtime 已验证。
- Windows 上生成的备份、既有 localhost 截图或 Git 测试结果，不能替代目标 Mac 实测。

## 包根目录契约

解包后的根目录必须是普通目录，外层不允许符号链接：

```text
HANDOFF-MAC-PRIVATE.md
manifest.json
checksums.sha256
COMPLETE
payloads/
  source/                         # Git bundle / tracked source snapshot / locked upstream evidence
  synthetic/                      # 公开安全的合成测试照片与基线恢复资料
  owner/                          # Owner DB、业务状态、canonical originals、AI index
  private-metadata/               # 来源、导入、人工整理等私有 manifest（如需要）
  private-sources/                # 可选的只读原始来源归档，与 managed originals 分离
metadata/
  immich-upstream.lock.json       # 供解包前 architecture Gate 读取的公开锁副本
```

`COMPLETE` 的内容固定为：

```text
CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V1
```

`checksums.sha256` 使用小写 SHA-256、两个空格和 POSIX 相对路径。除
`checksums.sha256` 与 `COMPLETE` 外，每个普通文件必须恰好出现一次；未知文件、
绝对路径、`..`、反斜杠或外层 symlink 都会使验证失败。

`manifest.json` 至少包含：

```json
{
  "format": "class-archive-mac-private-handoff-v1",
  "version": 1,
  "created_at": "UTC ISO-8601",
  "git": { "branch": "codex/...", "head": "40-char sha" },
  "privacy": {
    "classification": "PRIVATE_LOCAL_ARTIFACT",
    "contains_real_media": true,
    "git_safe": false
  },
  "evidence": {
    "package_verified": true,
    "mac_runtime_tested": false
  },
  "payloads": [
    {
      "path": "payloads/...",
      "size": 1,
      "sha256": "64-char sha",
      "classification": "PUBLIC_SAFE_SOURCE | SYNTHETIC_TEST_DATA | PRIVATE_NONSECRET_METADATA | PRIVATE_ENCRYPTED_DATA",
      "encrypted": true,
      "required": true
    }
  ]
}
```

### v2：无口令、本地实体介质交接

```text
HANDOFF_V2_MODE=LOCAL_PHYSICAL_MEDIA_ONLY
HANDOFF_V2_ENCRYPTION=NONE
HANDOFF_V2_PUBLIC_OR_CLOUD_TRANSFER=FORBIDDEN
HANDOFF_V2_MAC_FILEVAULT_RECOMMENDED=YES
```

若 Owner 明确不使用便携恢复口令，可生成
`class-archive-mac-private-handoff-v2`。v2 不降低 v1 的加密契约，而是一个单独、
机器可识别的风险模式：外层必须是单个 POSIX `tar.zst`，`transport.encryption`
和 `confidentiality_protection` 都必须为 `NONE`，只能存放在受控的本地实体介质，
并明确禁止 Git、CI、云盘、邮件与公网传输。其完成标记为：

```text
CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2
```

v2 持有者能够读取真实照片、真实文件名、数据库、评论、账号密码哈希以及人脸/
搜索向量。SHA-256 只能证明字节一致；除非预期值通过包外可信渠道取得，否则不能
防止整包被替换。复制到 Mac 后应立即放入启用 FileVault 的本地磁盘并执行
`chmod 600`。包内仍严格禁止明文 `.env`、Cookie、可复用 session、token、API
secret、GPG data key 或恢复口令；Mac runtime secret 必须重新生成。

v2 **没有恢复口令，也没有便携密钥 envelope**。恢复时直接读取已校验的 payload；
任何要求输入 GPG/DPAPI 恢复秘密的步骤只适用于 v1。不要给 v2 临时增加一个写在脚本、
环境变量或文档里的共享口令。v2 的保密边界完全依赖实体介质保管，以及复制到 Mac 后
由 FileVault 提供的静态数据保护。

v2 的 `PRIVATE_UNENCRYPTED_LOCAL_DATA` payload 只有在 manifest 同时声明本地实体
介质限制、无保密保护、禁止公开/云传输和物理保管确认时才被 verifier 接受。
外层校验使用：

```bash
./infra/mac-handoff/verify-handoff-archive.sh \
  /path/to/ClassArchive-Complete-Mac-Handoff.tar.zst \
  <从同机交接报告取得的 64 位 SHA-256>
```

在 v1 中，Owner 数据、真实照片、数据库、来源 manifest、账号状态和 AI embedding
必须属于 `PRIVATE_ENCRYPTED_DATA`，使用 `.gpg` payload 且在包内保持加密。v2
只能按上一节的显式本地实体介质契约标记为 `PRIVATE_UNENCRYPTED_LOCAL_DATA`。只含
版本、公开 license、哈希或聚合计数的恢复清单可标为
`PRIVATE_NONSECRET_METADATA`。两种格式都不得把明文 `.env`、Cookie、密码、token、
GPG passphrase 或临时 data key 放入外层 tar、manifest、日志或交接文档。

## 目标 Mac 环境

恢复所需的最低环境：

1. 当前受支持的 macOS 与 Docker Desktop for Mac，包含 Docker Compose v2。
2. Git、Bash、Python 3.11+、GnuPG、BSD tar/gzip，以及 SHA-256 工具。
   校验脚本依次使用 `gsha256sum`、`sha256sum`、macOS 自带的 `shasum -a 256`。
3. 足够的本地存储：解包后 payload 大小的两倍，再额外保留至少 20 GiB；完整
   Owner 恢复应按实际 manifest 和 Docker Desktop data disk 增加余量。
4. 仅在需要重建 Immich Web 或运行浏览器测试时安装 Node `24.15.0`、通过
   Corepack 使用 pnpm `11.13.1`、Google Chrome Stable 与项目锁定的 Playwright。
   已交付且已校验的 Web build 不要求为了恢复而重新执行 `pnpm install`。
5. 可选 Homebrew 包：`gnupg python@3.11 coreutils gnu-tar`。不得用 Homebrew
   自动升级项目锁定的容器镜像或 Node/pnpm 版本。

推荐第一次执行：

```bash
./infra/mac-handoff/verify-handoff-package.sh /path/to/extracted-handoff
./infra/mac-handoff/mac-preflight.sh \
  --package-root /path/to/extracted-handoff \
  --fresh-project classarchive-mac-restore-001 \
  --check-build-toolchain
```

脚本只读检查；它不会解密、导入、创建 volume 或启动服务。

## Apple Silicon / 容器架构 Gate

当前 Immich v3.1.0 上游固定为 commit
`8aa95c67470a02a8ddedf03c2e52963af33065ff`。现有供应链证据中的 Immich Server
与 Machine Learning 镜像是固定的 `linux/amd64` digest；它们只在 Windows 主机的
amd64 Linux runtime 中验证过。

因此在 Apple Silicon 上：

- 不得把 Docker Desktop 的 Rosetta/emulation 开关当作兼容性证据；
- 不得把同 tag 的 arm64 manifest 悄悄替换进来；
- 必须逐个核对 Piwigo、MariaDB、PostgreSQL、Valkey、Immich Server、Immich ML、
  Gateway/BFF 的 tag、digest 和目标平台；
- 先用 synthetic 数据在隔离项目中启动并跑完整 smoke、MediaGuard 与角色矩阵；
- 再验证离线 ML cold start、Face/Search 查询与资源占用；
- 通过前，保持 `CONTAINER_ARCH_GATE=BLOCKED`、`MAC_RUNTIME_TESTED=NO`。

Intel Mac 只能通过主机架构静态匹配这一层 Gate；仍需真实 runtime 验证。

## 隔离与网络要求

- 只绑定 `127.0.0.1`；Compose 渲染结果中出现 `0.0.0.0`、空 host IP 或公开端口即停止。
- Immich Server、Machine Learning、PostgreSQL、Valkey 不发布 host port。
- 浏览器只进入 Class Archive 的 loopback UI；任何媒体仍经
  `ClassArchivePolicy -> MediaGuard -> X-Accel-Redirect`。
- 使用全新的 Compose project 名、全新的 MariaDB/PostgreSQL/media/model/gateway
  volumes。不得连接、复用或覆盖 Mac 上已有同名项目。
- 第一次恢复不要自动登录、开放局域网、导入真实用户邀请、连接 NAS 或配置公网反代。
- Docker Desktop file sharing 只开放解包/恢复所需目录；不要开放整个用户目录。

## 恢复顺序

### 1. 验证与代码恢复

1. 校验外层单文件压缩包的 sidecar SHA-256，再解包到普通本地目录。
2. 运行 `verify-handoff-package.sh`，确认 `PACKAGE_VERIFIED=PASS`。
3. 从 Git bundle 恢复分支，确认 HEAD、`git fsck`、tracked snapshot 与锁文件。
4. 运行 `mac-preflight.sh`；任何 architecture、空间、端口或 fresh-project 失败都先处理。
5. 在执行权限丢失的 exFAT 传输介质上解包后，明确为项目 `.sh` 和
   `infra/s6/php-fpm-run` 恢复执行位；不要依赖 exFAT mode。

### 2. Synthetic-first

1. 从 tracked synthetic fixtures 与 synthetic backup 建立全新工程项目。
2. 仅启动 8090/8091 等 loopback listener；验证 72 images / 72 originals /
   8 multi-album（最终以包内 synthetic manifest 为准）。
3. 验证登录、Classmate/Teacher/Family/Anonymous、Pending、评论、相册、搜索、
   known original/derivative 的 GET/HEAD/Range 和 freeze/logout/account switch。
4. 冷重启后再次验证；失败时不得继续恢复 Owner 数据。

### 3. Owner 私有状态

1. 校验 Owner immutable backup 自己的 `COMPLETE`、manifest 和全部 SHA-256。
2. v1 通过 portable GPG envelope 以 no-echo 方式解开恢复所需 data key；Mac 路径
   不得使用 Windows DPAPI。临时明文只放 `mktemp -d` 创建的 `0700` 目录，并在 trap
   中清除。v2 跳过本步，直接读取已通过 SHA-256 校验的明文 payload。
3. 从 `.env.example` 生成目标 Mac 的全新 runtime secret。v2 不携带原
   `CLASS_ARCHIVE_CLAIM_PEPPER`、`CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET`、bridge
   token 或 session secret；全部既有登录会话、未完成 Claim/Invite/Reset 必须视为失效。
4. 先启动空 MariaDB，恢复一致性逻辑 dump；再恢复 Piwigo application/scripts、
   uploads、galleries 与 canonical originals 的 POSIX tar。模式、UID/GID 与 symlink
   在 Linux volume 内恢复，不能依赖 macOS/exFAT 宿主权限。
5. 恢复 Immich PostgreSQL custom-format dump、Immich asset/upload state 与
   ClassArchivePhoto/ClassArchivePerson mapping。恢复 face/search index 后禁止先触发
   全库 AI 重算来掩盖缺失。
6. 在 maintenance/closed surface 下运行 schema 兼容检查和只允许的 additive migration；
   不得重新导入 canonical originals 或重建业务真相。
7. 对照 backup manifest 核对 Source records、Canonical Photos、album relationships、
   comments/replies、anonymous context、people/face/search、AI jobs、Spotlight、Memories、
   pinned collections、audit 和 display aliases。
8. 先跑 MediaGuard，再开放 BFF；最后用新 Chrome profile 跑 Owner、Family、Teacher、
   Anonymous 浏览器 E2E。Family 的 LIVING、Pending 和数量/封面/搜索侧信道必须继续拒绝。
9. 冷重启全部服务，证明人物、搜索、评论、回忆和投影立即可用，且没有全库 reindex。

### v2 的匿名代号连续性限制

匿名展示代号由 `CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET` 派生。因为无口令 v2
有意排除所有 runtime secret，Mac 重新生成该 secret 后，历史上下文的即时匿名代号和
管理员解析结果**不能保证与 Windows 完全相同**。数据库中已经保存的评论展示快照会保留，
但重新计算的代号可能变化。若必须保持跨主机完全连续，只能由 Owner 通过另一个受保护、
不在本包中的通道迁移原 secret，或改用含加密 secret envelope 的 v1；未做到时必须将
`ANONYMOUS_PSEUDONYM_CONTINUITY=NOT_GUARANTEED`，不得隐藏为完整恢复成功。

## 测试照片与真实图库

- `SYNTHETIC_TEST_DATA` 可以包含项目审查过的 fictional fixtures 和 72/72/8 基线；
  synthetic 与 Owner 数据必须进入不同数据库、volumes 和 Compose project。
- 机器可判定边界：`OWNER_SYNTHETIC_ISOLATION=DIFFERENT_DATABASES_VOLUMES_COMPOSE_PROJECTS`。
- 实际图库应由加密 Owner backup 中的 managed canonical originals 加上 MariaDB/
  PostgreSQL/mapping 恢复，不能用 Windows Docker VHDX 或裸 named-volume 目录当作
  可移植备份。
- 若交付还包含原始来源目录的第二份归档，必须明确标记为只读 provenance/source，
  与 managed canonical originals 分开计数，避免恢复时重复导入。
- 真实文件名、路径、截图、face mapping、导入 manifest 都必须留在加密 payload；
  不得复制进 Git checkout、公开 CI、README、Issue 或日志。

## ML 模型与许可证

业务备份应包含 Immich PostgreSQL 中已经生成的 face/search index 与模型 revision，
但默认不包含受限 ML 二进制：

- InsightFace `buffalo_l` 权重的项目结论是仅限非商业研究，redistribution 为
  `PROHIBITED`；
- 固定 OpenCLIP artifact 仓库未给出明确权重许可证，redistribution 为 `UNKNOWN`；
- 包内可含 `infra/immich-spike/ml-artifacts/manifest.json`、来源、revision、大小和
  SHA-256，但不能把模型权重作为普通可分发 payload；
- Mac 上只能从 manifest 指定的官方来源受控获取、逐文件校验、导入离线 cache，
  然后断开模型下载网络做 cold start；不得换第三方镜像或浮动 revision。

缺少合规的模型 cache 时，可以先恢复照片、相册和业务状态，但必须显示本地 AI
不可用，不能让 cache miss 自动联网或把 AI Gate 记为 PASS。

## 必须保留的最终证据

目标 Mac 的私有 ignored 目录至少记录：

- package/backup SHA-256 与 manifest 验证；
- `docker compose config` 的 loopback/internal-only 审计；
- fresh volume/project 证据；
- schema、记录数、canonical media 抽样 SHA-256；
- Owner/Family/Teacher/Anonymous 与 MediaGuard HTTP 结果；
- AI index 是否在恢复后立即存在、是否触发 reindex；
- Chrome Stable Desktop/Mobile 截图（真实照片截图不得进入 Git）；
- 冷重启结果、RTO 和任何手工步骤。

完成这些步骤前，最终状态应保持：

```text
PACKAGE_VERIFIED=PASS_OR_FAIL
MAC_PREFLIGHT=PASS_STATIC_ONLY_OR_BLOCKED
MAC_SYNTHETIC_RUNTIME=NOT_TESTED
MAC_OWNER_RESTORE=NOT_TESTED
MAC_RUNTIME_TESTED=NO
PRODUCTION_READY=NO
```
