# Changelog

## Unreleased

- Batch eight or more contiguous writable scalar fields of one embedded object with optional deepclone. Preserve embedded identity, constructor/hook bypass, root/child assignment order and readonly snapshot corrections; retain accessor fallback for small groups, nested/custom/readonly/enum/object mappings, runtime subclasses, lazy objects and null-to-uninitialized writes.

- Fix the CI hydration benchmark after removal of nested array graphs: measure and validate a supported flat array projection instead of the obsolete collection-graph scenario.

- Suggest optional ext-deepclone >=0.8.10 and automatically accelerate flat scalar entity hydration when available. Preserve accessor hydration for embedded/readonly/custom mappings, enum arrays, uninitialized lazy objects and inputs requiring ORM-specific conversion. Keep constructor bypass, property-hook bypass, assignment order and snapshots unchanged; exercise both paths in PHP 8.4/8.5 CI.

- Extract owning to-one FK normalization and loading decisions into EntityHydrator with explicit ToOneHydrationMode results. Keep Identity Map, registration, SQL, snapshots, batching and inverse repair in UnitOfWork; preserve SoftDelete and deferred-EAGER hint behavior.

- Extract scalar/embedded assignment into internal EntityHydrator. Keep Identity Map, registration, snapshots, associations and queues in UnitOfWork; apply readonly snapshot corrections in assignment order, preserving failure behavior.

- Extract pure scalar/embedded snapshot comparison into ChangeSetComputer, reusing FieldChangeComparator. Keep state, lifecycle, associations and scheduling in UnitOfWork; preserve INSERT/UPDATE permissions, missing-baseline recompute behavior and changeset ordering.

- Remove nested array graph hydration. HYDRATE_ARRAY/getArrayResult now accept one flat entity projection (with identifier) or scalar-only rows; reject graph/mixed/wrapper/DTO mappings before SQL. Preserve typed flat values, indexing and streaming; managed fetch joins and object-mode DTOs remain supported. Version hydration cache keys with full-v2-flat-array.

- Remove cascade refresh/detach and their builder/mapping helpers. all now expands to persist/remove only; reject removed options in mappings, listeners and cached metadata. Keep explicit refresh/detach scoped to one entity, including EAGER target state. Metadata cache salt is V15.

- Remove obsolete PHP <8.4 compatibility branches and ObjectCastPropertyAccessor; native lazy/raw property access is always used on the supported PHP versions.

- Remove partial managed entity hydration. Require complete field/embedded/FK projections for entity roots, joins and DTO entity arguments; reject legacy partial flags/hints and SQL missing declared columns. Retain row/scalar/DTO/array projections, native lazy references and batching. Replace partial-field snapshot tracking with weak lazy-reference markers and version hydration cache keys with the full-v1 contract.

- Remove EXTRA_LAZY mappings, constant/builder API and partial-read collection persister SQL. Use LAZY collections and Association Query API for explicit database queries. Reject removed fetch modes in mappings, overrides, listeners and cached metadata. Metadata cache salt is V14; restart workers and invalidate deployment caches.

- Exclude non-updatable fields from managed entity UPDATE change sets and recomputation, preventing phantom updates/events for generated-only local edits while retaining INSERT permissions.

- Skip transient and pending-insert association members during cascade refresh while restoring the owner from the database. Preserve explicit pending inserts, managed recursive refresh, cycle protection and detached-entity rejection.

- Retain generated non-nullable hooked IDs after deletion instead of attempting an illegal unset. Allow equivalent readonly DateTime refresh values while preserving object identity and change-tracking snapshots; changed readonly dates still reject refresh.

- Fix ManyToMany repository membership criteria on both sides without duplicate roots, including arrays, empty relations, count and pagination. Apply custom-type SQL conversion to quoted INSERT columns and preserve enum domain values for custom DBAL criteria converters.

- Remove doctrine/inflector; retain repository magic-method name conversion locally with its MIT notice.

- Remove doctrine/instantiator: materialize entities and missing embeddables through cached PHP Reflection without calling constructors or serialization/clone hooks. Move ext-ctype to development requirements and drop the unused direct PHP 8.6 polyfill requirement (Collections still requires it transitively).

- Restore metadata runtime mapping services from the owning configuration after cache deserialization, preserving custom naming/typed-field services without serializing them. Inherit explicit MappedSuperclass sequence definitions while retaining independent concrete defaults. Fix AnsiQuoteStrategy PK column names and native RSM field/FK/result-alias quoting. Metadata cache salt is V13.

