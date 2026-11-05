# Batch statistics and operation events

The connection-scoped `ModernORM\Statistics` collector now includes all three batch loaders and a backend-neutral completion observer. No Symfony, Prometheus or OpenTelemetry dependency is added. Export adapters and dashboards are separate work.

## Read immutable aggregates

Install `ModernORM\Statistics\Middleware` before constructing the DBAL Connection, as described in [Statistics API](statistics.md). Collection remains disabled by default.

```php
use ModernORM\Statistics\BatchKind;

$snapshot = $em->getStatistics()->snapshot();
$batch = $snapshot->batch;
$batch->count;
$batch->errors;
$batch->timeMilliseconds();
$batch->selectedReferences;
$batch->selectedCollections;
$batch->cacheHits;
$batch->cacheMisses;
$batch->cachePuts;
$batch->fallbackCounts; // e.g. ['size_one' => 2, 'cached_target' => 1]

$references = $batch->byKind[BatchKind::MANY_TO_ONE->value];
$collections = $batch->byKind[BatchKind::ONE_TO_MANY->value];
$manyToMany = $batch->byKind[BatchKind::MANY_TO_MANY->value];
```

All three byKind keys exist even before work has been recorded. Child values are immutable BatchSnapshot objects. Each child has empty byKind; the outer snapshot contains the per-kind split. Previously obtained snapshots do not change on further work, reset or disable.

| Field | Meaning |
| --- | --- |
| count | Completed selected batch attempts, including exceptions and a selected group of one |
| errors | Batch attempts that threw, including missing triggers, SQL, cache and callback errors |
| timeNanoseconds | Monotonic time after candidate selection through cache/SQL loading, hydration, PostLoad and queue completion |
| selectedReferences | Selected ManyToOne references, summed across attempts |
| selectedCollections | Selected OneToMany/ManyToMany collections, including empty owners |
| cacheHits/cacheMisses/cachePuts | Completed lookups/fills inside cached ManyToOne batches; invalid entries are misses, absent DB rows cause no put |
| fallbackCounts | Counts by fixed BatchFallbackReason values; separate from selected attempts |

Selected size describes an attempt, not the number of returned rows, initialized entities or committed changes. One collection can contain many rows; one shared entity can appear in multiple collections. Rollback does not subtract completed work. Failed batches retain selected sizes and cache progress completed before failure. These counters do not imply that a failed operation changed no managed state.

Cache counters cover only selected cached batches. Ordinary single loads, reentrant fallback and collection SQL are excluded, even when their time is nested inside a batch. Global entity cache totals remain available through the separate [StatisticsCacheLogger](read-only-l2-cache.md). Transactional/disabled-auto-commit batches use SQL and record zero cache lookups/fills.

SQL, flush and batch times overlap and must not be added together. Batch time includes hydration and PostLoad; standalone hydration timing is not introduced. Candidate discovery/selection happens before this batch timer. No latency distributions or benchmark results are stored in the core; completion events let adapters build histograms.

## Fallback decisions

The queue records a fallback only when initialization chooses the ordinary loader. Candidate registration/filtering and scanning other pending objects do not generate fallback counts.

Reasons are bounded enum values: size_one, partial_reference, cached_target, reentrant, not_queued, unmanaged, manager_closed, pending_delete, replaced_collection, unsupported_join and already_initialized. A fallback does not count as a selected batch and does not claim that the following single load succeeded.

Reference fallback events describe the reference queue, which also sees unqueued direct getReference/one-to-one ghosts. They cannot identify the originating association. ManyToOne batches group by target class and effective size across associations, so association is null. Collection events include the stable `SourceClass::field` role. configuredSize is zero when a reference was not queued and its effective size is unknown.

An initialized object access that never reaches a lazy loader emits no decision. The first decisive reason wins; the counters are not a list of every condition a candidate could fail.

## Observe completed work

