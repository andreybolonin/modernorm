# Benchmarks

[Recorded results](results/README.md): batch query reduction, statistics overhead, bulk writes and entity L2 measurements.

## Flat SQL hydration plan

`flat-sql-hydration.php` measures the full managed hydration stage for ObjectHydrator and SimpleObjectHydrator: two primitive fields, twelve primitive fields (including private/protected/hooked properties), and an unsupported JSON control. It uses PostgreSQL `generate_series` without creating tables. SQL execution, metadata warming and validation are excluded; driver fetching, DBAL conversion, plan preparation, assignment, Identity Map and snapshots are included. Every entity's converted values/order and managed count are checked, as is constructor/hook bypass. New contexts are used each round, so query-plan preparation is measured rather than hidden in setup.

```sh
PGUSER=your_role PGDATABASE=modernorm_test \
php -d opcache.enable_cli=1 benchmarks/flat-sql-hydration.php > flat-sql-results.json
```

Use `-d extension=/path/to/deepclone.so` only if the matching extension is not already enabled. `MODERNORM_BENCH_ROWS` defaults to 20,000 and `MODERNORM_BENCH_ROUNDS` to 9 (minimum 3), after two warmups per case. Output contains timing and incremental PHP peak-heap samples; these are not process RSS. There are no CI timing thresholds. The [recorded before/after comparison](results/flat-sql-plan.md) includes PHP 8.4/8.5 and an extension-free control.

## Experimental deepclone scalar hydration

