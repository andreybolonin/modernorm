# Statistics API

The core measures SQL/flush/batch/bulk/hydration counts, duration and errors, UnitOfWork phase work, selected group sizes, batch L2 hits/misses/puts and bounded fallback reasons. Snapshots are immutable; enable/disable/reset are explicit. An optional OperationObserver receives neutral completion events. Percentiles, active tracing and exporters remain separate features. See [batch aggregates/events](batch-statistics.md) and the [bulk/hydration/UnitOfWork event contract](operation-events.md).

## Setup

Install middleware **before** constructing the DBAL connection:

```php
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;

$statistics = new Statistics(enabled: true); // Disabled by default.
$dbalConfig = new DBALConfiguration();
$dbalConfig->setMiddlewares([
    ...$dbalConfig->getMiddlewares(),
    new Middleware($statistics),
]);
$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => '127.0.0.1',
    'dbname' => 'app', 'user' => 'app',
    'password' => getenv('PGPASSWORD'),
], $dbalConfig);
$ormConfig = new Configuration();
$ormConfig->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/src/Entity']));
$em = new EntityManager($connection, $ormConfig);

$statistics->reset(); // Exclude bootstrap/schema work if desired.
$em->flush();
$snapshot = $em->getStatistics()->snapshot();
printf("SQL: %d, %.3f ms, errors: %d\n",
    $snapshot->sqlCount, $snapshot->sqlTimeMilliseconds(), $snapshot->sqlErrors);
printf("Flush: %d, %.3f ms, errors: %d\n",
    $snapshot->flushCount, $snapshot->flushTimeMilliseconds(), $snapshot->flushErrors);
```

EntityManager discovers the collector through its connection's middleware; a second ORM-level collector is unnecessary. Without middleware, `getStatistics()` returns null and ordinary persistence still works. Symfony Cache/Console and external monitoring systems are not required.

One collector binds to one DBAL driver/connection. Reusing it to wrap another driver, including duplicate middleware installation, is rejected. Use a separate collector for another connection. Closing/reconnecting the same DBAL Connection retains its collector and totals. Changing middleware configuration after creating the connection does not retrofit wrappers.

## Measurement scope

Scope is the **DBAL Connection**, not a process, request or individual EntityManager. Direct SQL, native/entity queries, lazy loads and all EntityManagers sharing that connection contribute to the same SQL totals. Flush counts also combine those managers. Do not sum their snapshots as independent sources. Another connection has independent counters; no global collector is used.

EntityManager clear/close and database rollback do not reset statistics. `reset()` changes counters, not the persistence context, Identity Map, transaction or database. Long-lived applications define request/job reset boundaries, accounting for every user of the shared connection.

## Snapshot fields

| Field | Meaning |
| --- | --- |
| `sqlCount` | Completed driver query/exec/statement-execute attempts, including failures; failed prepare is one attempt |
| `sqlErrors` | SQL attempts that threw |
| `sqlTimeNanoseconds` | Sum of monotonic durations for those calls |
| `flushCount` | Completed EntityManager::flush calls, including empty/failed calls |
| `flushErrors` | Flush calls that threw in any phase |
| `flushTimeNanoseconds` | Total flush time, from manager-state checks to return/exception |
| `batch` | BatchSnapshot: count/errors/time, selected references/collections, cache progress, fallbackCounts and byKind |
| `observerErrors` | Suppressed observer exceptions, separate from SQL/ORM errors |
| `bulk` | BulkSnapshot: attempts/errors/time, input rows and known affected/returned rows |
| `hydration` | HydrationSnapshot: attempts/errors/active time, rows, newly reconstituted entities and incomplete attempts |
| `unitOfWork` | Per-phase attempts/errors/time and last scheduled-work snapshot |

`sqlTimeMilliseconds()` and `flushTimeMilliseconds()` convert totals to milliseconds. SQL inside flush contributes to both durations; they must not be added as independent end-to-end latency components. A snapshot is not live: later operations or reset never mutate it.

SQL timing stops when the driver returns Result or throws. ORM hydration/result fetching are covered by separate hydration scopes; arbitrary direct DBAL fetching and application row processing are not. Driver buffering/transfer performed before Result is returned is included. This is not pure PostgreSQL server execution time.

Successful prepare/bind do not increment sqlCount; each prepared execute counts once. Failed prepare counts once because execution cannot follow. Bind, connect and driver transaction-API failures are not SQL execution errors, although they may produce flushErrors if they escape flush. Internal PDO operations that do not pass through query/exec/execute are not observed separately.

Driver BEGIN/COMMIT/ROLLBACK calls are excluded. SAVEPOINT/RELEASE/ROLLBACK TO sent by DBAL as SQL statements are included, so nested transactions can add SQL counts.

## Errors and rollback

Counters describe attempted work, not committed changes. Rollback subtracts nothing. A successful INSERT followed by a UNIQUE failure contributes two SQL attempts and one error even when the entire flush rolls back.

A preFlush failure can yield one flush error, no SQL and an open EntityManager. A postFlush failure after commit also increments flushErrors even though data may already be committed. Flushing a closed manager is a failed attempt without SQL. Instrumentation does not change exceptions, transaction outcomes or recovery rules.

## Enable, disable and reset

```php
$statistics->disable(); // Keep totals; stop counting new operations.
$statistics->enable();  // Resume without resetting totals.
$statistics->reset();   // Clear totals; preserve the enabled flag.
```

Disabled collection allocates no measurement/event objects and reads no operation clocks. Installed wrappers still delegate to the driver; this is not a zero-overhead guarantee. Without middleware those wrappers are absent. [Local measurements](../benchmarks/results/README.md#statistics-overhead) are diagnostic observations, not production overhead guarantees.

Only operations starting and finishing within the same enabled generation are included. Reset/disable discard in-flight measurements, including a flush reset from a listener; their completion cannot pollute the new interval. Operation kinds are measured independently.

Core retains no SQL history, bindings, exception objects or results. Its state is bounded by counters, three batch kinds, three UnitOfWork phases, fixed fallback enums and transient hydration scopes. Observers receive immutable values and define their own storage policy. Other installed logging middleware have independent retention rules.

## Integration and verification

Unit/PostgreSQL coverage includes prepare/execute failures, repeated prepared execution, timing, empty flush, rollback/savepoints, pre/postFlush errors, closed managers, disabled mode, in-flight reset, snapshot immutability, shared/isolated/reconnected connections and logging-middleware order.

ModernORMBundle provides the Symfony Profiler adapter and optional bounded SQL history. Prometheus/OpenTelemetry exporters remain separate. Completion events do not create active spans or capture trace context. See [observer boundaries](batch-statistics.md#adapter-boundaries).
