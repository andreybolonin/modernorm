# Entity L2 cache: READ_ONLY and UPDATE + invalidate

L2 caches **flat entities loaded by identifier**. Configure a PSR-6 pool and opt in per entity with `#[Cacheable(ttl: ...)]`. The implementation lives in `src/Cache`, with the entity facade in `src/Cache.php`. L2 remains disabled by default.

## Configuration and attributes

```php
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

// Keep the pool's default lifetime at zero; entity TTLs are explicit.
$pool = new FilesystemAdapter('my_app_storage', 0, __DIR__ . '/var/cache');
$statistics = new StatisticsCacheLogger();
$config->setSecondLevelCache($pool, namespace: 'my_app_l2_v1', logger: $statistics);
```

Configure before constructing EntityManager. The namespace isolates applications/deployments even when they share a PSR-6 pool. Change it after incompatible mapping/type changes. The core depends on PSR-6, not Symfony Cache; Redis can be supplied through a PSR-6 adapter but is not yet certified by this suite.

For explicit configuration objects, use `new ModernORM\Cache\CacheConfiguration($pool, namespace: 'my_app_l2_v1', logger: $statistics)`, pass it to `setSecondLevelCacheConfiguration()`, then call `setSecondLevelCacheEnabled()`. Enabling L2 without a pool configuration is an error. No cache factory or public region configuration is required.

```php
use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Cacheable(ttl: 3600)]
class Currency
{
    #[ORM\Id, ORM\Column(length: 3)]
    public string $code;

    #[ORM\Column]
    public string $name;
}
```

TTL defaults to 3600 seconds and must be nonnegative. Zero delegates expiration to the pool's default: use a pool with zero default lifetime for nonexpiring entries and persistent generation metadata. Backend eviction/expiration can still remove data. The attribute applies only to concrete entities, not properties, associations, mapped superclasses or embeddables. TTL survives serialized metadata; legacy strategy/region metadata is rejected. Metadata cache salt is `__MODERNORM_ENTITY_CACHE_WRITE_POLICY_V9__`; application namespace versioning remains required.

## Write policy

```php
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Cacheable(ttl: 3600, writePolicy: CacheWritePolicy::INVALIDATE)]
class Product
{
    // Mapped scalar fields; no declared associations in the current scope.
}
```

| writePolicy | ORM UPDATE | INSERT / DELETE |
| --- | --- | --- |
| READ_ONLY (default) | Throws CannotUpdateReadOnlyEntity | Invalidate in the supported transaction scope |
| INVALIDATE | Normal ORM update/version checks; invalidate before UPDATE and after commit | Same invalidation protocol |

`#[ORM\Immutable]` is optional and independent. It makes UnitOfWork ignore field changes under either write policy, so no ORM UPDATE is generated. Neither policy makes PHP objects immutable or prevents direct SQL. `CacheWritePolicy` is a typed enum, not a Doctrine READ_WRITE lock strategy; strings and legacy strategy metadata are rejected.

INVALIDATE supports the same flat scalar entities as READ_ONLY. Updates delegate to the ordinary SQL persister: normal dirty checking, PreUpdate/PostUpdate callbacks, generated/version handling and snapshots apply. Versioned stale writers fail normally; unversioned entities do not gain implicit optimistic locking. Cached ORM primary-key changes are rejected. DELETE invalidates the database identifier retained by UnitOfWork.

No managed object is published after INSERT/UPDATE. After commit, a fresh ID read uses SQL and fills raw database values, including database-generated/trigger-modified values. A previously managed object still follows ordinary ORM rules and can differ from the database until refreshed. Trigger/cascade writes to other cached keys/classes are not automatically discovered by ordinary ORM UPDATE; such dependencies need application coordination. Bulk provides an explicit affectedEntities write set.

## Public facade

```php
$cache = $em->getCache(); // null when L2 is disabled
$cache->contains(Currency::class, 'USD');
$cache->evict(Currency::class, 'USD');
$cache->evictAll(Currency::class);
```

`evictAll()` invalidates one mapped class, never the whole shared pool. It does not detach or refresh existing managed objects. Identifiers can be integer/string/backed-enum values or an array containing exactly the single mapped PK field. Calls for uncached classes are no-ops (`contains()` returns false). Public APIs do not expose region objects, cache modes or query/collection caches.

## Supported and excluded scope

