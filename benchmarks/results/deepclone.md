# Experimental deepclone hydration — 2026-11-15

macOS arm64, PHP 8.4.27 / 8.5.12, CLI OPcache enabled, JIT disabled. Extension 0.8.10, [source revision 12285e5](https://github.com/symfony/php-ext-deepclone/commit/12285e5f6f774b0bbb4f0ea26056f45e0ed0fc74). ORM baseline: `f4f12e9`. No production source changes. Each case measures 20,000 entities with two warmups and nine measured rounds. Values below are medians from one sequential run per mode; they are observational, with no CI timing threshold.

Both columns load the same extension. The native adapter preserves embedded/readonly accessors and includes filtering, key mapping and argument-array construction.

| Scenario | PHP 8.4 accessors → native, ms | Speedup | PHP 8.5 accessors → native, ms | Speedup |
| --- | ---: | ---: | ---: | ---: |
| Flat, new entities | 40.69 → 23.06 | 1.76× | 29.64 → 18.27 | 1.62× |
| Flat, existing entities | 38.86 → 21.93 | 1.77× | 27.73 → 17.23 | 1.61× |
| Flat, lazy ghosts | 39.52 → 29.66 | 1.33× | 28.17 → 24.70 | 1.14× |
| Embedded + readonly, new | 30.13 → 29.13 | 1.03× | 21.78 → 20.48 | 1.06× |
| Embedded + readonly, existing | 28.85 → 27.95 | 1.03× | 20.77 → 20.16 | 1.03× |

Memory did not improve. New flat entities peaked at 5,009,120 bytes with accessors and 5,010,128 with the adapter; embedded/readonly entities at 3,889,400 and 3,889,432 bytes. For preallocated entities the incremental peak was 680 → 1,688 bytes (flat) and 1,392 → 1,424 bytes (embedded/readonly). These heap deltas exclude inputs, warmed metadata and preallocated entities; they do not measure total process memory.

The separate baseline without loading deepclone was close to the accessor baseline with it loaded; this run shows no material loading effect. All benchmark value, hook, readonly identity and lazy-state checks passed on both PHP versions. See [method and commands](../README.md#experimental-deepclone-scalar-hydration) for the measured boundary and limitations. The scalar-stage improvement warrants a guarded fast-path experiment, but these measurements do not establish a full hydration speedup.

Raw measurements:

- PHP 8.4: [without extension](deepclone-php84-without-extension.json), [accessors](deepclone-php84-accessors.json), [native adapter](deepclone-php84-native.json).
- PHP 8.5: [without extension](deepclone-php85-without-extension.json), [accessors](deepclone-php85-accessors.json), [native adapter](deepclone-php85-native.json).

## Guarded production implementation

The implemented automatic fast path adds metadata-plan caching and eligibility checks. It handles compatible scalar backed-enum properties natively, but retains the entire accessor path for embedded/readonly/custom mappings, enum arrays and uninitialized lazy objects. Inputs requiring special null or enum conversion also fall back before any writes. This differs from the experimental adapter above, which mixed native writes and accessors within each entity.

The production comparison uses the same workload, PHP configuration and sample counts; both processes load the extension. The `accessors` mode explicitly runs the accessor loop rather than automatic extension detection, while `orm` invokes the real EntityHydrator. Results from the final implementation:

| Scenario | PHP 8.4 accessors → ORM, ms | Speedup | PHP 8.5 accessors → ORM, ms | Speedup |
| --- | ---: | ---: | ---: | ---: |
| Flat, new entities | 40.42 → 28.84 | 1.40× | 29.22 → 19.81 | 1.47× |
| Flat, existing entities | 39.04 → 28.28 | 1.38× | 27.72 → 18.95 | 1.46× |
| Flat, lazy ghosts (accessor fallback) | 39.51 → 40.87 | 0.97× | 28.37 → 28.81 | 0.98× |
| Embedded + readonly, new (accessor fallback) | 30.03 → 31.00 | 0.97× | 21.60 → 21.74 | 0.99× |
| Embedded + readonly, existing (accessor fallback) | 29.11 → 29.92 | 0.97× | 20.88 → 21.63 | 0.97× |

The additional checks reduce the flat-entity gain relative to the prototype. Fallback cases show a small overhead in this run, rather than a speedup. Memory remains essentially unchanged: native new flat entities peak about 1 KiB above accessors; fallback cases about 32 bytes above. These are scalar-stage timings, not end-to-end application gains.

- PHP 8.4: [production baseline](deepclone-php84-production-baseline.json), [production native path](deepclone-php84-production-native.json).
- PHP 8.5: [production baseline](deepclone-php85-production-baseline.json), [production native path](deepclone-php85-production-native.json).
