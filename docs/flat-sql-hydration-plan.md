# Flat SQL hydration plan

FlatSqlHydrationPlan is one specialization for complete single-root SQL rows, not a general instruction interpreter. ObjectHydrator and SimpleObjectHydrator prepare it on the first entity-data row and reuse it for the active result. Unsupported contexts/mappings are considered once and stay on the existing EntityHydrator path. The metadata WeakMap caches of the generic native/embedded paths are unchanged.

## Eligibility and execution

The first version accepts standard raw/typed accessors for directly declared primitive `int`, `float`, `string` and `bool` properties, including nullable variants, private/protected visibility and backed property hooks. Inheritance, associations, embeddeds, PHP readonly/virtual properties, enums, arrays/objects and custom accessors are excluded. The result must contain one entity alias and no scalar/DTO/meta-column mix; refresh contexts and custom hydrator/UnitOfWork implementations are excluded. Existing managed or lazy entities do not take the specialized path.

Compilation checks complete field membership against metadata and records the original accessors/mappings and the non-nullable-field set. It does not generate closures, an operation list, runtime-guard objects or separate native/accessor/mixed plan classes. A new query recompiles its own context; there is no process-global plan cache or persisted plan format.

After UnitOfWork selects, creates and registers a new entity, the plan checks exact metadata/object class, unchanged accessor/mapping arrays, field count and lazy state once for the group. One value scan rejects object coercions and NULL-to-non-nullable writes before assignment; those cases use the existing path. Eligible data is passed directly to `deepclone_hydrate()` with NO_LAZY_INIT. Own private/protected names need no mangling, so neither filtering nor a second argument array is needed per entity. Errors propagate with earlier writes still observable, matching ordinary assignment order.

SQL producers guarantee shape and reference-free top-level elements: both built-in hydrators copy converted column values into a fresh entity-data array. Raw driver references are dereferenced by that normalization, which is tested. Arbitrary arrays and custom hydration hooks are not granted that guarantee and retain their existing path. The plan is an internal SQL contract, not an alternative public hydration API.

## Context and compatibility

The existing createEntity signature remains unchanged. An internal hint carries the plan in the SQL hydrator's private hints copy; preparation removes any incoming value for that reserved key, and cleanup clears it on success/error. Query hints, mapping/hydration caches and UnitOfWork state do not store the plan. Plans are never copied into original entity data or listener arguments. No global mutable hydration scope is installed on UnitOfWork, so nested queries use independent hydrators.

UnitOfWork retains canonical identity selection, registration-before-assignment, read-only policy, original snapshots, associations and postLoad. Refresh and already managed entities follow existing rules. Replaced accessors/mapping arrays make a cached context plan inapplicable on subsequent rows; the existing compatibility path handles them. Reusing a hydrator for another result resets its plan and compiles the new field shape.

## Validation and performance

Fourteen unit cases cover both SQL hydrators, context reuse/cleanup, normalized driver references, custom referenced rows, metadata/accessor replacement, managed/refresh identity, nullable values, hook/constructor bypass, readonly/inherited/incomplete mappings and partial assignment before a hooked-property failure. Five PostgreSQL cases cover real shuffled columns, ignored columns, postLoad, identity registration, Query hint isolation, dirty checking/flush/refresh and TypeError prefix/resource cleanup.

The full suite with deepclone enabled passes sequentially on PHP 8.4.27 and PHP 8.5.12 with PostgreSQL 18.7: **1269 tests / 8401 assertions / 21 Redis skips** per runtime. The 19 new cases also pass without deepclone. PHPStan and PSR-12 pass without suppressions.

Local measurements on PHP 8.4/8.5 showed an additional **1.04–1.10×** speedup for complete managed SQL hydration with deepclone already enabled. The workload used 20,000 entities, two warmups and nine measured rounds, including fetching, conversions, plan compilation, Identity Map and snapshots; SQL execution and validation were excluded. JSON fallback and extension-free controls were within about 1–2% of the previous implementation. Entity memory was essentially unchanged. First-query/single-row latency and cross-query plan caching are not established by this batch workload. Benchmark scripts and raw samples are kept outside this change.
