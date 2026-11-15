# EntityHydrator scalar assignment boundary

The first EntityHydrator extraction moves only scalar/embedded field assignment out of UnitOfWork::createEntity(). It receives ClassMetadata, the already selected entity and prepared PHP values. It uses the existing property accessors, ignoring associations and unmapped SQL/meta values. It has no EntityManager/UnitOfWork dependency, performs no SQL, creates no managed registrations and owns no snapshots or queues.

The coordinator still selects the canonical object, decides whether to initialize/refresh it, registers it, initializes its snapshot and controls read-only state. Association restoration, native references, eager/batch queues and postLoad remain in UnitOfWork. Projection completeness checks and DBAL conversion remain at their existing query/SQL/L2 hydration boundaries; this internal assignment operation does not reintroduce partial managed SQL projections.

## Readonly snapshot corrections

An accessor can retain the existing readonly DateTime when a newly converted object represents the same instant. UnitOfWork must snapshot the retained object to avoid a phantom UPDATE. hydrateScalars() therefore returns a generator that must be consumed: each readonly correction is yielded immediately after its field assignment. UnitOfWork applies the correction before the next field is processed.

This preserves input order and partial-assignment failure behavior: if a later assignment throws, earlier successful assignments and readonly snapshot corrections have already happened. The helper does not catch errors or add rollback behavior. It does not move snapshot ownership into the component or pass the coordinator back through a callback.

## Validation

EntityHydratorTest adds **6 scenarios / 23 assertions** covering private/nullable fields, raw property-hook bypass, ignored associations/unmapped values, constructor-free embedded materialization, equivalent and changed readonly dates, snapshot correction before a later failure, uninitialized readonly values and empty input. Metadata setup and assignment require no database connection or managed registration.

Existing SQL/object hydration, readonly refresh, native reference, embeddable, enum, cache, postLoad and worker regressions run unchanged. The full suite includes this extraction and the preceding ChangeSetComputer extraction: PHP 8.4.27 and PHP 8.5.12 on PostgreSQL 18.7 report **1175 tests / 7862 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan and PSR-12 pass without new suppressions.

No public API, schema or cache-format migration is introduced. To-one/to-many extraction is a separate future step.