- Preserve operations scheduled by postFlush for the next explicit flush, including callback failures and explicit dirty checking. Preserve local to-one changes and their baselines during lazy/EAGER hydration, with entity-scoped refresh. Scope nested array collection positions to the complete ancestor identity path. Add PostgreSQL and array-hydration regressions.

- Add immutable EntityManager::association() queries for OneToMany and both ManyToMany sides: database count/exists, typed field conditions and keyset pages without collection initialization or automatic flush. Preserve EXTRA_LAZY compatibility. Fix quoted foreign keys when loading ordinary OneToMany collections.

- Add SchemaTool::getSchemaMigrationSql() with reversible ordinary/FTS SQL plans for migration integrations, including initial schemas, missing assets and weight/configuration changes.
- Add mapped JSONB query expressions with bound containment, existence, immutable paths, JSON/text extraction and explicit JSONB/text/numeric comparisons. Preserve JSON type distinctions, support precise decimal comparison values and distinguish missing paths, JSON null and SQL NULL.

- Add CursorPaginator, Cursor and immutable CursorPage APIs adapted from Doctrine ORM, with PostgreSQL SQL integration, bidirectional keyset navigation, explicit root-field sorting, raw key snapshots, root deduplication before LIMIT and lazy total count. Collection fetch joins, nullable/expression sorting and mixed results are rejected. Include compound-index EXPLAIN evidence for 60,000 rows.

- Add QueryBuilder andWhereFullText(), addOrderByFullTextRank() and addFullTextRank() for mapped FullTextIndex searches, bound web-search input, relevance ordering and optional float scores. Support explicit index selection, joined entity aliases, cloning, pagination and SoftDelete visibility.

- Add repeatable class-level FullTextIndex mapping for weighted PostgreSQL STORED tsvector columns and GIN indexes. SchemaTool owns the technical assets, compares expressions without temporary DDL, repairs missing assets and updates weights/configuration. Removal, renames and source-type/set changes require explicit migrations; raw DBAL Schema export rejects FTS mappings. Metadata cache salt is V12.
- Preserve explicitly selected tables through schema filters when generating DROP SQL.

- Require DBAL 4.6 and resolve mapped types through the connection type provider across persistence, hydration, SQL generation, validation and L2 caching. Preserve DBAL Type parameter instances and isolate cached SQL projections by their type-specific SQL.

- Add executed INSERT/UPDATE/DELETE attempts, errors, timings and known affected-row aggregates at the DBAL driver boundary; annotate existing SQL events without retaining SQL text or claiming transaction commit.

- Add bulk `softDelete()` / `restore()` with guarded state transitions, typed scalar audit values, RETURNING, existing BYPASS/CLEAR and L2/transaction protocols, and distinct bulk Statistics events. Bulk operations do not run entity callbacks or automatically update versions/actors.

- Add query-local `onlyDeleted()` for SoftDelete roots while preserving active association visibility; cover remove/restore symmetry across repositories, mapped queries, lazy/batch collections, Identity Map, shared L2 and rollback. Document the visibility/lifecycle matrix.

- Add opt-in SoftDelete mapping and trait: logical `remove()`, explicit `restore()` / `hardDelete()`, query-local inclusion of deleted rows, repository/count/query-builder filtering and ordinary/batch collection visibility.
- Nullable to-one SoftDelete targets use eager loading; mandatory targets, cascade remove/orphanRemoval, EXTRA_LAZY targets, Immutable and READ_ONLY L2 combinations are rejected. Flat entity L2 uses the existing INVALIDATE commit/rollback protocol. Bulk DELETE remains physical.
- Extend EntityManagerInterface and its decorator with the SoftDelete API. Metadata uses cache salt V11; clear/version query-plan caches and restart workers when adopting changed mappings.

- Add `#[Blameable(actorClass: User::class)]` to generate nullable actor associations for BlameableTrait without redeclaring properties. Compatible explicit ManyToOne mappings remain supported.

## 0.1.0-beta.4 — 2026-11-05

- Add optional TimestampableTrait with immutable creation/update dates, import-value preservation on INSERT and lifecycle-based updates.
- Add BlameableTrait, actor-provider and entity contracts, and an opt-in listener for INSERT/UPDATE attribution without a Symfony dependency. Association mapping remains application-owned; actors must be managed by the receiving EntityManager.

