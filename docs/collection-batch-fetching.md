# Lazy OneToMany batch fetching

Configure batching on the collection association:

```php
use Doctrine\Common\Collections\Collection;
use ModernORM\Mapping as ORM;

class Order
{
    #[ORM\OneToMany(
        mappedBy: 'order',
        targetEntity: OrderItem::class,
        cascade: ['persist'],
        indexBy: 'sku',
        batchSize: 32,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $items;
}
```

After loading orders, initialize one collection as usual:

```php
$orders = $em->getRepository(Order::class)->findBy([], ['id' => 'ASC']);
foreach ($orders as $order) {
    foreach ($order->items as $item) {
        // Work with managed OrderItem instances.
    }
}
```

Instead of issuing one collection SELECT per owner, ModernORM queries compatible owners together with `WHERE order_id IN (...)`. The integration test loads seven collections, including empty ones, with three SELECTs at batch size 3.

## Configuration

- `batchSize: null`, or omitting the argument, inherits `Configuration::setLazyFetchBatchSize()`.
- `batchSize: 1` disables batching for that association.
- Larger values override the global default, including when it is 1.
- The global default is **1**, shared by lazy ManyToOne, OneToMany, and ManyToMany. Configure it before loading entities.
- Sizes must be between 1 and 65535. A batch size counts **owner collections**, not child rows.
- EAGER loading keeps its existing strategy. For EXTRA_LAZY, count/contains/containsKey/get/first/slice retain their existing partial-query paths; batching runs when the collection is fully initialized.

The attribute uses ModernORM Mapping, including inherited MappedSuperclass associations. Doctrine Mapping aliases are not provided. Serialized metadata retains batchSize. Current metadata cache salt: `__MODERNORM_ENTITY_CACHE_WRITE_POLICY_V9__`; applications must still version their namespaces after mapping changes.

## Queue and hydration

The queue belongs to one UnitOfWork/EntityManager and uses weak collection references. Its groups are keyed by the source entity class, collection field, and effective batch size. Different roles stay separate even when they target the same child class.

Accessing an uninitialized collection selects its owner and queued eligible peers up to the batch size. Existing persister criteria and DBAL bindings build the query; entity hydration uses the normal Identity Map. All selected collections are initialized, including those with no rows.

OrderBy is applied to the database query, preserving relative row order within each owner's collection. String and integer indexBy keys are supported; unindexed collections retain list keys. Index fields must meet the ordinary collection mapping uniqueness requirements.

Already initialized or replaced collections are excluded. Only owners still managed by the same EntityManager are eligible. clear/close clear the queue, and detach/removeFromIdentityMap exclude the owner. Detached collections retain the existing single-collection loading behavior.

## Pending changes

Batch initialization keeps additions made to uninitialized collections on both the trigger and its peers. The snapshot describes the loaded baseline; pending additions are restored afterwards and remain dirty. As with ordinary lazy initialization, pending additions are appended rather than automatically sorted or indexed by the database query.

Managed child objects are reused without refreshing their local fields. A local change to the child's owning FK is not overwritten while distributing rows. The application remains responsible for maintaining both sides of a bidirectional association. Collections already modified by removal are initialized through the ordinary collection API and are not subsequently overwritten by a peer batch.

Entities scheduled for deletion are excluded from distributed results. Cascade persist, orphan removal, child updates, and subsequent flush continue to use UnitOfWork. Immutable owners can batch-load their collections and persist mutable children according to their mapping.

## Failure and lifecycle behavior

A failed SQL query leaves collections uninitialized and keeps their pending additions and queue entries available for a later attempt. There is no automatic retry or transaction recovery.

PostLoad callbacks run during child hydration. If a callback explicitly initializes a selected collection, the loader uses the ordinary reentrant path and does not overwrite that collection afterwards. Such callbacks can cause additional SELECTs, so the simple batch query-count bound does not apply to arbitrary callback behavior.

## Scope

- Scalar owner PKs, including integer and string identifiers.
- Lazy OneToMany and full initialization of EXTRA_LAZY OneToMany.
- Enabling L2 does not disable batching for uncached target classes. Cached targets retain their ordinary loader; batching cached targets is separate future work. The current flat entity-cache scope excludes cached entities with declared associations, including the child's OneToMany back reference. This does not introduce collection L2 caching.
- ManyToMany uses a [separate join-table batch loader](many-to-many-batch-fetching.md).
- Streaming can only batch collections whose owners have already been discovered.
- SQL/flush and batch Statistics use the existing middleware: attempts, durations, errors, selected collections and fallback reasons, plus optional completion events. See [Batch statistics](batch-statistics.md). Per-entity/row counters and latency/memory benchmarks remain separate work.

See `CollectionBatchFetchingTest` for query counts, role isolation, ordering/indexBy, empty results, Identity Map, pending additions/FK changes/deletes, orphan removal, immutable owners, lifecycle cleanup with/without L2, SQL retry, EAGER/EXTRA_LAZY, PostLoad reentry, string IDs, and uncached batching under L2. `L2BatchFetchingTest` covers cached references alongside uncached collections, pending writes and shared-pool manager isolation. `CollectionBatchMappingTest` covers attributes, metadata cache, invalid sizes, and MappedSuperclass mappings.
