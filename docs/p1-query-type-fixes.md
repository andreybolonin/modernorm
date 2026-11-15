# P1 query and type fixes — 2026-11-15

This pass resolves three reproduced audit families in bounded PostgreSQL scenarios. It covers seven upstream references: #3752, #5770, #6173, #10527, #8191, #7460 and #10432. Historical inventory classifications remain unchanged; follow-up fields record the fixes.

- **ManyToMany repository criteria:** correlated EXISTS supports owning, inverse, unidirectional and self-referencing associations, including quoted identifiers and schemas. Criteria accept identifiers, entity references and arrays. Arrays mean any matching member; multiple fields are combined with AND. An empty array matches nothing, null means no relation, and `[null, id]` accepts either an empty relation or that member. EXISTS preserves distinct root results for count, ordering and limit/offset. This follows persisted join-table membership without initializing collections or flushing pending changes. Single-column identifiers remain required.
- **Quoted INSERT:** resolve mapped types through the same quote strategy as the INSERT column list. Both explicitly quoted columns and custom quote strategies now retain `convertToDatabaseValueSQL()`. The converter comes from the connection's type provider. The reproduced defect concerns INSERT; this does not claim reproduction of the historical upstream WHERE variant.
- **Custom enum criteria:** preserve the enum object for custom value converters when the mapped PHP property admits domain objects. Native enumType mappings, scalar PHP properties and inherited DBAL scalar converters retain backing-value coercion. Scalar and array repository criteria use the same rule, including arrays containing null. This change does not extend arbitrary explicit Query parameter conversion.

`tests/Integration/QueryTypeRegressionTest.php` adds six executable scenarios with 34 assertions, including count, findOneBy, pagination, multiple association roles, SQL conversion on INSERT/read/UPDATE and native enum compatibility. All six failed before the fixes.

Validation on PostgreSQL 18.7:

- PHP 8.4.27 and PHP 8.5.12: full suite **1035 tests / 7463 assertions / 21 Redis skips** per runtime.
- PHPStan includes the new regression file and passes.
- All three targeted diagnostic cases are `not-reproduced` on both runtimes: [PHP 8.4 evidence](evidence/p1-query-type-probes-php84-2026-11-15.json), [PHP 8.5 evidence](evidence/p1-query-type-probes-php85-2026-11-15.json).

The full suites run sequentially in a dedicated test database. Redis was not started. These fixes leave thirteen baseline families unresolved; they do not certify all variants of the upstream reports.
