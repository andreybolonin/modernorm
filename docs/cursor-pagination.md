# Cursor pagination

`CursorPaginator` traverses an entity query by sorting values instead of OFFSET. It adapts Doctrine ORM's `Cursor`, immutable page API and forward/backward navigation to ModernORM's SQL query builder; it does not use DQL or AST walkers. Adapted files retain Doctrine attribution and the [MIT license](../licenses/Doctrine-ORM-MIT.txt).

```php
use ModernORM\Tools\Pagination\CursorPaginator;

$source = $em->createQueryBuilder()->fromEntity(Event::class, 'e')
    ->where('e.tenant_id = :tenant')->setParameter('tenant', $tenantId);

$paginator = new CursorPaginator(
    limit: 25,
    orderBy: ['occurredAt' => 'DESC', 'id' => 'DESC'],
);
$page = $paginator->paginate($source, $_GET['cursor'] ?? null);

foreach ($page as $event) {
    // Managed Event; ordinary Identity Map behavior is preserved.
}
$next = $page->hasNextPage() ? $page->getNextCursorAsString() : null;
$previous = $page->hasPreviousPage() ? $page->getPreviousCursorAsString() : null;
```

Sorting uses **PHP field names on the root entity**, including mapped physical column names and quoting. The final field must be the root's single scalar primary key. No tie-breaker is added silently. Each direction is `ASC` or `DESC`; mixed directions are supported. The paginator owns the effective sorting and replaces any builder ORDER BY on a clone. Use the same source filters and paginator configuration when consuming a cursor.

## Query and page behavior

A nonempty page normally uses two queries:

1. Select distinct raw sorting values/IDs with a boundary predicate and `LIMIT limit + 1`. Duplicates introduced by filtering joins are eliminated before the limit. An extra root is used only for lookahead and is not hydrated.
2. Hydrate the trimmed root IDs through the original SQL/mapping, preserving filters and to-one fetch joins. Return entities in the key query's order.

The boundary values are captured from database rows, preserving timestamp microseconds, decimal strings and integer precision. They are independent of dirty or subsequently changed managed properties. `getCursorForItem($item)` and `getItemsWithCursors()` use the same snapshots, and accept only items of that page. Empty pages perform no hydration query and do not advertise next/previous cursors.

The paginator holds only its limit/sort configuration and can be reused. Source builders are not modified. Pages retain their original SQL, bindings and key snapshots independently of later builder changes. Navigation does not execute COUNT:

```php
count($page);                  // Number of entities on this page.
$total = $page->getTotalCount(); // One additional COUNT query, memoized per page.
```

COUNT ignores the cursor/limit and deduplicates root IDs. It reads the live database when first requested, not an earlier snapshot. Concurrent changes can affect it. The page container is immutable; its managed entities remain ordinary mutable entity instances.

## First-version contract

- Start with `fromEntity()` and use named parameters. Source OFFSET/LIMIT must be absent; set the page size on the paginator.
- Sort only by non-null mapped root fields using built-in integer, string/text/guid, boolean, decimal/float or date/time types. Nullable mappings are rejected even if a SQL predicate excludes nulls. NULL ordering, arbitrary expressions, custom type names and joined-field sorting are not supported.
- Scalar/mixed projections, partial entities, custom ResultSetMapping, UNION, CTEs, write builders and row locks are rejected. Keep source fragments read-only. Ordinary filtering joins and mapped to-one fetch joins work; collection fetch joins are rejected. Load collections separately after selecting roots, or use the existing explicit root-ID Paginator.
- `andWhereFullText()` can filter a source, but FTS score projections and sorting by relevance are outside this contract. The paginator replaces relevance ordering with its configured mapped fields.
- The primary key must be the final sorting field and uniquely identify a root. Query shape validation does not validate arbitrary SQL join conditions or application access policy.

Built-in type names resolve through the connection's DBAL type provider for normal entity hydration. Cursor pivots are already database values; they are bound without converting them through potentially lossy PHP DateTime formatting. Internal `orm_cursor_key_*` result-column names and `__orm_cursor_*` parameter names are reserved. Existing parameter names are skipped during internal allocation.

## Cursor encoding and access scope

`paginate()` accepts a `Cursor`, an encoded string, or `null`/`''` for the first page. Tokens are versioned URL-safe Base64 JSON carrying only scalar pivots and direction. Page size, column names and comparison structure are configured by server code. Malformed payloads, null/nested values, invalid direction flags and missing/extra sorting fields raise `InvalidCursor`.

Tokens are opaque to clients, but are not encrypted, signed or an authorization mechanism. They expose sorting values if decoded. Pivot values are bound parameters; they cannot select SQL columns or replace the source WHERE. Reapply tenant/access predicates on every request. Reusing a token with different filters or sort configuration is not a supported navigation contract.

## Indexes and concurrent changes

Create an index matching equality filters followed by the sorting columns. For the example above:

```sql
CREATE INDEX event_tenant_cursor ON events (tenant_id, occurred_at DESC, id DESC);
```

Uniform directions use PostgreSQL row comparisons such as `(occurred_at, id) < (:time, :id)`. Mixed directions use a lexicographic OR chain with an additional inclusive bound on the leading column, following Doctrine's optimization. Both require suitable indexes. Many ties on the leading field can increase work for the mixed-direction strategy; measure your actual query and data distribution.

The [uniform-direction](evidence/cursor-pagination-pg18-uniform.json) and [mixed-direction](evidence/cursor-pagination-pg18-mixed.json) EXPLAIN ANALYZE reports cover 60,000 rows with three rows per timestamp, a compound index and a 21-row lookahead. PostgreSQL 18.7 used an Index Only Scan in both cases: 21 returned index rows, with zero and two rows removed by the boundary filter respectively. This is a representative range-seek check, not a guarantee for arbitrary joins or distributions. The integration tests assert plan behavior and can write fresh reports with `MODERNORM_CURSOR_EVIDENCE=/tmp/cursor-report`.

Insertions/deletions before an unchanged boundary do not shift subsequent pages as OFFSET does. Sorting-key updates can move rows across the cursor. Filtering and hydration are separate statements; use a suitable transaction snapshot when a consistent view is required. No cursor promises a fixed dataset across requests, and an emptied or out-of-range page has no navigable item cursor.

See PostgreSQL's [row-comparison semantics](https://www.postgresql.org/docs/17/functions-comparisons.html#ROW-WISE-COMPARISON) and Doctrine's [cursor pagination API](https://www.doctrine-project.org/projects/doctrine-orm/en/3.7/tutorials/pagination.html#cursor-based-pagination).
