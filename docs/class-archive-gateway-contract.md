# Class Archive Gateway 合约（Phase 2）

## 证据等级

本文件只描述当前已实现的 Class Archive 自有边界。以下等级绝不混用：

| 项目 | 当前等级 | 含义 |
| --- | --- | --- |
| `ClassArchivePhoto` UUID、MariaDB 映射 schema、Adapter 接口 | `STATIC` | 已经源码和 MariaDB semantic fingerprint 检查 |
| Gateway policy、列表、时间线、相册、搜索、People、Memories 过滤 | `CONTRACT_TESTED` | synthetic Adapter + 本地 MariaDB 合约测试通过 |
| 同源 `/api` Piwigo Gateway | `RUNTIME_TESTED` | 真实 localhost Piwigo + MariaDB + ClassIdentity 运行态；37 次 HTTP 请求、631 个 ACL / 聚合 / DTO / 输入 / canonical MediaGuard delivery 断言通过 |
| Immich Server isolated boot | `RUNTIME_TESTED` | internal `pong`、无 host port、read-only originals 与 SHA-256 不变 |
| Immich technical user / external-library lifecycle | `RUNTIME_TESTED` | ephemeral internal user、read-only synthetic scan、asset count gate、spike reset 后空状态复验 |
| Immich Adapter / Gateway runtime query | `RUNTIME_TESTED` | temporary bridge → real pinned Immich v3.1.0; Classmate/FAMILY aggregation, internal-network isolation, no-media route and cleanup passed |
| 浏览器 API/UI | 未开始 | `/api` 已有同源 JSON 边界，但没有 Photo UI / Immich Web E2E |

[`immich-runtime-isolation.ps1`](../tests/phase2/immich-runtime-isolation.ps1) 与
[`immich-external-library-runtime.ps1`](../tests/phase2/immich-external-library-runtime.ps1)
可分别表述为 `RUNTIME_TESTED` 的隔离启动和可丢弃 external-library lifecycle。
[`immich-gateway-bridge-runtime.ps1`](../tests/phase2/immich-gateway-bridge-runtime.ps1)
进一步验证了真正的 `Piwigo Gateway → isolated bridge → Immich` metadata path；它仍
绝不是 `BROWSER_E2E_TESTED`，也没有公开 Immich Web 或媒体端点。

## Canonical ClassArchivePhoto

`ClassArchivePhoto` 是唯一的公开照片身份：RFC 4122 v4 UUID。它不是
Piwigo `image_id`，也不是未来 Immich `asset_id`。

`class_identity_photo` 使用以下受控内部映射：

```text
class_photo_id (opaque UUID)
    -> piwigo_image_id (internal, nullable only while PENDING)
    -> immich_asset_id (internal, nullable)
    -> media_checksum + media_reference (internal reconciliation provenance)
```

映射状态为：

- `PENDING`：仅关联 Family Submission；只允许 `SYSTEM_ADMIN`。
- `ACTIVE`：有且仅有一个 Piwigo image target，可供 Gateway 投影。
- `STALE` / `RETIRED`：不会被任何 Gateway 列表、计数或聚合投影。

如果一个已激活映射的文件校验和或安全存储引用改变，服务会先把该行
标记为 `STALE`，再拒绝继续使用它；不会把原 UUID 静默重绑定到不同媒体。
Piwigo 的 MyISAM 图片表不能使用 InnoDB FK，因此该外部关系由 Gateway
读取时的 checksum/path 验证与 Reconciliation 共同核对。

Family 投稿在写入 `PENDING` submission 时创建私有映射；审核通过后在
现有 Piwigo 上传管线创建图片、关联相册和 Archive metadata 的同一
ClassIdentity 事务中提升为 `ACTIVE`。拒绝则移除未发布的 PENDING 映射，
但保留 Submission 与 Audit 历史。

该映射不负责文件传输，也不产生新的静态 URL。

## Adapter 边界