`deepclone.php` compares an explicit accessor-only baseline with a benchmark-only adapter using [Symfony's deepclone extension](https://github.com/symfony/php-ext-deepclone), or with the actual `EntityHydrator::hydrateScalars()` (`orm` mode). It requires no database server. The explicit baseline remains independent of automatic extension detection in production hydration.

Build the extension in a temporary directory with the `phpize` and `php-config` belonging to the PHP binary being measured. Do not reuse a shared library built for another PHP minor version. For example:

```sh
git clone https://github.com/symfony/php-ext-deepclone.git /tmp/modernorm-deepclone-src
cd /tmp/modernorm-deepclone-src
git checkout 12285e5f6f774b0bbb4f0ea26056f45e0ed0fc74
phpize
./configure --enable-deepclone --with-php-config="$(command -v php-config)"
make -j4
```

From the ORM repository, run all three modes with identical PHP settings:

```sh
php -d opcache.enable_cli=1 benchmarks/deepclone.php accessors > without-extension.json
php -d opcache.enable_cli=1 -d extension=/tmp/modernorm-deepclone-src/modules/deepclone.so benchmarks/deepclone.php accessors > accessors.json
php -d opcache.enable_cli=1 -d extension=/tmp/modernorm-deepclone-src/modules/deepclone.so benchmarks/deepclone.php deepclone > native.json
php -d opcache.enable_cli=1 -d extension=/tmp/modernorm-deepclone-src/modules/deepclone.so benchmarks/deepclone.php orm > production.json
```

The second mode controls for loading the extension itself. No `php.ini` changes are needed. `MODERNORM_BENCH_ROWS` defaults to 20,000 and `MODERNORM_BENCH_ROUNDS` to 9 (minimum 3), following two warmups. Each invocation runs in a separate process. Metadata and the experimental native field plan are prepared before timing; the production plan warms during warmups, with runtime eligibility checks included in measured rounds. Filtering unmapped input, mapping inherited private field names, constructing per-row native argument arrays and consuming readonly snapshot corrections remain inside timing. New-object cases use the same `ClassMetadata::newInstance()` on both paths, isolating assignment rather than combining it with the extension's class-instantiation API.

Cases cover new flat entities, already populated entities, precreated native lazy ghosts, and new/existing entities with an embedded object and a readonly date. Flat entities include JSON arrays, dates, a backed enum, inherited private/protected fields and a property hook. Embedded and readonly assignments retain ORM accessors in the experimental adapter; native batches are flushed before these fields to preserve assignment order. Equivalent readonly dates retain their original object. Every round verifies all mapped values, ignored unmapped input, bypassed hooks and lazy state outside timing. Lazy objects retain one unassigned property so PHP does not automatically consider them fully initialized after assignment.

Peak and retained memory are PHP heap deltas after metadata/input setup, measured with `memory_reset_peak_usage()`. Existing entities and lazy ghosts are allocated before the measurement; new entities are retained until validation, so their heap is included. These values are not RSS or the extension's startup memory. No SQL, DBAL conversions, identity map, association hydration, full UnitOfWork snapshots or lifecycle events are timed. This measures only the scalar stage. The experimental adapter is not the production implementation: production restricts the fast path to supported flat mappings, retaining accessor hydration for embedded/readonly/custom fields, enum arrays and uninitialized lazy entities. Use `orm` mode to measure those guards and fallbacks, rather than extrapolating the initial adapter's results.

[Recorded PHP 8.4/8.5 comparison](results/deepclone.md).

### Further deepclone candidates

`deepclone-candidates.php` tests three extensions of the existing fast path: fused flat creation/assignment, writable embedded batching (one/eight fields, new/existing), and initial readonly assignment (one/six readonly fields). Its baseline is the current production `EntityHydrator`, with deepclone already enabled for flat entities. It needs no database server and changes no production code.

```sh
php -d opcache.enable_cli=1 -d extension=/tmp/modernorm-deepclone-src/modules/deepclone.so benchmarks/deepclone-candidates.php orm > candidates-orm.json
php -d opcache.enable_cli=1 -d extension=/tmp/modernorm-deepclone-src/modules/deepclone.so benchmarks/deepclone-candidates.php candidate > candidates-native.json
php -d opcache.enable_cli=1 -d extension=/tmp/modernorm-deepclone-src/modules/deepclone.so benchmarks/deepclone-candidates.php guarded > candidates-guarded.json
```

Use a separately built shared library for each PHP minor version, as above. Defaults are 20,000 entities, two warmups and nine measured rounds; `MODERNORM_BENCH_ROWS` and `MODERNORM_BENCH_ROUNDS` override them. `orm` and `candidate` cover eight scenarios, while `guarded` covers the three readonly scenarios only. Every timed result retains new entities until validation. Existing entities and an embedded-identity control list are prepared before timing. Field plans, metadata and input values are warmed outside timing; per-row filtering, argument/chunk construction, readonly checks/corrections, object creation and embedded attachment are timed. All values and object identities are verified outside timing, including constructor/hook bypass, ignored unmapped input, existing embedded identity and equivalent readonly-date snapshot identity.

The flat split/fused prototypes use identical argument preparation. Compare those two prototype cases to isolate creation fusion: comparing fused creation with the production baseline also includes differences in mapping/eligibility plumbing. Embedded batching preserves contiguous input groups, attaches a missing embedded object before writing its fields, and retains existing objects. The initial readonly prototype emits corrections only after its native batch completes; its error-order probe deliberately exposes that incompatibility. `guarded` adds a generator, initialization/type checks and accessor fallback before writing, preserving the characterized partial-failure corrections. Fixed fixtures use named types and no enum conversion, nested embedded graphs, readonly children, custom accessors or referenced input values; these prototypes are not general production adapters.

Additional untimed probes check actual UnitOfWork registration after a failed assignment versus combined creation, readonly corrections before a later type failure (both naive and guarded), and interleaved embedded/root writes before a later error. Expected incompatibilities are recorded as `false` in `compatibility_probes`; unexpected outcomes throw. Peak/retained memory values are incremental PHP heap bytes, not total process memory or RSS. There are no performance thresholds or end-to-end ORM claims.

[Recorded candidate measurements and recommendations](results/deepclone-candidates.md).

The [production embedded comparison](results/deepclone-embedded.md) measures the implemented batching against the pre-change EntityHydrator and EmbeddablePropertyAccessor. It includes runtime eligibility checks, the eight-field threshold and all fallback plumbing.

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

[Raw JSON](results/bulk-2026-11-05.json) contains all 81 measured samples (27 cases × 3 rounds), with PHP 8.4.27, DBAL 4.5.0, PostgreSQL 17.11 (Homebrew), macOS arm64, a local connection and a 1 GiB PHP memory limit. Default sizes, chunk size and context size were used. The [recorded results](results/README.md#bulk-writes) contain the median timing table; use the JSON for round ranges, throughput and heap deltas.

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

## Hydration

```sh
PGUSER=your_role PGDATABASE=modernorm_test php -d opcache.enable_cli=1 benchmarks/hydration.php > hydration-results.json
```

`hydration.php` uses PostgreSQL `generate_series` and literal JSON/date values; it creates no tables or schemas. Each scenario returns 10,000 driver rows: roots without a join, managed collection fetch joins with 1/10/100/1,000 children per root, an empty LEFT JOIN result shape, and a flat entity array projection without joins (`array-root`). The mapping includes JSON, two immutable dates and, for joined scenarios, an integer foreign-key meta column. Statistics and L2 are disabled. The former `array-1:100` scenario was removed with nested array graph hydration; its historical results are not comparable to `array-root`.

Each case warms up twice, then measures `MODERNORM_BENCH_ITERATIONS` iterations (default 15, minimum 3). Every iteration uses a fresh EntityManager and hydrator, with metadata warmed before timing. The timed interval includes PDO row fetching, conversions and hydration; SQL execution and result checks are excluded. Root counts, the first collection size and every flat-array row's identifier/JSON/date types are checked outside timing. JSON contains sorted timing/peak-heap samples and medians; heap deltas exclude setup and are not total process memory or RSS.

For a before/after comparison, run the same script, PHP settings and installed dependencies against both source revisions, sequentially without competing test workloads. The [recorded comparison](results/README.md#hydration) uses two sequential before/after pairs. Timing is observational and has no CI threshold; this is not a Doctrine comparison or an end-to-end application benchmark.
