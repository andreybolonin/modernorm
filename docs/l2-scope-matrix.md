# L2 cache scenario matrix

This matrix includes READ_ONLY and UPDATE/invalidate behavior. **Implemented** means code plus the named local coverage, not certification of every backend, network failure or production workload. See [setup/recovery](read-only-l2-cache.md) and [beta verification](beta-verification.md).

| Area | Scenario | Contract/status |
| --- | --- | --- |
| Reads | find by scalar PK | Implemented: Identity Map first, then L2, then SQL |
| Reads | Lazy ManyToOne targeting a cacheable entity | Initialize the existing native ghost while preserving PHP identity |
| Reads | Cached ManyToOne batch | L2 hits plus one SQL IN for misses; all-hit groups need no SELECT |
| Reads | Uncached targets with L2 enabled | ManyToOne/OneToMany/ManyToMany retain batching |
| Reads | findBy/count/native/entity SQL | Ordinary SQL path, without automatic L2 result caching/fill |
| Reads | EAGER/fetch joins | SQL path; no graph result cache |
| Reads | Partial objects | Partial state is not published to L2; full lazy loading retains partial-field rules |
| Reads | Missing row | No negative cache; a missing trigger raises the ordinary lazy-load error |
| Reads | Invalid/incompatible entry | Class/ID/signature/shape validation; invalid entries become misses, while conversion/backend errors are not hidden |
| Data | Flat entity, scalar PK | Supported scope |
| Data | Scalars/enums/JSON/null/dates/version/embeddables | Covered within the supported scope; managed objects are not stored in L2 |
| Data | Binary/resource fields | Unsupported |
| Data | Entity declaring associations | Excluded; inbound references from uncached entities are allowed |
| Data | Composite/association entity PK | Excluded by ORM mapping |
| Policies | READ_ONLY | Default; reject ordinary ORM UPDATE, allow supported INSERT/DELETE |
| Policies | UPDATE + invalidate | CacheWritePolicy::INVALIDATE evicts before UPDATE and after commit; it does not publish the PHP object |
| Policies | Immutable + Cacheable | Independent; Immutable ignores entity field UPDATE under either cache policy |
| Policies | Update-through cache publication | Excluded; a later SQL read fills a new entry |
| Writes | INSERT/DELETE | Automatic invalidation before/during writing and after commit |
| Writes | Versioned ORM UPDATE | Normal version guard/increment and stale-writer rollback; rejected state is not published |
| Writes | Unversioned ORM UPDATE | Invalidate without adding optimistic locking |
| Writes | Bulk BYPASS/CLEAR | Invalidate declared cached classes before DML and after an owned commit |
| Writes | Cached PK mutation | Rejected; moving rows between cache keys is unsupported |
| Writes | Trigger changes the same row | Later L2 fills read actual SQL state; the managed object follows ordinary generated-field/mapping rules |
| Writes | Trigger/cascade changes other cached classes | No automatic discovery; bulk requires affectedEntities, other writers require coordination |
| Transactions | Reads inside a transaction/auto-commit off | Bypass L2; batch fetches the entire group through SQL without cache fills |
| Transactions | Owned top-level flush | Supported boundary for cached INSERT/DELETE/UPDATE invalidation |
| Transactions | External transaction/savepoint flush | Cached writes rejected; no general caller-transaction completion hook |
| Transactions | Confirmed rollback | No new state published; pre-eviction may leave a safe miss |
| Transactions | Callback changes nesting | Not accepted as a final commit; manager closes and outcome may be unknown |
| Concurrency | Fill started before key/class eviction | Generation ticket makes the late fill unreachable |
| Concurrency | Reader fills old row before writer commit | Post-commit invalidation removes that generation |
| Concurrency | Atomic PostgreSQL + PSR-6 | Not guaranteed; a commit/invalidation visibility window remains |
| Invalidation | evict(Entity, id)/evictAll(Entity) | Implemented without clearing unrelated classes or the shared pool |
| Invalidation | External SQL/import/another system | Not observed automatically; invalidate after commit and coordinate L1 |
| Invalidation | TTL/namespace changes | Implemented; TTL does not replace invalidation |
| Invalidation | Old-generation cleanup | Application retention/maintenance policy; payload TTL expires entries naturally |
| Errors | Backend unavailable/save returns false | Propagate the error; no automatic fail-open fallback |
| Errors | Cache failure before UPDATE | No UPDATE; owned flush rolls back |
| Errors | Confirmed commit, eviction failure | databaseCommitted=true, closed EM, remaining keys/classes still attempted; recover cache without replaying SQL |
| Errors | Unknown COMMIT outcome | databaseCommitted=null, connection/EM closed, best-effort affected-key eviction. Injected before/after-driver failures and deferred constraints are covered, not real network partitions |
| Errors | COMMIT and recovery invalidation both fail | Preserve original commit error and separate cacheFailure; inspect database/cache without automatic retry |
| Errors | Redis partition/failover/generation-metadata loss | Not certified; requires backend-specific checks |
| Collections | OneToMany/ManyToMany L2 | Excluded; cached ManyToMany retains ordinary collection SQL |
| Queries | L2 result cache/timestamps | Excluded/removed; metadata and projection-plan PSR-6 caches remain separate |
| Strategies | READ_WRITE/NONSTRICT_READ_WRITE locks | Removed; INVALIDATE does not restore these strategies |
| Strategies | Stampede protection/SWR/refresh-ahead | Excluded; concurrent misses may independently query SQL, with no background refresh |
| Isolation | Applications/deployments | Namespace separates keys; applications version it for incompatible changes |
| Isolation | Multi-tenancy | No built-in tenant-aware EM/L1/L2 contract; a namespace alone is insufficient |
| Isolation | Replicas/multi-region/async invalidation | Outside the contract; lagging reads and invalidation events need explicit semantics |
| Lifecycle | clear/detach/close | Remove/clear queue members; do not automatically evict L2 |
| Lifecycle | PHP-FPM/long-lived workers | Applications define EM/reset boundaries and cache maintenance; core stores no entity event history |
| Metrics | Global entity hit/miss/put/eviction | StatisticsCacheLogger totals/per-class and reset |
| Metrics | Batch progress/fallback/completion events | Connection-scoped Statistics; export adapters are separate |
| Backends | ArrayAdapter/FilesystemAdapter | Read/update invalidation covered, including independent filesystem adapters reading updated raw rows followed by warm hits |
| Backends | Redis/load/failover certification | Separate work; unit/integration success does not establish these guarantees |

The bounded entity-cache implementation does not require graphs, collection/query caches or external cached-write transactions to be useful. Release claims remain limited by the tested backend matrix and infrastructure evidence in the [beta contract](beta-release.md).
