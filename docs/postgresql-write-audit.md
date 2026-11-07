# PostgreSQL write-path audit

The audit covers `BasicEntityPersister` writes. PostgreSQL-only storage does not remove PHP type conversion, optimistic locking, lifecycle events or UnitOfWork state tracking.

| Path | Decision | Reason |
| --- | --- | --- |
| Standard integer/bigint identity INSERT | Use `INSERT ... RETURNING` | Read the inserted row identifier directly, without relying on session sequence state |
| Empty column list | Emit `INSERT INTO ... DEFAULT VALUES` | PostgreSQL syntax needs no platform-specific identity-column fallback |
| Custom generators, including identity-generator subclasses | Preserve generator invocation | A custom post-insert generator can have its own behavior |
| Generated and version field refresh after INSERT/UPDATE | Keep SELECT | An AFTER trigger can perform a separate UPDATE of the row; RETURNING is not equivalent to reading the row after the statement completes |
| Identifier criteria for generated-field refresh | One mapped scalar column and one parameter | Composite and association entity identifiers are excluded by ModernORM metadata validation; backed enum identifiers are normalized to their backing value |
| UPDATE type conversion and version predicate/increment | Keep | PostgreSQL strict typing does not replace PHP/DBAL conversions or optimistic concurrency checks |
| DELETE and join-table cleanup | Keep | Association ownership and FK cascade semantics still determine which statements must run |
| Quote strategy and DBAL platform conversion hooks | Keep | Quoted identifiers and custom types still require these hooks on PostgreSQL |

`getInsertSQL()` continues to provide the base INSERT SQL; standard identity execution appends the quoted identifier RETURNING clause. Returned cursors are freed before the next statement. The existing `assignPostInsertId()` path still converts the identifier, updates the entity snapshot and registers it in the Identity Map before lifecycle callbacks. An identity INSERT returning no row throws and follows the existing failed-flush rollback/manager-close contract.

## Reproduced identity bugs

The same five initial regression tests were run against the previous persister and the changed implementation. Two tests fail before the change:

- An AFTER INSERT trigger advances another sequence starting at 9000. Previously the entity receives 9000 even though its actual primary key is 1. RETURNING identifies the inserted row correctly, including across repeated inserts and later updates.
- A BEFORE INSERT trigger returns NULL and suppresses a row after an earlier successful insert. Previously flush succeeds and assigns the suppressed entity an identifier despite no corresponding row. RETURNING produces no row, so flush now fails and closes the manager instead of registering a nonexistent row.

The inherited mechanism is visible in Doctrine ORM 3.7.6's [BasicEntityPersister](https://github.com/doctrine/orm/blob/3.7.6/src/Persisters/Entity/BasicEntityPersister.php) and [IdentityGenerator](https://github.com/doctrine/orm/blob/3.7.6/src/Id/IdentityGenerator.php). This records provenance, not the status of newer Doctrine versions.

`tests/Integration/PostgreSqlWriteTest.php` also verifies empty-column INSERT, bigint identifiers above 32-bit range, quoted identifier columns, custom identity-generator subclasses, generated fields updated by AFTER triggers, version refresh and assigned enum identifiers. Existing lifecycle, FK-ordering, optimistic-locking, cache and DML statistics tests remain part of the full regression suite. Trigger-internal writes are not counted as separate client DML statements.

PostgreSQL documents [RETURNING](https://www.postgresql.org/docs/17/dml-returning.html) and [sequence state](https://www.postgresql.org/docs/17/functions-sequence.html). The separate AFTER-trigger UPDATE boundary above is covered by the integration test, rather than inferred from the general description of trigger-modified RETURNING values.

This change does not replace generated-field refresh with RETURNING, remove custom type hooks, change generator preferences, batch identity inserts or alter UPDATE/DELETE lifecycle ordering.

Validation of the final revision: **687 tests pass on PHP 8.5.12 and PHP 8.4.27**, with PostgreSQL and disposable Redis, without skipped tests. PHPStan and PSR-12 checks pass without new baseline suppressions. The six focused PostgreSQL write tests pass with 23 assertions.

## Remaining platform-code cleanup

A follow-up audit of ID generators, SchemaTool, result mapping and SQL builders found few remaining multi-database paths. Removed:

- The internal `SQLResultCasing` trait, including Oracle/DB2 handling, generic fallback and obsolete DBAL 3 compatibility. Quote strategies and native result mapping now fold unquoted aliases with `strtolower`, matching PostgreSQL. The unused trait import in SimpleObjectHydrator is also gone.
- `supportsSequences()` around schema-drop sequence handling: PostgreSQL supports sequences. The existing sequence discovery, missing-asset filtering and drop ordering are preserved.
- Table-level `appendLockHint()` calls in entity SELECT and lock SQL: PostgreSQL uses the existing trailing `FOR SHARE` / `FOR UPDATE` clauses, not SQL Server table hints. Limit/offset handling stays in DBAL.
- Unused identity-generator setup variables and outdated non-PostgreSQL examples/comments.

Preserved intentionally:

- AUTO generator preferences, explicit sequence generation/allocation, custom generators and replica-to-primary routing before sequence allocation.
- DBAL platform hooks for quoting, type conversion, schema SQL and limits. A platform abstraction is not by itself an unsupported-database branch.
- Compatibility branches for the supported DBAL versions, including the schema editor API and primary-key introspection. DBAL version compatibility is separate from multi-database support.
- EntityManager's PostgreSQL platform guard and BulkExecutor's independent guard for EntityManagerInterface implementations.

Three added PostgreSQL tests verify native hydration with mixed-case result aliases, AnsiQuoteStrategy hydration, and sequence allocation followed by repeated schema drop with absent assets. Together with the existing PostgreSQL write and locking tests, the same 19 tests pass before and after this cleanup. This preserves behavior rather than introducing new mapping or lock semantics.

Further removal of platform abstractions would require changing supported customizations or duplicating DBAL functionality. The next useful stabilization boundary is flush queue state and cleanup in UnitOfWork, rather than continuing platform deletion for its own sake.

Cleanup validation: **690 tests pass on PHP 8.5.12 and PHP 8.4.27**, with PostgreSQL and disposable Redis, without skipped tests. PHPStan, PSR-12 and diff whitespace checks pass. The source-only diff removes 98 lines and adds 37 (net reduction: 61 lines).
