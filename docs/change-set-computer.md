# ChangeSetComputer boundary

ChangeSetComputer is a pure scalar/embedded snapshot comparison component shared by UnitOfWork's ordinary dirty checking and recompute path. It reads ClassMetadata and prepared snapshot arrays, returning field-name → old/new pairs. It does not receive EntityManager or entities, perform SQL, read properties, mutate snapshots/metadata, dispatch events or schedule operations.

FieldChangeComparator remains the value-level primitive: strict comparison, reference-based object tracking and normalization of original native enum values. ChangeSetComputer aggregates those comparisons across the prepared state and excludes associations. Existing embeddable-container identity comparisons are retained alongside flattened embedded fields.

## Preserved operation differences

- `compare()` returns changes eligible for UPDATE, excluding notUpdatable fields. Fields without an original baseline are skipped; a loaded null is a valid baseline.
- `compareForInsert()` re-compares a prepared INSERT without excluding insert-only fields. It does not create the initial INSERT changeset or decide the final SQL column list.
- UnitOfWork's recompute adapter supplies null baselines for absent fields, retaining its existing difference from ordinary dirty checking. It chooses the INSERT comparison only while an insertion is pending.

UnitOfWork retains property access, identity/version-field selection, entity-state and immutable/read-only checks, preFlush, native-reference reconciliation, initial INSERT changesets, PersistentCollection handling, association comparisons, orphan planning, snapshot updates, changeset merging and flush queues. Applying the scalar result in the original traversal order preserves changeset ordering alongside association changes. Transactions, callbacks and cache failure recovery are unchanged.

## Verification

ChangeSetComputerTest adds 15 scenarios / 35 assertions covering missing versus null baselines, strict types, enums/keyed enum arrays, metadata/input immutability, update/insert permissions, association exclusion, output ordering, embedded containers, mutable objects and UnitOfWork's missing-baseline recompute behavior without a database connection.

The retained generated-field, nullable/enum, preUpdate, embeddable, explicit-tracking, reference, queue and persistence regressions run in the full suite. PHP 8.4.27 and PHP 8.5.12 with PostgreSQL 18.7: **1169 tests / 7839 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan and PSR-12 pass without new suppressions.

This extraction introduces no public API or cache/schema migration. It does not move state ownership or create an independent change-tracking engine.
