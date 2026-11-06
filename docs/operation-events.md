# Bulk, hydration and UnitOfWork events

The existing connection-scoped `Statistics` collector now provides `bulk`, `hydration` and `unitOfWork` snapshots and three additional `OperationKind` cases. No monitoring library, Symfony service, event history, SQL text, parameter values or entity references are added to core.

## Reading the aggregates

```php
use ModernORM\Statistics\UnitOfWorkPhase;

$snapshot = $em->getStatistics()?->snapshot();
if ($snapshot !== null) {
    $bulkRows = $snapshot->bulk->affectedRows;
    $hydratedRows = $snapshot->hydration->rows;
    $newEntities = $snapshot->hydration->entitiesCreated;
    $dirtyCheckingNs = $snapshot->unitOfWork->phase(UnitOfWorkPhase::CHANGE_SETS)->timeNanoseconds;
}
```

All durations use monotonic nanoseconds. Snapshot values are immutable; later work/reset does not mutate an earlier snapshot. Each aggregate counts completed attempts, including failures, rather than successful database commits. A rollback never subtracts work.

`OperationObserver::completed()` receives the same `OperationEvent` envelope with optional immutable `bulk`, `hydration` or `unitOfWork` details. Existing positional constructor parameters remain in their original order, with new optional parameters appended. Custom exhaustive enum matches must handle the new operation kinds; using a default branch is appropriate for observers interested only in selected kinds. Observer exceptions are suppressed and counted as `observerErrors`, including failures from an older observer that does not understand a new enum case. Version or clear persisted serialized Profiler snapshots when upgrading their schema; cross-version serialized snapshots are not a stable storage format.

## Bulk

Every public INSERT/UPSERT/UPDATE/DELETE/softDelete/restore call produces one `BULK` completion when instrumentation is enabled, including validation/SQL failures. Timing includes SQL construction/type preparation, write-set checks, transaction handling, synchronous invalidation and CLEAR handling.

`BulkDetails` contains:

| Field | Meaning |
| --- | --- |
| `operation` | INSERT, UPSERT, UPDATE, DELETE, SOFT_DELETE or RESTORE |
| `entityClass` | The supplied entity class name |
| `policy` | The selected BulkPolicy; JSON encodes its case name |
| `inputRows` | Supplied INSERT/UPSERT row count; zero for predicate UPDATE/DELETE/softDelete/restore |
| `affectedRows` | Row count from a successfully returned BulkResult; null when no result was returned |
| `returnedRows` | Number of RETURNING rows in that result, not their contents; null on failure |
| `databaseCommitted` | BulkResult's true/false on success, BulkOperationException's true/null on failure; otherwise null |

A failed operation can have `databaseCommitted=true`. An affected-row count of null does not mean zero: post-commit cleanup may fail after the database changed. False on a successful caller-owned transaction means commit is still the caller's responsibility. Errors never imply rollback or authorize an automatic SQL retry.

`BulkSnapshot` provides count, errors, timeNanoseconds, inputRows, affectedRows and returnedRows. Row totals add only known returned results; attempted input counts include rejected calls. Ordinary per-entity flush INSERT/UPDATE/DELETE are not bulk events, and direct DBAL SQL is only SQL instrumentation.

## Hydration

`HYDRATION` details contain `hydrator`, `streamed`, consumed `rows`, `entitiesCreated` and `completed`. The built-in object, simple-object, array, scalar, scalar-column and single-scalar hydrators record consumed driver rows, including rows that later fail conversion. A result row can create multiple entities or reuse existing Identity Map entries, so row count and created-entity count differ.

`entitiesCreated` counts newly reconstituted entity instances allocated through UnitOfWork::createEntity during the active scope. Reused managed instances, existing lazy ghosts, separate association references, embeddables, application-created objects and scalar/array results are excluded. The counter is attributed to the innermost active hydration scope, avoiding double counting for nested PostLoad queries.

Hydrate-all timing includes prepare, result fetching, conversion, reconstitution and cleanup/PostLoad. Initial SQL execution happens before the hydrator and remains a separate SQL duration. Custom AbstractHydrator implementations use the protected `hydrationRows()` helper to report the rows they consume; direct Result access is not automatically intercepted.

Cached single-entity reconstitution is measured by ReadOnlyEntityHydrator, including validation/type conversion. Cached ManyToOne batches emit a `ReadOnlyEntityHydrator::batch` scope around reconstitution and PostLoad after cache lookup, SQL fetching and raw-value conversion. Their earlier work remains visible in SQL/batch time rather than this hydration duration. A rejected cache payload may complete a zero-row hydration attempt before the normal miss path runs.

For `toIterable()`:

- Measurement begins on first iteration; an unstarted generator produces no completion event.
- Time while a value is yielded to application code is excluded from active hydration duration and object attribution.
- Exhaustion reports `completed=true`. Closing/abandoning a started generator reports `completed=false` and `failed=false` when cleanup succeeds.
- Fetch/conversion/prepare/cleanup exceptions report `failed=true`; a consumer exception explicitly thrown into the generator is an error too.
- A suspended generator remains in-flight until resumed, closed or destroyed. No snapshot count is published prematurely.

