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
| 5,000 | `/photos` equivalent | 2.346 | 4.187 | 4.187 |
| 5,000 | `/timeline` equivalent | 3.583 | 4.165 | 4.165 |
| 5,000 | `/albums` equivalent | 1.866 | 2.133 | 2.133 |
| 5,000 | `/people` count/cover projection | 14.450 | 17.105 | 17.105 |
| 5,000 | smart-search result re-projection | 1.113 | 1.168 | 1.168 |
| 20,000 | `/photos` equivalent | 6.805 | 10.433 | 10.433 |
| 20,000 | `/timeline` equivalent | 15.457 | 20.931 | 20.931 |
| 20,000 | `/albums` equivalent | 7.253 | 8.352 | 8.352 |
| 20,000 | `/people` count/cover projection | 63.779 | 77.747 | 77.747 |
| 20,000 | smart-search result re-projection | 9.575 | 10.002 | 10.002 |

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
