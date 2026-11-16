# EntityHydrator boundary

EntityHydrator owns scalar/embedded assignment and pure owning to-one loading decisions extracted from UnitOfWork::createEntity(). Its scalar assignment operation receives ClassMetadata, the already selected entity and prepared PHP values. It uses property accessors or the optional native fast path below, ignoring associations and unmapped SQL/meta values. It has no EntityManager/UnitOfWork dependency, performs no SQL, creates no managed registrations and owns no snapshots or queues.

The coordinator still selects the canonical object, decides whether to initialize/refresh it, registers it, initializes its snapshot and controls read-only state. Association restoration execution, native references, eager/batch queues and postLoad remain in UnitOfWork. Projection completeness checks and DBAL conversion remain at their existing query/SQL/L2 hydration boundaries; this internal assignment operation does not reintroduce partial managed SQL projections.

## Readonly snapshot corrections

An accessor can retain the existing readonly DateTime when a newly converted object represents the same instant. UnitOfWork must snapshot the retained object to avoid a phantom UPDATE. hydrateScalars() therefore returns a generator that must be consumed: each readonly correction is yielded immediately after its field assignment. UnitOfWork applies the correction before the next field is processed.

This preserves input order and partial-assignment failure behavior: if a later assignment throws, earlier successful assignments and readonly snapshot corrections have already happened. The helper does not catch errors or add rollback behavior. It does not move snapshot ownership into the component or pass the coordinator back through a callback.

## Optional deepclone acceleration

