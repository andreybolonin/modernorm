# Local dirty-checking diagnostic

100,000 managed entities; 5/20/50 mutable scalar fields plus an identity-generated ID (excluded from dirty checking). Mixed int/string/bool/float/nullable-string fields. Exact class, DEFERRED_IMPLICIT; no associations, inheritance, embedded values, hooks, readonly fields, lazy objects, callbacks, custom accessors or SQL.

PHP 8.5.12, CLI OPcache/JIT disabled; one untimed warm-up and three measured samples per scenario, rotating variant order. Each width runs in a separate process. Changed entities are the first N contiguous records; random mutation locality is not modeled. The duplicate 0%-all-fields scenario is omitted. Setup, registration, mutations, resets, GC, oracle preparation and equivalence checks are outside the timers. Initial snapshot arrays are distinct per entity; unchanged field values may share normal PHP string storage.

Run: `php -d memory_limit=2G benchmarks/dirty-checking.php WIDTH 100000 3` (WIDTH = 5, 20, 50). No production files are changed. These are local diagnostics, not a native-extension speed estimate.

## Variants

- current: original computeChangeSets()/computeChangeSet() through the diagnostic subclass dispatch.
- fused: same computeChangeSets() traversal; specialized accessor-read + strict scalar comparison in one pass, copy-on-write snapshot only on change. One bound closure coordinates the same maps/queues. This assumes the controlled eligible dataset, not a complete production fast path.
- guarded: fused plus exact-class and the existing UnitOfWork::isUninitializedObject() check per entity. That generic helper repeats metadata lookup already performed by the outer traversal; this intentionally exposes its cost.
- fallback: force the same guard to reject every object, then call the original computeChangeSet(). Measures compatibility overhead for a fully rejected workload, not a realistic frequency estimate.
- floor: original traversal and queue/state writes using precomputed changesets/snapshots indexed by object ID. Computation and result allocation have been moved outside timing, with shared prepared arrays. Its memory numbers and speed are not achievable optimization claims. This is an empirical residual-cost diagnostic, not a strict mathematical upper bound on native speedup.

Full changeset, snapshot and scheduled-update equivalence is checked for every entity/variant in the warm-up. A new mutation is then applied and all results checked again; a third unchanged computation must preserve pending changes. Every measured run also checks the scheduled-update count. No database connection is opened.

## Whole computeChangeSets(), median milliseconds

| Fields | Changed | Mutation | Current | Fused | Guarded | Fallback | Oracle floor | Current/fused | Current/guarded |
|---:|---:|---|---:|---:|---:|---:|---:|---:|---:|
| 5 | 0% | one | 268.5 | 136.3 | 184.4 | 320.8 | 66.4 | 1.97x | 1.46x |
| 5 | 1% | one | 286.3 | 155.8 | 220.3 | 397.3 | 72.1 | 1.84x | 1.30x |
| 5 | 1% | all | 303.1 | 138.2 | 194.3 | 345.0 | 68.8 | 2.19x | 1.56x |
| 5 | 10% | one | 279.5 | 140.9 | 192.1 | 331.1 | 68.5 | 1.98x | 1.46x |
| 5 | 10% | all | 308.1 | 150.5 | 201.7 | 347.7 | 73.0 | 2.05x | 1.53x |
| 5 | 100% | one | 310.9 | 212.3 | 232.6 | 389.4 | 106.6 | 1.46x | 1.34x |
| 5 | 100% | all | 407.9 | 203.4 | 254.2 | 452.6 | 108.6 | 2.01x | 1.60x |
| 20 | 0% | one | 843.9 | 430.4 | 481.6 | 902.2 | 66.8 | 1.96x | 1.75x |
| 20 | 1% | one | 864.0 | 436.7 | 487.6 | 919.6 | 66.9 | 1.98x | 1.77x |
| 20 | 1% | all | 894.6 | 441.2 | 504.5 | 943.4 | 74.6 | 2.03x | 1.77x |
| 20 | 10% | one | 847.1 | 432.5 | 485.4 | 905.8 | 69.1 | 1.96x | 1.75x |
| 20 | 10% | all | 875.8 | 449.6 | 505.1 | 936.6 | 70.2 | 1.95x | 1.73x |
| 20 | 100% | one | 896.1 | 475.5 | 546.7 | 960.7 | 103.8 | 1.88x | 1.64x |
| 20 | 100% | all | 1318.7 | 603.4 | 661.9 | 1410.9 | 114.4 | 2.19x | 1.99x |
| 50 | 0% | one | 2507.3 | 1556.3 | 1682.7 | 2582.2 | 70.1 | 1.61x | 1.49x |
| 50 | 1% | one | 2507.1 | 1570.7 | 1621.3 | 2595.7 | 70.2 | 1.60x | 1.55x |
| 50 | 1% | all | 2522.9 | 1570.2 | 1625.6 | 2590.8 | 70.0 | 1.61x | 1.55x |
| 50 | 10% | one | 2523.8 | 1588.7 | 1622.4 | 2570.3 | 73.8 | 1.59x | 1.56x |
| 50 | 10% | all | 2621.9 | 1632.1 | 1679.6 | 2771.4 | 73.3 | 1.61x | 1.56x |
| 50 | 100% | one | 2555.0 | 1609.5 | 1675.5 | 2589.5 | 108.2 | 1.59x | 1.52x |
| 50 | 100% | all | 3426.6 | 1988.5 | 2014.8 | 3504.8 | 125.5 | 1.72x | 1.70x |

