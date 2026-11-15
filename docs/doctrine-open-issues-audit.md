# Open Doctrine ORM issue audit — 2026-11-15

This is an issue inventory and a bounded executable audit of ModernORM, not a claim that every variant of every Doctrine bug is fixed. The initial audit did not change production ORM code. The [critical fix pass](critical-persistence-fixes.md) resolves four baseline problem families; the [first P1 mapping fix pass](p1-mapping-fixes.md) resolves four more in their linked regression scenarios. The code under test is core `ad789bc`; audit tooling is added in the working tree. Doctrine status is a dated snapshot, not a permanent property of an issue.

The complete paginated GitHub API snapshot contains **335 open non-PR issues**, including **60 with the Bug label**. Unlabeled reports were also reviewed. One additional issue has GitHub issue type Bug; it is included in the same inventory. Open PRs and closed issues are not part of this snapshot. Newest/oldest pagination and absence of duplicate issue numbers were checked.

- [Complete per-issue inventory, reasons, source URLs and evidence](evidence/doctrine-open-issues-2026-11-15.json)
- [Human-readable issue index](doctrine-open-issues-index.md)
- [PHP 8.4 diagnostic results](evidence/doctrine-issue-probes-php84-2026-11-15.json)
- [PHP 8.5 diagnostic results](evidence/doctrine-issue-probes-php85-2026-11-15.json)

## Results

**57 diagnostic scenarios ran on PostgreSQL 18.7 with PHP 8.4.27 and 8.5.12. All scenario outcomes agree across the two PHP versions.** There are 38 `probe-failed` observations and 19 `not-reproduced` observations per runtime. A failed probe is not automatically a distinct bug: several exercise an intentional contract, several overlap, and some encounter a different failure before reaching the original issue's mechanism.

**33 open issue references map to 24 reproduced problem families** in the supported/adapted scenarios below. At baseline these findings were unfixed. The critical fix pass resolves the first four P0 rows below (six upstream references). The first P1 mapping pass resolves four more families (five upstream references); sixteen baseline problem families still require work. Priorities are local audit recommendations, not upstream severity labels.

The existing PHPUnit suite still reports **953 tests / 6929 assertions / 21 skipped Redis tests** on each PHP version. The additional 57 checks are **not PHPUnit regression tests** and are outside that suite. A passing existing suite therefore does not invalidate these newly reproduced findings. Redis was not started, so this audit adds no new Redis concurrency certification.

| Classification | Issues |
| --- | ---: |
| `covered-related` | 12 |
| `reproduced` | 33 |
| `not-bug-report` | 95 |
| `not-applicable` | 146 |
| `probe-not-reproduced` | 27 |
| `related-failure` | 3 |
| `observed-contract` | 9 |
| `needs-upstream-reproducer` | 1 |
| `known-limitation` | 8 |
| `source-confirmed-risk` | 1 |

`not-applicable` means that the original execution path requires a removed/unsupported feature: DQL/AST walkers, entity inheritance, composite/association IDs, old proxy machinery, non-attribute drivers or another database. It does not prove shared lower-level code is defect-free. MappedSuperclass and ordinary PHP inheritance remain applicable.

`not-bug-report` covers feature, API-design, documentation and performance proposals without a concrete supported-path correctness oracle. `probe-not-reproduced` certifies only the named probe. `covered-related` links retained tests for an analogous mechanism; it is deliberately weaker than a complete issue reproduction. `observed-contract` records behavior that needs an explicit API decision rather than silently declaring it defective. `related-failure` means a probe encountered a different failure before the reported mechanism could be certified.

## Reproduced problem families

