# Flush boundary audit

This audit follows the local UnitOfWork extractions through their integration boundaries. It adds seven PostgreSQL cases in `tests/Integration/FlushBoundaryAuditTest.php` against runtime revision `87be0d3`. All seven pass without a runtime fix. They verify actual database values together with PHP values, original snapshots, changesets, scheduled operation queues, Identity Map reuse and EntityManager/transaction state. Successful retry/no-op paths also assert that subsequent flushes execute no INSERT, UPDATE or DELETE.

## Coverage matrix

The matrix first identifies existing coverage, then records the additional cross-boundary assertions. Names below refer to test methods in the listed files.

| Boundary / scenario | Existing coverage | Additional evidence / status |
| --- | --- | --- |
| preFlush mutation before changeset calculation | LifecycleEventsTest::testPreFlushMutationIsIncludedInChangeSet | Covered; ordinary PHP mutation is included by normal change detection |
| preFlush exception before transaction | LifecycleEventsTest::testPreFlushFailureOccursBeforeTransactionAndLeavesManagerOpen | Covered; manager remains open and pending insertion is retained |
| onFlush adds/recomputes work | LifecycleEventsTest::testOnFlushRecomputesUpdateAndComputesNewEntityChangeSet; PersistenceAuditTest::testOnFlushCanScheduleWorkFromInitiallyEmptyQueue | Covered for both empty and nonempty initial queues |
| onFlush throws after explicitly scheduling INSERT and UPDATE | No combined snapshot/queue/retry assertion | New: testOnFlushFailureKeepsExplicitWorkForRetryBeforeAnyTransaction passes; DB unchanged, work retained, explicit retry succeeds once |
| preUpdate changes an additional scalar | LifecycleEventsTest::testPreUpdateCanChangeExistingChangeSetAndAdditionalScalar | Covered; additional scalar changes survive recomputation |
| preUpdate reverts a changed field and changes another field | No combined DB/object/snapshot/no-op assertion | New: testPreUpdateReversionAndAdditionalFieldHaveOneConsistentSnapshot passes |
| preUpdate setNewValue overrides SQL without assigning the property | Existing test assigned both changeset and property | New: testSetNewValueOverridesSqlWithoutImplicitlyChangingTheObject passes; SQL-only override is explicit, refresh aligns the object |
| Lazy initialization during onFlush after a local partial-field change | PartialLoadTrackingTest covers initialization outside callbacks; BatchFetchingTest and L2BatchFetchingTest cover partial peers | New: testOnFlushLazyInitializationPreservesLocalValueAndExplicitRecompute passes, including initially empty write queue |
| postFlush throws after commit | LifecycleEventsTest covers committed insert, empty-flush cleanup and caller-owned outer transaction | New: testPostFlushFailureKeepsLateFieldChangeForTheNextCycle passes; late PHP scalar mutation survives for the next explicit flush |
| A second ordinary UPDATE fails after the first executes | ConcurrencyTest covers UNIQUE/FK failures; UpdateL2CacheTest::testFailureOnSecondUpdateRollsBackFirstUpdateAndBothVersions covers cached writes | New: testSqlFailureAfterAnEarlierUpdateRollsBackAndClearsTracking passes for uncached writes, including two attempted UPDATEs and one SQL error |
| postUpdate throws after SQL | UpdateL2CacheTest::testPostUpdateFailureRollsBackWithoutPublishingChangedVersion | New: testPostUpdateFailureRollsBackSqlAndClearsSnapshotsAndQueues passes for uncached writes |
| preUpdate / postPersist throws in write phase | PersistenceAuditTest::testPreUpdateFailureRollsBackAndClosesManager; LifecycleEventsTest::testPostPersistFailureRollsBackClosesManagerAndSkipsPostFlush | Covered; rollback/closure does not rewind PHP properties or generated IDs |
| COMMIT error or post-commit L2 failure | UpdateL2CacheTest::testCommitErrorsReportUnknownAndInvalidateEveryAffectedClass; testPostCommitFailureDoesNotSkipOtherKeysOrOtherCachedClasses | Covered separately; uncertain commit is not presented as a known rollback |
| Manual outer transaction/savepoint | LifecycleEventsTest::testPostFlushFailureLeavesManualOuterTransactionUnderCallerControl; ConcurrencyTest::testFailedFlushRollsBackItsSavepointAndLeavesOuterTransactionActive | Covered; flush does not take ownership of the caller's outer transaction |
| Completed queues and next flush | Existing queue/lifecycle tests; all successful new audit paths | No replay or false DML reproduced in the selected cases |

## Event and recovery contracts

`preFlush` runs before normal change detection. `onFlush` runs after it; listeners adding or changing work must use the existing `computeChangeSet()` / `recomputeSingleEntityChangeSet()` APIs. In the tested onFlush exception case, no ORM transaction has begun, so the manager stays open and the explicitly scheduled work remains pending. The successful retry does not imply a safe retry policy after a write or commit error.

`preUpdate` property changes are recomputed before UPDATE. `PreUpdateEventArgs::setNewValue()` changes the SQL changeset, not the PHP property. If a listener only calls setNewValue, the object and its PHP comparison baseline can intentionally differ from the database. A later no-op flush does not overwrite that SQL value; explicit refresh aligns the instance. Assign the property as well when the listener intends to keep both representations equal.

A late scalar mutation in postFlush is outside the completed write cycle. Its previous written value remains the comparison baseline, so the next explicit flush can detect it, even if postFlush itself threw. Completed changesets and queues are cleared. This is scalar dirty detection on an already managed object; it does not establish support for adding new entities or scheduling new explicit operations from postFlush.

An SQL or write-phase callback failure closes the manager and clears its Identity Map, snapshots and scheduled work after rollback handling. PHP object fields remain modified. An ordinary postFlush failure after commit leaves the manager open and the database committed. Caller-owned outer transactions and unknown COMMIT outcomes retain their separate documented contracts.

## Scope and outcome

No new runtime defect was reproduced by these seven additional cases; no component was extracted and no runtime algorithm changed. The UnitOfWork commit-order comment is corrected to match the existing execution order: whole-collection deletes, entity inserts, entity updates, extra updates, collection updates, entity deletes.

This is a bounded integration audit, not a proof for every listener combination. Association mutation from preUpdate, scheduling work for other entities during write callbacks/postFlush, throwing from onClear and arbitrary external transaction manipulation are not certified here. Existing documented UNIQUE ordering limitations and non-atomic PostgreSQL/L2 commit boundaries remain unchanged. No new Doctrine issue or inherited-bug claim follows from this audit.

Final validation: **739 tests pass on PHP 8.5.12 and PHP 8.4.27**, with PostgreSQL and disposable Redis, without skipped tests. The seven new focused tests pass with 198 assertions. PHPStan and PSR-12 pass without new baseline suppressions.
