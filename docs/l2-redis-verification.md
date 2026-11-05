# L2 generation audit and Redis process verification

## Scope and evidence

On 2026-11-05, the focused L2/bulk suites passed on PHP 8.4.27, PostgreSQL 17.11, Symfony Cache 7.4.22, ext-redis 6.2.0 and a standalone Redis 8.10.3 on macOS. Result: **140 tests, 10,924 assertions**, including **1,000 UPDATE/read/warm-hit cycles** in separate long-lived PHP processes. This is a finite correctness run, not a duration-based soak, memory-growth measurement, failover certification or throughput benchmark.

GitHub Actions pins Redis 8.10.3 for both the service and disposable recovery/endurance servers in all PHP 8.4/8.5 and dependency jobs. The disposable binary is built from the official tag archive with a pinned SHA-256 and cached; its version is printed in the job log. The default repeated-update scenario uses 25 cycles. Configuration is not evidence that the new CI revision has passed; record its run after publication.

The Redis process suite checks:

- A precommit reader fills the old database value after the writer's preliminary eviction; successful post-commit eviction makes that payload unreachable.
- Independent warm hits and cold SQL reads after UPDATE, DELETE, reinsert and bulk UPDATE/DELETE.
- A transaction reads its private SQL state without publishing it; rollback preserves the shared cached value.
- Two writers preserve optimistic version checking; a stale writer cannot publish its rejected state.
- An injected cache save failure before UPDATE leaves the database unchanged and closes the writer's manager.
- An injected generation save failure after commit reports `databaseCommitted=true`, closes the manager, and requires explicit eviction without replaying SQL. The test deliberately observes the old precommit payload before recovery.
- Loss of region or per-entry generation keys, while payloads survive, does not revive the old payload. A subsequent late publication on the old ticket remains unreachable.
- Two processes initialize missing generation metadata concurrently. A delayed initializer can cause a safe miss, but cannot make the other process's invalidated payload reachable.

Each worker has its own EntityManager, PostgreSQL connection, Redis client and adapter. JSON pipe barriers establish interleavings without timing sleeps. IPC waits are bounded. Each test owns a random PostgreSQL schema and Redis adapter namespace; cleanup never uses FLUSHDB/FLUSHALL.

## Actual outage, restart and process-death checks

The next local slice passed **6 tests, 119 assertions** on the same PHP/Redis stack. The combined generation/concurrency/recovery run passed **27 tests, 602 assertions** with the default 25 repeated-update cycles. `RedisL2RecoveryTest` starts an owned server on a temporary port and directory for every test. It verifies:

- Killing Redis closes existing TCP sockets. A cache read fails explicitly instead of returning an absent entity. Restarting without persistence and constructing a fresh client/manager refills from current SQL, then produces a warm hit.
- Killing Redis before a cached UPDATE fails generation invalidation, closes the writer's manager and leaves the database row/version unchanged.
- Suspending Redis with SIGSTOP leaves TCP sockets open but stops replies. Finite client read timeouts produce an explicit cache failure within the bounded IPC deadline. After SIGCONT, a fresh client/manager can read again.
- Killing Redis after a precommit reader fills an old row, then letting PostgreSQL commit, produces `CacheSynchronizationException` with `databaseCommitted=true`. The RDB saved before the outage restores stale payloads; a new namespace and fresh context recover the committed row without replaying UPDATE.
- Even after successful UPDATE and invalidation, restoring a snapshot taken before the write can revive old payload addresses. Namespace rotation recovers the committed state.
- Killing the PHP writer after the physical PostgreSQL COMMIT and before final invalidation leaves the precommit reader's old payload reachable. Explicit eviction recovers the new row/version. No exception can be delivered by the dead writer.

Redis is stopped with SIGKILL to avoid implicit shutdown saves; snapshots are explicit SAVE operations. This tests standalone RDB rollback, not Sentinel/Cluster promotion, Redis replication or all network partition topologies. Recovery uses explicit fresh clients/contexts, not a promise of transparent reconnect on an existing adapter. The SIGSTOP case does not simulate a one-way partition.

Test clients use a 0.2-second connection timeout and read timeout. Application timeouts must be explicitly chosen for their latency budget: an unbounded read timeout cannot establish bounded failure handling. Symfony Cache accepts these options at client creation:

```php
$client = RedisAdapter::createConnection($dsn, [
    'timeout' => 1.0,
    'read_timeout' => 1.0,
    'retry_interval' => 0,
]);
$pool = new RedisAdapter($client, 'my_app_storage', 0);
```

A snapshot restore or uncertain backend recovery must be coordinated across all application instances before cache use resumes. Restarting only one worker with a new namespace does not update the others.

