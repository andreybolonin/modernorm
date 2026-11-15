# Critical persistence fixes — 2026-11-15

This pass fixes four P0 problem families from the [open issue audit](doctrine-open-issues-audit.md): lost postFlush work, OneToOne back-reference clobbering, EAGER to-one clobbering and nested array hydration with shared targets. The fixes are in the current working tree. Six upstream references are linked to the covered scenarios: #5732, #8282, #8436, #5747, #8205 and #9185. This does not certify every variation of those reports or resolve the other twenty reproduced problem families.

## Changes and regressions

| Mechanism | Resulting contract | PHPUnit coverage |
| --- | --- | --- |
| Completed flush cleanup ran after postFlush and erased its new queues | Completed work is cleared before listeners run. Persisted entities, scheduled deletes and deferred-explicit dirty checks remain pending for the next explicit flush. Listener exceptions preserve new work without replaying completed SQL. Nested flush remains rejected. | FlushBoundaryAuditTest: four insert cases cover empty/written flushes and returning/throwing listeners; delete and deferred-explicit update cases verify database state and a subsequent no-op flush. |
| Loading an old OneToOne reference completed its inverse link unconditionally | Bidirectional hydration preserves locally changed relations and their comparison baselines, including when changesets were already computed. A lazy initializer's targeted refresh does not refresh unrelated managed entities. | CriticalHydrationRegressionTest: six null/existing/new replacement cases with and without prior changeset computation; orphan replacement during flush. |
| EAGER re-fetch treated a locally nulled relation as a missing hydration link | Pending local to-one changes remain intact. Joined aliases still follow the database row so that nested joined data is not attached to a local replacement. Explicit refresh continues to restore the database relation. | CriticalHydrationRegressionTest: six null/existing/new replacement cases and explicit EntityManager/query refresh coverage. |
| ArrayHydrator reused collection indexes by immediate parent ID across independent array copies | Index maps are scoped to the complete ancestor identity path. Newly inserted elements use their actual local index. Shared entity IDs under different roots no longer lose children or create phantom null slots. | ObjectHydrationTest: plain, indexed, mixed and indexed/mixed results; reversed nested row order, revisited roots, duplicate rows and an empty join. |

Ordinary query hydration preserves dirty managed state; it is not an implicit refresh. Explicit refresh and database lock/refresh contracts retain their separate semantics. Scheduling arbitrary work during preUpdate/postPersist and arbitrary postFlush collection-graph mutation are not newly certified by this pass.

## Evidence

The initial focused regression run against baseline `ad789bc` contained **42 scenarios / 370 assertions / 15 failures**. After fixing those failures, one additional deferred-explicit postFlush test was added. The complete suite now contains **24 additional PHPUnit scenarios** relative to the 953-test baseline.

- PHP 8.4.27: **977 tests / 7153 assertions / 21 skipped Redis tests**.
- PHP 8.5.12: **977 tests / 7153 assertions / 21 skipped Redis tests**.
- PostgreSQL 18.7; Redis was not started. Other database/dependency combinations remain CI checks.
- PHPStan passes without new baseline entries or suppressions. Changed PHP files pass PSR-12.

The original 57-case diagnostic runner was run once against the changed code on PHP 8.5. Exactly four outcomes changed from `probe-failed` to `not-reproduced`: old OneToOne reference loading, EAGER null re-fetch, postFlush persist and shared-target nested array hydration. The other 53 outcomes retain their original classification. PHP 8.4 behavior is covered by the complete PHPUnit suite; a redundant second diagnostic run was not needed.

[Before/after and suite evidence](evidence/critical-persistence-fixes-2026-11-15.json) · [Follow-up diagnostic JSON](evidence/critical-issue-probes-after-php85-2026-11-15.json)

The original issue snapshot and initial PHP 8.4/8.5 diagnostic evidence remain historical baseline evidence. Inventory follow-up fields identify the six locally covered issue references without changing upstream issue state or rewriting the baseline outcomes.
