# Mapping scope

ModernORM uses PHP attributes and one independent metadata root per concrete entity. Entity inheritance and discriminator-based polymorphic hydration are unsupported. These are intentional differences from Doctrine ORM, not compatibility switches.

## Identifiers and explicit queries

Each entity has one scalar field PK: integer, string or UUID through the appropriate DBAL type. BackedEnum identifiers normalize to their scalar value. Composite entity IDs and association-as-ID are rejected, including metadata restored from caches or changed by listeners. Composite UNIQUE constraints and many-to-many join-table primary keys remain supported.

Use a surrogate scalar PK and a multi-column UNIQUE constraint when replacing a natural composite entity key. Associations reference the entity PK. `find()` and `getReference()` accept a scalar or an array containing the single named identifier; an entity object is not an identifier. Assigned, IDENTITY, SEQUENCE and custom generation remain available within the tested scope.

`findBy()` and `count()` accept array conditions for equality, IN and NULL. Use SQL QueryBuilder for complex predicates. Criteria visitors, repository matching, LazyCriteriaCollection and ORM Filters are removed. `PersistentCollection::matching()` retains the inherited interface signature but rejects the operation without initializing the collection. Only LAZY/EAGER fetch modes are supported. EXTRA_LAZY is removed; use [Association Query API](association-query-api.md) for database queries without loading collections.

Applications supply tenant/access predicates for every read/write path; automatic tenant isolation is not provided. Proxy generation, proxy directories/namespaces, alternate lazy-object switches and optional ID-collision compatibility switches are removed. PHP native lazy objects and Identity Map collision checks are always used.

Managed SQL entity results require complete mapped fields and owning to-one FK columns. Partial managed entities are removed; use rows/scalars/DTO projections for subsets. Native lazy references remain supported. See [migration](partial-entity-removal.md). Array hydration is flat-only: one entity-style projection with its identifier, or scalar-only mappings. Nested array graphs are [removed](array-graph-removal.md).

Association cascades support persist/remove only; all expands to those two operations. Cascade refresh/detach are removed and rejected. Explicit refresh/detach act on one entity; clear discards the whole context. See [migration](cascade-refresh-detach-removal.md).

## Attributes only

`Configuration::setMetadataDriverImpl()` accepts ModernORM AttributeDriver and its subclasses. It supports multiple paths or a ClassLocator. Generic drivers and MappingDriverChain are rejected.

```php
use ModernORM\Configuration;
use ModernORM\Mapping\Driver\AttributeDriver;

$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([
    __DIR__.'/src/Entity',
    __DIR__.'/src/ReferenceData',
]));
```

`ORMSetup::createAttributeMetadataConfig()` with an explicit PSR-6 pool is another entry point. XML/SimplifiedXml/Database reverse-engineering drivers and XML setup factories are removed; YAML and docblock annotations are unsupported. Low-level metadata helpers and loadClassMetadata listeners may adapt attributes but cannot re-enable unsupported mappings.

Import `use ModernORM\Mapping as ORM`; Doctrine mapping aliases are not provided. Legacy ModernORM InheritanceType/Discriminator attributes and diagnostic metadata fields exist only to report unsupported/old payloads clearly.

## Inheritance boundary

SINGLE_TABLE/JOINED persisters, discriminator dispatch, subclass/table projection joins, polymorphic hydration and fluent inheritance helpers are removed. InheritanceType, DiscriminatorColumn and DiscriminatorMap raise MappingException. An Entity extending another Entity is rejected even without these attributes. Replace an abstract Entity with MappedSuperclass or concrete composition.

Validation runs when reading attributes, after metadata listeners and when restoring cached metadata. Inheritance metadata setters and discriminator ResultSetMapping are also rejected. Runtime hydration, Identity Map, identifier generation and persister paths no longer implement composite/association identifiers, recursive identifier references or subclass loading. Concrete class names identify entities directly. Legacy metadata flags and discriminator fields remain only for explicit rejection, cache restoration and diagnostic tools; they do not enable an alternate persistence mode.

Composite UNIQUE constraints and multi-column join-table primary keys are independent of composite entity IDs. Their column loops and schema generation remain supported; regression checks include a string owner ID stored under a non-default column name. MappedSuperclass/ordinary PHP inheritance, enum IDs, native lazy loading, batching and explicit fetch joins continue to use the scalar-ID paths.

MappedSuperclass and ordinary PHP inheritance from a non-Entity class remain supported:

```php
use ModernORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class IdentifiedRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Version, ORM\Column]
    public int $version;
}

#[ORM\Entity, ORM\Table(name: 'documents')]
final class Document extends IdentifiedRecord
{
    #[ORM\Column]
    public string $title = '';
}
```

A MappedSuperclass shares fields, associations and callbacks but has no table and cannot be a query root. Its concrete subclasses have separate tables, identifiers and Identity Maps. A class cannot be both Entity and MappedSuperclass. Inherited integer version types, identifiers, associations/callbacks, native lazy loading and SQL joins are covered by the retained regression suite.

## PostgreSQL CHECK constraints

