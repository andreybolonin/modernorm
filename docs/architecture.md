# Architecture

ModernORM is a PostgreSQL-first ORM for PHP 8.4+, intended for ERP workloads with large object graphs, frequent reference-data reads and explicit bulk writes. It is derived from Doctrine ORM 3.7.6, but evolves independently through community issues and pull requests. There is no scheduled upstream synchronization process.

## Runtime and mapping

The core depends on DBAL 4.6+, Doctrine Collections 3.1+, Doctrine Persistence and PSR-6 contracts. It does not require Symfony FrameworkBundle, Console, a profiler or an exporter. PostgreSQL is the only database target; PHP native lazy objects are the only lazy-object implementation. Entity and embeddable materialization uses cached PHP Reflection to create instances without invoking constructors; no separate instantiator package is required.

Attributes are the only mapping source. Each concrete entity has one scalar primary key and an independent metadata root. MappedSuperclass and embeddables reuse mappings without introducing polymorphic SQL queries. Composite UNIQUE constraints and composite join-table keys remain valid. Entity inheritance, discriminator hydration, composite entity identifiers, XML/YAML mapping, Criteria SQL translation and ORM Filters are excluded. See [mapping scope](mapping-scope.md).

## Persistence context

One EntityManager owns an Identity Map, UnitOfWork, persisters and lazy-loading queues. Entity queries, native SQL hydration and repository loaders use that same context. Bulk writes deliberately expose separate state policies rather than silently synchronizing objects.

Snapshot and explicit change tracking, persist/remove cascades, orphan removal, callbacks and basic locking remain part of the supported persistence model. Not every combination of inherited extension points is covered; the [compatibility matrix](compatibility.md) defines the tested boundary.

`Internal/UnitOfWork/EntityHydrator` assigns prepared scalar/embedded fields through existing accessors. It yields readonly snapshot corrections in assignment order, computes owning to-one identifiers/loading decisions from supplied facts, and prepares/assigns to-many collections while preserving supplied collection state. UnitOfWork applies the results and retains Identity Map, registration, snapshots, loading execution, associations and loading/event queues. See the [hydration boundary](entity-hydrator.md).

Built-in SQL hydrators can prepare one [FlatSqlHydrationPlan](flat-sql-hydration-plan.md) per active single-root scalar result. It specializes existing deepclone support for normalized rows without building another argument array. UnitOfWork applies it only to newly registered exact-class objects; incompatible mappings, runtime values and refresh/existing entities retain EntityHydrator. The plan stays local to the SQL result and introduces no generic operation interpreter or additional strategy classes.

`Internal/UnitOfWork/ChangeSetComputer` compares prepared scalar/embedded snapshots and returns old/new pairs, using FieldChangeComparator for individual values. UnitOfWork retains property access, baseline preparation, state, events, collections, associations and queue updates. The [comparison boundary](change-set-computer.md) records ordinary/recompute and INSERT/UPDATE differences.

`Internal/UnitOfWork/ScalarChangeTrackingPlan` compiles ordinary scalar fields for one read/compare pass after preFlush. Eligible fully managed DEFERRED_IMPLICIT entities use one object cast with precompiled raw property keys and strict scalar comparison; snapshots and UPDATE queues remain in UnitOfWork. Weak metadata-keyed caching also remembers rejected plans and detects structural metadata changes. Associations, embedded values, inheritance, hooks, readonly/non-scalar/custom accessors, pending INSERTs and tracked lazy references retain the compatibility path. The same plan supplies one-cast reads during recomputation; UnitOfWork keeps recompute comparison/merge rules and accessor fallback for pending INSERTs and tracked references. See [scalar dirty checking](scalar-change-tracking.md).

