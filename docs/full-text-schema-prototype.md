# Full-text SchemaTool prototype

The executable [prototype](../tools/full-text-schema-prototype.php) checks the schema feasibility of a future full-text mapping attribute using `configuration: 'pg_catalog.russian'`. It composes existing SchemaTool metadata/schema generation with a prototype PostgreSQL catalog comparison. It does not add a public mapping attribute or QueryBuilder API.

## Run

Install development dependencies and use a dedicated PostgreSQL 17+ test database:

```sh
PGUSER=your_role PGDATABASE=modernorm_test php tools/full-text-schema-prototype.php
```

The script creates a random schema inside one transaction, prints a JSON report and rolls back all changes in `finally`. No existing schema is dropped or migrated. An SQL failure propagates after rollback. The native DBAL DROP statements in the report are diagnostics and are never executed.

## Verified behavior

- Create the ordinary entity table through SchemaTool, then add an unmapped STORED tsvector column and GIN index. Fields map from PHP property names to physical column names, including a quoted mixed-case name.
- Compose weighted vectors using the explicit regconfig, `coalesce` and text casts. A null description remains searchable by the name field.
- Obtain an empty repeat diff with the prototype catalog adapter.
- Change name/description weights from A/B to B/A and obtain one `ALTER TABLE ... ALTER COLUMN ... SET EXPRESSION AS (...)` statement. Apply it and obtain another empty diff.
- Verify existing rows are recomputed: the same Russian query changes ranking from IDs `[1, 2]` to `[2, 1]`, with ID as a stable secondary order. The GIN index remains present.
- Change an ordinary mapped column default through metadata. The ordinary DBAL comparison still produces the appropriate SET DEFAULT and converges after application.
- Remove the GIN index and detect one CREATE INDEX. Remove the technical column and detect ADD COLUMN plus CREATE INDEX. Both repairs converge to an empty diff and preserve the selected weights.

## DBAL finding

On DBAL 4.5.0, PostgreSQL tsvector introspects as TextType. The introspected column has no columnDefinition representing its generated expression; the index exposes neither a GIN flag nor its access method as a schema option. These distinctions therefore cannot be recovered from the ordinary table model alone.

The future technical column is deliberately absent from entity metadata. Comparing the unmodified SchemaTool target with that database table currently yields:

```sql
DROP INDEX prototype_schema.idx_product_search;
ALTER TABLE prototype_schema.products DROP search_vector;
```

The prototype removes exactly its known technical column/index from an in-memory copy of the introspected table before ordinary DBAL comparison. It compares their real definitions separately using `pg_attribute`, `pg_attrdef`, `pg_index`, `pg_class` and `pg_am`. An unexpected ordinary column under the technical-column name, invalid GIN or unexpected index shape is rejected rather than silently replaced.

This proves the composition path, not native full-text support in the current public SchemaTool. Production integration must carry ownership through the schema/migration pipeline; simply appending CREATE INDEX to the current SchemaTool is insufficient.

## Remaining production decisions

The prototype obtains PostgreSQL's canonical form of the expected expression by creating an empty temporary LIKE table and generated column. This avoids brittle whitespace/cast/configuration-name string rewriting, but makes diff generation perform temporary DDL. A public schema-diff API must not acquire those side effects implicitly. A production representation/comparison strategy still needs to be selected and tested.

The prototype handles one index/technical column, a fixed table and text scalar fields. It does not implement attribute validation, serialization/cache invalidation, index/column renames, removal ownership, configuration changes, arbitrary schema evolution, dependency analysis or concurrent index migrations. It leaves UnitOfWork untouched.

Changing a stored generation expression rewrites stored data. This is a feasibility migration on two test rows, not an online migration strategy or performance benchmark. PostgreSQL documents [stored generated search columns](https://www.postgresql.org/docs/17/textsearch-tables.html), [GIN for text search](https://www.postgresql.org/docs/17/textsearch-indexes.html) and [ALTER TABLE SET EXPRESSION](https://www.postgresql.org/docs/17/sql-altertable.html).

## Evidence

All checks pass on DBAL 4.5.0 across four combinations:

| PostgreSQL | PHP | Result |
| --- | --- | --- |
| 17.11 | 8.4.27 | Passed |
| 17.11 | 8.5.12 | Passed |
| 18.6 | 8.4.27 | Passed |
| 18.6 | 8.5.12 | Passed |

PostgreSQL 17 was a disposable local cluster stopped and removed after execution. Schema names are normalized to `prototype_schema` in reports. Raw SQL and outcomes are recorded for [PostgreSQL 17](evidence/full-text-schema-prototype-pg17.json) and [PostgreSQL 18](evidence/full-text-schema-prototype-pg18.json). Separate PHPStan level 5 analysis of the prototype and the repository PHP-CS-Fixer checks pass. No production runtime files are changed.
