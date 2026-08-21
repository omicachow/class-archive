# Phase 2 Gateway 性能基线

当前指标分为两类，不能混写：

- `CONTRACT_TESTED`：真实 PHP Gateway/Policy/People/Search projection 代码的内存合成
  候选集；不启动 Immich、不读 Piwigo DB、不交付媒体字节；
- `RUNTIME_TESTED`：现有 72 张 canonical synthetic Piwigo 基线的 localhost HTTP/BFF
  回归。它证明协议和 ACL，不足以声称 5k 或 20k HTTP 性能。

## 本机 contract 基准（2026-08-22）

执行：

```powershell
.\infra\scripts\dev.ps1 test-phase2-performance-contract
```

Family policy 先过滤 LIVING 后的 P50 / P95 / Max（毫秒）：

| assets | projection | P50 | P95 | Max |
| ---: | --- | ---: | ---: | ---: |
| 5,000 | `/photos` equivalent | 1.954 | 2.671 | 2.671 |
| 5,000 | `/timeline` equivalent | 2.288 | 2.728 | 2.728 |
| 5,000 | `/albums` equivalent | 1.208 | 1.341 | 1.341 |
| 5,000 | `/people` count/cover projection | 10.061 | 12.408 | 12.408 |
| 5,000 | smart-search result re-projection | 1.100 | 1.320 | 1.320 |
| 20,000 | `/photos` equivalent | 5.769 | 10.395 | 10.395 |
| 20,000 | `/timeline` equivalent | 9.462 | 16.689 | 16.689 |
| 20,000 | `/albums` equivalent | 4.153 | 5.326 | 5.326 |
| 20,000 | `/people` count/cover projection | 46.143 | 69.382 | 69.382 |
| 20,000 | smart-search result re-projection | 5.086 | 6.121 | 6.121 |

Peak PHP process memory was about 12 MiB for 5k and 44 MiB for 20k. The
benchmark intentionally returns only canonical UUID candidates and recomputes counts after policy filtering.

## 不能从这张表得出的结论

它不是：Piwigo/MariaDB real-query latency、Nginx throughput、thumbnail first paint、Chrome
scroll、Immich ML index time、People clustering latency，或 20k physical originals 的验收。

生产前的 runtime scale fixture 必须建立 5k/20k **distinct synthetic physical originals**；不能
让多个 Piwigo image row 指向同一个路径来追求快，因为 Gateway/MediaGuard 正确地将该状态
视为 source ambiguity 并 fail closed。该独立 fixture 目前尚未运行，因此：

```text
PERFORMANCE_5K=CONTRACT_TESTED_NOT_RUNTIME
PERFORMANCE_20K=CONTRACT_TESTED_NOT_RUNTIME
```