`Internal/UnitOfWork/EntityStateManager` stores identifiers, cached states and original snapshots and centralizes managed registration, post-insert ID registration, identified-entity NEW/DETACHED decisions and per-entity cleanup after DELETE or detach. State detection uses supplied version/generator facts and a deferred identity/existence observation; metadata reads and lookup execution stay in UnitOfWork. Cleanup preserves each transition's unset order; queues, identity removal, ID property reset and events remain in UnitOfWork. UnitOfWork retains ID conversion/property writes and publishes identity through one bound callback at the existing validation point. Remaining lifecycle/dirty-check operations still access the internal maps directly, preserving their timing and avoiding copying/reference aliases or per-entity state objects. See the [state-registration boundary](entity-state-manager.md).

`Internal/UnitOfWork/AssociationChangePlanner` owns the orphan candidate queue, scheduling reasons and removal policy. Collection mutations report candidates; replacement compares materialized membership arrays. The planner receives `AssociationMembership` facts and does not depend on EntityManager, metadata or PersistentCollection. It does not execute SQL or read entity properties.

UnitOfWork adapts ORM state into membership facts, lazily and one candidate at a time, then invokes the existing removal lifecycle for confirmed orphans. Observation stops at the first retained membership or owning-side transfer. This preserves collection-loading/callback order instead of taking an early snapshot of every origin. `clear()` and flush cleanup reset the planner. SQL execution order and public scheduling methods remain unchanged. [Audit evidence and remaining limitations](persistence-audit.md) document the behavior that this local extraction preserves.

`Internal/UnitOfWork/WriteOrderPlanner` computes INSERT/DELETE order from explicit FK dependencies and UPDATE order from runtime class names and an explicit ID-hash callback. UPDATE sorting remains stable and lexicographic (including numeric strings); ID hashes are read only while comparing same-class entities, without precomputation. It owns graph construction, optional insert edges and cascade-component ordering. UnitOfWork supplies dependencies lazily in the previous traversal order; the planner does not read metadata or entity properties. Per-entity deletion groups preserve edge insertion order without retaining a second full dependency list. Existing topological-sort and strongly-connected-component algorithms remain unchanged.

`Internal/UnitOfWork/FlushExecutor` executes prepared INSERT batches through an explicitly supplied persister. One acknowledgement callback lets UnitOfWork remove each insertion immediately after a successful `addInsert`, before execution. Ordinary and extra UPDATE writes share a lazy operation stream: UnitOfWork prepares each operation immediately before SQL and resumes its queue/event coordination only after successful execution. It requests ordering from WriteOrderPlanner and retains readonly skips, preUpdate/recomputation, snapshots, changeset installation and postUpdate. Persisters retain SQL generation and post-insert ID assignment; UnitOfWork retains transaction/failure handling. DELETE operations also use a lazy stream: UnitOfWork removes identity before SQL, clears state/IDs after the write, and defers postRemove until all deletes finish. See the [execution boundary](flush-executor.md). Collection deletes/updates also run through lazy executor streams; owner eligibility, queues, phase order and post-commit snapshots stay in UnitOfWork; UPDATE ordering uses WriteOrderPlanner's existing class/ID-hash sort. The planner introduces no UNIQUE conflict solver or new constraint-deferral behavior. Independent graph tests and PostgreSQL checks cover FK order, nullable/required cycles, assigned-ID self-references, cascade groups and SET NULL; [persistence limitations](persistence-audit.md) remain applicable.

Flush completion stays inside UnitOfWork, with explicit methods for committing its transaction/savepoint, handling write/commit failure, synchronizing visited collections, and dispatching `postFlush` with queue cleanup. The failure path is scoped to writes and commit. Successful completion notifies L2 before collection snapshots and `postFlush`; callback errors do not enter the rollback path. Empty flushes share callback/cleanup handling without transaction or L2 completion. A successful nested flush does not commit its caller's outer transaction.

`#[Immutable]` skips entity field/FK UPDATE while retaining INSERT, DELETE and related-entity/collection operations. It does not exclude the table from SchemaTool or make PHP objects immutable. It is independent of L2 write policy. See [immutable entities](immutable-entities.md).

The [flush boundary audit](flush-boundary-audit.md) records cross-component lifecycle, snapshot and recovery checks after the local extractions.

## SQL-first queries

