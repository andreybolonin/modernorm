# EntityStateManager boundary

EntityStateManager centralizes managed registration, post-insert ID registration, identified-entity state decisions and per-entity state cleanup after DELETE or detach. EntityStateManager contains the single authoritative identifier/state/original-snapshot maps previously held directly by UnitOfWork. It introduces no duplicate state, reference aliases or per-entity record objects. Snapshot row arrays retain PHP copy-on-write behavior.

`registerManaged(entity, id, data)` replaces that object's identifier, records MANAGED state, replaces its entire original snapshot and then publishes identity. Re-registering the same canonical object can therefore replace a snapshot even when IdentityMap::add() returns false for an existing member.

`registerPostInsertId(entity, field, value)` records the converted identifier and MANAGED state, merges only the ID field into the existing snapshot, then publishes identity. Other snapshot fields survive. UnitOfWork performs DBAL conversion and property-accessor assignment before invoking this transition. A readonly/property failure therefore leaves registration maps untouched; the manager never writes entity properties.

## Identity and error order

UnitOfWork still owns IdentityMap and its public lookup/normalization methods. One first-class callable, bound once when UnitOfWork is constructed, publishes identity through the existing addToIdentityMap() method. This keeps metadata loading, ID validation, collision detection and overrides at their original observation point, after the state writes. The manager needs no EntityManager, SQL or metadata access.

These transitions do not add rollback-on-error semantics. As before, invalid identity or collision errors can leave the attempted object's identifier/state/snapshot recorded, while the canonical Identity Map entry remains unchanged. Generated-ID assignment happens before publication, so an assigned ID remains on the attempted object if publication fails. The extraction preserves these behaviors rather than changing them implicitly.

## Unknown entity state

`resolveIdentifiedState(versionValueIsTruthy, postInsert, identityOrDatabaseExists)` chooses NEW or DETACHED for an uncached entity with an identifier. A natural versioned identifier uses the existing version truthiness rule, including NEW for zero/null versions. A post-insert-generated identifier implies DETACHED. Other identified entities use one deferred observation: Identity Map first, persister existence only after a miss. The manager receives facts and a narrow observation callback; it has no metadata, EntityManager or persister dependency.

UnitOfWork retains the cached-state, assume and empty-ID short circuits; metadata/identifier access and enum-ID flattening; and conditional version/generator inspection. Natural identifiers never inspect the generator. No Identity Map or database observation is performed for a version or post-insert shortcut. NEW/DETACHED decisions are deliberately not cached: database existence may change and untracked object IDs can be reused. Lookup exceptions propagate without installing state.

EntityStateDetectionTest covers decision precedence and callback counts, unmapped cached/assumed objects without metadata reads, empty IDs without version access, natural version values, pre/post-insert generator observation order, enum-ID map hits, changing existence results and failed-lookup retry, without connecting to a database.

## Remaining coordination

UnitOfWork retains change sets, queues, readonly policy, lazy tracking, lifecycle events, transactions and property access. Its remaining persist/remove/detach/hydration/dirty-check code accesses the internal manager's maps directly. This staged access is deliberately not a claim of complete encapsulation; it keeps hot accesses and absent-versus-empty entries intact while establishing one storage owner and centralizing registration and per-entity cleanup transitions.

`forgetAfterDeletion(oid)` removes identifiers, snapshots and cached state after successful SQL. `forgetAfterDetach(oid)` removes identifiers, cached state and snapshots after queue/lazy cleanup. Separate operations preserve the observation order of snapshot destructors. Both are idempotent, leave other entities untouched, and do not remove Identity Map entries or write entity properties. UnitOfWork retains those actions, generated-ID reset policy and deferred postRemove dispatch.

clear() retains its prior execution/assignment order. In particular, clear() empties the maps in the original chain interleaved with other UnitOfWork cleanup, then dispatches onClear. No callback-driven reset, implicit map alias or new snapshot normalization is introduced. No mapping cache/public API migration is required. Other coordinated transitions can be assessed separately after this boundary is reviewed.

## Validation

Component cases cover publication-time consistency, snapshot replacement, copy-on-write, post-insert merge/zero IDs without entity-property assignment, collision/failure mutation order and isolation between managers. UnitOfWork cases verify actual DBAL conversion, readonly-property failure before registration, post-insert collision with canonical preservation, empty-ID failure ordering and clear behavior without connecting to a database. Cleanup cases also check idempotence, entity isolation, snapshot destructor observation order, and detach cancellation of pending updates while preserving entity IDs.

The full SQL/hydration/proxy/generated-ID/lifecycle/rollback/worker suites pass sequentially on PHP 8.4.27 and PHP 8.5.12 with PostgreSQL 18.7: **1314 tests / 8658 assertions / 21 Redis skips** each. PHPStan and PSR-12 pass without suppressions. No benchmark files are part of this extraction.
