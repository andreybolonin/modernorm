# Persistence lifecycle audit — 2026-11-07

This audit checks selected inherited collection and UnitOfWork behavior against local PostgreSQL. It is not a claim that all Doctrine issues have been reviewed or that all persistence combinations are certified.

The same **36 test scenarios** ran against baseline ModernORM `203e165eee7b9296dae15f0a192d55103b7b5006` and the changes accompanying this report. **23 scenarios failed before; all 36 pass after.** These are data-provider cases, not 23 independent bugs. Tests for documented limitations pass by asserting the expected failure or state boundary. [Per-scenario results](evidence/persistence-audit-2026-11-07.json) · [Test suite](../tests/Integration/PersistenceAuditTest.php).

Statuses: **fixed** means reproduced on the recorded baseline and passing after a code change; **documented limitation** means the behavior is reproduced and deliberately retained; **not reproduced** means this particular contract already passed; **not applicable** means excluded by ModernORM's validated feature boundary. Verified workarounds are identified separately.

## Findings

| Area / scenario | Status | Result and evidence |
| --- | --- | --- |
| Replace an initialized/uninitialized orphan collection, retaining all or some members | Fixed | Retained entities survive; only discarded members are removed. `testReplacementDeletesOnlyDiscardedChildren` |
| Replace with an empty collection | Fixed | Children are removed through entity lifecycle, rather than a bulk DELETE that skips callbacks. Same provider, `keep none` |
| Replace collections on an immutable/read-only class | Fixed | Association processing uses the same membership planning; retained children survive. Same provider, `read-only owner` |
| Replace with retained and new children | Fixed | Retained rows survive, discarded rows disappear and new children are inserted. `testReplacingCollectionWithKeptAndNewMembers` |
| Remove one of several occurrences of the same entity | Fixed | `remove()` and `removeElement()` do not orphan an entity still present in the collection. `testRemovingOneOccurrenceDoesNotOrphanRemainingMember` |
| Replace an indexed element using `set()` | Fixed | Only the displaced entity is orphaned; another entity held under a second key survives removal of one occurrence. `testReplacingIndexedMemberOrphansOnlyTheDisplacedEntity` |
| `clear()` followed by re-adding a kept child and a new child | Not reproduced | Kept/new children survive and genuinely removed children disappear. `testClearAndReaddPreservesKeptChildAndInsertsNewChild` |
| Explicit transfer between owners with wholesale replacement | Fixed | Update the owning side and both collections in the same flush. Both source-first and destination-first discovery are tested. `testExplicitTransferWithCollectionReplacementSurvivesRegardlessOfDiscoveryOrder` |
| EXTRA_LAZY OneToMany `get(id)` | Fixed | Checks the owner even when a foreign target is already managed; missing/foreign IDs return null without initializing the collection. `testExtraLazyOneToManyLookupChecksOwnerEvenForManagedTarget` |
| EXTRA_LAZY ManyToMany `get(id)`, owning and inverse sides | Fixed | Lookup checks join-table membership instead of calling `find()` for an arbitrary target ID. `testExtraLazyManyToManyLookupChecksJoinTable` |
| EXTRA_LAZY bidirectional ManyToMany indexed by a non-ID field | Not reproduced | Owner-scoped lookup already worked and remains covered. Same provider, `owning/inverse by code` |
| EXTRA_LAZY unidirectional ManyToMany indexed by ID or another field | Fixed | Does not require an inverse property to scope the query. Same provider, `unidirectional` |
| Remove an entity and insert a replacement with the same immediate UNIQUE value in one flush | Documented limitation | INSERT precedes entity DELETE; PostgreSQL rejects the insert and the flush rolls back/closes the manager. `testImmediateUniqueReplacementInOneFlushFailsAndRollsBack` |
| Swap two immediate UNIQUE values in one flush | Documented limitation | Sequential UPDATEs can violate the intermediate constraint; the transaction rolls back. `testImmediateUniqueSwapInOneFlushIsDocumentedLimitation` |
| Two flushes inside one transaction for UNIQUE replacement | Verified workaround | Delete/flush, then insert/flush, then commit. `testTwoFlushesWithinTransactionReplaceUniqueValueAtomically` |
| PostgreSQL deferred UNIQUE for one-flush replacement | Verified workaround | A manually created `DEFERRABLE INITIALLY DEFERRED` constraint checks final transaction state. `testDeferredUniqueConstraintSupportsOneFlushReplacement` |
| Required FK insertion and cascade deletion order | Not reproduced | Parent is available before child INSERT; dependent child is deleted before parent. `testForeignKeyInsertAndDeleteOrder` |
| Nullable cycles / mixed nullable-required cycles | Not reproduced | Existing `UnitOfWorkContractsTest` covers insert completion and unlinking |
| Required generated-ID cycle | Documented limitation | Existing `UnitOfWorkContractsTest` verifies explicit cycle failure before INSERTs; no automatic constraint deferral |
| `onFlush` with explicitly recomputed changes and an initially nonempty queue | Not reproduced | Values persist and a subsequent flush is stable. `testOnFlushRecomputedChangeAndSubsequentFlushAreStable` |
| `onFlush` schedules an INSERT/UPDATE from an initially empty queue | Fixed | Explicit `computeChangeSet()` / `recomputeSingleEntityChangeSet()` work is executed instead of discarded by the empty-flush branch. `testOnFlushCanScheduleWorkFromInitiallyEmptyQueue` |
| Nested `flush()` from a callback | Fixed | Rejected with `LogicException` before re-entering UnitOfWork. The guard resets after failure. `testNestedFlushIsRejectedBeforeItCanWrite` |
| Exception from `preUpdate` | Not reproduced | Database writes roll back, the manager closes and PHP values remain changed. `testPreUpdateFailureRollsBackAndClosesManager` |
| Exception from `postFlush` | Fixed | Already completed database work is not undone; internal flush queues/change sets are cleared in `finally`. `testPostFlushFailureDoesNotReplayCommittedInsert` |
| Manual outer transaction rollback after successful flush | Documented limitation | SQL rolls back, but PHP values and UnitOfWork snapshots are not restored. Clear/reload the context before reuse. `testExternalRollbackRequiresClearingTheManagedState` |
| Outer failure through `wrapInTransaction()` | Not reproduced | Rollback closes the context and leaves the database unchanged. `testTransactionWrapperClosesContextAfterOuterRollback` |
| Replace only the inverse collection without orphan removal | Documented limitation | Does not rewrite the owning FK; rows and links remain. `testReplacingInverseCollectionWithoutOrphanRemovalKeepsDatabaseLinks` |
| SoftDelete combined with cascade remove/orphanRemoval or EXTRA_LAZY targeting SoftDelete | Not applicable | Mapping validation already rejects these combinations. `SoftDeleteMappingTest::testUnsupportedScenariosAreRejected`; this audit does not enable them |
| DQL, entity inheritance, composite entity IDs and other databases | Not applicable | Excluded by the supported mapping/query boundary |

