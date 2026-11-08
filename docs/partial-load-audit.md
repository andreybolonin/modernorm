# Partial-load tracking boundary and regression audit

`Internal/UnitOfWork/PartialLoadTracker` records which scalar fields of a partially loaded object are known to be loaded. It uses weak object keys, returns field-list snapshots, selects known values from a supplied snapshot and forgets tracking on completion or context cleanup. It never reads properties, performs SQL, modifies entity data or dispatches lifecycle events.

An empty field list represents a tracked association reference with no loaded scalar fields; null means that the object is not tracked. Loaded null values are retained when selecting known snapshot fields. Weak keys do not keep entities alive and do not transfer state to another object when PHP reuses an object ID.

## UnitOfWork integration

UnitOfWork still decides whether hydration is partial, filters projected fields through scalar metadata, performs hydration and snapshot merging, computes changesets and dispatches callbacks. It retains the distinction between lazy initialization, which preserves loaded local values and their original baseline, and explicit refresh, which replaces them with database values.

Tracking is forgotten after a full load, after successful fallback reconciliation of an already initialized proxy, on successful removal from the Identity Map, on detach and on clear. A still-partial object keeps its tracking across flushes. Batch-loading eligibility and SoftDelete/L2 policies remain in their existing components.

Hydrator registration now avoids re-registering an object that is already the canonical Identity Map instance. UnitOfWork's `createEntity()` remains responsible for merging the partial baseline or replacing the snapshot during refresh. Fresh/unregistered objects still follow normal managed registration.

## Reproduced regressions

The final seven PostgreSQL cases in `tests/Integration/PartialLoadTrackingTest.php` were run against revision `179a427` before the extraction. Five pass and two fail:

| Case | Before | After |
| --- | --- | --- |
| A selected nullable field changes from null to a value before lazy initialization | Passes | Passes |
| A selected nullable field changes from a value to null before lazy initialization | Passes | Passes |
| Explicit refresh discards local changes and allows clean subsequent flushes | Passes | Passes |
| A selected immutable DateTime survives lazy initialization without a false UPDATE | Fails | Passes |
| Detach releases a partial object and permits a clean full reload | Passes | Passes |
| Clear releases a partial object and permits a clean full reload | Passes | Passes |
| A proxy completed by inverse-property hydration is reconciled only once | Fails | Passes |

The first regression occurs when a hydrator re-registers an already managed partial object before `createEntity()` can preserve its baseline. The incoming row contains a newly converted DateTime instance, while the entity keeps the previously loaded instance. Reference-based change tracking then reports a change despite no user modification. Avoiding redundant managed registration preserves the original baseline.

The second regression occurs when hydration of the owning side fills the last outstanding property of an inverse-side native lazy reference. PHP marks it initialized without a full `createEntity()` pass for that reference. UnitOfWork's existing fallback reconciliation supplies a baseline, but its tracking marker previously survived, forcing the same changes again on later flushes. Forgetting the marker after successful reconciliation makes the fallback run once. The first reconciliation retains its previous conservative write behavior.

These are reproduced ModernORM regressions. This audit does not establish their status or applicability in upstream Doctrine and does not add them to the README's confirmed inherited-bug list.

## Verification scope

Five isolated tracker tests cover unknown versus empty tracking, null-preserving snapshot selection, object identity, independent returned field lists, replacing projections, clear and weak-reference release. Existing partial/batch, L2 batch, readonly entity and object hydration tests provide additional regression coverage. The baseline comparison records behavior; seven cases are not seven independent bugs.

Final validation: **732 tests pass on PHP 8.5.12 and PHP 8.4.27**, with PostgreSQL and disposable Redis, without skipped tests. PHPStan and PSR-12 checks pass without new baseline suppressions.
