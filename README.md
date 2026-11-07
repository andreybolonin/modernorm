# ModernORM

**PostgreSQL-first ORM for PHP 8.4+.**

Batch fetching, PostgreSQL bulk writes, entity L2 cache and built-in observability — designed for ERP and data-heavy applications.

**100 orders. 100 distinct customers. 101 SELECTs → 5 with batch size 32.** [Measured workload](benchmarks/results/README.md#association-batch-fetching).

## Built for demanding persistence workloads

| Feature | What you get |
| --- | --- |
| **Batch fetching** | Load lazy ManyToOne, OneToMany and ManyToMany associations in groups, configured directly on attributes. |
| **PostgreSQL-native bulk** | Multi-row INSERT, UPSERT, UPDATE, DELETE and `RETURNING`, with explicit UnitOfWork policies. |
| **Entity L2 cache** | Cache entities across EntityManagers with PSR-6 storage, TTL and generation-based invalidation. |
| **SQL-first queries** | PostgreSQL expressions and CTEs, with managed entity hydration or plain rows. |
| **Built-in behaviors** | Timestampable, Blameable and SoftDelete built in — no extra behavior package required. |
| **Built-in Statistics API** | Measure SQL, flush, batch, bulk, hydration and UnitOfWork phases; consume neutral operation events. |

PHP attributes, native lazy objects, Identity Map and UnitOfWork keep ordinary entity persistence familiar.

Behaviors are explicitly enabled per entity: [Timestampable](docs/timestampable.md), [Blameable](docs/blameable.md) and [SoftDelete](docs/soft-delete.md). SoftDelete is unreleased; beta.4 includes Timestampable and the Blameable provider/listener API.

## Fewer queries. The same entity workflow.

Set the batch size on a relation, then access it normally:

```php
use ModernORM\Mapping as ORM;

#[ORM\Entity]
class Order
{
    // Identifier and other fields omitted.
    #[ORM\ManyToOne(targetEntity: Customer::class, batchSize: 32)]
    public Customer $customer;
}

$orders = $em->getRepository(Order::class)->findBy([], ['id' => 'ASC']);
foreach ($orders as $order) {
    echo $order->customer->name;
}
```

Accessing a queued reference loads compatible references together through an `IN` query. OneToMany and ManyToMany also accept `batchSize`; the ORM distributes results across their owner collections.

Batching is opt-in. Association settings override the global default, and `batchSize: 1` disables batching for that relation. [Batch fetching](docs/batch-fetching.md) · [OneToMany](docs/collection-batch-fetching.md) · [ManyToMany](docs/many-to-many-batch-fetching.md).

## Bulk writes for imports and mass changes

```php
use ModernORM\Bulk\BulkPolicy;

$result = $em->bulk()->upsert(Product::class, [
    ['sku' => 'A', 'price' => '12.50'],
    ['sku' => 'B', 'price' => '15.00'],
], conflictFields: ['sku'], updateFields: ['price'],
   policy: BulkPolicy::CLEAR, returning: ['id', 'sku']);
```

Choose `BYPASS` to leave managed state unchanged, or `CLEAR` to detach the entire context and discard pending work after success. Bulk operations skip per-row ORM callbacks and do not flush pending changes automatically.

[Bulk API](docs/bulk-api.md) · [Recorded timings, memory and methodology](benchmarks/results/README.md#bulk-writes).

## Cache reference data across requests

```php
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Cacheable(ttl: 3600, writePolicy: CacheWritePolicy::INVALIDATE)]
class Currency
{
    #[ORM\Id, ORM\Column(length: 3)] public string $code;
    #[ORM\Column] public string $name;
}

$config->setSecondLevelCache($pool, namespace: 'app_l2_v1');
```

Use a PSR-6 pool for entity-by-ID caching. `INVALIDATE` permits ORM updates with pre-write/post-commit invalidation; the default `READ_ONLY` policy rejects ORM updates. The current cache scope is entities without declared associations.

`#[ORM\Immutable]` separately skips entity field updates while preserving INSERT and DELETE. [L2 cache](docs/read-only-l2-cache.md) · [Supported scenarios](docs/l2-scope-matrix.md) · [Immutable entities](docs/immutable-entities.md).

## Query PostgreSQL. Get entities or rows.

```php
$query = $em->createQueryBuilder()->fromEntity(Project::class, 'p');
$projects = $query
    ->where($query->column('p', 'name').' = :name')
    ->setParameter('name', 'alpha')
    ->getQuery()
    ->getResult();
```

Build SQL directly while retaining entity hydration and the Identity Map. Use DBAL-style row queries when managed objects are unnecessary. [SQL query API](docs/sql-query-api.md).

## See what your ORM does

Enable Statistics to inspect operation counts, timings, errors and batch sizes without a monitoring backend:

```php
$snapshot = $em->getStatistics()->snapshot();
$snapshot->sqlCount;
$snapshot->sqlTimeMilliseconds();
$snapshot->batch->count;
$snapshot->bulk->affectedRows;
```

[Statistics setup](docs/statistics.md) · [Operation events](docs/operation-events.md).

With [ModernORMBundle](https://github.com/andreybolonin/modernormbundle#profiler-gallery), inspect eight Symfony Profiler tabs and a Performance Advisor with recommendations linked to the relevant queries and panels.

![ModernORM Symfony Profiler dashboard](docs/screenshots/symfony-profiler/overview.png)

Real demonstration workload; displayed timings are not benchmark results. The bundle also includes an opt-in [OpenTelemetry → Collector → VictoriaMetrics → Grafana prototype](https://github.com/andreybolonin/modernormbundle/tree/main/examples/observability).

## Focused by design

| Capability | ModernORM | Doctrine ORM |
| --- | --- | --- |
| Database | PostgreSQL only | Multiple databases through DBAL |
| Queries | SQL QueryBuilder with entity hydration | DQL QueryBuilder and native SQL |
| Association loading | Lazy batching for ManyToOne, OneToMany and ManyToMany | Fetch joins and eager to-one batching |
| L2 cache | Entity-by-ID, PSR-6, `Cacheable` attribute | Entity, collection and query cache regions |
| Statistics | SQL, flush, batch, bulk, hydration and UnitOfWork events | DBAL instrumentation and L2-specific statistics |
| Mapping | Attributes and one scalar entity PK | Multiple drivers, inheritance and composite IDs |

DQL, entity inheritance, composite entity IDs, XML/YAML mapping, ORM Filters and Criteria SQL translation are intentionally excluded. MappedSuperclass, embeddables, lifecycle callbacks, cascades, orphan removal and basic locking remain available. [Mapping scope](docs/mapping-scope.md) · [Compatibility](docs/compatibility.md).

## Confirmed inherited bugs

These behaviors were reproduced on ModernORM before the persistence audit; the corresponding mechanisms were checked against the Doctrine ORM 3.7.6 sources from which ModernORM was derived. **Fixed** means fixed in the current development code, **not in the published beta.4 release**. Upstream links identify provenance, not the current status of a Doctrine fix.

| Confirmed bug | Upstream reference | ModernORM status |
| --- | --- | --- |
| Replacing an orphan-removal collection deletes retained/transferred children; whole-collection deletion skips entity removal callbacks | [#6999](https://github.com/doctrine/orm/issues/6999), [#12642](https://github.com/doctrine/orm/issues/12642) | **Fixed** — compare membership and remove only actual orphans through entity lifecycle |
| `remove()` / `removeElement()` deletes a child still held under another collection key | [#12642](https://github.com/doctrine/orm/issues/12642) | **Fixed** — recheck remaining membership |
| `set()` over an occupied key does not orphan the displaced entity | [#12641](https://github.com/doctrine/orm/pull/12641) | **Fixed** — orphan the displaced member only if it is no longer present; UNIQUE ordering remains a separate limitation below |
| Loading an inverse collection overwrites an explicitly changed owning-side reference | [PersistentCollection source](https://github.com/doctrine/orm/blob/3.7.6/src/PersistentCollection.php#L136) | **Fixed** — preserve owning-side changes during hydration |
| EXTRA_LAZY `get(id)` returns a target belonging to another owner, including ManyToMany associations | [#8607](https://github.com/doctrine/orm/issues/8607), [PersistentCollection source](https://github.com/doctrine/orm/blob/3.7.6/src/PersistentCollection.php#L366) | **Fixed** — query association membership, including for already-managed targets |
| EXTRA_LAZY indexed lookup on unidirectional ManyToMany requires a nonexistent inverse property | [ManyToManyPersister source](https://github.com/doctrine/orm/blob/3.7.6/src/Persisters/Collection/ManyToManyPersister.php#L80) | **Fixed** — scope lookup through the join table |
| `onFlush` loses explicitly computed INSERTs/UPDATEs when the initial queue is empty | [UnitOfWork source](https://github.com/doctrine/orm/blob/3.7.6/src/UnitOfWork.php#L388) | **Fixed** — recheck pending operations after the event |
| An exception from `postFlush` skips cleanup of completed flush state | [UnitOfWork source](https://github.com/doctrine/orm/blob/3.7.6/src/UnitOfWork.php#L496) | **Fixed** — cleanup runs in `finally`; an already committed transaction stays committed |
| Removing a row and inserting its replacement with the same immediate UNIQUE value fails in one flush | [#5109](https://github.com/doctrine/orm/issues/5109) | **Open — documented limitation**: INSERT precedes DELETE; use two flushes in one transaction or a suitable deferred UNIQUE constraint |
| Swapping two immediate UNIQUE values fails on an intermediate UPDATE | [Update execution source](https://github.com/doctrine/orm/blob/3.7.6/src/UnitOfWork.php#L1209) | **Open — documented limitation**: no automatic conflict-resolution plan; suitable deferred constraints require an explicit migration |

[Audit, contracts and per-bug tests](docs/persistence-audit.md) · [Before/after evidence: 36 scenarios, 23 failing before, all passing after](docs/evidence/persistence-audit-2026-11-07.json). Scenario counts are not independent bug counts; limitation tests assert the expected failure. This is a confirmed list, not an exhaustive inventory of Doctrine issues. Nested-flush rejection, manual rollback boundaries and excluded SoftDelete combinations are tracked separately in the audit.

## Install & get started

Requires **PHP 8.4+**, PostgreSQL and `pdo_pgsql`.

```sh
composer require 'andreybolonin/modernorm:^0.1@beta'
```

Start with the [executable persistence example](examples/migration/README.md), or use [ModernORMBundle](https://github.com/andreybolonin/modernormbundle) for Symfony, migrations, fixtures and Profiler integration. Code excerpts above assume configured services and mapped entities.

**Current release: 0.1.0-beta.4.** The package remains beta; read the [supported contract and limits](docs/beta-release.md) before adopting it.

[Documentation](docs/README.md) · [Benchmarks](benchmarks/README.md) · [Roadmap](roadmap.md) · [Changelog](CHANGELOG.md) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md).

## License

[MIT](LICENSE). Derived from Doctrine ORM; original third-party notices are preserved in [licenses/](licenses/).
