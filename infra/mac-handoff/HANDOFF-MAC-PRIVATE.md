# Class Archive 完整私有 Mac 迁移交接

本文是 macOS 迁移的**通用恢复契约**；文档正文不包含照片、账号、密钥、数据库或
任何本机绝对路径。最终交付包会包含真实媒体与私有业务状态，必须把本文复制到包根
目录，并生成与包本身一致的 `manifest.json`、`checksums.sha256` 和根目录唯一的
`COMPLETE`。当前完整私有交接采用 v2 无加密实体介质模式，属于高度敏感的本地私有
数据，不是适合网络传输或公开存储的发布包。

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
  private-sources/                # 必需的只读原始来源归档，与 managed originals 分离
  source/immich-upstream.lock.json # 供解包前 architecture Gate 读取的公开锁副本
```

`COMPLETE` 必须只出现在交接包根目录，且内容与 `manifest.json` 的格式版本严格对应。
v1 使用：

```text
CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V1
```

v2 使用：

```text
CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2
```

包内 payload、Owner 子目录或数据库备份不得再放置第二个 `COMPLETE`；完整性只由根目录
标记、根 manifest 和逐文件校验和共同判定。

`checksums.sha256` 使用小写 SHA-256、两个空格和 POSIX 相对路径。除
`checksums.sha256` 与 `COMPLETE` 外，每个普通文件必须恰好出现一次；未知文件、
绝对路径、`..`、反斜杠或外层 symlink 都会使验证失败。

`manifest.json` 至少包含：

```json
{
  "format": "class-archive-mac-private-handoff-v2",
  "version": 2,
  "created_at": "UTC ISO-8601",
  "git": { "branch": "codex/...", "head": "40-char sha" },
  "privacy": {
    "classification": "PRIVATE_LOCAL_ARTIFACT",
    "contains_real_media": true,
    "git_safe": false
  },
  "evidence": {
    "capture_completed": true,
    "package_verification": "EXTERNAL_VERIFIER_REQUIRED",
    "private_source_archive_verification": "PASS",
    "source_integrity_before_after": "PASS",
    "runtime_sanitization": "PASS",
    "mac_runtime_tested": false
  },
  "payloads": [
    {
      "path": "payloads/...",
      "size": 1,
      "sha256": "64-char sha",
      "classification": "PUBLIC_SAFE_SOURCE | SYNTHETIC_TEST_DATA | PRIVATE_UNENCRYPTED_LOCAL_DATA",
      "encrypted": false,
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

因此 v2 只能通过受控的实体介质当面转移；禁止上传云盘、邮件附件、即时通信文件、
Git、CI artifact、HTTP 文件服务或任何公网位置。运输盘遗失、借用或被复制，应视为
真实图库和数据库已经泄露。接收后先在启用 FileVault 的 Mac 本地磁盘完成校验与持久
化存放，再开始恢复；不用时应物理保管或安全移除介质。

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

### v2 运行状态 payload 对照

当前发布器使用以下稳定文件名；最终以 `manifest.json` 中逐文件 SHA-256 为准：

| 文件 | 恢复内容 | 重要限制 |
|---|---|---|
| `payloads/owner/owner-mariadb.sql.gz` | Piwigo、ClassIdentity、相册、评论、人物整理、投影与 Audit | 已清空 session/auth key/激活 key，并移除 Piwigo `secret_key` |
| `payloads/owner/owner-immich-postgres.dump` | Immich asset、face/person、smart-search index | 已排除 session、API key、shared link、stream session，以及 `system_metadata`、`user_metadata` 的全部数据 |
| `payloads/owner/owner-piwigo-data.tar` | Piwigo 应用状态 | 不含数据库配置、bridge secret、session、日志与可重建 cache |
| `payloads/owner/owner-piwigo-scripts.tar` | 已安装生命周期脚本 | 恢复到独立只读/受管 volume 后核对执行位 |
| `payloads/owner/owner-canonical-uploads.tar`、`owner-canonical-galleries.tar` | Class Archive managed originals | 只恢复到全新空 volume |
| `payloads/owner/owner-immich-canonical.tar` | Immich `library/upload/profile` | 不含 Immich `backups/` |
| `payloads/owner/owner-piwigo-derivatives.tar`、`owner-immich-derivatives.tar` | 已预生成的预览和缩略图 | 可重建，但随本地交接保留以缩短首次可用时间 |
| `payloads/synthetic/*` | 独立 72/72/8 工程基线与 fictional fixtures | 永远使用不同 Compose project、DB 和 volumes |
| `payloads/private-sources/private-source-a.tar`、`private-source-b.tar` | 两个完整只读原始来源集合 | 默认不导入；只作 provenance/重新核验来源 |
| `payloads/private-metadata/*` | 来源清单、导入映射、恢复指纹和私有 QA 元数据 | 含真实文件名，禁止复制到 Git 或公开报告 |

所有内外层归档都只允许普通文件和目录；符号链接、硬链接及设备/FIFO/socket 等特殊节点
会被 verifier 拒绝。归档头可以保存普通文件的 POSIX mode/UID/GID 恢复信息，但不得依赖
链接语义搬运运行状态。

恢复任一 POSIX tar 前必须先执行包校验，并确认目标 named volume 完全为空。恢复 helper
使用 `--network none`、只读 rootfs 和最小 capability；不得把 tar 直接展开到 macOS 用户
目录或现有业务 volume。MariaDB 先建立目标数据库后导入 gzip 逻辑 dump；PostgreSQL 用
`pg_restore --exit-on-error --clean --if-exists --no-owner --no-privileges` 导入 custom-format
dump。恢复后再生成新的 `.env`/runtime secrets，随后启动应用层。

Immich PostgreSQL dump 有意不携带 `system_metadata` 和 `user_metadata` 的任何行；这些
表中的安装级、用户级状态不能当作已恢复业务证据。目标 Mac 必须根据锁定版本、包内非
秘密配置及恢复脚本重新建立所需运行 metadata，再核对 schema 和服务健康。face/person、
search index 是否恢复，应以各自业务表和 manifest 计数验证，不能用重建的 metadata 行
替代。

在 v1 中，Owner 数据、真实照片、数据库、来源 manifest、账号状态和 AI embedding
必须属于 `PRIVATE_ENCRYPTED_DATA`，使用 `.gpg` payload 且在包内保持加密。v2
只能按上一节的显式本地实体介质契约标记为 `PRIVATE_UNENCRYPTED_LOCAL_DATA`。只含
版本、公开 license、哈希或聚合计数的恢复清单可标为
`PRIVATE_NONSECRET_METADATA`。两种格式都不得把明文 `.env`、Cookie、密码、token、
GPG passphrase 或临时 data key 放入外层 tar、manifest、日志或交接文档。

## 目标 Mac 环境

恢复所需的最低环境：

1. 当前受支持的 macOS 与 Docker Desktop for Mac，包含 Docker Compose v2。
2. Git、Bash、Python 3.11+、BSD tar/gzip、`zstd`，以及 SHA-256 工具。v1 另需
   GnuPG；当前无加密 v2 不需要 GPG 解密，但 preflight 仍可能检查完整通用恢复工具集。
   校验脚本依次使用 `gsha256sum`、`sha256sum`、macOS 自带的 `shasum -a 256`。
3. 足够的本地存储：解包后 payload 大小的两倍，再额外保留至少 20 GiB；完整
   Owner 恢复应按实际 manifest 和 Docker Desktop data disk 增加余量。
4. 仅在需要重建 Immich Web 或运行浏览器测试时安装 Node `24.15.0`、通过
   Corepack 使用 pnpm `11.13.1`、Google Chrome Stable 与项目锁定的 Playwright。
   已交付且已校验的 Web build 不要求为了恢复而重新执行 `pnpm install`。
5. 推荐通过 Homebrew 安装：`brew install zstd python@3.11 coreutils gnu-tar`；恢复
   v1 时再安装 `gnupg`。`zstd` 是读取外层 `.tar.zst` 的必需工具，不是可选项。不得
   用 Homebrew 自动升级项目锁定的容器镜像或 Node/pnpm 版本。

单文件归档无法在解包前提供自身内部的执行脚本。第一次接收时，必须先从交接报告或
Owner 直接提供的另一条可信通道取得 64 位外层 SHA-256，使用 macOS 自带工具完成
bootstrap 校验，然后只解包到新建的隔离目录：

```bash
archive=/Volumes/TRANSFER/ClassArchive-Complete-Mac-Handoff-....tar.zst
expected='PASTE_64_HEX_SHA256_FROM_TRUSTED_HANDOFF_REPORT'
actual=$(shasum -a 256 -- "$archive" | awk '{print $1}')
test "$actual" = "$expected"
mkdir -p /path/to/private/classarchive-handoff
chmod 700 /path/to/private/classarchive-handoff
zstd -q -dc -- "$archive" | tar -tf - >/dev/null
zstd -q -dc -- "$archive" \
  | tar -xf - -C /path/to/private/classarchive-handoff --no-same-owner
```

外层 SHA-256 匹配后，先从包内当前 HEAD 的 source snapshot 建立 checkout，再使用其中的
完整 verifier 重新检查包头边界、每个 payload、manifest 和 Git bundle。下面的 `head`
只能来自刚刚通过外层哈希校验的 manifest：

```bash
package=/path/to/private/classarchive-handoff/ClassArchive-Complete-Mac-Handoff-...
head=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1],encoding="utf-8"))["git"]["head"])' "$package/manifest.json")
mkdir -p /path/to/private/classarchive-checkout
tar -xzf "$package/payloads/source/class-archive-source-$head.tar.gz" \
  -C /path/to/private/classarchive-checkout --strip-components=1
/path/to/private/classarchive-checkout/infra/mac-handoff/verify-handoff-package.sh "$package"
/path/to/private/classarchive-checkout/infra/mac-handoff/mac-preflight.sh \
  --package-root "$package" \
  --fresh-project classarchive-mac-restore-001 \
  --check-build-toolchain
```

如果已从另一条可信通道取得同一 HEAD 的 verifier，可直接运行
`verify-handoff-archive.sh ARCHIVE EXPECTED_SHA256 WORK_DIR`；它会在工作目录临时解包、
完成同等检查并自动删除临时副本。无论使用哪条路径，持久 Owner 恢复都只能引用
FileVault 保护、空间充足且不在 Git checkout 中的 `package` 目录。

上面的 `mac-preflight.sh` 只读检查；它不会解密、导入、创建 volume 或启动服务。

### 保守恢复编排器

包内同一 HEAD 的 `restore-mac.sh` 提供一个故意受限的恢复路径。先从 Git bundle 恢复出
带 `.git` 的 checkout，并确认其 HEAD 与 package manifest 一致；不要直接在只有 source
tar 内容、没有 Git object database 的目录中运行。推荐顺序如下（路径与 project id 仅为
示例）：

```bash
package=/path/to/private/classarchive-handoff/ClassArchive-Complete-Mac-Handoff-...
checkout=/path/to/private/classarchive-checkout
runtime_env="$HOME/Library/Application Support/ClassArchive/restore-owner.env"
state="$HOME/Library/Application Support/ClassArchive/restore-owner-state"

"$checkout/infra/mac-handoff/restore-mac.sh" --prepare-source \
  --package-root "$package" --checkout "$checkout"
"$checkout/infra/mac-handoff/restore-mac.sh" --init-env "$runtime_env" \
  --restore-id classarchive_mac_owner_001 --core-port 8490 --compat-port 8491
"$checkout/infra/mac-handoff/restore-mac.sh" --preflight-only \
  --package-root "$package" --checkout "$checkout" \
  --runtime-env "$runtime_env" --state-dir "$state"
"$checkout/infra/mac-handoff/restore-mac.sh" --restore \
  --package-root "$package" --checkout "$checkout" \
  --runtime-env "$runtime_env" --state-dir "$state"
```

`--dry-run` 是 `--preflight-only` 的同义入口。runtime env 必须由 `--init-env` 新建为当前
用户拥有的 `0600` 文件；脚本只输出“已生成”，绝不输出秘密值。恢复 identity、state
目录、Compose containers、networks 和每个 named volume 都必须是全新的；发现任何同名
对象就 fail closed。脚本没有 reset/reuse/delete/prune/down 路径，失败后的 partial restore
会原样保留供人工核查，不能用同一 identity 重跑。

当前 `--restore` 的成功范围仅为 `DATA_RESTORE + PIWIGO_CORE_READY`：它恢复 MariaDB、
Immich PostgreSQL dump、Piwigo/managed media/derivative POSIX tar，在 Linux named volume
中恢复 archive mode，并对照 capture manifest 核对计数；只发布 loopback Piwigo 端口，
同时验证 guest 对已知 original/derivative 的 GET、HEAD、Range 拒绝。完成标记只会在这些
检查全部通过后生成。

它**不会**伪装完成以下步骤：被排除的 ML model cache 安装、Immich `system_metadata` /
`user_metadata` 重建、技术用户与 bridge secret/bootstrap、Immich/ML/BFF 全栈启动、角色
浏览器 E2E、授权用户 MediaGuard 矩阵、cold restart/no-reindex 证明。脚本会明确输出
`IMMICH_METADATA_BOOTSTRAP=NOT_RUN`、`IMMICH_BRIDGE_BOOTSTRAP=NOT_RUN`、
`AI_RESULTS_AVAILABLE_IMMEDIATELY=NOT_RUNTIME_TESTED` 与 `MAC_RUNTIME_TESTED=NO`；这些
项目完成真实 Mac runtime 验收前，不得把数据恢复等同于完整迁移 PASS。

当前容器锁是 `linux/amd64`。Apple Silicon 必须显式传
`--allow-amd64-emulation` 才能进入实验性 preflight/restore；这只是风险确认，不会把
架构或性能 Gate 改成 PASS。

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

1. 按“目标 Mac 环境”的 bootstrap 流程从包外可信渠道取得外层 SHA-256，校验后解包到
   FileVault 保护的持久普通目录；若另有可信 verifier，也可先运行
   `verify-handoff-archive.sh`，注意其临时解包会自动删除。
2. 从已校验包中的 source snapshot 建立 checkout，再运行 `verify-handoff-package.sh`，
   确认 `PACKAGE_VERIFIED=PASS`；后续恢复只引用该持久目录。
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

1. 校验交接包根目录唯一的 `COMPLETE`、`manifest.json` 和全部 SHA-256；Owner payload
   没有也不应拥有独立 `COMPLETE` 标记。
2. v1 通过 portable GPG envelope 以 no-echo 方式解开恢复所需 data key；Mac 路径
   不得使用 Windows DPAPI。临时明文只放 `mktemp -d` 创建的 `0700` 目录，并在 trap
   中清除。v2 跳过本步，直接读取已通过 SHA-256 校验的明文 payload。
3. 从 `.env.example` 生成目标 Mac 的全新 runtime secret。v2 不携带原
   `CLASS_ARCHIVE_CLAIM_CODE_PEPPER`、`CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET`、bridge
   token 或 session secret；全部既有登录会话、未完成 Claim/Invite/Reset 必须视为失效。
4. 先启动空 MariaDB，恢复一致性逻辑 dump；再恢复 Piwigo application/scripts、
   uploads、galleries 与 canonical originals 的 POSIX tar。普通文件的 mode、UID/GID
   恢复信息以归档头为准，不能依赖 macOS/exFAT 宿主权限；交接归档不接受 symlink 或
   其他特殊节点。
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
- 实际图库应由已验证的 Owner payload 中的 managed canonical originals 加上 MariaDB/
  PostgreSQL/mapping 恢复；v1 payload 必须加密，v2 则仅允许处于已声明的本地实体介质
  风险模式。两者都不能用 Windows Docker VHDX 或裸 named-volume 目录当作可移植备份。
- 完整私有交接必须包含原始来源目录的独立归档，并明确标记为只读
  provenance/source；它与 managed canonical originals 分开计数，恢复时默认只挂载为
  只读来源，绝不能自动再次导入。
- 真实文件名、路径、face mapping、导入 manifest 只能留在私有 payload；v1 中必须加密，
  v2 中虽为明文但必须保持实体介质私有保管。不得复制进 Git checkout、公开 CI、README、
  Issue 或日志。
- 大批历史浏览器截图与临时 Profile 不包含在本交接包中；`private-import-and-provenance.tar`
  可能保留少量与真实图库清晰度/导入验收直接相关的私有截图证据。它们同整个交接包一样
  只能留在受控本地介质，不能充当 Mac 实测证据。Mac 恢复后仍应在其独立 ignored 目录
  重新生成必要截图；含真实照片的截图不得回传 Git、CI、Issue 或云端。

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
