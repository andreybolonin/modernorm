# Scalar dirty checking

`ScalarChangeTrackingPlan` is an internal optimization for fully managed entities using DEFERRED_IMPLICIT change tracking. It casts each admitted entity to an array once and combines raw field reads and strict scalar comparison in one pass. Mangled keys for private/protected properties are compiled once; per-field accessor calls and repeated casts are avoided. There is no new mapping option, public API or PHP extension requirement.

Plans accept exact-class entities with ordinary typed int, float, string or bool properties, including nullable/private/protected and uninitialized fields handled by standard accessors. Associations, embeddables, PHP or mapped inheritance, property hooks, readonly properties, enums, arrays/objects, untyped/union fields and custom accessors retain the existing path. Pending INSERTs, missing snapshots and tracked lazy references also retain it. Uninitialized lazy objects cannot enter the plan.

UnitOfWork still applies readonly policy, invokes preFlush before choosing the plan, owns identifiers/state/snapshots, and schedules UPDATEs. PreUpdate, recomputation, persisters, optimistic version updates, transaction/error handling and SQL ordering are unchanged. A no-change result leaves pending changesets and original snapshots untouched. A changed result replaces the changeset/snapshot and schedules the entity at the original coordination point.

Plan compilation admits only standard raw accessors/wrappers and records their corresponding object-cast keys. Missing/uninitialized properties yield null, matching those accessors. Actual-data assignments copy scalar values without binding snapshots to property references. Scalar equality remains the same as the generic path. Missing original keys are skipped; present nulls participate in comparison. Fields marked notUpdatable are read into actual data but cannot produce UPDATE changes. Generated identity IDs and version fields are excluded by the same rule as ordinary dirty checking. Non-scalar original values request the generic comparator, retaining enum/custom baseline behavior.

Actual data is built in metadata order during the pass. Copying a baseline only when the first field changes would be incorrect for existing snapshots with extra IDs/version keys, different key order or missing fields. The optimization does not promise lower retained memory: updated snapshots and changesets still need storage.

A WeakMap caches accepted and rejected plans by metadata instance. Structural metadata changes (accessors, field mappings, identity/version settings, associations, embeds, inheritance, readonly/change-tracking policy or reflection class) invalidate the entry. Cache values do not reference their metadata key; they cannot keep a discarded metadata instance alive. Per-field notUpdatable and non-scalar baseline checks remain dynamic.

Regression coverage compares complete changesets/snapshot shape against the existing comparator, including repeated dirty checking, missing baselines, insert-only fields, lifecycle mutations, readonly marking, cache invalidation/collection and fallback. A PostgreSQL test exercises hydrated generated-ID/version snapshots, preUpdate mutation/recomputation, insert-only preservation and repeated no-op flushes.

The full suite passes on PHP 8.4 and PHP 8.5: 1330 tests, 8781 assertions and 21 Redis skips per version. PHPStan and code style checks pass.

Diagnostic benchmark scripts and raw measurements remain local and are not part of this feature.
