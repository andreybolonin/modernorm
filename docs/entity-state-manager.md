# EntityStateManager boundary

The first extraction centralizes two coordinated transitions: register an entity as managed, and register its post-insert ID. EntityStateManager contains the single authoritative identifier/state/original-snapshot maps previously held directly by UnitOfWork. It introduces no duplicate state, reference aliases or per-entity record objects. Snapshot row arrays retain PHP copy-on-write behavior.

`registerManaged(entity, id, data)` replaces that object's identifier, records MANAGED state, replaces its entire original snapshot and then publishes identity. Re-registering the same canonical object can therefore replace a snapshot even when IdentityMap::add() returns false for an existing member.

`registerPostInsertId(entity, field, value)` records the converted identifier and MANAGED state, merges only the ID field into the existing snapshot, then publishes identity. Other snapshot fields survive. UnitOfWork performs DBAL conversion and property-accessor assignment before invoking this transition. A readonly/property failure therefore leaves registration maps untouched; the manager never writes entity properties.

## Identity and error order

UnitOfWork still owns IdentityMap and its public lookup/normalization methods. One first-class callable, bound once when UnitOfWork is constructed, publishes identity through the existing addToIdentityMap() method. This keeps metadata loading, ID validation, collision detection and overrides at their original observation point, after the state writes. The manager needs no EntityManager, SQL or metadata access.

These transitions do not add rollback-on-error semantics. As before, invalid identity or collision errors can leave the attempted object's identifier/state/snapshot recorded, while the canonical Identity Map entry remains unchanged. Generated-ID assignment happens before publication, so an assigned ID remains on the attempted object if publication fails. The extraction preserves these behaviors rather than changing them implicitly.

## Remaining coordination

UnitOfWork retains change sets, queues, readonly policy, lazy tracking, lifecycle events, transactions and property access. Its remaining persist/remove/detach/hydration/dirty-check code accesses the internal manager's maps directly. This staged access is deliberately not a claim of complete encapsulation; it keeps hot accesses and absent-versus-empty entries intact while establishing one storage owner and centralizing the two registration transitions.

Delete/detach unsets and clear() retain their prior execution/assignment order. In particular, clear() empties the maps in the original chain interleaved with other UnitOfWork cleanup, then dispatches onClear. No callback-driven reset, implicit map alias or new snapshot normalization is introduced. No mapping cache/public API migration is required. Other coordinated transitions can be assessed separately after this boundary is reviewed.

## Validation

Six component cases cover publication-time consistency, snapshot replacement, copy-on-write, post-insert merge/zero IDs without entity-property assignment, collision/failure mutation order and isolation between managers. Four UnitOfWork cases verify actual DBAL conversion, readonly-property failure before registration, post-insert collision with canonical preservation, empty-ID failure ordering and clear behavior without connecting to a database.

The full SQL/hydration/proxy/generated-ID/lifecycle/rollback/worker suites pass sequentially on PHP 8.4.27 and PHP 8.5.12 with PostgreSQL 18.7: **1279 tests / 8460 assertions / 21 Redis skips** each. PHPStan and PSR-12 pass without suppressions. No benchmark files are part of this extraction.
