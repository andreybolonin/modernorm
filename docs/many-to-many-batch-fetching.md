# Lazy ManyToMany batch fetching

Enable batching on either side of a ManyToMany relation:

```php
use Doctrine\Common\Collections\Collection;
use ModernORM\Mapping as ORM;

class Group
{
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'groups', batchSize: 32)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    public Collection $roles;
}

class Role
{
    #[ORM\ManyToMany(targetEntity: Group::class, mappedBy: 'roles', batchSize: 32)]
    public Collection $groups;
}
```

After loading groups, accessing one roles collection loads memberships for queued groups through the join table. **Seven collections at batch size 3 use three SELECTs**, including empty owners. The same applies to inverse collections.

## Mapping and configuration

- Omitted/null batchSize inherits `Configuration::setLazyFetchBatchSize()`.
- `batchSize: 1` disables batching for that role; larger values override the global default of 1.
- Sizes are 1…65535 and count owner collections, not related rows.
- Owning and inverse sides have their own settings and queues.
- OrderBy and string/integer indexBy preserve their ordinary collection roles; unindexed collections use list keys.
- MappedSuperclass declarations support the argument through ModernORM Mapping attributes.
- Attribute values survive serialized metadata. Current metadata cache salt: `__MODERNORM_TYPED_MAPPING_V10__`.

Configure defaults before loading entities. Changing mapping still requires an application cache namespace update. Each side of the join table must reference a single scalar entity key for batching; multi-column join definitions retain the ordinary loader.

## Memberships and Identity Map

The queue belongs to one EntityManager and is grouped by source class, collection field, and effective batch size. A batch query uses the owning mapping's join table, reverses the join-column roles for inverse loading, and includes the owner identifier in each result row.

The loader preserves every membership while reusing managed entity objects. A role shared by several groups appears in each applicable collection as the **same PHP object**. A shared target is not lost through root-result deduplication. Self-referencing ManyToMany mappings use the same policy.

All selected collections are initialized, including those with no memberships. Different roles and sizes, other EntityManagers, initialized/replaced collections, and detached owners are excluded. clear/close clear the queue; detach/removeFromIdentityMap remove the owner. The queue keeps weak references to collections.

Existing managed targets retain local field changes. Entities scheduled for deletion are excluded from distributed results. As with ordinary ORM loading, OrderBy orders database rows; pending additions are restored afterwards rather than automatically sorted/reindexed.

## Pending changes and writes

Additions made before collection initialization survive on both the trigger and peer collections. Database memberships form the snapshot; pending additions remain outside it and keep the collection dirty. New targets can cascade-persist; existing targets can acquire new links on flush.

Removal initializes the collection through its existing API before applying the mutation. Later batches do not overwrite that collection. Removing a link does not delete the target unless mapping rules explicitly require it.

**Owning-side write rules are unchanged.** Inverse-only add/remove operations change the PHP collection but do not write the join table. Applications must synchronize both sides when they need consistent in-memory graphs. Immutable owners can still batch-load and change collection memberships according to their mapping.

Schema foreign keys, onDelete behavior, cascades, and orphan removal retain their ordinary meaning. Batching does not remove unrelated incoming references or override database constraints.

## Fetch modes, lifecycle, and limits

EXTRA_LAZY count/contains/containsKey/get/first/slice keep their existing partial operations; a full collection initialization starts batching. EAGER keeps its existing loading strategy.

When L2 is enabled, uncached target classes continue batching on both owning and inverse sides. Cacheable targets keep the ordinary per-collection SQL loader, even if entity-by-ID L2 entries are warm; peers remain uninitialized. This preserves existing collection behavior and Identity Map without introducing collection L2 caching or combining entity cache hits with batched misses.

SQL failure leaves collections and pending additions available for a later attempt; the loader does not retry or recover a failed transaction automatically. PostLoad runs for newly hydrated targets, once per entity rather than once per membership. Callback-driven loading can add queries beyond the basic batch-count bound.

Streaming can batch only owners discovered so far. SQL/flush and batch timings/counts/errors, selected collections and fallback reasons are available through Statistics middleware, with optional backend-neutral completion events. See [Batch statistics](batch-statistics.md). Comparative latency/memory benchmarks remain future work. Public mixed-result streaming restrictions are unchanged by this internal loader.

## Verification

`ManyToManyBatchFetchingTest` covers owning/inverse queries, shared identities and memberships, empty collections, ordering/indexBy, integer/string IDs, role/size isolation, self-referencing joins, pending writes/deletes, SQL retry, lifecycle cleanup with/without L2, manager isolation, immutable owners, EAGER/EXTRA_LAZY, PostLoad, and uncached batching under L2. `L2BatchFetchingTest` verifies mixed cached/uncached targets, cached-target collection fallback, pending changes and Identity Map in an L2-enabled manager.

`ManyToManyBatchMappingTest` covers owning/inverse metadata, MappedSuperclass, serialized caches, invalid attributes/sizes, and cache validation. [OneToMany batching](collection-batch-fetching.md) and [ManyToOne batching](batch-fetching.md) remain separate loading paths within the shared lazy-batching configuration.