```text
ClassIdentityAdapter
    -> 已认证 ClassIdentity Principal（只投影 role）

PiwigoGatewayAdapter
    -> Piwigo/Archive 的内部候选照片
    -> 校验原图、checksum、ClassArchivePhoto 映射

GatewayPolicy
    -> 先按 role + era + state 过滤

ImmichAdapter
    -> 只接收已过滤的 ClassArchivePhoto UUID 集合
    -> 返回候选成员关系，Gateway 重新计算可见计数
```

正常关闭状态下，`NullImmichAdapter` 表示 **Gateway→Immich bridge** 明确
`UNAVAILABLE`：它不会建立 socket、不会模拟 Immich 内容，也不会把空结果称为
Immich E2E。运行时 bridge gate 会短暂启用 `BridgeImmichAdapter`，只把已经通过
`GatewayPolicy` 的 canonical UUID 与内部 asset binding 交给固定内部 bridge；gate
finally 会撤销配置、删除 bridge credential 和 asset bindings，再将 Immich spike
复原为空状态。

## 当前同源只读 HTTP API

下列路由已经由 Piwigo Nginx 的严格同源 rewrite 绑定到
`GatewayHttpController`。Controller 只允许 `GET` / `HEAD`，解析固定 route/query allowlist，要求
每请求重新解析 ClassIdentity principal，并返回 private/no-store JSON；未知 principal、
映射、文件、来源或序列化状态均拒绝或以 generic 503 fail closed。它没有 CORS，也没有
任何 Immich proxy；唯一的 canonical media route 只会重新进入既有 MediaGuard / Nginx
X-Accel-Redirect 交付链。

| 路由 | 当前用途 |
| --- | --- |
| `GET /api/me` | 只返回业务 role；不返回 Account / Seat / Principal / Piwigo user id |
| `GET /api/photos` | 过滤后的卡片列表与重新计算的 `total` |
| `GET /api/photos/{id}` | opaque UUID 照片 metadata；隐藏与不存在统一为无结果 |
| `GET` / `HEAD /api/photos/{id}/media/{thumbnail\|preview\|original}` | UUID 只在服务端映射；先做 Gateway 可见性过滤，再由 MediaGuard 重做媒体授权，最后由 nginx internal X-Accel 传输 |
| `GET /api/timeline` | 过滤后再分组、再计算每组数量 |
| `GET /api/albums` | 过滤后再聚合相册数量 |
| `GET /api/search` | 过滤后才做匹配与返回数量 |
| `GET /api/people` | 只使用可见 UUID，交集后重算每个人的照片数 |
| `GET /api/memories` | 只使用可见 UUID，交集后重算每条回忆的照片数 |

API public projection 不包含：`piwigo_image_id`、`immich_asset_id`、
`media_checksum`、`media_reference`、Piwigo category/user id、ClassIdentity
principal/account/seat/identity id。

当前 Piwigo adapter 只投影已关联 Piwigo 的 `ACTIVE` 照片。Family 投稿的 `PENDING`
记录继续由独立的 SYSTEM_ADMIN 投稿审核页处理；它们不因为 Gateway 路由而向任何 Seat
公开。`PENDING -> SYSTEM_ADMIN only` 的 policy 分支已由合约测试覆盖，未来将 Pending
候选交给 Gateway 时也必须保留该约束。

## 强制 ACL 与侧信道规则

| Principal | HERITAGE | LIVING | PENDING |
| --- | ---: | ---: | ---: |
| `CLASSMATE` | allow | allow | deny |
| `TEACHER` | allow | allow | deny |
| `ANONYMOUS` | allow | allow | deny |
| `FAMILY` | allow | deny | deny |
| `SYSTEM_ADMIN` | allow | allow | allow |
| 缺失 / 异常 | deny | deny | deny |

Gateway 的 Adapter 接口没有“未经授权 aggregate count”方法。每一种
`total`、timeline group、album、search、People、Memories 都先调用同一个
`GatewayPolicy::filterVisible()`，再计算数值。故 FAMILY 不会因 LIVING
出现于原始候选集而获得缩略图、asset id、搜索命中、人物计数或相册数量。

