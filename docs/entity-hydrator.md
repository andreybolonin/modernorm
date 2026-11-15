# EntityHydrator boundary

EntityHydrator owns scalar/embedded assignment and pure owning to-one loading decisions extracted from UnitOfWork::createEntity(). Its scalar assignment operation receives ClassMetadata, the already selected entity and prepared PHP values. It uses the existing property accessors, ignoring associations and unmapped SQL/meta values. It has no EntityManager/UnitOfWork dependency, performs no SQL, creates no managed registrations and owns no snapshots or queues.

The coordinator still selects the canonical object, decides whether to initialize/refresh it, registers it, initializes its snapshot and controls read-only state. Association restoration execution, native references, eager/batch queues and postLoad remain in UnitOfWork. Projection completeness checks and DBAL conversion remain at their existing query/SQL/L2 hydration boundaries; this internal assignment operation does not reintroduce partial managed SQL projections.

## Readonly snapshot corrections

An accessor can retain the existing readonly DateTime when a newly converted object represents the same instant. UnitOfWork must snapshot the retained object to avoid a phantom UPDATE. hydrateScalars() therefore returns a generator that must be consumed: each readonly correction is yielded immediately after its field assignment. UnitOfWork applies the correction before the next field is processed.

This preserves input order and partial-assignment failure behavior: if a later assignment throws, earlier successful assignments and readonly snapshot corrections have already happened. The helper does not catch errors or add rollback behavior. It does not move snapshot ownership into the component or pass the coordinator back through a callback.

## Owning to-one loading decisions

`toOneIdentifier()` extracts the target identifier from physical FK columns, preserving missing/NULL handling and normalizing backed enums without converting zero or empty string identifiers to NULL.

`planToOneLoad()` accepts the target metadata, identifier and observed Identity Map/lazy/fetch facts. It returns a ToOneHydrationMode enum: NULL, SoftDelete visibility lookup, managed reuse (with/without deferred eager scheduling), new reference (with/without deferred eager scheduling), or immediate eager lookup. It does not inspect entity properties, read Identity Map or execute a lookup itself.

UnitOfWork observes identity/lazy facts only where the old path observed them. NULL and SoftDelete cases bypass raw Identity Map resolution. Queue writes, reference creation/registration, find(), assignment, snapshot changes, ManyToOne batching and inverse-link repair retain their previous order in the coordinator. Inverse-side loads and associations already supplied as registered objects also remain there; to-many handling is unchanged.

The deferred eager hint retains its existing distinction: an existing eager proxy is queued when the hint is present, even when false; a new eager reference is deferred only when the hint is enabled. This extraction characterizes that behavior instead of changing it. Existing dirty-state and entity-scoped refresh checks remain unchanged.

## Validation

EntityHydratorTest adds **6 scenarios / 23 assertions** covering private/nullable fields, raw property-hook bypass, ignored associations/unmapped values, constructor-free embedded materialization, equivalent and changed readonly dates, snapshot correction before a later failure, uninitialized readonly values and empty input. Metadata setup and assignment require no database connection or managed registration.

Existing SQL/object hydration, readonly refresh, native reference, embeddable, enum, cache, postLoad and worker regressions run unchanged. The full suite includes this extraction and the preceding ChangeSetComputer extraction: PHP 8.4.27 and PHP 8.5.12 on PostgreSQL 18.7 report **1202 tests / 7937 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan and PSR-12 pass without new suppressions.

ToOneHydrationTest adds 23 scenarios / 53 assertions for FK values/enums, loading decisions, input immutability, SoftDelete precedence, managed reuse and hint presence/truthiness. Four PostgreSQL cases in CriticalHydrationRegressionTest verify actual identity reuse, deferred/immediate EAGER execution and snapshots, including the disabled-hint case. Existing SoftDelete, dirty relation, refresh, lazy/batch/L2, inverse-link and postLoad regressions remain covered.

No public API, schema or cache-format migration is introduced. To-many extraction is a separate future step.
