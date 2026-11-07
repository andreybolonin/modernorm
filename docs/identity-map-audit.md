# Identity Map boundary and regression audit

`Internal/UnitOfWork/IdentityMap` owns the class/normalized-identifier-to-object map. It registers an object, rejects collisions using the existing `EntityIdentityCollisionException`, looks up objects, checks exact instance membership, removes only that instance, counts entries, returns array snapshots and clears retained references. It has no EntityManager, metadata, SQL, loading, lifecycle or entity-state dependencies.

UnitOfWork still supplies mapped class names and normalized string keys. Identifier conversion, NEW/MANAGED/REMOVED/DETACHED state, readonly bookkeeping, lazy/batch queue cleanup, dirty checking, hydration, SoftDelete visibility and L2 policy remain in their existing adapters. The component does not decide whether a row is visible in a query.

## Preserved contracts

- Registering the same instance under the same key returns false; a different instance raises the existing collision exception and cannot replace the registered object.
- Classes have separate key spaces. Leading-zero string IDs such as `01` remain distinct from `1`. PHP array key coercion, class ordering and empty class buckets retain the previous public snapshot shape.
- Array snapshots do not expose mutable map storage. Entity objects themselves retain their existing shared identity.
- `tryGetById()` and `tryGetByIdHash()` still return false on a miss. Nullable `getByIdHash()` now returns null quietly on a missing key instead of emitting undefined-array-key warnings.
- A successful removal performs UnitOfWork's existing readonly and lazy/batch queue cleanup. A rejected removal leaves the registered object and its bookkeeping untouched.
- `clear()` releases entries before `onClear`. It does not reset database rows or generate new identifiers.

## Confirmed inherited bug: wrong-instance membership and eviction

Before extraction, membership and removal checked only whether a class/ID slot existed, without checking which object occupied it. `registerManaged()` writes tracking information before collision detection. After a second instance is rejected for an occupied key, it therefore has enough tracking information to pass the old membership check. Removing or detaching that rejected instance could evict the original managed object and allow another instance to be loaded for the same row.

Three PostgreSQL cases in `tests/Integration/IdentityMapTest.php` reproduce the bug against the preceding revision `32e4ded`:

| Case | Before | After |
| --- | --- | --- |
| Rejected copy is reported as a member | Fails | Passes |
| Removing rejected copy evicts the canonical instance | Fails | Passes |
| Detaching rejected copy evicts the canonical instance | Fails | Passes |

The other two integration cases pass before and after: reference/find/query/association reuse of a single object, and string-key/snapshot/clear/readonly bookkeeping behavior. The five cases are not five independent bugs.

The mechanism was checked directly against [Doctrine ORM 3.7.6 UnitOfWork](https://github.com/doctrine/orm/blob/3.7.6/src/UnitOfWork.php), specifically `registerManaged()`, `isInIdentityMap()` and `removeFromIdentityMap()`. The fix is in ModernORM development code; this is not a claim about newer Doctrine versions or published ModernORM releases.

Membership and removal now require the stored object to be the supplied instance. Collision registration still raises the existing exception. This extraction does not make all of UnitOfWork's registration bookkeeping atomic or establish a general policy for continuing after arbitrary persistence exceptions.

## Validation

Twelve isolated cases in `tests/Unit/IdentityMapTest.php` cover registration, collision preservation, class separation, normalized keys, exact-instance removal, snapshot isolation, missing lookups, empty buckets and releasing references on clear.

One hundred focused PostgreSQL tests pass across the new Identity Map cases, lazy/batch loading, L2 batch loading, SoftDelete and mutable L2 cache behavior. Existing coverage includes SoftDelete visibility with already-managed objects and cached entities, repeated foreign keys sharing one proxy, and partial/lazy initialization preserving local changes.

Final validation: **720 tests pass on PHP 8.5.12 and PHP 8.4.27**, with PostgreSQL and disposable Redis, without skipped tests. PHPStan and PSR-12 checks pass without new baseline suppressions.
