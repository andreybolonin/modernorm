# P1 hooked ID and readonly DateTime fixes — 2026-11-15

This pass resolves two bounded scenarios from the [audit](doctrine-open-issues-audit.md): #12077 and #9505.

## Behavior

- Deleting an entity with a generated, non-nullable backed hooked ID retains the ID. PHP forbids unsetting a hooked property, and assigning null violates its type. Identity Map and UnitOfWork registration are still removed; postRemove can read the retained ID. Persisting that object again generates a new ID. Nullable hooked IDs still reset to null; ordinary non-nullable IDs are still unset. Readonly and assigned IDs retain their previous behavior. ORM access continues to bypass user property hooks.
- An initialized readonly DateTimeInterface property accepts a separately converted value representing the same instant, retaining the original object. Different instants still raise LogicException. Other readonly values retain strict identity/value checks. Hydration snapshots the actual retained readonly value, so the next flush cannot infer a change merely from the converter allocating a new date object. Mutable and immutable DateTime mappings are covered; this does not add in-place mutable DateTime change detection.

## Validation

`tests/Integration/P1ReadonlyHookRegressionTest.php` adds eight scenarios / 40 assertions: persisted/lazy hooked deletion, postRemove, Identity Map removal, reinsertion, nullable/plain reset controls, repeated refresh after persist/hydration/lazy loading, snapshot identity, no phantom preUpdate and changed mutable/immutable dates rejecting refresh.

The initial six scenarios against the unchanged production code reported four errors (hooked deletion and three refresh variants); the reset and changed-date controls already passed. Two further variants cover lazy deletion and a changed mutable date.

- Full PHP 8.4.27 and PHP 8.5.12 suites on PostgreSQL 18.7: **1043 tests / 7503 assertions / 21 Redis skips** per runtime.
- PHPStan includes the new test file and passes; changed PHP passes PSR-12.
- Both targeted diagnostic cases are `not-reproduced`: [PHP 8.4 evidence](evidence/p1-readonly-hook-probes-php84-2026-11-15.json), [PHP 8.5 evidence](evidence/p1-readonly-hook-probes-php85-2026-11-15.json).

The full suites run sequentially in a dedicated test database. No new Redis coverage is claimed. These fixes leave four P1 and seven P2 baseline families open.
