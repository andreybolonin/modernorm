# P1 cascade refresh fix — 2026-11-15

Historical fix report: cascade refresh has since been [removed](cascade-refresh-detach-removal.md). RootRefreshRegressionTest retains the applicable explicit refresh scenarios; recursive refresh/detach and their visited sets no longer exist.

This pass resolves the supported cascade refresh scenario linked to #7169 and #10499 in the [audit](doctrine-open-issues-audit.md). It does not certify every upstream graph variation.

Cascade refresh skips association members that are NEW or scheduled for INSERT, because they have no stored row to reload. Refreshing the owner restores its scalar fields and associations from the database, discarding transient collection members or local to-one replacements. Managed associated entities still refresh recursively; cycles remain guarded by the visited set. Detached associated entities still raise the existing exception. Direct refresh of an unmanaged root remains invalid.

Refresh does not implicitly persist, flush, detach or cancel explicitly scheduled inserts. A child explicitly persisted before refresh stays scheduled and can be inserted by the next flush, even when the refreshed inverse collection no longer contains it. This does not change orphan removal or cascade-persist cancellation semantics.

## Regression coverage

`tests/Integration/CascadeRefreshRegressionTest.php` adds **10 scenarios / 43 assertions**:

- OneToMany and ManyToMany collections, initialized and uninitialized, including assigned string identifiers.
- Transient to-one replacement restored to the database reference.
- Mixed managed/transient children, recursive refresh and a bidirectional cycle.
- A plain array containing a transient child.
- An explicitly persisted child whose INSERT must remain pending.
- Unmanaged root and detached association rejection controls.

Before the fix, the same ten scenarios produced eight errors and one PHP warning. The two rejection controls already passed. The production change only distinguishes cascade calls in the existing refresh traversal; hydration and collection loading retain their existing behavior.

## Validation

- Full PHP 8.4.27 and PHP 8.5.12 suites on PostgreSQL 18.7: **1053 tests / 7546 assertions / 21 Redis skips** per runtime, run sequentially.
- PHPStan includes the new regression file and passes; changed PHP passes PSR-12.
- The original `cascade_refresh_new_child` diagnostic is `not-reproduced` on both versions: [PHP 8.4 evidence](evidence/p1-cascade-refresh-probes-php84-2026-11-15.json), [PHP 8.5 evidence](evidence/p1-cascade-refresh-probes-php85-2026-11-15.json).

Redis was not started. After this pass, three P1 and seven P2 baseline families remain open.