- Cached entities have a single scalar PK and no declared associations. Scalars, enums, nulls, JSON, dates, versions and flattened embeddables are covered.
- Uncached entities can hold lazy ManyToOne references to cached entities; initializing them can hit L2.
- `find()` by ID and lazy initialization use entries. Identity Map takes priority.
- Criteria/native/entity queries, counts, locks and existence checks use SQL; they neither use L2 query-result caching nor automatically fill entity entries.
- L2 query-result cache, collection cache, timestamp regions, locking/RW strategies and cache factories have been removed. Association graphs and binary/resource fields remain unsupported.
- PSR-6 metadata and entity query-plan caches remain available. DBAL result/hydration caches are separate mechanisms and do not acquire automatic L2 invalidation guarantees.
- Lazy ManyToOne batching supports Cacheable targets: valid L2 hits hydrate without SQL, while all misses in the selected group use one SQL IN query. An all-hit batch performs no SELECT. Uncached targets still batch across ManyToOne/OneToMany/ManyToMany; cached ManyToMany collections retain their ordinary per-collection SQL loader.

Entries contain complete raw scalar database rows, never managed objects or mutable PHP snapshots. DBAL/enum conversion runs on every hydration. PostLoad mutations, mutable DateTime objects and local changes do not leak through shared entries.

Entries validate class, ID, mapping signature and exact field shape. Invalid/incompatible entries become misses. Backend and field-conversion errors propagate; they are not treated as absent database rows. Missing rows are not negatively cached.

## Transactions and automatic invalidation

Active DBAL transactions and disabled auto-commit mode bypass L2 reads/fills. Identity Map objects still follow normal ORM behavior; bypass is not an implicit refresh.

For cached ManyToOne batching, transaction bypass applies to the entire selected group: one SQL IN read, no L2 lookup/fill or cache hit/miss/put events. Batch size 1, one-to-one and partial references keep their single loader. A trigger hit may still issue SQL for cold peers; the batch limit counts references, not only misses. All found peers hydrate before PostLoad, while cache payloads always come from raw database rows. Missing peers stay lazy until accessed; a missing trigger raises EntityNotFoundException without negative caching.

Cache/SQL failures propagate without automatic retries. SQL failure does not hydrate already-read cache hits; an explicit retry can use the same queue. Backend failures can leave some valid raw payloads filled, but never publish managed-object changes. Middleware/cache callbacks must not change transaction mode/nesting during a cached batch read: detected changes are rejected before hydration, and a post-SELECT guard prevents private rows from being filled into L2.

ORM cached INSERT/DELETE and INVALIDATE UPDATE require a top-level ORM-owned flush transaction with auto-commit enabled. Outer transactions, savepoint flushes and disabled auto-commit are rejected before that persister's DML. UnitOfWork closes the EntityManager and rolls back its flush transaction/savepoint. The caller must finish recovery of an outer transaction. Uncached ORM writes retain their existing semantics.

INSERT/DELETE/INVALIDATE UPDATE invalidate keys during the write and again after successful commit. Failed SQL/version checks roll back the owned transaction and publish no changed state. A preliminary eviction can leave a safe miss. Refresh evicts and uses SQL. Callbacks must not change transaction nesting; a savepoint release is not a final commit.

The [Bulk API](bulk-api.md) invalidates entire declared entity classes before DML and after an owned commit in both BYPASS and CLEAR. Trigger/cascade dependencies must be declared through affectedEntities. Cached bulk writes inside caller-owned transactions are rejected. Direct DBAL/external writers are not automatically observed.

## Generations, concurrency and external writers

Internal regions remain implementation details. Each entry key includes a class generation and a per-entry generation. A reader captures its fill ticket before SELECT. Key/class invalidation changes the generation, so a delayed fill on the previous generation becomes unreachable. Post-commit invalidation also removes values filled by another connection before commit.

There is no Redis key scanning, `clear(prefix)` or clearing of shared PSR-6 storage. Old payloads expire by their TTL; generation metadata may remain. Nonexpiring payloads and repeated generation changes require an application namespace retention/maintenance policy.

This is not atomic PostgreSQL/cache consistency. Already-started reads may finish with an old value; the post-commit invalidation window and backend/distributed failure modes remain limitations. PSR-6 offers no general CAS or database transaction protocol.

External SQL, imports through direct DBAL and other applications must invalidate after their own commit and refresh/clear persistence contexts as needed. Eviction only before commit is insufficient. The simplified facade does not weaken or replace these responsibilities with TTL alone.