Use repeatable `#[ORM\Check(name: 'ordered_dates', expression: 'end_date >= start_date')]` attributes on a concrete entity. Predicates use physical SQL column names and can refer to multiple columns of the same row. PostgreSQL accepts both `TRUE` and `UNKNOWN` as passing results. Declare columns `NOT NULL` separately when required. Supply predicates only from trusted application code.

`SchemaTool` adds missing declared checks after ordinary table DDL. PostgreSQL validates existing rows on addition. Removing a declaration leaves its database constraint in place. Use an explicit migration to change or remove a constraint.

Schema diff reads CHECK definitions from PostgreSQL catalogs. New constraints record the source and catalog fingerprints in their comments during migration apply. Existing checks without fingerprints use conservative comparison with PostgreSQL’s deparsed SQL. Ambiguous comparisons require an explicit migration. Qualify custom functions and types in predicates to keep their resolution stable across sessions.

## PostgreSQL row triggers

```php
#[ORM\Trigger(
    name: 'trg_order_audit',
    timing: 'AFTER',
    events: ['INSERT', 'UPDATE', 'DELETE'],
    functionSql: 'database/functions/fn_order_audit.sql',
)]
```

Omit `function` to derive `fn_order_audit` from the `trg_` prefix. An explicit `function` accepts a name in the entity table's schema or a qualified name. Declare `BEFORE` or `AFTER` and one or more row-level events from `INSERT`, `UPDATE` and `DELETE`.

Omit `functionSql` when an earlier migration provides the function. Otherwise, set the SQL root with `Configuration::setTriggerFunctionSqlRoot()`. ModernORMBundle sets it to Symfony's project directory. Paths are resolved inside the root. A source file contains one schema-qualified `CREATE OR REPLACE FUNCTION ...() RETURNS trigger LANGUAGE plpgsql AS $tag$...$tag$;` statement. The file's function name must match the resolved attribute target. Use trusted application SQL.

Generated migrations embed the source and its SHA-256. The `modernorm_trigger_functions` table in each function schema records applied hashes. PostgreSQL commits the function definition and hash in the same transaction. Changes to the source update the function without recreating its triggers. Functions provided by existing external migrations remain externally managed. Migrations that change a managed function require an explicit rollback migration. Removing a declaration leaves the database object in place.

## PostgreSQL partial indexes, exclusion constraints and STORED columns

`#[ORM\Index(name: 'active_order_number', columns: ['order_number'], where: 'deleted_at IS NULL', unique: true)]` creates a partial unique index. Use physical column names in predicates and `fields` for mapped PHP properties in the index key. Predicates are trusted SQL. Existing `options['where']` mappings are supported.

`#[ORM\Exclude(name: 'no_overlap', elements: ['resource_id WITH =', 'period WITH &&'], where: 'confirmed')]` creates a GiST exclusion constraint. Provision any required range types and operator classes before applying the migration. For scalar equality operators that require `btree_gist`, install it in a prerequisite migration with `CREATE EXTENSION IF NOT EXISTS btree_gist`.

`#[ORM\Column(type: 'decimal', precision: 24, scale: 5, generated: 'quantity * unit_price')]` declares a PostgreSQL STORED expression. The reserved values `NEVER`, `INSERT` and `ALWAYS` retain their existing fetch-mode meaning. The ORM ignores direct property assignments to STORED fields when building its write set, fetches database-generated values after writes, and allows bulk `RETURNING` to read them. The bulk write API rejects assigning these fields.

Schema diff reads PostgreSQL catalogs in read-only transactions. On creation, the migration records fingerprints of the source declaration and PostgreSQL’s stored definition in the object comment. This keeps the next diff stable even when PostgreSQL emits implicit casts. For pre-existing objects without fingerprints, the comparison accepts matching deparsed SQL with redundant outer parentheses removed. Use a hand-written migration for changes to a declared partial predicate, exclusion definition or STORED expression, and for changes between regular and generated columns. Existing undeclared partial indexes, exclusion constraints and generated columns are preserved during ordinary diff. Keep the generated DDL and its fingerprint statement together. When changing the definition in a hand-written migration, remove the old fingerprint and verify the new definition by diff. If PostgreSQL rewrites catalog definitions during restore or upgrade, an existing fingerprint may require explicit reconciliation.

## Cache formats and migration

Current internal salts are `__MODERNORM_TYPED_MAPPING_V18__` for metadata and `sql_entity_v4_` for entity projection plans. They reject old internal formats; they are not an automatic mapping fingerprint. Applications must version metadata/query cache namespaces and create new EntityManagers when their own mappings change.

Move XML/programmatic sources to attributes. Removing inheritance attributes alone does not migrate an inheritance schema: choose concrete models/composition and explicitly migrate their tables/data. A discriminator column may remain as an ordinary business field, but no automatic PHP subclass dispatch occurs. Separate entities are not a polymorphic Identity Map substitute.

[Mapping transformation manifest](../tools/mapping-simplification.json) and [persistence simplification manifest](../tools/persistence-simplification.json) record the historical changes. [Provenance](../tools/upstream.json) preserves source hashes; MIT notices remain in the distribution.
