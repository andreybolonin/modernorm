# FlushExecutor boundary

The first extraction covers execution of one prepared InsertBatch. FlushExecutor receives the batch, its EntityPersister and a single acknowledgement callback. It calls `addInsert(entity)`, acknowledges that successful enqueue, then proceeds to the next entity. Once every entity is queued, it calls `executeInserts()` exactly once. It does not catch failures, retry, open transactions or dispatch lifecycle events.

UnitOfWork computes dependency order and prepares batches with InsertBatch::batchByEntityType(). Its callback removes each successfully queued entity from FlushOperationQueues before SQL execution. A failed `addInsert` leaves that entity unacknowledged and stops the batch; a SQL failure propagates after all successful acknowledgements. This preserves what persisters observe during generated-ID assignment and scheduling of circular-FK extra updates.

Persisters still own SQL generation, identity/RETURNING handling and calls to UnitOfWork::assignPostInsertId(). That method retains ID conversion, entity identifiers/state/snapshots and Identity Map registration. UnitOfWork collects postPersist events only after successful batch execution and dispatches them after all INSERT batches and their generated IDs are complete. A later failed batch prevents the deferred events from being dispatched.

Transactions/savepoints, statistics, rollback, uncertain COMMIT/L2 recovery and successful-flush cleanup remain in UnitOfWork. Collection execution is unchanged. The executor has no EntityManager or UnitOfWork dependency and no access to their state arrays.

## Ordinary and extra UPDATE

`FlushExecutor::executeUpdates()` consumes an iterable of `[entity, persister]` operations and executes one write before requesting the next. UnitOfWork supplies private generators so preparation stays lazy; no list of changesets is copied into the executor and no additional callbacks or state-access interface is introduced.

For ordinary updates, UnitOfWork keeps class/ID ordering, readonly skips, subscription lookup, preUpdate and changeset recomputation. A nonempty changeset yields one SQL operation. After the executor successfully writes it and resumes iteration, UnitOfWork removes the update from its queue and invokes postUpdate. Empty changesets yield no SQL but still perform the original queue cleanup and postUpdate. The next entity is prepared after those actions, allowing an earlier postUpdate to affect its recomputed values.

For extra updates, UnitOfWork installs each queued changeset immediately before yielding its operation. No ordinary update events are introduced. The extra-update queue clears only after the entire execution returns successfully. The existing snapshot of extra operations taken at the start of iteration is preserved.

If SQL throws, the generator is not resumed past that yield: no post-write cleanup/event or preparation of a later operation occurs. Preparation/event exceptions also propagate directly. UnitOfWork's existing flush failure handler remains responsible for rollback, closing the manager, clearing tracking and L2 recovery. Neither generator has a finally block that would run successful-write coordination during failure unwinding.

## DELETE

`FlushExecutor::executeDeletions()` consumes a separate lazy stream of `[entity, persister]` operations. UnitOfWork keeps dependency ordering and removes the current entity from Identity Map before yielding its DELETE. After the write returns, it removes that deletion from its queue, clears identifiers/state/snapshots and applies the existing ID-reset policy. Natural, readonly and non-nullable hooked IDs retain their existing exceptions.

The next entity is prepared only after that cleanup. Once all deletes complete, the generator dispatches the collected postRemove events in their existing order. A SQL failure stops before the failed entity's cleanup and all deferred postRemove events; an event failure propagates after successful SQL/state cleanup. UnitOfWork's unchanged failure handler rolls back and closes the manager in either case. ID values already reset before a failure are not restored, matching the previous implementation.

## Validation

Five unit cases cover INSERT queue visibility at execution and propagation of failures during enqueue, acknowledgement and SQL execution without later work or retry. PostgreSQL WriteOrderTest additionally verifies that the first postPersist sees every generated ID, both registered objects, empty insertion scheduling and all inserted rows inside the still-active transaction. A second case fails the later INSERT with a CHECK constraint and verifies rollback, no postPersist events, a closed manager and cleared tracking. Existing generated-ID, dependency/cycle, lifecycle, flush-boundary and cache rollback regressions remain unchanged.

Two additional executor cases verify sequential update preparation/completion and failure short-circuiting. PostgreSQL cases verify earlier postUpdate effects on the next entity, queue/postUpdate completion without SQL for an explicitly empty changeset, and rollback of an ordinary update plus an earlier extra update when the next extra update fails. Existing extra-update merge/no-replay and ordinary update/event-failure regressions run unchanged.

Two DELETE executor cases verify preparation/cleanup/event order and failure short-circuiting. Three PostgreSQL cases verify complete deletion/state cleanup before postRemove, rollback of an earlier successful DELETE when a later FK-constrained DELETE fails without postRemove, and rollback plus skipped later events when the first postRemove throws. Existing FK/cascade/SET NULL ordering and hooked/readonly-ID regressions run unchanged.

Validation: the full suite passes sequentially on PHP 8.4.27 and PHP 8.5.12 with PostgreSQL 18.7: **1240 tests / 8193 assertions / 21 Redis skips** per runtime. PHPStan and PSR-12 pass without new suppressions.