```php
use ModernORM\Statistics;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use ModernORM\Statistics\OperationObserver;

final class ApplicationObserver implements OperationObserver
{
    public function completed(OperationEvent $event): void
    {
        if ($event->kind === OperationKind::BATCH) {
            // Feed duration and actual selected size into your adapter's histograms.
            // Send counters to your adapter; do not retain ORM objects.
        }
    }
}

$statistics = new Statistics(enabled: true, observer: new ApplicationObserver());
// Pass this instance to Statistics\Middleware before creating the DBAL connection.
```

OperationEvent is readonly. Its kinds are SQL, FLUSH, BATCH and BATCH_FALLBACK. SQL/flush events use the same completed-attempt definitions as their counters, including failed preparation/execution and errors after flush commit. Successful prepare alone produces no SQL completion event.

Events carry a Unix-epoch start timestamp in nanoseconds, a monotonic duration, a failed flag, and optional batch metadata: kind, target class, collection role, configured size, selected references/collections, cache counts and fallback reason. `endedAtUnixNanoseconds()` adds the monotonic duration to the captured wall-clock start; epoch accuracy is limited by the system clock.

Fallback is a decision event with zero duration, not a timed single-load span. SQL/flush events have no entity/association metadata. There are no SQL strings, bind values, identifiers, exception objects/messages, entities or result rows in the payload.

Observers run synchronously after counters are updated. The collector stores no event history. Export/network latency can delay the caller and contribute to an enclosing operation's duration; adapters own buffering, limits and delivery. Exceptions from an observer are suppressed and counted in `snapshot()->observerErrors`; they cannot replace SQL/ORM errors or turn successful work into a reported database failure. Work executed inside the observer is not recursively instrumented by this collector.

## Adapter boundaries

- Symfony Profiler can consume snapshots and retain a bounded per-request history of immutable events.
- Prometheus can consume completion/decision events to maintain counters and size/duration histograms across collector resets. Prefer bounded kind/reason labels; adapters decide whether class/role labels are appropriate.
- OpenTelemetry can consume explicit timestamps and outcomes to emit completed spans/metrics. This API does not create active spans or capture trace context. The adapter must define parent/context handling; making SQL spans children of an active batch span requires start/end instrumentation, not completion events alone.

No exporter, Symfony collector, trace propagation, Prometheus endpoint, Grafana dashboard or PostgreSQL advisor is implemented by this slice.

## Request/job boundaries and overhead

Statistics remains scoped to one DBAL Connection, shared by its EntityManagers. Use reset at application-defined request/job boundaries, taking all users of that Connection into account. reset clears SQL/flush/batch/fallback/observer-error counters, preserves the enabled flag and leaves EntityManager, queues and transactions unchanged.

Reset or disable invalidates in-flight measurements: their later completion produces neither counters nor operation events in the next window. Already delivered events cannot be retracted. Adapters must respect request/job lifecycle independently. A failing observer that resets the collector does not leak its old error into the new window.

When disabled, no measurements/events are allocated and no timing clocks are read. Without an observer, enabled collection reads only monotonic clocks; epoch timestamps and OperationEvent objects are created only for the observer. Core aggregate storage is bounded by three batch kinds and the fixed fallback enum. No per-ID/class/role history is stored in the collector. The observer instance itself remains attached; any history/buffering belongs to the adapter. Overhead has not been benchmarked.

## Verification

BatchStatisticsTest verifies disabled mode, immutable aggregates, per-kind totals, timestamps/outcomes, reset/toggle generations, bounded fallback reasons, exporter exception isolation and recursive suppression. PostgreSQL L2BatchFetchingTest verifies real group sizes, mixed cache counters, collection kinds, empty owners, cached-target/partial/reentrant fallback, failed SQL attempts, reset during PostLoad, disabled collection and observer failure preserving database behavior. Existing batching/L2 and SQL/flush suites remain regression coverage.
