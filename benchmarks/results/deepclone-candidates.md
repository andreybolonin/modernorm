# Further deepclone candidates — 2026-11-16

Core baseline `8edf978`, macOS arm64, PHP 8.4.27 / 8.5.12, CLI OPcache enabled, JIT disabled. Both baseline and candidate processes load deepclone 0.8.10 ([source 12285e5](https://github.com/symfony/php-ext-deepclone/commit/12285e5f6f774b0bbb4f0ea26056f45e0ed0fc74)). Each scenario processes 20,000 entities with two warmups and nine measured rounds. All figures below are medians. The production baseline already includes native hydration for eligible flat entities.

Processes ran sequentially: PHP 8.4 ORM → candidate → guarded; PHP 8.5 guarded → candidate → ORM. These are single local observational comparisons, without timing thresholds or a full application/SQL benchmark. See [commands, scope and validation](../README.md#further-deepclone-candidates).

## 1. Fused creation and assignment

| Implementation | PHP 8.4, ms | PHP 8.5, ms |
| --- | ---: | ---: |
| Current ClassMetadata creation + EntityHydrator | 22.60 | 15.98 |
| Prototype: cached Reflection creation + native assignment | 17.07 | 11.95 |
| Prototype: native creation and assignment in one call | 16.28 | 11.32 |

The isolated fusion gain is **1.05× / 1.06×**, comparing the two prototypes with identical argument preparation. The larger **1.39× / 1.41×** difference from the production baseline also includes simpler fixed-fixture mapping/eligibility plumbing; it cannot be attributed to creation fusion.

The failure probe confirms a behavioral obstacle: actual UnitOfWork::createEntity() registers its object before scalar assignment; that object remains observable in the identity map when a later type error occurs. A combined `deepclone_hydrate(Class::class, ...)` call throws before returning the object, so it cannot preserve that registration order. Retained entity heap is identical (4,368,440 bytes), with only sub-KiB peak differences. This small isolated gain does not justify changing UnitOfWork registration semantics by itself.

## 2. Embedded batching

The prototype batches contiguous fields of each writable embedded object. It creates a missing embedded without its constructor, attaches it before child assignment, and updates an existing embedded in place. Chunk boundaries preserve interleaved root/child input order, including a tested later type failure.

| Scenario | PHP 8.4 ORM → prototype, ms | Speedup | PHP 8.5 ORM → prototype, ms | Speedup |
| --- | ---: | ---: | ---: | ---: |
| One embedded field, new | 21.07 → 23.06 | 0.91× | 15.08 → 16.65 | 0.91× |
| Eight embedded fields, new | 72.80 → 46.36 | 1.57× | 51.65 → 34.97 | 1.48× |
| Eight embedded fields, existing | 69.16 → 43.55 | 1.59× | 49.11 → 32.55 | 1.51× |

One-field batching loses time; eight-field batching is the strongest candidate here. Retained heap is unchanged: 6,288,440 bytes for 20,000 new roots with wide embedded objects. The native temporary-buffer peak is approximately 0.9 KiB higher for new wide embeddeds and 1 KiB higher for existing ones. Existing embedded identity and private/protected fields were verified. The crossover between one and eight fields was not measured; nested/readonly embedded members and general mapping eligibility would require further implementation and coverage.

## 3. New readonly entities

Batching initial writes avoids repeated readonly initialization checks, but the naive prototype loses an earlier snapshot correction if a later write throws. Its happy-path speedups (**1.21–1.32×**) therefore do not establish a compatible replacement.

The guarded prototype checks that readonly fields are uninitialized, validates the fixed fixture's named property types before writing, and falls back to the existing generator on incompatible input. It preserves the tested earlier correction before a later type failure. Existing readonly values use the accessor fallback, preserving equivalent DateTime object identity.

| Scenario | PHP 8.4 ORM → guarded, ms | Speedup | PHP 8.5 ORM → guarded, ms | Speedup |
| --- | ---: | ---: | ---: | ---: |
| One readonly field, new | 24.85 → 20.81 | 1.19× | 18.03 → 14.23 | 1.27× |
| Six readonly fields, new | 70.95 → 65.18 | 1.09× | 52.31 → 45.55 | 1.15× |
| Six readonly fields, existing control | 80.44 → 83.98 | 0.96× | 58.69 → 59.80 | 0.98× |

Guard checks consume much of the initial gain, especially with more readonly fields. Retained heap is unchanged; the guarded peak is 128 bytes higher for new entities and 328 bytes higher for the existing control. This is a possible second experiment for new-only hydration; support for broader mappings, referenced values and all failure modes remains outside these fixed fixtures.

## Outcome

Prioritize batching larger writable embeddeds, with a fallback for small groups and unsupported mappings. Consider guarded initial readonly hydration separately. Keep fused creation experimental until its small isolated gain can justify a design preserving UnitOfWork registration behavior. None of the three shows meaningful entity-memory savings; no production code was changed by this benchmark.

The embedded candidate has subsequently been implemented with stricter eligibility and an eight-field threshold. See the [production comparison](deepclone-embedded.md) for actual gains including those guards.

Raw measurements, including timing/peak samples and compatibility probes:

- PHP 8.4: [current ORM](deepclone-candidates-php84-orm.json), [native prototypes](deepclone-candidates-php84-native.json), [guarded readonly](deepclone-candidates-php84-guarded.json).
- PHP 8.5: [current ORM](deepclone-candidates-php85-orm.json), [native prototypes](deepclone-candidates-php85-native.json), [guarded readonly](deepclone-candidates-php85-guarded.json).
