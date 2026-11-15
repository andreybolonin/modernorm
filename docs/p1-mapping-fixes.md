# First P1 mapping fix pass — 2026-11-15

This pass resolves four problem families from the [open issue audit](doctrine-open-issues-audit.md), linked to #12783, #11957, #12390, #10014 and #3927. The changes are committed in `e98c822`, based on core `46eb4b8`. The original issue classifications and initial diagnostic JSON remain historical baseline evidence. After this pass, sixteen other reproduced problem families remained; see the audit for current status.

| Area | Fix | Regression coverage |
| --- | --- | --- |
| Metadata cache | Deserialized metadata reattaches the owning EntityManager configuration's naming strategy and typed-field mapper before reflection wakeup. Application services stay outside the serialized payload. | A genuinely serializing PSR-6 pool proves a warm hit without reloading attributes, custom naming/type inference with closure-backed services, dynamic field/association mapping and association overrides. Standalone wakeup restores defaults; manual custom restoration can attach configured services before wakeup. |
| MappedSuperclass sequences | Explicit name, quoting, allocation and initial value propagate through mapped-superclass chains before concrete generation is completed. An abstract default sequence remains deferred so each concrete child derives its own name. | Both classic/editor SchemaTool APIs, two concrete children sharing an explicit sequence, a concrete driver override, actual INSERT IDs/allocation and a second manager restoring serialized metadata. Separate unnamed defaults stay independent. |
| AnsiQuoteStrategy | Identifier SQL uses mapped physical column names rather than PHP field names. ANSI strategy retains its existing requirement for ANSI-compatible/already-qualified names. | Real find, UPDATE and DELETE for `id` mapped to `record_key`; ordinary operations use the physical PK. |
| Native RSM SQL | Scalar fields and owning FK columns use the configured quote strategy; result aliases are escaped as identifiers. Connection-scoped DBAL SQL conversion remains applied. | Real PostgreSQL hydration with mixed-case/spaced columns, a quoted FK, root/joined entities, explicit table aliases, custom quote strategy, NONE/INCREMENT/CUSTOM renaming and spaces/double quotes in result aliases. |

## Cache and deployment

Metadata cache salt is **V13**. Old V12 payloads may contain automatically completed superclass sequence names that cannot safely be treated as explicit inherited definitions. The new namespace prevents those stale definitions from being reused. Clear/version deployment query caches and restart workers that retain metadata in memory.

Normal cache loading restores runtime mapping services automatically from the owning configuration. Naming strategies and typed-field mappers may contain non-serializable application dependencies such as closures; they are deliberately not embedded in metadata cache entries. Direct `unserialize()` integrations must still call reflection wakeup. When they need custom services, attach them with `restoreMappingServices($namingStrategy, $typedFieldMapper)` before calling `wakeupReflection()`. Existing live metadata retains its already-bound services; changing mapping configuration requires the usual metadata-cache invalidation.

MappedSuperclass has no physical table/sequence of its own. An unnamed SEQUENCE strategy on it is resolved for each concrete entity after that entity's table mapping is known. Explicit sequence names retain their normal PostgreSQL namespace semantics; qualify the name when a particular schema is required.

## Evidence

**13 new PHPUnit scenarios** were added in `MetadataRuntimeServicesTest` and `P1MappingRegressionTest`. The same 13 scenarios against the four baseline production classes extracted from git report **3 errors and 8 failures**; the two default-sequence control cases already pass. No workspace rollback was used to collect that baseline.

- PHP 8.4.27: **990 tests / 7258 assertions / 21 skipped Redis tests**.
- PHP 8.5.12: **990 tests / 7258 assertions / 21 skipped Redis tests**.
- PostgreSQL 18.7. Redis was not started; no new Redis concurrency certification is claimed.
- Bundle smoke against the working core on PHP 8.5: **64 tests / 403 assertions**, without skips.
- PHPStan passes with the new regressions included, without new baseline suppressions. Changed PHP passes PSR-12.

Six diagnostic outcomes change from `probe-failed` to `not-reproduced`: the three metadata restoration cases, inherited sequence, Ansi PK names and native RSM quoting. The other 51 outcomes match the preceding critical-fix snapshot.

Two original metadata diagnostics attempted to map the already-mapped `name` field. Before this fix, the missing runtime service masked the later duplicate-field error. They now map an intentionally unmapped typed `extra` property, matching the intended runtime-augmentation scenario. Both refined inputs were separately rerun against the old classes and still raise the original uninitialized typedFieldMapper error. The original JSON was not rewritten. The runner now accepts repeated `--case=NAME` options for focused verification; an unknown case/option exits 2 before connecting to PostgreSQL.

[Suite and before/after evidence](evidence/p1-mapping-fixes-2026-11-15.json) · [Follow-up diagnostic JSON](evidence/p1-mapping-probes-after-php85-2026-11-15.json) · [Refined metadata inputs against baseline](evidence/p1-metadata-refined-baseline-2026-11-15.json)
