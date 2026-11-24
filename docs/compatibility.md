# Compatibility and verified scope

ModernORM originated from Doctrine ORM 3.7.6, commit `4487f850401c0e1f27891cce5897fa90b3055c2a`. It is an independent SQL-first API: retained mapping/persistence concepts do not imply compatibility with Doctrine query languages, builders, paginators or extensions.

The 1.0 candidate pair passed CI on PHP 8.4–8.6 and PostgreSQL 17–19, including the Core locked, lowest and latest dependency profiles and Bundle Symfony 7.4 and 8.x profiles. The RC guide records exact revisions and clean consumer checks. [RC verification](1.0-release-candidate.md#candidate-verification) · [Historical beta verification](beta-verification.md)

**Verified** refers to the listed scenarios. **Bounded** means a restricted contract or inherited API with incomplete coverage. **Excluded** means unsupported, not a hidden compatibility mode.

| Capability | Status | Evidence and boundary |
| --- | --- | --- |
| CRUD, snapshot tracking, Identity Map, refresh/clear | Verified | PersistenceTest, AssociationsTest, QueryTest |
| Explicit refresh/detach | Verified cases | Root-only operations; pending inserts/deletes, dirty peers, EAGER targets and lazy references. Cascade refresh/detach are [removed](cascade-refresh-detach-removal.md) |
| DEFERRED_EXPLICIT | Verified cases | Repeated persist, scheduling reset, existing-child cascade persist and explicit remove |
| Flush cycles | Verified cases | Nullable/mixed cycles, required generated-ID cycle rejection, assigned-ID self-reference; nullable deletion after explicit unlink/flush |
| ModernORM attributes | Verified | ModernORM mapping imports required; no Doctrine aliases |
| Scalar PK, embeddables, MappedSuperclass | Verified cases | AdvancedMappingTest, IdentifierScopeTest, MappedSuperclassTest; no composite/association entity IDs |
| Entity inheritance/discriminators | Excluded | Attributes, entity-to-entity inheritance, metadata hooks/cache and discriminator RSM are rejected |
| Ordinary PHP inheritance/MappedSuperclass | Verified cases | Independent tables/identity, inherited ID/fields/association/callbacks/version, native lazy and SQL joins; superclass is not a query root |
| Associations and collections | Verified cases | Four association kinds, owning/inverse, persist/remove cascades and orphan removal; not every combination |
| Native lazy objects | Verified cases | Final entities, ID access without initialization, explicit initialization and missing rows; PHP readonly classes/properties not separately certified |
| Immutable | Verified cases | INSERT/DELETE/schema management; ignored field/embedded/FK updates; mutable children, cascades/orphans, M:N links, versions/callbacks, lazy/refresh/cache metadata; [contract](immutable-entities.md) |
| ManyToOne batching | Bounded | Scalar/string IDs, shared identities/sizes, size-one opt-out, missing/loaded peers, lifecycle; cached targets combine L2 hits with one SQL IN for misses; transactional reads bypass L2 |
| OneToMany batching | Bounded | FK IN, empty owners, role queues, OrderBy/indexBy, string PKs, pending work, orphan removal, immutable owners, retry/lifecycle and fetch-mode/L2 fallback; [contract](collection-batch-fetching.md) |
| ManyToMany batching | Bounded | Owning/inverse, shared/self-referencing targets, memberships, ordering/indexing, empty owners and pending changes; [contract](many-to-many-batch-fetching.md) |
| EXTRA_LAZY | Removed | LAZY/EAGER mappings only; use [Association Query API](association-query-api.md) for explicit database operations. [Migration](extra-lazy-removal.md) |
| Partial managed entity hydration | Removed | Complete field/embedded/FK mappings required; row/scalar/DTO/array projections and native lazy references remain supported. [Migration](partial-entity-removal.md) |
| SQL QueryBuilder/Query | Verified cases | Concrete projections, mapped columns, parameters/arrays, entity/scalar/mixed-object/flat-array hydration, CTE, cloned bindings/hints and plain streaming |
| Nested array graphs | Removed | HYDRATE_ARRAY is flat-only; managed entity fetch joins and object-mode DTOs remain supported. [Migration](array-graph-removal.md) |
| Explicit association fetch | Bounded | To-one/to-many joins, root deduplication, empty collections and Identity Map; filtered collections must not be represented as complete automatically |
| Collection pagination | Verified cases | Explicit root-ID builder, count/offset/empty page/bindings/full collections; one scalar ID and application-managed scope/order |
| Native SQL/ResultSetMapping | Verified cases | Entity/join/scalar mappings and PostgreSQL integer/boolean conversion; no discriminator results |
| Bulk BYPASS/CLEAR | Bounded | INSERT/UPSERT/UPDATE/DELETE and SoftDelete/restore, raw RETURNING/count, state/queues, rollback, external uncached writes, declared L2 write sets and commit failures; no SYNC/COPY/chunking/UPDATE FROM; [contract](bulk-api.md) |
| Lifecycle | Bounded | Entity/global listeners and callbacks, preFlush/onFlush/preUpdate mutation, empty flush and preFlush/postPersist/postFlush errors; not every subscriber/onClear/external-transaction combination |
| Integer optimistic locking | Verified cases | Stale writer, version increment and SQL guards |
| PESSIMISTIC_WRITE | Verified cases | Separate PostgreSQL backends, timeout and release after commit; PESSIMISTIC_READ not separately certified |
| Transactions/savepoints | Bounded | UNIQUE/FK failures, nested and external rollback, deadlock and SERIALIZABLE failures, and lost COMMIT replies. Recovery and retry are application-controlled. [Lifecycle contract](lifecycle-contract.md) |
| Metadata/projection-plan PSR-6 | Verified cases | Serialization, Array/FilesystemAdapter, independent managers/adapters, TTL/isolation/invalidation and changed namespaces after schema changes; parameter values/types are not projection-key components |
| Custom EntityRepository | Bounded | Executable example uses the SQL entity builder; not every inherited method is covered |
| SchemaTool/migrations | Bounded | Core create/drop; separate ModernORMBundle covers restricted diff/apply/empty-diff and rollback; arbitrary production updates and SchemaValidator not certified |
| Custom types/strategies/readonly/enums/date/time/JSON | Bounded | Specific fixtures exist; arbitrary combinations are not certified |
| Entity L2 READ_ONLY/INVALIDATE | Bounded | Flat entities, Array/FilesystemAdapter, typed raw rows, generations, transactions, rollback/commit, late fills and explicit failure outcomes; [contract](read-only-l2-cache.md), [matrix](l2-scope-matrix.md) |
| Result/hydration cache | Bounded | Inherited surface without a new comprehensive contract |
| Statistics SQL/flush/batch | Verified cases | Counts/time/errors, immutable snapshots, reset/disabled/in-flight discard, connection sharing/isolation/reconnect, fallback and observer error/reentrancy handling; [contract](statistics.md) |
| L2 class statistics | Bounded | Optional StatisticsCacheLogger totals/per-class hit/miss/put/eviction and reset; cache-logger overhead not separately measured |
| Extended observability | Verified cases | Core operation events and hydration/DML counters. Symfony Profiler and optional OpenTelemetry metrics integration are provided by ModernORMBundle. [Operation events](operation-events.md) |
| L2 plus batching | Bounded | Cached ManyToOne hits/batched misses and late-fill safety; uncached targets of all three kinds batch with L2 enabled. Cached ManyToMany uses ordinary collection SQL |
| Console | Optional/bounded | Core console commands require optional Symfony Console; Symfony registration belongs to ModernORMBundle |
| Doctrine ORM/DoctrineBundle coexistence | Bounded | A local Symfony application exercises separate services/connections and Profiler; no drop-in ServiceEntityRepository/Doctrine extension compatibility |
| DQL, inheritance, composite IDs, XML/YAML/annotations, Criteria SQL, ORM Filters, generated proxies, other databases | Excluded | See [mapping](mapping-scope.md) and [SQL API](sql-query-api.md) |
| L2 graphs/collections/query results/RW strategies and distributed failover | Excluded or uncertified | Standalone Redis has bounded recovery verification. Metadata/query-plan cache remains separate. [L2 matrix](l2-scope-matrix.md) · [Redis verification](l2-redis-verification.md) |

## Provenance and support

[Source manifest](../tools/upstream.json) preserves imported file hashes, including subsequently removed files. [Selected test adaptations](../tools/upstream-tests.json), [DQL removal](../tools/dql-removal.json) and [mapping changes](../tools/mapping-simplification.json) record the transformation. This is not the complete upstream test suite or a Doctrine performance comparison.

MIT and Doctrine copyright notices remain in [LICENSE](../LICENSE) and [licenses](../licenses/Doctrine-ORM-MIT.txt). Maintenance follows community issues/PRs without an automatic upstream patching commitment. The [beta contract](beta-release.md) defines release and compatibility expectations.

The [persistence audit](persistence-audit.md) records collection replacement/orphan removal, historical owner-scoped EXTRA_LAZY lookup fixes (the mode is now removed), UNIQUE/FK ordering, callback mutations, nested flush rejection and rollback boundaries with per-scenario before/after evidence.