## TTL, memory pressure and concurrent endurance

`RedisL2EnduranceTest` uses disposable Redis instances for actual expiry and memory pressure:

- A one-second payload TTL expires while zero-default-lifetime generation keys remain persistent. A fresh fill works after expiry.
- Zero payload TTL with a zero pool default lifetime creates a nonexpiring payload, but eviction still makes its old fill ticket unreachable.
- A nonzero pool default lifetime can expire generation keys while a longer-lived payload survives. Recreated generations and delayed old-ticket publication must remain safe misses. Production configuration should still use a zero pool default lifetime to avoid unnecessary metadata expiry.
- Under `volatile-lru`, real Redis maxmemory pressure evicts an expiring payload while retaining persistent generation keys.
- Under `allkeys-lru`, real pressure evicts generation metadata. Redis may also evict the payload in the same command; both outcomes are allowed. A surviving or subsequently republished old-ticket payload must remain unreachable. The tests verify Redis's eviction counter increased; they do not simulate eviction with DEL.

A separate timed workload uses one writer and four independent reader processes. It overlaps reads with each UPDATE, allowing either the preceding committed version or the newly committed version for those in-flight reads. A second read group starts only after commit/invalidation acknowledgement and must return the new row and version. Every worker samples PHP heap after GC and its managed-entity count; Redis key count, persistent-key count and used memory are sampled too.

The local 60-second run on 2026-11-05 completed **44,256 measured UPDATE cycles** after 20 warm-up updates, with no freshness assertion failures. The endurance suite at that revision passed 5 tests and 1,152,193 assertions. After adding the expiring-metadata case and extracting shared disposable-server setup, the combined generation/concurrency/recovery/endurance checks passed 33 tests and 52,530 assertions with the short default workload. [Raw report](evidence/l2-soak-2026-11-05.json):

| Observation | Recorded result |
| --- | --- |
| Sampled writer retained heap range after warm-up | 0 bytes |
| Largest sampled reader retained heap range after warm-up | 132,904 bytes (about 130 KiB) |
| Managed entities per worker | 1 |
| Sampled Redis key count during load | 31–1,086 |
| Sampled Redis used memory | 1,131,360–1,543,216 bytes |
| Persistent generation keys after warm-up / after load | 6 / 6 |
| Payload keys after quiescence and expiry | 0 |

There are six rather than two persistent keys because concurrent cold initialization can leave entry-generation keys belonging to superseded region generations. The fixed-ID workload verifies that this initial baseline does not keep growing. It does not establish a bound for increasing entity/ID cardinality, repeated class invalidation or repeated metadata loss. Those workloads still require a namespace retention/maintenance policy. Payload TTL does not garbage-collect persistent generation metadata.

These are sampled PHP heap values, not RSS or a proof of indefinite memory stability. The duration is a finite single-host correctness run, not production capacity or a throughput promise. The worker's test-only cache-write recorder is enabled only for the explicit generation-ticket probes, so the measurement does not accumulate artificial event history.

CI runs a three-second endurance workload by default and uploads `l2-soak.json`. Use `MODERNORM_L2_SOAK_SECONDS` (1–600) for a longer run and `MODERNORM_L2_SOAK_REPORT` to save JSON:

```sh
PGUSER=your_role PGDATABASE=modernorm_test \
MODERNORM_TEST_REDIS_SERVER=redis-server \
MODERNORM_L2_SOAK_SECONDS=60 \
MODERNORM_L2_SOAK_REPORT=/tmp/l2-soak.json \
php vendor/bin/phpunit tests/Integration/RedisL2EnduranceTest.php --fail-on-skipped
```

Expiry/eviction waits are bounded polling conditions; LRU tests first establish Redis's observable key-age gap. SCAN and maxmemory changes are restricted to the owned disposable test server. Production invalidation continues to use generation keys without scans.

## Generation audit

Generation values are fresh random 128-bit tokens, not counters that restart at zero. Missing metadata generates a new token; it does not recreate an earlier payload address. Region and entry generation keys are read from the shared pool rather than retained in a process-local generation cache.

Generation values now require the exact lowercase 32-hex-character format produced by the implementation. Invalid arrays, empty strings, arbitrary strings and wrong-length strings are replaced. Repeated malformed string values must not recreate a deterministic stale-payload address.

Concurrent initialization and rotation are last-writer-wins PSR-6 operations. A newly generated token may immediately become unreachable, causing additional SQL/misses. The protocol does not promise that every successful fill remains reachable or that only one reader loads SQL. Existing reads may finish with the value they already obtained.