## Stage timings and memory

Extraction collects all actual rows and retains them for the subsequent standalone comparator measurement. Comparison uses distinct prepared original snapshots and prepared actual rows. These independent stage timings are not additive to whole computeChangeSets(): their lifetimes, surrounding work and cache behavior differ. In production only the current entity's temporary actual row is needed. In particular, retaining 100,000 diagnostic actual rows is not a production memory saving opportunity.

| Fields | Changed | Mutation | Extract ms | Compare ms | Whole current peak MiB | Whole fused peak MiB | Retained current MiB |
|---:|---:|---|---:|---:|---:|---:|---:|
| 5 | 0% | one | 118.7 | 53.7 | 0.00 | 0.00 | 0.00 |
| 5 | 1% | one | 129.1 | 54.7 | 0.64 | 0.64 | 0.64 |
| 5 | 1% | all | 123.6 | 57.2 | 1.47 | 1.47 | 1.47 |
| 5 | 10% | one | 127.0 | 60.6 | 6.90 | 6.90 | 6.90 |
| 5 | 10% | all | 128.4 | 64.2 | 15.14 | 15.14 | 15.14 |
| 5 | 100% | one | 127.1 | 67.0 | 66.46 | 66.46 | 66.46 |
| 5 | 100% | all | 130.1 | 87.5 | 148.86 | 148.86 | 148.86 |
| 20 | 0% | one | 546.5 | 149.6 | 0.00 | 0.00 | 0.00 |
| 20 | 1% | one | 543.3 | 156.0 | 0.65 | 0.65 | 0.64 |
| 20 | 1% | all | 554.0 | 162.2 | 5.48 | 5.47 | 5.47 |
| 20 | 10% | one | 541.0 | 159.2 | 6.90 | 6.90 | 6.90 |
| 20 | 10% | all | 548.3 | 167.5 | 55.19 | 55.19 | 55.19 |
| 20 | 100% | one | 545.7 | 163.2 | 66.46 | 66.46 | 66.46 |
| 20 | 100% | all | 578.7 | 268.1 | 549.40 | 549.40 | 549.40 |
| 50 | 0% | one | 1923.4 | 353.4 | 0.00 | 0.00 | 0.00 |
| 50 | 1% | one | 1911.4 | 359.4 | 0.65 | 0.65 | 0.64 |
| 50 | 1% | all | 1897.5 | 365.7 | 12.88 | 12.88 | 12.87 |
| 50 | 10% | one | 1893.9 | 364.1 | 6.90 | 6.90 | 6.90 |
| 50 | 10% | all | 1916.3 | 387.2 | 129.20 | 129.20 | 129.20 |
| 50 | 100% | one | 1897.7 | 371.6 | 66.46 | 66.46 | 66.46 |
| 50 | 100% | all | 2029.2 | 649.6 | 1289.46 | 1289.45 | 1289.45 |