## 媒体交付边界

public projection 仍只返回 `MEDIAGUARD_REQUIRED`，不返回 Piwigo image id、
Immich asset id、路径或后台 byte URL。客户端若要显示媒体，只能请求
`/api/photos/{opaque-uuid}/media/{thumbnail|preview|original}`。该入口先在
`GatewayService` 中把 UUID 与当前可见候选匹配，再将私有 Piwigo id 仅在服务端交给
`ClassArchiveMediaGuard::resolveCanonicalDelivery()`；MediaGuard 仍重验 physical-path
唯一性、Community Pending、Era、principal、Piwigo ACL 与 original policy，成功后仅发
internal `X-Accel-Redirect`。UUID 不是授权凭据，已注销、冻结、撤权或无权限角色的旧 URL
均会重新被拒绝；Immich asset endpoint 永远不参与交付。

## 运行与测试

```powershell
.\infra\scripts\dev.ps1 test-phase2-contract
```

该命令仅运行以下本地测试：

- `CLASS_ARCHIVE_PHOTO_SCHEMA=PASS`：锁定 MariaDB 11.8.8 semantic digest。
- `CLASS_ARCHIVE_PHOTO_MAPPING=PASS`：临时、精确清理的 MariaDB 映射测试。
- `GATEWAY_CONTRACT=PASS`：synthetic Adapter policy/aggregation/redaction 测试。

它不启动 Immich、不访问公网、不上传真实图片，也不构成 Runtime 或 Browser
验收。

```powershell
.\infra\scripts\dev.ps1 test-phase2-gateway-http
```

此门是独立的 `RUNTIME_TESTED` 证据：真实 Piwigo + MariaDB + HTTP session 下验证
`CLASSMATE` / `TEACHER` / `ANONYMOUS` 可见两个 Era、`FAMILY` 只见 HERITAGE，并对
列表 total、单图 UUID、Timeline、Albums、Search、People、Memories、重复 query、跨域
Origin、方法和 DTO 敏感字段，以及 canonical thumbnail / preview / original、HEAD、Range、
隐藏 LIVING 与 Guest 媒体拒绝运行 37 次请求 / 631 个断言。它明确输出
`IMMICH_ADAPTER=UNAVAILABLE_NOT_SIMULATED`，所以绝不构成 Immich Adapter、Immich
Web 或浏览器 E2E 证据。

已启动时可单独运行：

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime
```

该门只检查隔离 runtime，不能替代上面的 Gateway contract 或未来的真实 Immich
Adapter / Browser 集成。

```powershell
.\infra\scripts\dev.ps1 test-phase2-runtime-integration
```

该生命周期门短暂创建一个仅限 internal network 的 Immich technical user 和一座只读
external library，扫描 synthetic originals 后销毁并重建仅属于 spike 的 volumes。它不保留
technical credentials、library 或 asset，也不把 Gateway 的 `NullImmichAdapter` 变成已实现
的 runtime adapter。

```powershell
.\infra\scripts\dev.ps1 test-phase2-immich-gateway-bridge
```

该门是 `RUNTIME_TESTED` 的实际 bridge integration：它重置 spike、建立一次性 internal
technical user 和 read-only external library、只绑定两张现有 synthetic canonical photos，
再以真实 Classmate 与 Family HTTP session 调用 `/api/memories` 和 `/api/people`。同一
Immich memory 对 Classmate 聚合为 2 张、对 Family 只聚合为 1 张；所有 Piwigo image id、
Immich asset id、storage reference、checksum 与媒体 URL 都必须不出现在 public DTO。它还
断言 `/api/media` 不存在、bridge 没有 host port、bridge 不挂载 Piwigo originals，并在
finally 验证 Immich state 为空、Piwigo original SHA-256 不变。最近一次结果为 651 项断言、
5 个 HTTP probes 通过。