Loss of metadata differs from restoration of an old cache snapshot: restoring previous generation tokens together with old payloads can restore stale addresses. After cache restore, rollback of Redis persistence, uncertain failover or an incompatible deployment, establish a new application L2 namespace before resuming cache use. All workers must receive that namespace and discard their old persistence contexts. Namespace rotation does not refresh L1 objects already held by workers.

## Running the focused checks

Use a dedicated PostgreSQL test database and a standalone Redis instance with zero pool default lifetime. The recovery suite additionally needs a local `redis-server` executable, `proc_open` and POSIX signal constants from ext-pcntl; these are test-only requirements. L2 remains optional; neither ext-redis nor Symfony Cache becomes a core runtime dependency.

```sh
PGUSER=your_role PGDATABASE=modernorm_test \
MODERNORM_TEST_REDIS_DSN=redis://127.0.0.1:6379 \
MODERNORM_TEST_REDIS_SERVER=redis-server \
MODERNORM_L2_STRESS_CYCLES=1000 \
php vendor/bin/phpunit \
  tests/Unit/ReadOnlyRegionTest.php \
  tests/Integration/RedisL2ConcurrencyTest.php \
  tests/Integration/RedisL2RecoveryTest.php \
  tests/Integration/RedisL2EnduranceTest.php \
  tests/Integration/ReadOnlyL2CacheTest.php \
  tests/Integration/UpdateL2CacheTest.php \
  tests/Integration/L2BatchFetchingTest.php \
  tests/Integration/BulkOperationsTest.php \
  --fail-on-skipped
```

The concurrency suite skips when the DSN is absent; recovery tests skip when `MODERNORM_TEST_REDIS_SERVER` is absent. Recovery tests own their Redis instances and do not stop the DSN's existing service. When configured, missing ext-redis, unavailable services, worker startup errors and timeouts fail the suite. CI supplies the DSN and retains `--fail-on-skipped`.

## Bounded v1 stability decision

The intended entity-L2 v1 feature set is complete enough to freeze: flat entities with scalar identifiers, READ_ONLY/INVALIDATE, owned ORM/bulk transaction boundaries, generation-based invalidation, supported batching and explicit failure/recovery outcomes. Collection/query caches, association graphs, caller-owned cached-write transactions, outbox delivery and automatic Redis failover are excluded; they are not prerequisites for this bounded v1.

Local evidence now includes concurrency, real Redis interruption/restart, TTL/eviction, a finite multi-reader workload and [L2 latency/counter measurements](../benchmarks/README.md). Those results support a stable candidate, not an automatic production-stable declaration. Latency measurements in particular do not establish correctness or uniform speedups.

Before publishing that claim, the exact revision still needs the updated Linux PHP 8.4/8.5 dependency/Redis CI matrix and clean-consumer checks to pass. Publish a tag for that tested commit and record supported adapter/version evidence and the remaining limitations. Previously published beta.1 CI does not certify these newer local changes. The overall package remains on its beta line until a separate release decision.

The consistency contract retains the commit/final-invalidation window and process-death risk. A finite payload TTL, coordinated recovery, external-writer invalidation and namespace retention are application responsibilities. The release does not promise strict cross-system atomicity, durable invalidation delivery or bounded metadata growth across arbitrary IDs/classes. No collection-cache or outbox implementation is required merely to rename this bounded scope stable.

## Recovery and remaining release gates

PostgreSQL and PSR-6 do not share an atomic commit. If invalidation fails after commit, stop the affected workflow, discard the EntityManager, recover the full affected cache write set and resume with a fresh context. Inspect the database first when commit outcome is unknown. Do not automatically retry the write.

If a worker dies after database commit but before final invalidation, it cannot report a recovery exception. A precommit reader's payload may remain reachable until TTL or explicit recovery. TTL is only a finite bound when the entity actually has a finite payload TTL. A durable outbox or another database-coordinated invalidation mechanism is required if eventual delivery after process death is part of the promised contract; it is not implemented here.

Remaining scale gates are larger entity/ID cardinality, repeated class invalidation, a broader namespace retention policy, cross-host load and longer production-representative worker runs. Standalone TTL/eviction and a 60-second five-process fixed-ID workload now have evidence. Controlled standalone interruption, unresponsiveness, RDB restart and explicit recovery now have coverage. Sentinel/Cluster, replica lag, automatic failover, cross-host/one-way partitions and transparent client reconnect remain outside the tested contract. Durable delivery of invalidation after writer death is still not implemented.

[L2 contract](read-only-l2-cache.md) · [Scenario matrix](l2-scope-matrix.md)