| Priority | Finding | Upstream reports | Diagnostic case |
| --- | --- | --- | --- |
| P0 | Local OneToOne replacement is overwritten while loading the old reference | [#8282](https://github.com/doctrine/orm/issues/8282), [#8436](https://github.com/doctrine/orm/issues/8436), [#5747](https://github.com/doctrine/orm/issues/5747) | `to_one_local_change_survives_old_reference_load` |
| P0 | EAGER re-fetch restores a locally nulled FK | [#8205](https://github.com/doctrine/orm/issues/8205) | `eager_to_one_null_survives_refetch` |
| P0 | Entity persisted in postFlush is lost before the next flush | [#5732](https://github.com/doctrine/orm/issues/5732) | `post_flush_persist_survives_next_flush` |
| P0 | Nested array hydration loses elements when a shared target appears in different row order | [#9185](https://github.com/doctrine/orm/issues/9185) | `nested_array_hydration_shared_target_row_order` |
| P1 | Discarded new orphan is still inserted after pre-flush collection replacement | [#8133](https://github.com/doctrine/orm/issues/8133) | `generated_pending_orphan_collection_replacement` |
| P1 | Cached metadata loses typedFieldMapper and namingStrategy for later mapping/overrides | [#12783](https://github.com/doctrine/orm/issues/12783), [#11957](https://github.com/doctrine/orm/issues/11957) | `metadata_association_override_after_cache` |
| P1 | Deleting a generated hooked ID tries to unset the hooked property | [#12077](https://github.com/doctrine/orm/issues/12077) | `hooked_generated_identifier_delete` |
| P1 | Unchanged readonly DateTime refresh throws on a separately converted object | [#9505](https://github.com/doctrine/orm/issues/9505) | `readonly_datetime_refresh` |
| P1 | Cascade refresh traverses an unpersisted child and throws | [#7169](https://github.com/doctrine/orm/issues/7169), [#10499](https://github.com/doctrine/orm/issues/10499) | `cascade_refresh_new_child` |
| P1 | Explicit sequence name on MappedSuperclass is discarded | [#12390](https://github.com/doctrine/orm/issues/12390) | `mapped_superclass_sequence_name` |
| P1 | Owning ManyToMany findBy criteria lack association context | [#3752](https://github.com/doctrine/orm/issues/3752), [#5770](https://github.com/doctrine/orm/issues/5770), [#6173](https://github.com/doctrine/orm/issues/6173), [#10527](https://github.com/doctrine/orm/issues/10527) | `many_to_many_repository_criteria` |
| P1 | Native result builder does not quote mixed-case columns | [#3927](https://github.com/doctrine/orm/issues/3927) | `native_result_mapping_quoted_select` |
| P1 | Custom-type SQL conversion is skipped by quoted INSERT | [#8191](https://github.com/doctrine/orm/issues/8191), [#7460](https://github.com/doctrine/orm/issues/7460) | `quoted_custom_type_sql_conversion` |
| P1 | AnsiQuoteStrategy returns field names for physical identifier columns | [#10014](https://github.com/doctrine/orm/issues/10014) | `ansi_quote_identifier_column_names` |
| P1 | Custom enum type receives an unwrapped backing value in criteria | [#10432](https://github.com/doctrine/orm/issues/10432) | `custom_enum_type_criteria` |
| P1 | Non-updatable generated field creates phantom update scheduling | [#12017](https://github.com/doctrine/orm/issues/12017) | `generated_field_local_change_tracking` |
| P1 | ManyToMany preUpdate old/new values are not collection history | [#4076](https://github.com/doctrine/orm/issues/4076) | `many_to_many_event_values_with_entity_change` |
| P2 | A singular InverseJoinColumn override raises TypeError | [#11390](https://github.com/doctrine/orm/issues/11390) | `single_inverse_join_column_override` |
| P2 | Invalid indexBy target passes validation | [#11608](https://github.com/doctrine/orm/issues/11608) | `invalid_index_by_validation` |
| P2 | Column/association attribute conflict silently drops the association | [#7513](https://github.com/doctrine/orm/issues/7513), [#5978](https://github.com/doctrine/orm/issues/5978) | `conflicting_column_association_attributes` |
| P2 | Embedded parent is reported by hasField but rejected by isNullable | [#7133](https://github.com/doctrine/orm/issues/7133) | `embedded_metadata_nullable` |
| P2 | Unregistered RSM alias produces an undefined-array-key warning | [#7243](https://github.com/doctrine/orm/issues/7243) | `rsm_unknown_alias_validation` |
| P2 | Custom indexBy key uses database encoding instead of domain representation | [#10152](https://github.com/doctrine/orm/issues/10152) | `custom_type_collection_index_domain_value` |
| P2 | Redundant FK index remains beside a covering compound index | [#6783](https://github.com/doctrine/orm/issues/6783) | `redundant_fk_index_with_covering_index` |

Upstream numbers describe related reports, not a one-to-one mapping between issues and independent defects. Several probes intentionally adapt the report to PostgreSQL or ModernORM's SQL/RSM APIs. The inventory spells out those differences. In particular, the custom type SQL failure is in the current INSERT path; it does not establish that the historical WHERE-path bug survives. Custom UUID/ULID/indexBy probes use a local type with a distinguishable database encoding, not an installed Symfony UID integration.

## Contracts, limitations and unresolved evidence

- **Locks:** SQL `FOR UPDATE` preserves an already managed entity through Identity Map; repeated `find(..., PESSIMISTIC_WRITE)` refreshes and overwrites a local edit. Reports #6650/#3689 and #12260 express conflicting expectations, so they are recorded as contracts rather than both being called bugs. Decide and document the supported lock/refresh policy before changing either path.
- **Immediate UNIQUE constraints:** replacement/swap and moving a OneToOne target to another existing owner can fail because INSERT/UPDATE precedes DELETE. Existing two-flush transaction/deferred-constraint workarounds remain relevant. No automatic commit-order rewrite was attempted.
- **Delete cycles:** a parent with a featured child and child-to-parent FKs fails delete topological sort despite nullable edges. Explicit unlinking or suitable ON DELETE behavior is required. The diagnostic traceback confirms the failure is in delete ordering, not initial insertion.
- **DDL:** FK replacement emits separate DROP/ADD statements. Apply the full plan in a PostgreSQL transaction; a SQL list is not a zero-downtime or single-statement guarantee. Explicit CREATE SQL also bypasses the introspection asset filter; bundle ownership validation is separate.
- **Identity Map:** a query can return an entity already scheduled for deletion; this preserves one object per identity. It is not an independent database snapshot.
- **Callbacks:** collection-only clear does not dispatch an owner entity preUpdate. The transaction decorator passes its wrapped manager to the callback. Both are recorded separately from confirmed corruption/error findings.
- **Different removal failure:** the lazy OneToOne orphan/cascade probes hit EntityNotFoundException while a postRemove callback reads an uninitialized lazy file after its database row has been deleted. This needs its own regression/fix; it is not evidence that the original wrong-callback-entity report #7806 or missing-orphan report #12352 has reproduced exactly.
- **Parameter construction:** #7389's mechanism remains visible in AbstractQuery: each setParameter scans existing parameters via getParameter, retaining quadratic cost across repeated additions. No fixed-host performance threshold is asserted.
- **No upstream reproduction:** #11785 explicitly supplies no minimal model/reproducer. Its unspecified historical refresh graph remains uncertified. The official #11956 reproduction was inspected from PR #11955; it requires JOINED inheritance, which ModernORM rejects. Generic nested postLoad callback graphs are not certified by that exclusion.

## Running the diagnostics

```sh
export PGHOST=127.0.0.1 PGPORT=5432 PGUSER=YOUR_USER PGDATABASE=modernorm_test
php tools/issue-audit/run.php > /tmp/modernorm-issue-probes.json
# Run one case without repeating the entire inventory:
php tools/issue-audit/run.php --case=metadata_mapping_after_cache
# Optional: exit 1 when any probe observation fails its oracle:
php tools/issue-audit/run.php --fail-on-findings
```

Use a dedicated test database. Every scenario creates a random private schema, creates its fixture tables/sequences and drops that schema in cleanup. Setup/connection failures exit 2; ordinary probe exceptions are recorded and the next case runs. The default exit status reports completion of the diagnostic run, not a green correctness suite. Several contract probes are expected to fail their proposed oracle in the current implementation.

Source responses were retrieved read-only from `GET /repos/doctrine/orm/issues?state=open&per_page=100` with full pagination. The inventory retains number/title/URL, labels/type, updated timestamp and a SHA-256 of the body. It does not republish full issue bodies or user/application data. To refresh, fetch all pages again and review applicability/reproductions; do not relabel a report as passing solely from its title, age, labels or a closed linked PR.

The [critical fix pass](critical-persistence-fixes.md) adds 24 PHPUnit scenarios and resolves the four P0 baseline families; the [first P1 mapping pass](p1-mapping-fixes.md) adds 13 more scenarios and resolves four further families. Before stable, handle the remaining high-priority families with focused regressions and use the diagnostic JSON to track which outcomes actually changed. Do not flip a matrix entry to fixed merely because the default suite is green.
