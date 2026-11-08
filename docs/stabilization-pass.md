# Stabilization pass: schema, workers and core/bundle

This records a bounded local stabilization pass based on core `9b1267b` plus the SchemaTool changes described below, and bundle `eb9e8be`. It is not a stable release announcement. Machine-readable results, the SchemaTool source hash and local ZIP hash are in [the evidence file](evidence/stabilization-2026-11-08.json).

## Schema findings

`SchemaRoundTripTest` adds ten cases across the classic DBAL schema API and the optional editor API. Nine fail against the preceding SchemaTool; all ten pass after the fixes. The isolated predicate and FK-drop cases prevent one failure from hiding the others.

| Finding | Fix | Verified boundary |
| --- | --- | --- |
| Mixed quoting (`schema."Ledger"` versus `"schema"."Ledger"`) defeats target whitelisting and legacy schema keys, producing false CREATE/DROP TABLE SQL | Use parsed canonical names for whitelist matching and normalize introspected table names before legacy Schema comparison | Quoted table/column names, restrictive application filter, repeated empty diff and preservation of an unrelated filtered table |
| PostgreSQL adds one outer parenthesis pair to a partial-index predicate, producing perpetual DROP/CREATE INDEX | Recognize exactly the `predicate` versus `(predicate)` equivalence in the comparison target | `IS NULL` and numeric comparison; genuine condition changes still generate/apply DDL and converge |
| Editor metadata can contain unnamed FKs; DROP emits an empty constraint name and silently leaves the parent table | Build table-drop SQL from deployed constraint names for the mapped tables | Classic/editor deletion followed by an empty repeated drop; no DROP of unrelated tables |

The normalization does not attempt general SQL-expression equivalence or add support for arbitrary generated columns. SchemaTool retains the existing drop policy of suppressing individual execution errors; this pass fixes the identified source of invalid DROP SQL, not that broader policy.

The matrix also changes nullable/length/default attributes, a quoted default literal, ordinary/partial indexes, FK on-delete behavior and explicit sequence allocation, then verifies another empty diff. Real identity/sequence inserts are exercised. The original application asset-filter callable is restored after comparison.

These are confirmed local schema defects. This report does not classify them as new Doctrine issues or claim they are all inherited from Doctrine ORM.

## Worker and L2 checks

`WorkerContextTest` performs 200 partial/native hydration, lazy association, update, no-op flush and clear cycles. Every cycle verifies that the entity graph becomes collectible through WeakReference checks. A write-callback failure closes and clears the context; replacing it releases the failed manager and objects, and later cycles remain usable. Exception objects themselves must also be released by the caller: their stack traces can legitimately retain a context.

Observed retained PHP heap after collection was 28,202,712 bytes at cycle 20 and 28,203,056 bytes at cycle 200 in the full-suite run. This is not an RSS bound or a universal memory budget; the exact collectible-object assertions are the deterministic check.

Existing Redis tests were run with a longer 10-second soak: one writer, four independent readers and 7,310 committed updates after warmup. Reads started after acknowledgement saw the new version; in-flight reads retain their documented allowance for the preceding committed version. Generation metadata stayed at its post-warmup baseline and payloads expired after quiescence. This fixed-ID workload does not bound metadata growth for unlimited new IDs or classes.

## Core, bundle and packaging

Before the request to leave the PostgreSQL version matrix to CI, the following checks completed locally on PHP 8.5.12 / PostgreSQL 18.6 / DBAL 4.5.0:

| Check | Result |
| --- | --- |
| Full core suite, PostgreSQL + disposable Redis | 750 tests, no skips |
| Bundle suite using its compatibility bootstrap against the working core | 64 tests / 403 assertions, no skips |
| Saved Profiler state compatibility | 4 tests / 26 assertions, no skips |
| Observability SDK/OTLP compatibility | 18 tests / 186 assertions, no skips |
| ZIP installation in an isolated no-dev consumer | Passed, 308 source files verified |
| Optional cache installation and consumer CRUD/batching/L2/rollback recovery | Passed |
| PHPStan and PHP-CS-Fixer | Passed, no new baseline suppressions |

The consumer ZIP was built from an isolated copy of tracked working files, including the modified SchemaTool. The untracked FTS prototype was excluded. Its `0.1.0-rc.1` version is an internal package identifier, not a published release/tag. Bundle source files were not modified. Its existing upstream CI core pin remains a separate release-coordination step.

## CI matrix and remaining gates

Core CI now declares PHP 8.4/8.5 × locked/latest/lowest dependencies × PostgreSQL 17/18/19rc1: **18 mandatory integration jobs**, with no allowed failure for the RC. The requested 19rc1 image manifest was checked. Job/artifact names include PostgreSQL to prevent upload collisions, and the server version is logged explicitly.

Worker and L2 evidence are uploaded for locked jobs. The existing measurement step now also records hydration alongside statistics, batching, L2 and bulk measurements. These are observations rather than fixed-host performance regression thresholds.

The PostgreSQL matrix was not run locally; the pending disposable 19rc1 launch was cancelled at the user's request. The workflow is configured but no new remote run was triggered. Before a release, its 18 jobs must pass for the final committed candidate, including latest/lowest dependencies and both PHP versions. The local checks above do not replace those gates. Linux results, bundle core-pin coordination and same-host historical performance comparisons are not asserted by this report.

See the [lifecycle/recovery contract](lifecycle-contract.md), [flush boundary audit](flush-boundary-audit.md) and [release checklist](beta-release.md).