## Errors and metrics

- Backend failures propagate; no implicit fallback policy hides failed invalidation.
- After a known ORM commit followed by invalidation failure, `CacheSynchronizationException::databaseCommitted` is true and the EntityManager is closed. Invalidation is attempted for all pending keys/classes even after one fails. A failed key can still contain a precommit reader's old payload: recover the entire affected cache write set before continuing; do not retry SQL.
- COMMIT exceptions are conservatively reported as unknown (`databaseCommitted === null`) for affected cached writes, including an exception before/after the actual driver commit or a deferred constraint failure. The connection is disconnected, the manager is closed, and all pending keys/classes receive best-effort eviction. `getPrevious()` preserves the commit failure; optional `cacheFailure` preserves an additional invalidation failure. No SQL retry or guaranteed rollback is claimed.
- If callbacks change nesting, that exception can also carry null: final database outcome is unknown. Roll back remaining transaction state, discard the manager, recover cache state and inspect the database before retrying.
- Bulk post-write/unknown-commit failures use `BulkOperationException` as described in the bulk contract.

Optional `StatisticsCacheLogger` records hits, misses, puts and evictions by entity class, with no identifier labels. Call `getHitCount()`, `getMissCount()`, `getPutCount()` and `getEvictionCount()` for totals, or pass an entity class for a class count. `reset()` clears all counters at a request/job boundary. `CacheLoggerChain` can fan out events. Symfony Profiler, Prometheus and OpenTelemetry adapters remain separate Observability work.

## Migration and verification

| Removed API | Replacement |
| --- | --- |
| `#[Cache(usage: 'READ_ONLY', region: ...)]` | Class-level `#[Cacheable(ttl: ...)]`; class namespaces are internal |
| `DefaultCacheFactory` / `RegionsConfiguration` | `Configuration::setSecondLevelCache($pool, namespace: ...)` |
| `containsEntity` / `evictEntity` / `evictEntityRegion` | `contains` / `evict` / `evictAll` |
| `getEntityCacheRegion` / collection/query regions | No public replacement; use entity invalidation |
| Query `setCacheable`, cache region/mode/lifetime methods | Removed L2 result-caching API; metadata/query-plan PSR-6 APIs remain |
| Region-based cache logger callbacks/counters | Entity-class hit/miss/put/evict callbacks and counters |
| CLI `orm:clear-cache:region:*` | Optional `orm:clear-cache:entity EntityClass [id]`; metadata/query-plan commands remain |

Old Cache attributes fail explicitly with a migration error; they do not silently disable caching. Update imports/configuration and version application namespaces when deploying this change.

`ReadOnlyL2CacheTest`, `ReadOnlyRegionTest`, `ReadOnlyCacheScopeTest` and `BulkOperationsTest` cover independent managers/connections, ArrayAdapter/FilesystemAdapter, raw typed hydration, immutable/mutable policies, TTL, namespace isolation, malformed entries, key boundaries, metadata serialization/validation, transactions, concurrent precommit/delayed fills, declared write sets, failure outcomes and statistics.

`UpdateL2CacheTest` covers versioned/unversioned UPDATE, repeat flush, normal callbacks/typed state, Immutable independence, optimistic conflicts, multi-update rollback, outer/disabled-auto-commit rejection, nested callbacks, primary-key guards, database-trigger values, cold/warm and lazy-batch reads, precommit readers/delayed fills, cache errors before/after commit, partial invalidation failure across classes, and injected failures before/after physical COMMIT. Independent FilesystemAdapter instances verify update invalidation and payload refill. Fault injection is not certification of real network partitions or distributed cache failures.

[Full scenario/scope matrix](l2-scope-matrix.md).

`L2BatchFetchingTest` additionally covers cold/warm/mixed cached batches, SQL parameters containing only misses, invalid entries, transaction bypass, missing rows, raw typed values/PostLoad, dirty/partial peers, cached ManyToMany fallback, pending changes, shared-reference opt-out, manager/lifecycle isolation, retry after SQL/backend failures, and eviction between SELECT and publication on a separate writer connection. Lifecycle cleanup with L2 enabled is also covered by all three batch suites.

Redis/distributed partitions, arbitrary custom types/quote strategies, graph caches, cached ManyToMany batching and production overhead remain uncertified. The optional Console command has not been integration-tested with Symfony Console in this dependency set.