Related upstream reports: [orphan collection replacement #6999](https://github.com/doctrine/orm/issues/6999), [retained members and transfers #12642](https://github.com/doctrine/orm/issues/12642), [EXTRA_LAZY membership #8607](https://github.com/doctrine/orm/issues/8607), [UNIQUE commit order #5109](https://github.com/doctrine/orm/issues/5109). A shared mechanism does not establish that every variation in an upstream report reproduces here.

## Association contract

For OneToMany orphan removal, changing the collection container is not a request to delete every previous child. Membership is compared by managed object identity; replacement enumerates the old collection when necessary. This can load an uninitialized collection and uses per-entity removal/callbacks. It trades the old bulk-delete shortcut for correct retained membership and lifecycle.

Update the owning FK and inverse collections together for an explicit transfer. Loading the inverse collection preserves an already modified owning-side value. This does not synchronize arbitrary inconsistent graphs or permit multiple owners for a single OneToMany child. Detached copies of entities must be resolved to managed instances first.

EXTRA_LAZY indexed lookup includes association membership in its SQL. A managed target alone does not prove membership. Locally pending indexed values are returned from the collection's current contents; database lookups keep the collection uninitialized.

## Flush and transaction contract

Callbacks must not call `flush()` recursively, including from `postFlush`. Start any subsequent unit of work outside the callbacks, after the outer call returns; enqueuing new ORM work from `postFlush` is outside this audited contract. `preFlush` remains the normal point for changes before automatic detection. `onFlush` listeners must explicitly compute the change set for a new entity or recompute it for a changed managed entity. The audit covers these scalar/entity cases; arbitrary association graph changes during `onFlush` are not certified.

`postFlush` runs after the flush's database transaction boundary. Without an outer transaction, its exception does not undo a commit. With an outer transaction, durability still depends on the caller's eventual commit/rollback. Cleanup clears completed flush state even if the callback throws; external side effects from callbacks are not rolled back by the ORM.

The ORM does not automatically solve intermediate UNIQUE conflicts. Use separate flushes inside one transaction when replacing a row:

```php
$em->wrapInTransaction(function ($em) use ($old, $replacement): void {
    $em->remove($old);
    $em->flush();
    $em->persist($replacement);
    // wrapInTransaction performs the final flush and commit.
});
```

For suitable PostgreSQL schemas, deferred UNIQUE constraints are another option and must be created explicitly by a migration. Ordinary unique/partial indexes are not automatically made deferrable; the test does not establish a solution for partial UNIQUE indexes or every multi-entity FK dependency.

A manual database rollback cannot rewind object properties, generated identifiers or UnitOfWork snapshots. After an external rollback, clear/reload or replace the context. The transaction wrapper closes the context on failure. A database write error during flush closes the manager; do not retry that manager.

## Component boundary introduced

`Internal/UnitOfWork/AssociationChangePlanner` now owns candidate scheduling/cancellation, origin tokens, reasons and the final orphan policy. It compares materialized object arrays and consumes `AssociationMembership` facts. It has no dependency on EntityManager, metadata, persisters or PersistentCollection and performs no SQL/property access. Origins are opaque tokens interpreted by the UnitOfWork adapter.

PersistentCollection reports candidate changes for associations with orphan removal enabled; it does not decide final membership. UnitOfWork observes current collection membership and owning-side references lazily, delegates the decision, and invokes the existing removal lifecycle. Short-circuit observation and per-candidate execution preserve loading/callback order. Candidate snapshots keep insertion order, and flush cleanup/clear discard pending candidates and their origins.

The original 36-case comparison above remains historical audit evidence. The local extraction adds 11 isolated planner tests plus two PostgreSQL regression cases: retaining a previously scheduled orphan via collection replacement, and clearing pending candidates before a later flush. One-to-one candidates without collection origins retain their previous unconditional scheduling behavior; no new transfer semantics or SQL ordering are introduced.

A subsequent local extraction moves INSERT/DELETE graph ordering into `Internal/UnitOfWork/WriteOrderPlanner`. UnitOfWork supplies streamed `InsertDependency` / `DeleteDependency` facts and per-entity deletion groups. Separate cascade and ordinary dependency observations preserve the previous two-pass read order, SCC representatives and graph edge order. The planner reads no ORM state and performs no SQL. Thirteen isolated tests cover ordering and invalid graph input; four PostgreSQL tests verify actual FK behavior and callback order. The same four tests also pass against the pre-extraction UnitOfWork at `ef8c003`.

UPDATE sorting, SQL execution, callbacks, transactions and generated-ID assignment remain in UnitOfWork. UNIQUE conflicts stay documented limitations; this extraction does not attempt to fix them.

Flush completion is subsequently separated into private UnitOfWork methods: `commitFlushTransaction`, `handleFailedFlush`, `completeSuccessfulFlush`, `synchronizeVisitedCollections`, and `dispatchPostFlushAndCleanup`. No independent coordinator class or new public API is introduced. Existing exception wrapping, uncertain-COMMIT cache recovery, manager/connection handling and phase metrics retain their previous order. Cache completion failure stops before snapshots/`postFlush`; a `postFlush` exception clears completed queues without entering write-failure rollback.

Two additional lifecycle tests cover an empty flush whose callback fails followed by a successful update, and a callback failure after releasing the flush savepoint inside a manual outer transaction. The existing post-commit L2 failure test now also asserts that `postFlush` is not dispatched. Existing write-failure, deferred-constraint COMMIT failure, lost-acknowledgement, cache-recovery and outer rollback tests remain regression coverage. Collection snapshot errors and arbitrary `onClear` failure combinations are not given new semantics by this extraction.

Scalar-field comparison is shared by `computeChangeSet` and `recomputeSingleEntityChangeSet` through `Internal/UnitOfWork/FieldChangeComparator`. It receives PHP values and an enum flag, returns either an old/new pair or no change, and never reads metadata or mutates snapshots. Original backed-enum values (including keyed arrays) retain the existing normalization; comparison remains strict and object identity based. DBAL type conversion, property access, partial-load eligibility, association processing, snapshot updates and changeset merging remain in UnitOfWork. In-place mutation of a mutable `DateTime` remains undetected; replace the instance to persist it.

Twelve isolated comparator cases cover nullable values, strict types, enum arrays, snapshot immutability and object references. Three additional PostgreSQL lifecycle cases cover nullable/enum round trips, mutable-date replacement and explicit changeset merging followed by a clean repeated flush. The complete lifecycle test file passes against both the previous UnitOfWork and the extracted implementation; existing `preUpdate` tests also verify additional field changes after callbacks. This is the comparison boundary only, not an independent change-tracking engine. Full regression validation after this extraction passes 681 tests on PHP 8.5.12 and PHP 8.4.27 with PostgreSQL and disposable Redis, without skipped tests; PHPStan and PSR-12 checks pass.

`Internal/UnitOfWork/FlushOperationQueues` subsequently takes ownership of eight pending-work maps: entity insertions/updates/deletions, extra updates, collection updates/deletions, visited collections and collection-element removal keys. Maps remain keyed by object identity and retain insertion order. Accessors return array snapshots, not mutable references. The component merges extra updates with the latest field pair winning, cancels collection updates on explicit whole-collection deletion, and provides common cleanup for completed/empty flushes and `clear()`. It never executes SQL, inspects metadata, mutates collections or dispatches callbacks.

UnitOfWork retains entity-state validation, Identity Map changes, changeset computation/merging, dirty-check scheduling, orphan policy adaptation, write ordering and lifecycle/transaction execution. Successful collection snapshot synchronization still precedes `postFlush`, cleanup still runs in `finally` after that event, and manager closure still clears pending work on write failure. Public scheduled-operation getters retain their previous shape and object-ID keys. Detach cancels only the entity INSERT/UPDATE/DELETE queues, preserving the previous extra-update behavior. Direct collection-replacement deletion and explicit scheduled collection deletion retain their distinct update-cancellation behavior.

Eight isolated queue tests cover object identity/order, snapshot isolation, extra-update merging, detach cancellation, collection removal markers, cleanup and release of retained references. Five added PostgreSQL lifecycle cases cover cancellation of new INSERTs by remove/detach, clearing mixed entity queues before `onClear`, extra-update merging without replay, cancelling DELETE through `persist()`, and extra-update-only empty-flush cleanup. All 60 lifecycle/persistence-audit/write-order integration cases pass against both the preceding UnitOfWork and this extraction. Existing callback-failure, rollback, cache and collection tests remain regression coverage.

Extra updates and collection synchronization alone still do not start a flush transaction; an otherwise empty flush discards extra updates during cleanup. This extraction records that existing internal contract rather than changing it. No new recovery/retry semantics or public queue API are introduced. Full regression validation after the queue extraction passes 703 tests on PHP 8.5.12 and PHP 8.4.27 with PostgreSQL and disposable Redis, without skipped tests. PHPStan and PSR-12 pass without new baseline suppressions.

These are local extractions, not a full UnitOfWork redesign. A future write-plan/executor split should preserve these tests plus the existing lifecycle, optimistic-locking, cache and rollback contracts. Moving methods between classes without explicit data and phase boundaries would retain the same coupling.

## Reproduction and scope

Use a dedicated test PostgreSQL database; the integration suite creates/drops its own `modernorm_audit_*` tables. Connection settings follow the other integration tests.

```sh
PGUSER=your_role PGDATABASE=modernorm_test php vendor/bin/phpunit tests/Integration/PersistenceAuditTest.php
php vendor/bin/phpunit tests/Unit/SoftDeleteMappingTest.php
composer quality
```

The per-scenario comparison used PHP 8.5.12. To repeat the baseline comparison, run the same final test file against the recorded baseline sources with the same dependencies; these cases are expected to fail there. The normal test suite contains no expected-failure skips. Full regression checks also cover supported cache/Redis behavior; this audit adds no collection L2 and does not certify concurrency or all subscriber/association combinations.

Final regression validation: **625 tests on PHP 8.5.12 and PHP 8.4.27**, with PostgreSQL and disposable Redis and no skipped tests; PHPStan and PSR-12 checks pass. The PHP 8.4 run was repeated after an initial `RedisException: OOM` in the existing artificial-memory-pressure fixture (`RedisL2EnduranceTest::testActualMemoryPressureCausesSafeMisses`, allkeys-lru). The repeat passed without changing the Redis fixture. This transient failure is recorded separately from the deterministic persistence findings; it does not establish that the pressure test is free of flakiness.
