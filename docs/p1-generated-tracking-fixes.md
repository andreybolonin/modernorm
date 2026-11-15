# P1 generated-field change tracking fix — 2026-11-15

This pass resolves the supported #12017 scenario in the [audit](doctrine-open-issues-audit.md).

The entity persister already omits `updatable: false` columns from UPDATE SQL. UnitOfWork now applies the same permission when computing changes for existing entities, recomputing them after callbacks, and reconciling stale partial-load snapshots. A local edit to a non-updatable generated field alone no longer schedules an entity UPDATE or dispatches preUpdate/postUpdate. Version fields do not advance for that edit.

The rule follows `updatable: false`, rather than excluding every generated field. INSERT changes still include insertable fields, even when those fields cannot later be updated. Conversely, `insertable: false` with `updatable: true` remains writable on UPDATE. Ordinary changes and preUpdate callback edits still persist; generated values are still fetched after a real write. An ignored local edit is not an implicit refresh of the object's property.

## Regression coverage

`tests/Integration/GeneratedFieldTrackingRegressionTest.php` adds **7 scenarios / 48 assertions**:

- Generated-only changes after persist, ordinary hydration, lazy loading and deferred-explicit scheduling.
- Direct recomputation ignores generated and insert-only field edits on existing entities.
- Real changes produce one SQL UPDATE and the expected events/version change, while preUpdate callback edits to writable fields still persist. The following flush is a no-op.
- INSERT-only and UPDATE-only permissions remain independent, including an explicit recomputation before INSERT.

The same seven scenarios before the production change produced six failures; the INSERT/UPDATE permission control already passed. SQL middleware statistics verify no SQL for generated-only flushes and one UPDATE for actual changes.

## Validation

- Full PHP 8.4.27 and PHP 8.5.12 suites on PostgreSQL 18.7: **1060 tests / 7594 assertions / 21 Redis skips** per runtime, run sequentially.
- PHPStan includes the new test file and passes; changed PHP passes PSR-12.
- The original `generated_field_local_change_tracking` diagnostic is `not-reproduced`: [PHP 8.4 evidence](evidence/p1-generated-tracking-probes-php84-2026-11-15.json), [PHP 8.5 evidence](evidence/p1-generated-tracking-probes-php85-2026-11-15.json).

This pass includes the preceding cascade-refresh fix in full-suite validation. Redis was not started. After both passes, two P1 and seven P2 baseline families remain open.