Install and enable [ext-deepclone](https://github.com/symfony/php-ext-deepclone) >=0.8.10 to enable automatic native assignment for flat entities. It is a Composer suggestion, with no mandatory dependency or ORM configuration. The runtime also checks for `deepclone_hydrate()` and `DEEPCLONE_HYDRATE_NO_LAZY_INIT`; missing or older extensions use the accessor path.

Eligible mappings use standard raw/typed accessors, including backed-enum properties whose declared type matches the ORM enum mapping. Field keys preserve inherited private and protected scope. One native call writes the filtered mapped fields in input order without invoking hooks. Entity construction remains under ClassMetadata/UnitOfWork.

For the flat-entity fast path, readonly, virtual or custom field accessors, enum-array mappings, and uninitialized lazy objects retain accessor hydration. Inputs containing NULL for non-nullable fields or enum scalars with the wrong backing type also fall back before any assignments happen. This preserves strict enum conversion, null-to-uninitialized behavior, readonly DateTime identity, snapshot corrections and partial-assignment failures. Native errors propagate without retrying assignments.

### Embedded batches

Entities with embeddeds can batch **eight or more contiguous eligible fields of one embedded object** through internal EmbeddedHydrator. The threshold follows the measured eight-field benefit; one-field native batches were slower. Smaller segments use the original accessor writes. No field is moved across a mapped root field, another embedded group or an unsupported child assignment. Unmapped input remains ignored.

Eligibility requires direct standard parent/child accessors and a writable backed property typed `string`, `int`, `float` or `bool`, including nullable variants. Private/protected field keys retain their declaring scope, and raw writes bypass set hooks. Nested embeddeds, readonly parents/children, enums, arrays/objects, virtual properties and custom accessors stay on accessors. Incoming objects also force an accessor write: their scalar coercion can invoke user code and replace the parent's embedded reference. A NULL for a non-nullable child similarly forms a boundary and retains its original unset behavior. Referenced input values are written separately after flushing preceding fields, then reread so references to those fields observe their updated values. The same rule applies to small buffered segments.

Before child writes, a missing embedded is created without its constructor and attached through its existing parent accessor. Existing embedded objects are updated in place. Uninitialized lazy embedded objects and runtime subclasses use the accessor path; a batch never replaces them with the mapping's concrete class. Native errors leave earlier writes and the already attached object observable, without retrying. Root readonly snapshot corrections are yielded before the next segment executes.

Embedded plans use a separate WeakMap with accessor-identity invalidation. No persistence-context state, entity instance, snapshot or SQL moves into the batch helper, and no metadata cache format changes. [Production before/after measurements](../benchmarks/results/deepclone-embedded.md) include these checks and fallbacks rather than only the prototype.

### Specialized flat SQL plan

Built-in ObjectHydrator and SimpleObjectHydrator can compile one [FlatSqlHydrationPlan](flat-sql-hydration-plan.md) for an active SQL result. The first version supports a single complete entity root with direct `int`, `float`, `string` or `bool` properties, including nullable/private/protected/backed-hooked fields. It passes the already normalized entity-data array directly to deepclone, eliminating generic per-row field-map lookups and argument-array rekeying for this case. The existing generic native/accessor and embedded paths remain available for other mappings.

Only fresh objects use this plan, after normal UnitOfWork registration. Refresh/existing objects, custom hydrators/UnitOfWork implementations, inheritance, associations, embedded/readonly/enum/array/object properties retain their previous paths. Runtime metadata/accessor changes invalidate applicability; object-valued coercions and NULL-to-non-nullable input fall back before writes. The private context hint is cleared on prepare/cleanup and never installed on Query itself or serialized into mapping/result caches. No createEntity signature changes or global mutable UnitOfWork context are introduced.

The field plan is cached in a WeakMap keyed by metadata, so clearing an EntityManager does not create a global class-name retention cache. Replacing a runtime property accessor invalidates its plan; plans are not serialized into metadata caches. Full suites run with the extension on PHP 8.4/8.5 in the locked-dependency PostgreSQL 18 CI jobs, after their ordinary extension-free runs. The [benchmark](../benchmarks/README.md#experimental-deepclone-scalar-hydration) distinguishes the initial experiment from the guarded production path (`orm` mode).

## Owning to-one loading decisions

`toOneIdentifier()` extracts the target identifier from physical FK columns, preserving missing/NULL handling and normalizing backed enums without converting zero or empty string identifiers to NULL.

`planToOneLoad()` accepts the target metadata, identifier and observed Identity Map/lazy/fetch facts. It returns a ToOneHydrationMode enum: NULL, SoftDelete visibility lookup, managed reuse (with/without deferred eager scheduling), new reference (with/without deferred eager scheduling), or immediate eager lookup. It does not inspect entity properties, read Identity Map or execute a lookup itself.

UnitOfWork observes identity/lazy facts only where the old path observed them. NULL and SoftDelete cases bypass raw Identity Map resolution. Queue writes, reference creation/registration, find(), assignment, snapshot changes, ManyToOne batching and inverse-link repair retain their previous order in the coordinator. Inverse-side loads and associations already supplied as registered objects also remain there; to-many handling is unchanged.

The deferred eager hint retains its existing distinction: an existing eager proxy is queued when the hint is present, even when false; a new eager reference is deferred only when the hint is enabled. This extraction characterizes that behavior instead of changing it. Existing dirty-state and entity-scoped refresh checks remain unchanged.

## Validation

EntityHydratorTest includes **15 scenarios / 58 assertions** covering private/protected/inherited fields, raw property-hook bypass, ignored associations/unmapped values, constructor-free embedded materialization, equivalent and changed readonly dates, snapshot correction before a later failure, uninitialized readonly values and empty input. Additional cases verify string/int enums (including zero), enum arrays and strict conversion errors, NULL-to-uninitialized handling, lazy ghosts, partial assignment after errors, property/input references and runtime custom-accessor replacement. These tests run both with and without the extension. Metadata setup and assignment require no database connection or managed registration.

EmbeddedHydratorTest adds **11 scenarios / 76 assertions** for visibility, constructor/hook bypass, nullable fields, identity preservation, multiple groups, interleaved writes, partial failures, root readonly correction order, nested/readonly/enum/custom fallbacks, lazy/subclass targets, cache invalidation, side effects during string coercion and cross-field input references. EmbeddedBatchHydrationTest adds one SQL scenario / 21 assertions for loading, refresh, readonly snapshots and subsequent embedded dirty checking/flush. All 12 new cases pass with and without the extension.

Existing SQL/object hydration, readonly refresh, native reference, embeddable, enum, cache, postLoad and worker regressions run unchanged. With deepclone 0.8.10 enabled, PHP 8.4.27 and PHP 8.5.12 on PostgreSQL 18.7 report **1223 tests / 8069 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan and PSR-12 pass without new suppressions.

ToOneHydrationTest adds 23 scenarios / 53 assertions for FK values/enums, loading decisions, input immutability, SoftDelete precedence, managed reuse and hint presence/truthiness. Four PostgreSQL cases in CriticalHydrationRegressionTest verify actual identity reuse, deferred/immediate EAGER execution and snapshots, including the disabled-hint case. Existing SoftDelete, dirty relation, refresh, lazy/batch/L2, inverse-link and postLoad regressions remain covered.

No public API, schema or cache-format migration is introduced. To-many extraction is a separate future step.
