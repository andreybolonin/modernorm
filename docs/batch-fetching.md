# Lazy batch fetching

This page describes ManyToOne references. [OneToMany](collection-batch-fetching.md) and [ManyToMany](many-to-many-batch-fetching.md) collections use the same default/attribute settings with association-role queues.

## Configuration

```php
use ModernORM\Mapping as ORM;

class Order
{
    #[ORM\ManyToOne(targetEntity: Customer::class, batchSize: 32)]
    public Customer $customer;
}
```

- Missing/null `batchSize` inherits Configuration.
- Size 1 disables batching for the association.
- A value greater than 1 overrides the default even if the global size is 1.
- EAGER fetching keeps its own strategy; this setting controls lazy loading.

Set the global default before creating the EntityManager/loading entities:

```php
$config->setLazyFetchBatchSize(32);
$em = new EntityManager($connection, $config);
$orders = $em->getRepository(Order::class)->findBy([], ['id' => 'ASC']);
foreach ($orders as $order) {
    echo $order->customer->name;
}
```

The default is 1. Accepted sizes are 1–65535, bounded by PostgreSQL bind parameters for a scalar PK. Size 32 is a starting point, not a universal optimum. This setting is separate from `setEagerFetchBatchSize()`.

Sizes survive serialized metadata and MappedSuperclass mappings. Invalid listener/cache values are validated too. The current metadata salt is `__MODERNORM_TYPED_MAPPING_V10__`; applications still version their cache namespaces when changing mappings.

## Queue and loading

During hydration, one EntityManager registers uninitialized references by target class and effective size. Accessing a field on one reference selects compatible peer IDs with SQL IN, using ordinary persister/type bindings. Repository, SQL QueryBuilder and native RSM hydration share the same UnitOfWork and registration path.

Seven distinct references at size 3 require three additional SELECTs in the integration example. One queued object uses one SELECT. Streaming cannot discover peers from future rows; EAGER loads or additional associations may add queries. The ceil(N/size) expectation applies to ordinary compatible target loads, not every object graph.

Sizes are fixed at registration. Different effective sizes do not mix. When multiple associations reach the same Identity Map object, the smallest observed size wins regardless of hydration order: size 1 excludes that shared reference from batching. Already initialized objects do not become lazy again.

## Persistence and lifecycle

- Hydration preserves existing PHP identities, including the native ghost that triggered loading. Repeated foreign keys do not take additional slots.
- Initialized/dirty entities are not overwritten. Loaded peers receive normal UnitOfWork snapshots and participate in later flushes. Partial objects are excluded.
- PostLoad runs for every loaded member. Peer callbacks may run before the application reads those peers; the queue does not promise callback order.
- A missing triggering row raises EntityNotFoundException. A missing peer remains lazy and fails on its own access without preventing found rows from loading. There is no negative cache.
- SQL failure does not discard the queue. A caller may retry after repairing the cause/transaction; there is no automatic retry.
- detach/removeFromIdentityMap remove members; clear/close empty the queue. WeakReference entries do not retain entities beyond UnitOfWork. Detached ghosts keep the ordinary single-reference loader; batching does not promise reattachment.

Scalar integer/string keys, including string UUIDs, use normal DBAL types. Composite/association IDs are outside mapping scope. `getReference()` alone does not enqueue an object, but later ManyToOne hydration may find and enqueue that same Identity Map reference. OneToOne and partial references keep their ordinary loader.

## L2 interaction

Uncached targets batch with L2 enabled. Cacheable ManyToOne targets check L2 for the selected group, hydrate hits and fetch all misses with one SQL IN query. An all-hit group performs no SELECT. A triggering L2 hit may still cause one query for its cold peers: the size limits references, not just misses. Invalid entries are misses; missing rows are not cached.

Generation tickets are captured before SELECT and used to publish raw database rows. Key/class eviction makes late fills unreachable. All selected found entities are hydrated before PostLoad; callbacks can read peers without another SELECT. Local/callback mutations are never published to L2, and managed/dirty/partial peers are not overwritten.

Active transactions and disabled auto-commit bypass L2 for the entire group, including reads, fills and cache hit/miss/put events. Transaction-mode/nesting changes during cache access are rejected before hydration; a post-SELECT check prevents private transaction rows from being published. The caller remains responsible for transaction recovery.

Cached ManyToMany collections keep their ordinary SQL loader. No tenant/access SQL filters are introduced; applications supply access scope explicitly.

## Diagnostics and coverage

`BatchFetchingTest` and `L2BatchFetchingTest` cover scalar keys, shared identities/sizes, partial/dirty peers, missing rows, lifecycle/retry, mixed L2 hits/misses, transaction bypass, raw typed rows/PostLoad and late fills after eviction. Collection contracts have their own coverage.

The connection Statistics middleware reports SQL/batch attempts, duration/errors, selected references, cache progress and fallback reasons. Optional observers receive immutable completed operations. See [batch statistics](batch-statistics.md).
