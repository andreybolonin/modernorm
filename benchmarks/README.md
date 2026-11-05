# Benchmarks

Run against a dedicated disposable PostgreSQL database, using PHP 8.4+ and installed Composer dependencies. Connection settings use `PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD` and `PGDATABASE`; the default database is `modernorm_test`. Scripts create randomly named schemas and remove them in `finally`. An interrupted/killed process may require manual cleanup of its benchmark schema.

```sh
PGUSER=your_role PGDATABASE=modernorm_test php benchmarks/batching.php
PGUSER=your_role PGDATABASE=modernorm_test php benchmarks/statistics.php
PGUSER=your_role PGDATABASE=modernorm_test php -d memory_limit=1G benchmarks/bulk.php > bulk-results.json
```

JSON goes to stdout; bulk progress goes to stderr. There are no timing thresholds, and these scripts do not compare ModernORM with Doctrine.

## Bulk writes

`bulk.php` measures INSERT, UPDATE and DELETE through UnitOfWork (`uow`), bulk `BYPASS` and bulk `CLEAR`. Defaults:

| Setting | Environment variable | Default |
| --- | --- | --- |
| Row counts | `MODERNORM_BENCH_SIZES` | `1000,10000,100000` |
| Measured rounds per case | `MODERNORM_BENCH_ROUNDS` | `3` |
| Explicit bulk INSERT chunk size | `MODERNORM_BENCH_CHUNK_SIZE` | `1000` |
| Preloaded context rows | `MODERNORM_BENCH_CONTEXT_SIZE` | `1000` |

Each case warms up once with at most 1,000 target rows. The order of all nine operation/mode cases rotates between rounds. Every sample starts with an identical freshly seeded table in a private schema, with an integer primary key and two scalar fields. IDs are assigned by the application; there are no associations, callbacks, generated IDs, RETURNING rows or L2 cache. Statistics counters are enabled for all cases.

Before timing, the EntityManager loads 1,000 unrelated rows. UPDATE/DELETE also preload up to 1,000 affected rows. Both preparation and database-result checks are outside the timed interval. BYPASS must retain the original managed count and stale target values; CLEAR must empty the entire context. PHP references to preloaded objects stay alive in every mode: detaching objects does not imply freeing all their memory.

Timing includes one outer transaction and its commit, input/entity construction, the operation and policy handling. UoW UPDATE/DELETE include loading the full target set; UoW INSERT persists all target objects and performs a single flush. Bulk INSERT explicitly calls the API in chunks within the same outer transaction; UPDATE/DELETE each use one statement. CLEAR runs on every call, so the first INSERT chunk clears the populated context and later chunks clear an empty context. The API does not provide automatic chunking.

SQL counts include UoW's nested SAVEPOINT/RELEASE statements and the target SELECT for UoW UPDATE/DELETE, but exclude driver begin/commit calls. Setup and validation SQL are excluded. Validation checks affected bulk row counts, final row counts, ID sums, values, preserved unrelated rows and UnitOfWork state.

The output includes every sample, median/min/max time, median rows/second, managed counts, and PHP peak/retained heap deltas. Heap deltas start after preloading and are not total process memory or RSS. Table size, data shape, constraints, network latency, transaction durability, allocator state and database cache state affect results. No operating-system or PostgreSQL cache reset is attempted. Bulk and UoW have different lifecycle semantics; these results measure these specific workflows, not equivalent callback/cascade processing.

## Existing read benchmarks

`batching.php` accepts `MODERNORM_BENCH_ROWS` (default 100) and compares lazy loading with batch sizes 1 and 32, including hydration and checked SELECT counts.

`statistics.php` accepts `MODERNORM_BENCH_ITERATIONS` (default 200) and `MODERNORM_BENCH_ROUNDS` (default 7), rotates disabled/counters/empty-observer cases, and reports per-round memory and timing.

## Recorded bulk run — 2026-11-05

[Raw JSON](results/bulk-2026-11-05.json) contains all 81 measured samples (27 cases × 3 rounds), with PHP 8.4.27, DBAL 4.5.0, PostgreSQL 17.11 (Homebrew), macOS arm64, a local connection and a 1 GiB PHP memory limit. Default sizes, chunk size and context size were used. The root README contains the median timing table; use the JSON for round ranges, throughput and heap deltas.

## Entity L2

```sh
PGUSER=your_role PGDATABASE=modernorm_test \
MODERNORM_BENCH_REDIS_DSN=redis://127.0.0.1:6379 \
php benchmarks/l2.php > l2-results.json
```

`l2.php` uses `MODERNORM_BENCH_ROWS` (default 100, range 2–5,000) and `MODERNORM_BENCH_ROUNDS` (default 5, range 3–25). The Redis DSN falls back to `MODERNORM_TEST_REDIS_DSN`. It creates a random PostgreSQL schema and a matching private RedisAdapter namespace. Namespace clearing is restricted to that owned namespace and excluded from timing. Use a dedicated test database/cache service. RedisAdapter uses finite connection/read timeouts and zero pool default lifetime; the entity payload TTL is 3,600 seconds.

The eight read cases combine find-by-ID or lazy ManyToOne (batch size 32) with disabled/cold/warm/mixed L2. Each owner references a distinct flat target. Mixed mode primes the first half of ascending IDs; the distribution matters because an all-hit batch needs no target SELECT, while a mixed batch still queries its misses. Each sample uses a fresh EntityManager and preloaded metadata; cache priming uses a separate manager so Identity Map cannot fake L2 hits. Read timing includes lookup, SQL where needed and hydration; database/cache setup and checksum verification are excluded.

The two UPDATE cases start with all targets already managed and, when enabled, all payloads primed. One flush performs 100 individual UPDATEs by default and one owned commit. Timing includes dirty checking and synchronous generation invalidation before/after commit, but excludes initial loading/priming and later refill. There is no outbox processing. SQL row values and fresh cold/warm reads are verified after timing. The expected eviction count is twice the target count; this is an ORM event count, not the number of Redis commands.

All ten cases warm up once, and measured order rotates. Statistics and cache counters are enabled. Output contains median/min/max milliseconds, all samples, SQL/hit/miss/put/eviction counts and PHP heap deltas. No timing thresholds or cross-ORM claims are used. The single benchmark process reuses a PostgreSQL connection and Redis client while rebuilding persistence contexts; this is not a PHP-FPM connection-startup or distributed-load benchmark.

[Recorded run — 2026-11-05](results/l2-2026-11-05.json) contains 50 measured samples. With these small local rows, batched SQL was faster than Redis L2 even when warm. Use L2 measurements to decide where caching pays off rather than assuming that a hit is always faster than SQL.
