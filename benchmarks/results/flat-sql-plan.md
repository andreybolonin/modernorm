# Flat SQL plan — 2026-11-16

Baseline `7439ebb`, macOS arm64, PostgreSQL 18.7, PHP 8.4.27 / 8.5.12, deepclone 0.8.10. CLI OPcache enabled, JIT disabled. Each scenario hydrates 20,000 managed entities with two warmups and nine measured rounds; times are medians in milliseconds. SQL execution, metadata warming and result validation are excluded. Fetching, conversion, query-local plan compilation, registration/Identity Map, scalar assignment and snapshots are included.

Baseline UnitOfWork, AbstractHydrator, ObjectHydrator and SimpleObjectHydrator were extracted with `git show 7439ebb:src/...` into `/tmp` and loaded through `auto_prepend_file` after Composer autoload. All other source, fixtures, connection settings and PHP settings are shared. The after process uses the implemented plan. The working tree was not swapped between runs.

Processes ran sequentially without concurrent tests/PHPStan: PHP 8.4 before → after, PHP 8.5 after → before, followed by PHP 8.5 extension-free before → after. These are single local observational comparisons, not application-wide gains or timing thresholds. [Script and commands](../README.md#flat-sql-hydration-plan).

| Hydrator / fields | PHP 8.4 before → after | Speedup | PHP 8.5 before → after | Speedup |
| --- | ---: | ---: | ---: | ---: |
| Object, 2 primitive fields | 85.74 → 82.81 | 1.04× | 65.83 → 61.33 | 1.07× |
| Object, 12 primitive fields | 139.89 → 129.75 | 1.08× | 102.98 → 93.37 | 1.10× |
| Object, JSON fallback control | 93.84 → 94.55 | 0.99× | 73.57 → 74.06 | 0.99× |
| Simple, 2 primitive fields | 70.47 → 67.18 | 1.05× | 53.04 → 49.35 | 1.07× |
| Simple, 12 primitive fields | 145.67 → 136.31 | 1.07× | 105.77 → 97.10 | 1.09× |
| Simple, JSON fallback control | 77.68 → 78.69 | 0.99× | 60.47 → 61.34 | 0.99× |

The additional speedup is **1.04–1.10× for full managed hydration**, compared with a baseline already using generic deepclone scalar assignment. It is not another multiplier to apply directly to the earlier 1.47× scalar-stage result. The plan removes generic field-map lookups and argument-array construction but leaves fetching, conversions and UnitOfWork work in place.

## Extension-free control

| Scenario, PHP 8.5 without deepclone | Before → after, ms | Speedup |
| --- | ---: | ---: |
| Object, 2 fields | 64.47 → 64.79 | 1.00× |
| Object, 12 fields | 114.05 → 114.45 | 1.00× |
| Object, JSON | 72.55 → 72.63 | 1.00× |
| Simple, 2 fields | 51.16 → 52.06 | 0.98× |
| Simple, 12 fields | 116.40 → 117.58 | 0.99× |
| Simple, JSON | 59.86 → 60.09 | 1.00× |

Unsupported JSON controls show about 1% overhead in this run; extension-free controls vary by up to about 2%. New-row context dispatch is kept behind a cheap absent-hint check. Tiny result sets/cold single lookups were not measured here; no broad latency claim follows from this batch workload.

## Memory and validation

Entity/Identity Map/snapshot heap remains essentially unchanged. With deepclone, Object/wide peak delta is 35,116,056 → 35,113,632 bytes; Simple/wide is 33,160,384 → 33,158,032 bytes. The roughly 2.4 KiB reduction is query-level temporary storage, not a per-entity saving. Values are incremental PHP heap after setup, not RSS or total process memory.

Every entity's ID/order and converted field values are verified, as are managed counts and constructor/hook bypass. Real PostgreSQL regressions additionally verify callbacks, canonical identity, refresh/dirty checking, private hint isolation and registered prefixes after assignment failure. Full suites: **1269 tests / 8401 assertions / 21 Redis skips** on each runtime with the extension; the 19 new cases also pass without it. PHPStan and PSR-12 pass.

- PHP 8.4: [before](flat-sql-plan-php84-before.json), [after](flat-sql-plan-php84-after.json).
- PHP 8.5: [before](flat-sql-plan-php85-before.json), [after](flat-sql-plan-php85-after.json).
- PHP 8.5 without deepclone: [before](flat-sql-plan-php85-no-extension-before.json), [after](flat-sql-plan-php85-no-extension-after.json).
