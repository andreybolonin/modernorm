# Full-text indexes

`#[FullTextIndex]` declares a PostgreSQL STORED generated `tsvector` column and a GIN index. It requires PostgreSQL 17+ and DBAL 4.6+. The technical column is absent from entity fields, hydration and UnitOfWork writes.

```php
use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\FullTextIndex(
    name: 'product_search',
    fields: ['name' => 'A', 'description' => 'B'],
    configuration: 'pg_catalog.russian',
    column: 'search_vector',
)]
class Product
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(type: 'text')]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;
}
```

`fields` contains PHP field names in vector concatenation order, with weights `A`, `B`, `C` or `D`. Sources must map `string` or `text` scalar fields, without enums or custom `columnDefinition`. Physical column names and mapped quoting are respected. Each field is converted with an explicit configuration and `COALESCE(column::text, '')`, then weighted; nullable fields do not discard the rest of the document.

The configuration must be schema-qualified, such as `pg_catalog.russian` or `public.product_dictionary`. It must already exist in PostgreSQL. Names use simple ASCII identifiers of at most 63 bytes. Technical column and index names are emitted as quoted identifiers, preserving case. `column` defaults to `search_vector`. The attribute is repeatable; each vector needs a distinct column, and index names must be unique within their database schema. Apply it to concrete entities, including entities whose fields come from a mapped superclass.

## Creating and comparing schemas

Use the complete SchemaTool SQL APIs for schema migration generation:

```php
$tool = new ModernORM\Tools\SchemaTool($em);
$classes = [$em->getClassMetadata(Product::class)];
$create = $tool->getCreateSchemaSql($classes);
$update = $tool->getUpdateSchemaSql($classes);
```

Creation emits the ordinary table, generated column, GIN index and ownership comments. Include **all** statements in migrations and preserve comments in database dumps. Direct `createSchema()`, `updateSchema()` and `dropSchema()` use the same pipeline.

Comparison reads PostgreSQL catalogs and parses the limited expression grammar emitted by this feature. Configuration aliases are compared by configuration OID, identifiers retain their quoting semantics, and PostgreSQL's grouping and text-cast formatting are handled structurally. Diff generation performs no temporary DDL and works in a read-only transaction.

Owned technical assets are checked against the real catalog definitions before being excluded from ordinary DBAL comparison. Repeat comparison is empty; unrelated mapped column changes remain visible. Missing vectors and indexes are recreated. Existing ordinary columns, unowned vectors, partial/expression indexes, invalid indexes and non-GIN indexes under the requested names are rejected.

DBAL's ordinary `Schema` model cannot represent these generated expressions and access methods completely. `getSchemaFromMetadata()` therefore rejects entities with FullTextIndex instead of returning an incomplete migration target. External migration tools must consume the SchemaTool SQL APIs; an independent native DBAL schema diff is not supported for these entities. Schema-generation events continue to operate on ordinary mapped assets; technical names may not collide with listener-generated assets.

## Searching and ranking

Use the existing SQL query API. Match the configuration used by the index and bind user input:

```php
$query = $em->createQueryBuilder()->fromEntity(Product::class, 'p')
    ->where('p.search_vector @@ websearch_to_tsquery(:config::regconfig, :search)')
    ->orderBy('ts_rank(p.search_vector, websearch_to_tsquery(:config::regconfig, :search))', 'DESC')
    ->addOrderBy('p.id')
    ->setParameter('config', 'pg_catalog.russian')
    ->setParameter('search', $userInput)
    ->getQuery();
$products = $query->getResult();
```

Stored vectors follow database INSERT/UPDATE automatically, including writes outside the ORM. No search methods, score properties or query-language extensions are added. See PostgreSQL's documentation for [stored search vectors](https://www.postgresql.org/docs/17/textsearch-tables.html) and [ranking](https://www.postgresql.org/docs/17/textsearch-controls.html).

## Supported changes and explicit migrations

Changing weights, concatenation order or configuration generates `ALTER COLUMN ... SET EXPRESSION AS (...)`. PostgreSQL recomputes stored vectors for existing rows and retains the GIN index. This may rewrite data and acquire locks; the generated SQL is a regular migration, not an online migration strategy. Index creation is not concurrent. See [ALTER TABLE](https://www.postgresql.org/docs/17/sql-altertable.html).

Removing or renaming an attribute, changing source columns or source column types, and additional indexes on the technical column require an explicit migration. The first version supports ordinary tables, not partitioned tables. It does not create dictionaries/configurations or track changes inside them.

For a source-set change or removal, explicitly drop the old vector (its index is dropped with it), apply the mapping/source-column changes, and generate the replacement schema SQL if needed. Do not simply erase ownership comments to bypass validation. Removing an attribute while its owned column remains is rejected before SQL execution. Dropping the whole mapped table is supported.

Metadata uses cache salt V12. Clear/version deployment query caches and restart workers when changing mapping definitions. The former [schema prototype](full-text-schema-prototype.md) is retained as historical feasibility evidence; the production implementation does not use its temporary-table canonicalization.