DBAL QueryBuilder supplies SQL construction. ModernORM adds entity projections and explicit ResultSetMapping hydration. DQL parsing, AST walkers, association-path translation and DQL pagination are not part of the system.

SQL predicates use column names; `column(alias, field)` resolves a mapped property when needed. Joins and fetch mappings are explicit. Collection pagination uses a root-ID query and a separate hydration query. Parameterization protects values, not arbitrary identifiers or SQL fragments. See [SQL API](sql-query-api.md).

## Lazy batching

Association attributes choose `batchSize`; a missing value inherits the configuration default of 1. ManyToOne references are grouped by target class and effective size. OneToMany and ManyToMany queues are grouped by association role and size.

Reading one queued reference or collection loads compatible peers with one SQL IN query. Identity Map objects and pending changes are preserved. Queues use weak references and follow detach/clear/close. Streaming cannot batch future rows that have not been discovered. EAGER keeps its documented loading path. EXTRA_LAZY is removed; explicit association queries are separate from collection initialization. See [batch fetching](batch-fetching.md).

## Bulk writes

The PostgreSQL bulk API implements INSERT, ON CONFLICT, UPDATE and DELETE with raw RETURNING rows. Every call explicitly selects BYPASS or CLEAR. Neither policy flushes automatically or synthesizes per-row ORM callbacks. Version guards and trigger/cascade write dependencies are explicit.

BYPASS leaves the persistence context unchanged. CLEAR detaches the whole context after success, including pending work and lazy queues. SQL failure, confirmed commit followed by cleanup failure and unknown commit have different outcomes. SYNC, COPY, UPDATE FROM and automatic chunking are future work. See [bulk API](bulk-api.md).

## Cache boundaries

PSR-6 metadata and entity projection-plan caches are separate from L2 entity caching. Application namespaces must change when mappings or incompatible cache formats change; there is no automatic mapping fingerprint.

Entity L2 stores raw typed rows for flat entities with one scalar PK. `#[Cacheable]` chooses TTL and READ_ONLY or INVALIDATE write policy. Public operations are contains, evict and evictAll; regions, generations and persister wrappers remain internal. Query-result and collection L2 are excluded.

Generation tickets prevent late fills from surviving key/class eviction. Cached writes require an ORM/API-owned top-level transaction and invalidate before writing and after commit. Reads inside transactions bypass L2. PostgreSQL commit and PSR-6 invalidation are not one atomic operation. External writers and database side effects require application coordination. See [cache contract](read-only-l2-cache.md) and [scenario matrix](l2-scope-matrix.md).

## Statistics and integrations

Statistics belongs to one DBAL connection and measures SQL, flush, batch, bulk and hydration attempts plus UnitOfWork phases, duration, errors, selected group sizes and bounded fallback reasons. Optional immutable completion events provide a backend-neutral export boundary. Core keeps no event/SQL history and creates no active tracing spans. Overlapping SQL/flush/batch durations must not be added together.

ModernORMBundle owns Symfony services, lifecycle reset, Profiler and optional SQL history. Doctrine Migrations and Data Fixtures are integrated there through adapters; they are not required by the ORM core. Prometheus/OpenTelemetry exporters, PostgreSQL diagnostics and Grafana dashboards remain separate work. See [Statistics](statistics.md).

## Validation and evolution

Local persistence checks, dependency resolution, clean-consumer ZIP installation and diagnostic benchmarks are documented in [beta verification](beta-verification.md). Local checks do not substitute for Linux CI, a public package installation or production-backend certification. Performance comparisons require reproducible measurements; PostgreSQL specialization or removing DQL alone does not establish a speed advantage over Doctrine.

The [beta contract](beta-release.md) defines the intended public API and release gates. The [roadmap](../roadmap.md) distinguishes implemented behavior from future proposals. MIT and original provenance notices remain part of the distribution.

Schema convergence, long-lived contexts and current core/bundle compatibility results are recorded in the [stabilization pass](stabilization-pass.md). See the [lifecycle contract](lifecycle-contract.md) for mutation and recovery boundaries.
