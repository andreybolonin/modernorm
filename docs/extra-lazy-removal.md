# EXTRA_LAZY removal

EXTRA_LAZY has been removed. Association mappings support **LAZY** and **EAGER**. Ordinary lazy collection reads initialize the collection through the existing loading/batching pipeline; subsequent reads operate on its in-memory contents, including pending changes.

## Mapping migration

Remove `fetch: 'EXTRA_LAZY'` or replace it with `fetch: 'LAZY'` in OneToMany/ManyToMany attributes and association overrides. LAZY is the default. `ClassMetadata::FETCH_EXTRA_LAZY` and `AssociationBuilder::fetchExtraLazy()` no longer exist. Programmatic numeric mode `4`, metadata listeners and cached mappings cannot restore the removed mode.

Metadata cache salt is **V14**. Restart workers retaining metadata and invalidate/version deployment metadata and query caches. This change requires no database schema migration.

## Explicit database queries

```php
$items = $em->association($order, 'items');
$count = $items->count();
$exists = $items->exists($itemId);
$page = $items->where('status', 'active')->page(limit: 50, after: $cursor);
```

These operations query persisted association membership without initializing or flushing the collection. They do not merge pending additions/removals. For in-memory membership and pending changes, use the ordinary collection. Existing Identity Map semantics still apply to returned entities.

`exists()` takes a target identifier, not an indexBy key or entity. To query a mapped indexBy field, use a condition, for example `$items->where('slug', $slug)->page(limit: 1)`. `page()` uses cursors, not offsets; `Collection::slice()` remains an in-memory operation after initialization. See [Association Query API](association-query-api.md) for supported conditions and pagination.

## Removed internals and retained coverage

PersistentCollection no longer overrides count/contains/containsKey/get/first/slice to issue partial SQL queries. Collection persisters now implement only delete/update; their partial-read SQL and helper methods are removed. The unused entity-persister sliced collection methods and cache forwarding wrappers are removed too. Full collection loading, batching, indexing, ordering and writes retain their existing paths.

The former EXTRA_LAZY integration suites are retained as `LazyCollectionOperationsTest` and `LazyManyToManyOperationsTest`, covering ordinary lazy loading, Identity Map, pending additions, owning/inverse writes and deferred orphan/link deletion. Batch and owner-scoped lookup regressions are adapted to ordinary collections. `FetchModeScopeTest` covers removed-mode rejection and retained LAZY/EAGER metadata behavior, including serialized-cache restoration.

## Verification

Full suites on PHP 8.4.27 and PHP 8.5.12 with PostgreSQL 18.7: **1071 tests / 7622 assertions / 21 Redis skips** per runtime. The suites ran sequentially. PHPStan includes the retained lazy collection suites and fetch-mode rejection tests; four obsolete collection-persister baseline entries were removed without new suppressions.
