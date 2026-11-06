# Compatibility and verified scope

ModernORM originated from Doctrine ORM 3.7.6, commit `4487f850401c0e1f27891cce5897fa90b3055c2a`. It is an independent SQL-first API: retained mapping/persistence concepts do not imply compatibility with Doctrine query languages, builders, paginators or extensions.

The local core suite passed **418 tests / 2170 assertions** on PHP **8.4.27 and 8.5.12**, PostgreSQL **17.11**, with pdo_pgsql. Lowest/latest dependency and clean-consumer results are recorded in [beta verification](beta-verification.md). Linux workflows are configured; a remote run is not confirmed by those local results.

**Verified** refers to the listed scenarios. **Bounded** means a restricted contract or inherited API with incomplete coverage. **Excluded** means unsupported, not a hidden compatibility mode.

| Capability | Status | Evidence and boundary |
| --- | --- | --- |
| CRUD, snapshot tracking, Identity Map, refresh/clear | Verified | PersistenceTest, AssociationsTest, QueryTest |
| Detach/cascade detach | Verified cases | UnitOfWorkContractsTest: pending inserts/deletes, dirty objects and to-one cycles; not every collection/orphan combination |
| DEFERRED_EXPLICIT | Verified cases | Repeated persist, scheduling reset, existing-child cascade persist and explicit remove |
| Flush cycles | Verified cases | Nullable/mixed cycles, required generated-ID cycle rejection, assigned-ID self-reference; nullable deletion after explicit unlink/flush |
| ModernORM attributes | Verified | ModernORM mapping imports required; no Doctrine aliases |
| Scalar PK, embeddables, MappedSuperclass | Verified cases | AdvancedMappingTest, IdentifierScopeTest, MappedSuperclassTest; no composite/association entity IDs |
| Entity inheritance/discriminators | Excluded | Attributes, entity-to-entity inheritance, metadata hooks/cache and discriminator RSM are rejected |
| Ordinary PHP inheritance/MappedSuperclass | Verified cases | Independent tables/identity, inherited ID/fields/association/callbacks/version, native lazy and SQL joins; superclass is not a query root |
| Associations and collections | Verified cases | Four association kinds, owning/inverse, cascades and orphan removal; not every combination |
| Native lazy objects | Verified cases | Final entities, ID access without initialization, explicit initialization and missing rows; PHP readonly classes/properties not separately certified |
| Immutable | Verified cases | INSERT/DELETE/schema management; ignored field/embedded/FK updates; mutable children, cascades/orphans, M:N links, versions/callbacks, lazy/refresh/cache metadata; [contract](immutable-entities.md) |
| ManyToOne batching | Bounded | Scalar/string IDs, shared identities/sizes, size-one opt-out, missing/partial peers, lifecycle; cached targets combine L2 hits with one SQL IN for misses; transactional reads bypass L2 |
| OneToMany batching | Bounded | FK IN, empty owners, role queues, OrderBy/indexBy, string PKs, pending work, orphan removal, immutable owners, retry/lifecycle and fetch-mode/L2 fallback; [contract](collection-batch-fetching.md) |
| ManyToMany batching | Bounded | Owning/inverse, shared/self-referencing targets, memberships, ordering/indexing, empty owners and pending changes; [contract](many-to-many-batch-fetching.md) |
| EXTRA_LAZY | Bounded | Both collection sides, count/contains/containsKey/get/first/slice/isEmpty and pending changes; indexBy-ID variants not separately certified; no Criteria matching/filters |
| SQL QueryBuilder/Query | Verified cases | Concrete projections, mapped columns, parameters/arrays, entity/scalar/mixed/array hydration, CTE, cloned bindings/hints and plain streaming |
| Explicit association fetch | Bounded | To-one/to-many joins, root deduplication, empty collections and Identity Map; filtered collections must not be represented as complete automatically |
| Collection pagination | Verified cases | Explicit root-ID builder, count/offset/empty page/bindings/full collections; one scalar ID and application-managed scope/order |
| Native SQL/ResultSetMapping | Verified cases | Entity/join/scalar mappings and PostgreSQL integer/boolean conversion; no discriminator results |
| Bulk BYPASS/CLEAR | Bounded | INSERT/UPSERT/UPDATE/DELETE and SoftDelete/restore, raw RETURNING/count, state/queues, rollback, external uncached writes, declared L2 write sets and commit failures; no SYNC/COPY/chunking/UPDATE FROM; [contract](bulk-api.md) |
| Lifecycle | Bounded | Entity/global listeners and callbacks, preFlush/onFlush/preUpdate mutation, empty flush and preFlush/postPersist/postFlush errors; not every subscriber/onClear/external-transaction combination |
| Integer optimistic locking | Verified cases | Stale writer, version increment and SQL guards |
| PESSIMISTIC_WRITE | Verified cases | Separate PostgreSQL backends, timeout and release after commit; PESSIMISTIC_READ not separately certified |
| Transactions/savepoints | Bounded | UNIQUE/FK failures, nested rollback and external rollback; deadlock retries, SERIALIZABLE and timestamp versions not separately certified |
| Metadata/projection-plan PSR-6 | Verified cases | Serialization, Array/FilesystemAdapter, independent managers/adapters, TTL/isolation/invalidation and changed namespaces after schema changes; parameter values/types are not projection-key components |
| Custom EntityRepository | Bounded | Executable example uses the SQL entity builder; not every inherited method is covered |
| SchemaTool/migrations | Bounded | Core create/drop; separate ModernORMBundle covers restricted diff/apply/empty-diff and rollback; arbitrary production updates and SchemaValidator not certified |
| Custom types/strategies/readonly/enums/date/time/JSON | Bounded | Specific fixtures exist; arbitrary combinations are not certified |
| Entity L2 READ_ONLY/INVALIDATE | Bounded | Flat entities, Array/FilesystemAdapter, typed raw rows, generations, transactions, rollback/commit, late fills and explicit failure outcomes; [contract](read-only-l2-cache.md), [matrix](l2-scope-matrix.md) |
| Result/hydration cache | Bounded | Inherited surface without a new comprehensive contract |
| Statistics SQL/flush/batch | Verified cases | Counts/time/errors, immutable snapshots, reset/disabled/in-flight discard, connection sharing/isolation/reconnect, fallback and observer error/reentrancy handling; [contract](statistics.md) |
| L2 class statistics | Bounded | Optional StatisticsCacheLogger totals/per-class hit/miss/put/eviction and reset; cache-logger overhead not separately measured |
| Extended observability | Separate work | Core has no standalone hydration/DML counters or active spans. Symfony Profiler exists in ModernORMBundle; Prometheus/OTel exporters remain future work |
| L2 plus batching | Bounded | Cached ManyToOne hits/batched misses and late-fill safety; uncached targets of all three kinds batch with L2 enabled. Cached ManyToMany uses ordinary collection SQL |
| Console | Optional/bounded | Core console commands require optional Symfony Console; Symfony registration belongs to ModernORMBundle |
| Doctrine ORM/DoctrineBundle coexistence | Bounded | A local Symfony application exercises separate services/connections and Profiler; no drop-in ServiceEntityRepository/Doctrine extension compatibility |
| DQL, inheritance, composite IDs, XML/YAML/annotations, Criteria SQL, ORM Filters, generated proxies, other databases | Excluded | See [mapping](mapping-scope.md) and [SQL API](sql-query-api.md) |
| L2 graphs/collections/query results/RW strategies and distributed backends | Excluded or uncertified | Metadata/query-plan cache remains separate; see the L2 matrix |

## Provenance and support

[Source manifest](../tools/upstream.json) preserves imported file hashes, including subsequently removed files. [Selected test adaptations](../tools/upstream-tests.json), [DQL removal](../tools/dql-removal.json) and [mapping changes](../tools/mapping-simplification.json) record the transformation. This is not the complete upstream test suite or a Doctrine performance comparison.

MIT and Doctrine copyright notices remain in [LICENSE](../LICENSE) and [licenses](../licenses/Doctrine-ORM-MIT.txt). Maintenance follows community issues/PRs without an automatic upstream patching commitment. The [beta contract](beta-release.md) defines release and compatibility expectations.
