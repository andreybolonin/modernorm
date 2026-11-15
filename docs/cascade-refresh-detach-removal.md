# Cascade refresh and detach removal

Association cascades support **persist**, **remove**, and **all**. `all` now expands to persist/remove only. Explicit refresh/detach cascades are rejected, including uppercase options and combinations such as `['all', 'refresh']`. Remove these options from attributes and programmatic mappings. AssociationBuilder::cascadeRefresh()/cascadeDetach() and AssociationMapping::isCascadeRefresh()/isCascadeDetach() are removed.

## Explicit entity operations

EntityManager::refresh($entity) reloads that entity's scalar state and association references/membership from the database. It does not recursively refresh related entities. Existing managed targets retain their local fields and snapshots, including EAGER targets selected by the root query. Refresh each known related entity explicitly when its fields must also be discarded. Query-level refresh hints retain their separate query hydration contract.

EntityManager::detach($entity) detaches only that object and cancels its pending entity insertion/update/deletion, retaining the existing queue policy. It does not initialize the object, traverse associations, detach related objects or cancel their independently scheduled writes. The remaining managed graph can still reference the detached object; adjust those links or explicitly detach the related objects before flushing. Use clear() to discard the entire persistence context and its pending work.

Lazy references, ordinary collection loading, persist/remove cascades, orphan removal and explicit refresh/detach remain supported. No recursive replacement helper is introduced.

## Metadata and deployment

Metadata cache salt is **V15**. Old `all` mappings may have expanded refresh/detach entries, so old payloads must not be reused. Restart workers retaining metadata and invalidate/version deployment caches. Listeners and restored cached metadata cannot reintroduce removed options. No database schema migration is required.

## Verification

CascadeScopeTest adds 33 unit scenarios covering all four association kinds, mixed all/removed options, programmatic metadata, listeners, serialized cache restoration, and retained all/builder expansion.

RootRefreshRegressionTest retains the applicable tests from the historical cascade-refresh fix, adding root-scoped EAGER refresh, persist/remove through all, lazy detach and preservation of a pending child INSERT. UnitOfWorkContractsTest now verifies that cyclic/dirty peers stay managed after detaching only the root. Existing refresh, lazy, readonly, cache and worker tests remain regression coverage.

Full PHP 8.4.27 and PHP 8.5.12 suites on PostgreSQL 18.7: **1131 tests / 7773 assertions / 21 Redis skips** per runtime, run sequentially. PHPStan passes with one obsolete cascadeDetach baseline entry removed and no new suppressions. Changed PHP passes PSR-12.

The historical #7169/#10499 family was fixed before this removal; it is now outside the cascade feature scope. The diagnostic runner retains its old `cascade_refresh_new_child` case name to check the remaining root-refresh contract, using mappings without cascade refresh. Historical JSON observations are preserved.
