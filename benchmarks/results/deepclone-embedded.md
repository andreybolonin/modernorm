# Production embedded batching — 2026-11-16

macOS arm64, PHP 8.4.27 / 8.5.12, deepclone 0.8.10 enabled in both before/after processes, CLI OPcache enabled, JIT disabled. Each case retains 20,000 new entities or uses 20,000 precreated existing entities, with two warmups and nine measured rounds. Inputs and all result/identity checks are the same [candidate workload](../README.md#further-deepclone-candidates); the measured mode is `orm`, invoking the actual EntityHydrator on both revisions.

The baseline uses EntityHydrator and EmbeddablePropertyAccessor from `8edf978`; the after run uses their updated implementations and EmbeddedHydrator. Baseline files were copied from Git into `/tmp` and loaded before the benchmark through `auto_prepend_file`, after requiring the repository's Composer autoloader. All other source, metadata, fixtures, dependencies and PHP settings are shared. Source files were not swapped in the working tree. Both original classes must be used for the baseline, including the old embedded accessor's object-creation method.

To reproduce, extract those two baseline classes with `git show 8edf978:path/to/file.php`, require them after `vendor/autoload.php` in a temporary prepend file, then run:

```sh
# PHP must already load deepclone, or add -d extension=/path/to/the/matching/deepclone.so.
php -d opcache.enable_cli=1 -d auto_prepend_file=/tmp/modernorm-before-embedded-prepend.php benchmarks/deepclone-candidates.php orm > before.json
php -d opcache.enable_cli=1 benchmarks/deepclone-candidates.php orm > after.json
```

Processes ran sequentially, PHP 8.4 before → after and PHP 8.5 after → before, with no concurrent tests or PHPStan. These are single local observational comparisons, not end-to-end application gains or CI thresholds. Plans warm during warmups. Field eligibility, input-reference checks, grouping, concrete-class/lazy checks, fallback handling and object creation are included in timing.

## Results

All times are medians in milliseconds for 20,000 entities.

| Scenario | PHP 8.4 before → after | Speedup | PHP 8.5 before → after | Speedup |
| --- | ---: | ---: | ---: | ---: |
| Embedded, eight fields, new | 72.69 → 65.02 | 1.12× | 51.17 → 46.74 | 1.09× |
| Embedded, eight fields, existing | 69.28 → 61.79 | 1.12× | 48.53 → 43.16 | 1.12× |
| Embedded, one field (accessor fallback) | 21.12 → 22.31 | 0.95× | 14.81 → 15.55 | 0.95× |
| Flat creation control | 22.77 → 22.83 | 1.00× | 15.89 → 16.09 | 0.99× |
| One readonly field control | 24.91 → 25.03 | 1.00× | 17.88 → 19.07 | 0.94× |
| Six readonly fields control | 71.09 → 71.64 | 0.99× | 52.09 → 52.19 | 1.00× |
| Existing readonly control | 81.02 → 82.21 | 0.99× | 57.14 → 59.19 | 0.97× |

The measured production gain is **1.09–1.12×** for eight-field embeddeds, below the **1.48–1.59×** experimental prototype. The prototype included root-field batching and omitted general runtime eligibility/reference checks; those figures are not a production promise. Fallback controls show small overhead/variation in this run. The second flat creation control and all individual samples remain in the raw JSON.

Retained entity heap is unchanged. New eight-field embedded rows retain 6,288,440 bytes on both paths; their peak incremental PHP heap increases from 6,289,488 to 6,290,600 bytes. For precreated entities the temporary peak increases from 1,048 to 2,504 bytes, with zero retained delta. These values exclude setup/metadata and do not measure total process RSS. There is no meaningful entity-memory saving.

## Behavior and checks

At least eight contiguous eligible writable primitive fields of the same embedded are required. Earlier writes flush before root fields, other embedded groups, referenced input, null-to-uninitialized writes or unsupported accessors. Native batches retain private/protected scope and bypass property hooks. New embedded objects are attached before child assignment; existing objects retain identity. Runtime subclasses and lazy embedded objects use accessor writes. Native exceptions propagate without retrying assignments.

The benchmark checks values, constructor/hook bypass, ignored input, existing embedded identity, readonly date identity and snapshots. Its compatibility probes still characterize the separate fused-creation/readonly prototypes; their expected incompatibility flags do not describe failures of the embedded production path. New regression tests additionally cover cross-field references, interleaved errors, custom-accessor replacement, nested/readonly/enum fallback and coercion callbacks replacing the embedded. A PostgreSQL test exercises load → refresh → dirty checking → flush → reload.

The full suite with deepclone enabled passes on PHP 8.4 and 8.5: **1223 tests / 8069 assertions / 21 Redis skips** each. The 12 new cases also pass without the extension. PHPStan and PSR-12 pass.

- PHP 8.4: [before](deepclone-embedded-php84-before.json), [after](deepclone-embedded-php84-after.json).
- PHP 8.5: [before](deepclone-embedded-php85-before.json), [after](deepclone-embedded-php85-after.json).
