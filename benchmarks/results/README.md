# Recorded benchmark results

These local runs are observations, not a comparison with Doctrine or a production performance guarantee. Commands below run from the repository root.

## Association batch fetching

**100 orders, 100 distinct lazy targets: 101 SELECTs become 5 with a batch size of 32.** Both runs return the same data and retain the same 200 managed entities.

Measured on **PHP 8.4.27, PostgreSQL 17.11 (Homebrew), macOS**, with a local database connection. SQL counts include the initial owner query; wall time includes fetching and hydration. Memory values are PHP heap deltas during loading, not process RSS.

| Batch size | SELECTs | Wall time | Retained heap | Peak heap delta |
| --- | --- | --- | --- | --- |
| 1 | 101 | 11.679 ms | 482.0 KiB | 667.0 KiB |
| 32 | 5 | 3.035 ms | 296.9 KiB | 639.9 KiB |

This is one run per batch size, in the order 1 then 32. Warm-up and allocation effects differ between runs. The query reduction is verified; timing and memory values are observations, not a production performance guarantee or a comparison with Doctrine.

## Statistics overhead

A separate `SELECT 1` microbenchmark runs **200 queries per round, 7 rounds per mode**, including result fetching. Each connection is warmed up, and measurement order rotates between modes.

| Statistics mode | Median total time / 200 queries | Observed round range |
| --- | --- | --- |
| Disabled | 6.260 ms | 5.590–11.199 ms |
| Counters enabled | 6.335 ms | 5.299–6.577 ms |
| Counters + empty observer | 6.334 ms | 5.217–6.744 ms |

The observed differences overlap run-to-run variation. These measurements do not establish a fixed overhead percentage for application workloads. The empty observer measures event delivery without an exporter; it does not represent Prometheus or OpenTelemetry export costs.

## Bulk writes

Measured on **PHP 8.4.27, DBAL 4.5.0, PostgreSQL 17.11 (Homebrew), macOS arm64**, using a local connection and a 1 GiB PHP memory limit. Three measured rounds per case after warm-up; the table shows median milliseconds. L2 is disabled and Statistics counters are enabled.

| Rows | Operation | UnitOfWork | Bulk BYPASS | Bulk CLEAR |
| --- | --- | --- | --- | --- |
| 1,000 | INSERT | 52.00 ms | 6.18 ms | 6.13 ms |
| 1,000 | UPDATE | 68.41 ms | 2.00 ms | 1.99 ms |
| 1,000 | DELETE | 57.78 ms | 0.44 ms | 0.73 ms |
| 10,000 | INSERT | 493.19 ms | 59.66 ms | 64.71 ms |
| 10,000 | UPDATE | 687.82 ms | 15.75 ms | 16.57 ms |
| 10,000 | DELETE | 560.56 ms | 2.05 ms | 2.23 ms |
| 100,000 | INSERT | 5,316.99 ms | 1,057.47 ms | 1,250.96 ms |
| 100,000 | UPDATE | 7,199.46 ms | 282.42 ms | 330.56 ms |
| 100,000 | DELETE | 5,586.01 ms | 29.59 ms | 53.41 ms |

Each sample commits one transaction. UnitOfWork persists/removes entities and flushes once; UPDATE/DELETE include target loading. Bulk INSERT explicitly uses 1,000-row chunks; UPDATE/DELETE use one statement. SQL counts at 100,000 rows are **100,002 / 100,003 / 100,003** for UnitOfWork INSERT/UPDATE/DELETE (including flush savepoints), versus **100 / 1 / 1** for either bulk policy.

All cases start with 1,000 unrelated managed entities; UPDATE/DELETE also preload 1,000 affected entities. BYPASS retains that context and stale objects; CLEAR detaches it. Setup and correctness checks are outside timing. At 100,000 inserted rows, the median additional peak PHP heap was **227.4 MiB with UnitOfWork and 1.34 MiB with either bulk policy**. These are heap deltas after preloading, not total process memory.

Bulk skips entity hydration, dirty checking and per-row callbacks; these workflows have different semantics. BYPASS/CLEAR timing differences include run-to-run variation and do not isolate the cost of clearing. No production throughput or Doctrine comparison is implied. [Methodology and options](../README.md) · [All samples, throughput and memory](bulk-2026-11-05.json).

## Entity L2: local Redis measurements

Measured on PHP 8.4.27, PostgreSQL 17.11 and Redis 8.10.3 on macOS, with local connections. Each case loads **100 distinct targets**, using a fresh EntityManager; five measured rounds follow a warm-up. Mixed mode primes the first 50 IDs. Medians include cache access and hydration; setup/cache priming are excluded.

| Workflow | L2 disabled | Cold L2 | Warm L2 | 50% warm L2 |
| --- | --- | --- | --- | --- |
| 100 find-by-ID calls | 13.68 ms | 33.74 ms | 10.74 ms | 25.01 ms |
| 100 lazy ManyToOne targets, batch size 32 | 3.47 ms | 25.67 ms | 11.12 ms | 19.45 ms |

SQL counts in the same order are **100 / 100 / 0 / 50** for find-by-ID and **5 / 5 / 1 / 4** for ManyToOne (including the owner SELECT). Warm reads record 100 L2 hits; mixed reads record 50 hits and 50 misses.

Updating 100 already-managed entities and committing took **16.04 ms without L2** and **31.73 ms with INVALIDATE**, which records 200 pre/post-commit eviction events. Both execute 100 UPDATE statements. The timed interval excludes loading, initial cache priming and post-update refill; correctness checks verify fresh cold and warm reads afterwards.

**Fewer SQL statements do not guarantee lower latency.** Here, batched PostgreSQL reads outperform even warm Redis L2; cache round trips and hydration still cost time. Cold/mixed cache paths and write invalidation add overhead. These small local rows and local services do not represent expensive queries, networked databases or production throughput. [Methodology](../README.md) · [All samples and counters](l2-2026-11-05.json).

Reproduce against a dedicated test database:

```sh
PGUSER=your_role PGDATABASE=modernorm_test php benchmarks/batching.php
PGUSER=your_role PGDATABASE=modernorm_test php benchmarks/statistics.php
PGUSER=your_role PGDATABASE=modernorm_test php -d memory_limit=1G benchmarks/bulk.php > bulk-results.json
PGUSER=your_role PGDATABASE=modernorm_test MODERNORM_BENCH_REDIS_DSN=redis://127.0.0.1:6379 php benchmarks/l2.php > l2-results.json
```

The scripts emit JSON with environment and measurement details. Adjust `MODERNORM_BENCH_ROWS`, `MODERNORM_BENCH_ITERATIONS` and `MODERNORM_BENCH_ROUNDS` as needed. Bulk options and measurement boundaries are documented in [benchmarks](../README.md). [Verification report](../../docs/beta-verification.md).