Stream completion events include an explicit `finishedAtUnixNanoseconds`: `endedAtUnixNanoseconds()` gives the actual wall-clock end, while `durationNanoseconds` remains active work time. Trace adapters must not interpret active streaming duration as a contiguous wall-clock span.

`HydrationSnapshot` provides count, errors, timeNanoseconds, rows, entitiesCreated and incomplete. A failed or abandoned attempt contributes to incomplete; incomplete alone does not mean failure.

## UnitOfWork phases

`UNIT_OF_WORK` events and `snapshot->unitOfWork->phase($phase)` cover:

| Phase | Duration boundary | Scheduled-work snapshot |
| --- | --- | --- |
| CHANGE_SETS | computeChangeSets, including its callbacks | After the attempt, including partially calculated work on error |
| WRITES | Collection deletes, entity inserts/updates/deletes, extra updates and collection writes | Before executing writes |
| COMMIT | DBAL commit call and commit-error handling within that call site | Before commit; many queues have already been drained |

Details contain phase, managedEntities and scheduled insertion/update/deletion, collection update/deletion and extra-update counts. These are planned ORM operations, not affected SQL rows; callbacks can schedule additional work. Per-phase snapshots retain only count, errors, total time and the last immutable details. The last value is a sampled boundary, not a live Identity Map gauge.

An empty flush emits CHANGE_SETS but no WRITES/COMMIT. A write failure emits a failed WRITES attempt without inventing a COMMIT attempt. COMMIT success can mean savepoint release for an uncached caller-owned transaction, not final durable commit. Post-commit cache recovery and postFlush callbacks belong to total flush time and can still make flush fail after COMMIT succeeded. A failed COMMIT event is not proof that PostgreSQL rolled back.

## Lifecycle, overlap and adapters

Reset/disable invalidate in-flight measurements of all kinds. Old streaming generators cannot replace a new generation's active hydration scope or add their completion to new totals. Disabled instrumentation reads no operation clocks or creates measurement/event objects; normal delegating calls and small closures in bulk/flush remain overhead.

SQL, bulk, flush, batch, nested hydration and UnitOfWork durations overlap. They are diagnostic scopes, not additive latency components. SQL error, failed bulk and failed flush can describe different layers of one failure; summing them is not an incident count.

Core keeps only bounded aggregate/phase state and transient active scopes, not an event history. Symfony Profiler can retain a capped history; Prometheus can aggregate counts/histogram buckets without retaining individual events. Those adapters must explicitly consume the new kinds. This core change does not automatically add new Symfony panel sections, metrics endpoints, exporters or active tracing spans.

Observers must not mutate EntityManager/transaction state. Notification is synchronous, and reentrant instrumentation during notification is suppressed; this does not make recursive ORM writes from an exporter safe.

## Executed DML

`snapshot()->dml` exposes `insert`, `update` and `delete` aggregates for single direct client statements executed through the Statistics DBAL middleware. Each has `count` (attempts), `errors`, `timeNanoseconds`, `affectedRows` (sum of known successful driver counts), and `unknownRowCounts` (successful statements without a usable count). Successful statement count is `count - errors`. Zero affected rows is a known result, not a failure or an unknown count.

Counters cover ordinary flush statements, extra updates, direct DBAL writes and bulk API SQL exactly once. Multi-row INSERT is one executed statement with multiple affected rows. UPSERT is classified by its INSERT command; the driver does not split inserted versus updated rows. SoftDelete/restore are SQL UPDATE statements as well as their distinct bulk API operations. DML metrics annotate the existing SQL completion event through optional `DmlDetails`; they do not emit a second SQL event or retain SQL/parameters/entity identifiers.

Only driver execution is counted. Successful prepare is not execution; failed preparation remains a SQL error without an executed DML attempt. A statement prepared while Statistics is disabled can still contribute after enable; only its enum classification is retained. Classification happens once for prepared SQL and only on measured direct query/exec paths. Execution and row-count metadata are timed; result fetching/hydration is excluded. A rowCount exception, negative or oversized count is recorded as unknown without failing successful application SQL.

Classification accepts one direct INSERT/UPDATE/DELETE after PostgreSQL whitespace/comments, including nested block comments, quoted identifiers, ordinary/E strings and dollar quotes. It follows standard-conforming PostgreSQL string rules. WITH/CTE, EXPLAIN, COPY, multiple statements and unclassified native SQL remain in overall SQL metrics and are deliberately not assigned guessed write effects. Trigger/cascade work inside PostgreSQL is not counted as additional client statements.

**Execution success is not commit.** A later rollback, savepoint rollback, optimistic-lock failure following a zero-row UPDATE, post-write callback failure or uncertain COMMIT does not erase executed SQL. These counters do not report committed entity changes. Existing flush/UnitOfWork commit events describe their separate boundaries; their durations overlap SQL/DML/bulk and must not be added. Reset/disable generations and observer-failure isolation apply to DML exactly as to SQL totals.
