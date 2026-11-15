# Array graph hydration removal

HYDRATE_ARRAY/getArrayResult() now supports **one flat entity-style projection or scalar-only rows**. It no longer assembles association graphs. Joined entity mappings, multiple entity roots, entity/scalar mixed wrappers, named entity wrappers and DTO constructor mappings are rejected in array mode, before SQL or hydration-cache lookup. Direct ArrayHydrator use enforces the same boundary before consuming rows.

## Migration

Use getResult() for managed entity fetch joins. Use explicit SQL rows or scalar-only mappings for flat join projections. DTO projections remain available through object hydration; they are not an array-mode result.

```php
// A single flat entity projection. No objects enter UnitOfWork.
$rows = $em->createQueryBuilder()->fromEntity(Project::class, 'p')->getQuery()->getArrayResult();

// Flat join rows: define each requested output column explicitly.
$rows = $em->createQueryBuilder()->from('projects', 'p')
    ->leftJoin('p', 'tasks', 't', 't.project_id = p.id')
    ->addScalar('p.id', 'project_id', 'project_id', 'integer')
    ->addScalar('t.title', 'task_title', 'task_title', 'string')
    ->getQuery()->getArrayResult();
```

Entity-style flat mappings may select a subset of fields but must include the identifier. Use scalar mappings or DBAL rows when no entity identifier is selected. Nullable fields, embedded field keys, scalar FK meta values, DBAL conversions, enums and JSON field values remain supported. A JSON value can itself contain arrays; this is field data, not ORM association graph assembly.

The flat mode preserves duplicate SQL rows. Simple root/scalar indexBy remains available; duplicate index keys retain the usual last-value-wins behavior. A null mapped root identifier retains the existing null-result representation. Streaming returns the same flat values without graph state. Ordinary object hydration, mixed object/scalar results and managed fetch-join deduplication remain supported.

Hydration cache keys advance to `full-v2-flat-array`, preventing reuse of cached nested results even when a new flat mapping uses the same SQL as an old graph query. The change requires no schema migration. Restart workers and invalidate/version deployment query/hydration caches. Mapping validation runs before cache hits; unsupported old graph mappings cannot return cached nested results.

## Implementation and validation

ArrayHydrator is reduced from 277 to 47 lines. Root/ancestor identity maps, result pointers, collection assembly, graph deduplication and mixed/DTO wrapper construction are removed. Shared DBAL/enum conversion remains in the existing hydration infrastructure.

The retained ObjectHydrationTest graph cases now assert rejection without consuming rows. Its flat-array case still covers repeated fields, JSON/enum conversion and absence of managed entities. CompleteEntityProjectionTest adds 23 scenarios covering invalid shapes before SQL (buffered/streaming), direct hydrator rejection, flat null/FK/embedded values, root/scalar indexing, typed scalar arrays, duplicate-row streaming null roots and legacy hydration cache isolation. Existing object fetch joins and DTO cases remain regression coverage.

Full PHP 8.4.27 and PHP 8.5.12 suites on PostgreSQL 18.7: **1154 tests / 7804 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan passes; the obsolete ArrayHydrator baseline entry is removed without new suppressions. Changed PHP passes PSR-12.

Historical #9185 evidence remains intact. Its old diagnostic case name now verifies rejection of the removed graph mapping; it does not certify support for nested array graphs.
