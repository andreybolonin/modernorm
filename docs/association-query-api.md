# Association Query API

Query a persisted owner's OneToMany or ManyToMany association without loading its collection:

```php
$items = $em->association($order, 'items');

$count = $items->count();       // SQL COUNT(*)
$exists = $items->exists($id);  // SQL EXISTS, scoped to this order

$active = $items->where('status', 'active');
$page = $active
    ->orderBy('createdAt', 'DESC')
    ->addOrderBy('id', 'DESC')
    ->page(limit: 50, after: $cursor);

foreach ($page->getItems() as $item) {
    // Managed entities, using the ordinary identity map.
}
$nextCursor = $page->hasNextPage() ? $page->getNextCursorAsString() : null;
```

The owner must be managed by this EntityManager and already persisted. New owners, scheduled inserts, detached owners and owners scheduled for deletion are rejected. Use `find()` or a managed `getReference()` for an existing owner. Reference creation does not verify database existence; an absent owner's association is empty. Queries capture the owner's persisted identifier and mapped association when constructed.

## Database semantics

`count()`, `exists()` and `page()` execute database queries in the current transaction. They do not flush, initialize, replace or synchronize the collection, regardless of whether it is uninitialized, initialized or dirty. Pending collection additions/removals and unflushed entity changes do not alter SQL selection. Writes already executed in the current transaction are visible according to PostgreSQL transaction isolation.

Entity results use the ordinary identity map: a matching row can return an already-managed entity with local, unflushed property changes. This API does not refresh entities. COUNT and EXISTS perform no entity hydration and do not use entity or collection L2 results. Page hydration follows the existing cursor query pipeline.

Both sides of ManyToMany work, including unidirectional owning mappings. Membership is checked through the join table with EXISTS, preventing duplicate root rows. Names and join columns follow the configured quote strategy, including explicit schemas. Target visibility uses the same built-in SoftDelete policy as `fromEntity()`. This does not replace application authorization or tenant predicates; apply the required conditions on every request. Owner visibility is not an additional query predicate.

## Conditions and immutable queries

`where(string $field, mixed $value, string $operator = '=')` adds an AND condition on a mapped scalar PHP field of the target. It returns a new query, leaving the original unchanged. Supported operators are `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE` and `ILIKE`; use operators appropriate to the mapped database type. Values are bound using the mapped DBAL type, and backed enum values are unwrapped. Equality/inequality with `null` becomes `IS NULL`/`IS NOT NULL`; other null comparisons are rejected.

```php
$recent = $items->where('createdAt', $since, '>=');
$named = $recent->where('name', 'bolt%', 'ILIKE');
$withoutNote = $named->where('note', null);

// $items and $recent retain their original predicates.
```

`exists()` accepts a scalar target identifier, not an entity or a collection index key. All accumulated conditions apply to membership checks and counts. Raw SQL expressions, association paths and operator fragments are rejected. Use the SQL QueryBuilder for more complex query shapes.

## Ordering and keyset pages

`orderBy()` replaces ordering; `addOrderBy()` adds or replaces a field. Both return new queries. Without explicit ordering, the association's mapped `OrderBy` is used; otherwise the default is the target primary key ascending. Before pagination, the primary key is moved to the final position or appended as an ascending tie-breaker. Set its direction explicitly when needed.

`page()` delegates to the existing [CursorPaginator](cursor-pagination.md). Sort fields must be non-null mapped root fields with supported built-in scalar types. Nullable or unsupported sort types are rejected, as are nonpositive limits and invalid cursors. Navigation uses keyset predicates without offsets. Forward and backward cursor tokens are both accepted by `after`. Counts ignore ordering, cursor and page size; `$page->getTotalCount()` lazily counts matching database rows using the cursor pipeline.

Cursor tokens contain position values; they do not authenticate association ownership or filters. Reapply the same owner, conditions and ordering for navigation. Keep an appropriate index, such as `(order_id, created_at, id)`, for the workload. Concurrent writes can affect navigation; pages do not create a transaction snapshot across requests.

ModernORM's single scalar primary-key contract applies to owner and target. Association queries require single-column joins referencing those primary keys. OneToOne/ManyToOne entry points, composite or association identifiers, and non-primary-key joins are unsupported.

## Ordinary collections

Use this API for explicit database counts, membership checks, filtered queries and cursor pages. Ordinary collection iteration and modification continue to use the existing collection and batch-loading behavior. EXTRA_LAZY is removed; see the [migration guide](extra-lazy-removal.md). Association queries use database membership, while ordinary collections retain their in-memory pending changes. This API also does not introduce collection L2 caching.