- Prepare compact scalar hydration column plans once per result, preserving mapped type conversion, raw scalar values, streaming cleanup and Statistics. Adapted from Doctrine ORM PR #12699.
- Reduce parameter-processing allocations: append persister values/types, avoid recursive array merges and unchanged scalar-array writes, and stop parameter lookup at the first match without a temporary collection. Adapted from Doctrine ORM PRs #12691, #12693, #12701 and #12702; SQL and conversion semantics are preserved.
- Remove mapping-object ArrayAccess and its converting setters; use typed properties and mapping factories.
- Remove duplicate `ClassMetadata::$columnNames` and `FieldMapping::$default`; resolve column names from field mappings and declare defaults through `options['default']`.
- Preserve explicit version-field defaults and reject unsupported version types. Metadata uses a new cache salt for the simplified format.

- **Breaking:** remove the unused legacy reflection API and five compatibility classes, metadata table-name/lifecycle shortcuts, `UnrecognizedField::byName()`, `SequenceGenerator` Serializable methods and the no-op schema update `--complete` option.
- Preserve runtime property accessors, normal lifecycle dispatch and native sequence-generator serialization. Use property accessors, `setPrimaryTable()`, `byFullyQualifiedName()` and native serialization; omit `--complete`.

## 0.1.0-beta.3 — 2026-11-05

- Extend connection Statistics with bulk, hydration and UnitOfWork phase snapshots and immutable completion details for Profiler/metrics adapters.
- Measure bulk policies, known row counts and commit outcomes; hydration rows/new instances and active streaming time; change-set, write and commit phases with scheduled-work counts.
- Preserve reset/disable generations and observer isolation. New operation kinds require exhaustive custom observers to handle additional enum cases; persisted Profiler snapshot schemas should be versioned or cleared.

- Replace profiler mockups with captured Symfony panels and document the opt-in bundle OTLP prototype.
- Include PHPStan and PSR-12 CI gates introduced after beta.2.

## 0.1.0-beta.2 — 2026-11-05

Harden the bounded entity L2 contract and add Redis verification and performance evidence. The package remains beta.

- Validate generation tokens as canonical random-token strings; malformed metadata is replaced instead of reusing deterministic stale addresses.
- Add independent Redis/PHP process checks for stale fills, cold-generation races, optimistic writers, rollback and ORM/bulk invalidation.
- Exercise actual Redis socket loss, unresponsiveness, restart/RDB rollback and PHP writer death after physical COMMIT, with explicit recovery outcomes.
- Verify payload/generation TTL and real maxmemory eviction; record a 60-second one-writer/four-reader workload with sampled heap and Redis key retention.
- Add reproducible bulk and L2 benchmarks with SQL/cache counters, raw samples and documented measurement boundaries. Local batched SQL can outperform warm Redis L2; fewer SELECTs are not a blanket speed claim.
- Extend PHP/dependency CI with pinned Redis 8.10.3, disposable-server checks, short endurance and measurement artifacts; update the clean-consumer candidate version.

Existing valid cache generations and public APIs remain compatible. No collection/query cache, outbox, external cached-write transaction support or automatic failover is added. The commit/invalidation crash window, coordinated snapshot recovery and generation-metadata retention remain explicit limits; see [Redis verification](docs/l2-redis-verification.md).

## 0.1.0-beta.1 — 2026-11-05

First public beta. The supported contract is bounded; this is not a production-stable release.

- PostgreSQL-only persistence with PHP 8.4 native lazy objects, attributes, scalar entity identifiers, Identity Map and UnitOfWork.
- SQL-first QueryBuilder/entity hydration; DQL, inheritance mappings, composite entity IDs and ORM Filters are excluded.
- Lazy ManyToOne, OneToMany and ManyToMany batch fetching with association-level batch sizes.
- Independent Immutable entity semantics and entity-by-ID PSR-6 L2 with READ_ONLY or UPDATE/invalidate write policies.
- PostgreSQL bulk BYPASS/CLEAR operations and connection-scoped SQL/flush/batch Statistics with optional completion observers.
- Separate ModernORMBundle integration for Symfony, Profiler, migrations and fixtures.
- Release-archive/clean-consumer checks, dependency-matrix CI and reproducible local measurements.

See [beta scope and release gates](docs/beta-release.md) for supported scenarios and pending publication checks. There is no automatic Doctrine upstream synchronization policy.

Beta preparation corrections: require DBAL 4.3+ for the CTE QueryBuilder API, explicitly require psr/log 3 for development test loggers, and exclude local IDE/vendor/environment files from Composer archives.
