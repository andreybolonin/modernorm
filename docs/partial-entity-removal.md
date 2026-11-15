# Partial managed entity hydration removal

Query and NativeQuery no longer hydrate partially loaded managed entities. Every managed root, joined alias and entity argument inside a DTO must map **all scalar/embedded fields and all owning to-one FK columns**. Nullable, generated, non-updatable and version fields remain part of that full projection. A nullable SQL column must be present even when its value is null.

Use QueryBuilder::fromEntity()/addJoinedEntity() or ResultSetMappingBuilder to construct full entity projections. Handwritten ResultSetMapping must cover the same fields/FKs. Incomplete mappings fail before SQL, including streaming and empty-result queries. Object/SimpleObject hydrators additionally check the actual SQL result columns before creating or refreshing any managed object, so a full RSM paired with an incomplete SELECT cannot bypass the restriction.

## Migration

```php
// Full managed entities, including their mapped FK columns.
$records = $em->createQueryBuilder()->fromEntity(Record::class, 'r')->getQuery()->getResult();

// Selected columns as rows, without UnitOfWork registration.
$rows = $em->createQueryBuilder()->select('id', 'title')->from('records')->fetchAllAssociative();
```

Scalar mappings, scalar DTO constructor arguments and non-managed flat array hydration can still select a subset of columns (entity-style array mappings must include the identifier). Nested array graphs have subsequently been [removed](array-graph-removal.md). A DTO argument that is itself an entity must be a fully hydrated managed entity.

Remove markPartialEntityResult(), partialAliases flags and the old partial argument to addJoinedEntityResult(). These legacy RSM entry points remain only to raise a clear exception, including when a serialized mapping restores a partial flag. Query::HINT_PARTIAL and HINT_FORCE_PARTIAL_LOAD are removed; their raw hint names and `isPartial` cannot enable the old behavior. Custom hydrators with managed entity mappings receive the same query mapping validation; custom code that calls internal UnitOfWork registration APIs remains responsible for its own contract.

Hydration cache keys include a versioned entity hydration contract, preventing reuse of old partial hydration results even if an old RSM declared more fields than its SELECT returned. Invalidate/version application hydration/query caches and restart workers during deployment. Metadata cache salt remains V14; this change requires no schema migration.

## Retained lazy loading

getReference(), native lazy associations, batch fetching, Identity Map, explicit refresh, embeddables and L2 reads remain supported. A lazy reference represents an existing identity with its row not yet loaded; it is not a partial SQL entity projection.

Partial-field lists and snapshot merging are removed from UnitOfWork. PartialLoadTracker becomes a smaller LazyReferenceTracker containing only weak object markers. It retains the reconciliation needed when inverse-association hydration completes a PHP lazy reference without a full row load. Embeddable ghosts used only by partial projections are removed.

## Validation

CompleteEntityProjectionTest covers incomplete roots and joined aliases, missing nullable/embedded/FK fields, missing actual SQL columns, streaming, DTO entity arguments, legacy flags/hints, direct hydrators, null/empty joins, Identity Map and retained row/scalar/DTO/array projections. FullEntityLoadingTest retains null-baseline, DateTime, refresh, detach/clear and completed-native-reference regressions. Worker, batch and L2 cases now use complete entity projections.

Full PHP 8.4.27 and PHP 8.5.12 suites on PostgreSQL 18.7: **1094 tests / 7683 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan passes with the new/retained regressions and four obsolete ProxyFactory/RSM baseline entries removed. No new suppressions were added.