Peak/retained memory is additional PHP-used memory (`memory_get_usage(false)`), resetting the peak immediately before each timed region. It is not process RSS or total allocator reservation. Whole-path changesets and updated snapshots remain live at timer end; the shared prepared oracle can release baseline snapshots during timing, so its net memory is deliberately excluded from the comparison.

## Sample ranges (min..max ms)

| Fields | Changed | Mutation | Current | Fused | Guarded |
|---:|---:|---|---|---|---|
| 5 | 0% | one | 267.2..270.1 | 132.1..137.1 | 182.9..184.5 |
| 5 | 1% | one | 274.5..396.3 | 136.1..214.1 | 187.7..232.3 |
| 5 | 1% | all | 296.3..456.8 | 137.0..192.3 | 192.1..196.6 |
| 5 | 10% | one | 279.0..279.6 | 140.6..142.3 | 191.7..192.6 |
| 5 | 10% | all | 307.0..319.3 | 150.2..151.8 | 201.3..232.2 |
| 5 | 100% | one | 308.8..356.6 | 185.1..249.7 | 226.5..269.7 |
| 5 | 100% | all | 405.5..409.1 | 203.2..205.7 | 253.0..254.3 |
| 20 | 0% | one | 842.6..844.0 | 429.5..431.5 | 481.2..486.0 |
| 20 | 1% | one | 855.9..967.6 | 435.5..437.4 | 486.4..489.1 |
| 20 | 1% | all | 879.8..996.4 | 435.0..448.7 | 501.0..508.4 |
| 20 | 10% | one | 843.8..848.3 | 430.5..433.6 | 482.0..487.4 |
| 20 | 10% | all | 873.7..894.7 | 448.1..469.6 | 501.3..505.8 |
| 20 | 100% | one | 872.9..972.2 | 464.8..483.8 | 521.1..569.8 |
| 20 | 100% | all | 1247.1..1397.6 | 602.8..614.3 | 648.1..671.1 |
| 50 | 0% | one | 2492.3..2554.2 | 1554.0..1563.2 | 1602.2..1737.6 |
| 50 | 1% | one | 2506.6..2557.0 | 1565.3..1652.5 | 1619.7..1627.3 |
| 50 | 1% | all | 2517.5..2533.4 | 1568.4..1572.6 | 1622.8..1629.6 |
| 50 | 10% | one | 2499.0..2576.5 | 1566.8..1686.1 | 1615.4..1831.4 |
| 50 | 10% | all | 2619.6..2757.6 | 1601.1..1673.3 | 1655.0..1746.0 |
| 50 | 100% | one | 2537.4..2578.8 | 1604.6..1609.6 | 1661.8..1711.1 |
| 50 | 100% | all | 3416.7..3438.6 | 1963.5..2011.7 | 2009.1..2043.9 |

## Interpretation

The specialized PHP pass improves whole computeChangeSets() by 1.46x–2.19x across these controlled cases; the variant with the extra generic guard improves it by 1.30x–1.99x. This combines fewer loops, removal of per-field metadata decisions/comparator calls and lazy snapshot construction. It does not isolate the benefit of any one change and does not predict C/Rust performance.

Whole-path peak/retained memory is essentially unchanged: changed snapshots and changeset arrays are still retained. Eliminating one temporary actual row per unchanged entity does not eliminate an entity-count-sized retained allocation in the original implementation.

A separate 100,000-object guard microbenchmark (seven measured samples, one warm-up, ordinary exact-class objects) measures 48.18 ms using UnitOfWork::isUninitializedObject() versus 3.46 ms using the already available metadata ReflectionClass directly. Both recognize an uninitialized lazy ghost in a negative control. This is a guard-only measurement, not a measured cached-guard whole-path speedup. The outer production traversal already performs a lazy check; avoid adding redundant metadata lookup in a future eligible fast path.

Next useful implementation experiment: integrate the narrowly eligible scalar PHP plan with existing lifecycle/state coordination and fallback, then validate the same semantics under existing regression tests. A native read+compare prototype remains a separate experiment if dirty checking is still a material application bottleneck. Native comparison alone cannot remove property extraction and metadata-preparation costs.

The forced-fallback variant adds 1.4%–38.8% to the original path across this matrix. This conservative variant repeats the generic metadata/lazy check and adds subclass dispatch; reject ineligible metadata once when compiling the plan rather than charging this overhead to every entity.
