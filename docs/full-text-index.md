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
$plan = $tool->getSchemaMigrationSql($classes); // ['up' => [...], 'down' => [...]]
```

`getSchemaMigrationSql()` combines ordinary schema changes and FTS SQL in reversible `up`/`down` lists. It supports new tables, adding or repairing technical assets, and changes to weights or search configuration. Rollback restores the previous expression with schema-qualified configuration names, independent of the execution connection’s `search_path`. Pass `fromEmptySchema: true` to generate a complete initial migration without comparing existing tables. Existing unrelated namespaces are preserved. Removing an FTS-bearing table, renaming/removing the attribute, or changing source columns/types requires an explicit migration.

Creation emits the ordinary table, generated column, GIN index and ownership comments. Include **all** statements in migrations and preserve comments in database dumps. Direct `createSchema()`, `updateSchema()` and `dropSchema()` use the same pipeline.

Comparison reads PostgreSQL catalogs and parses the limited expression grammar emitted by this feature. Configuration aliases are compared by configuration OID, identifiers retain their quoting semantics, and PostgreSQL's grouping and text-cast formatting are handled structurally. Diff generation performs no temporary DDL and works in a read-only transaction.

Owned technical assets are checked against the real catalog definitions before being excluded from ordinary DBAL comparison. Repeat comparison is empty; unrelated mapped column changes remain visible. Missing vectors and indexes are recreated. Existing ordinary columns, unowned vectors, partial/expression indexes, invalid indexes and non-GIN indexes under the requested names are rejected.

DBAL's ordinary `Schema` model cannot represent these generated expressions and access methods completely. `getSchemaFromMetadata()` therefore rejects entities with FullTextIndex instead of returning an incomplete migration target. External migration tools must consume the SchemaTool SQL APIs; an independent native DBAL schema diff is not supported for these entities. Schema-generation events continue to operate on ordinary mapped assets; technical names may not collide with listener-generated assets.

## Searching and ranking

Use the query builder helpers after registering the entity alias. They resolve the vector column and configuration from `FullTextIndex` and bind the input automatically:

```php
$builder = $em->createQueryBuilder()->fromEntity(Product::class, 'p')
    ->andWhereFullText('p', $userInput)
    ->addOrderByFullTextRank('p')
    ->addOrderBy('p.id')
    ->setMaxResults(20);
$products = $builder->getQuery()->getResult();
```

`andWhereFullText()` appends an AND predicate, preserving existing filters and SoftDelete visibility. Call `where()` before it, and use `andWhere()` for further filters; DBAL's `where()` replaces prior predicates. The helper uses `websearch_to_tsquery`: unquoted words are combined with AND, double quotes search a phrase, `OR` introduces alternatives, and `-` excludes a term. Empty/whitespace-only input is rejected. Stopword-only or punctuation-only queries can produce no matches. The language always comes from the mapping, independently of `default_text_search_config`.

`addOrderByFullTextRank()` appends `ts_rank` ordering with `DESC` by default; pass `direction: 'ASC'` for ascending scores. Add it before secondary ordering and include a unique tie-breaker such as the primary key when paging. Ranking uses the vector's mapped A/B/C/D labels and PostgreSQL's default rank weights and normalization.

For a catalog showing relevance scores, add a scalar projection:

```php
$rows = $em->createQueryBuilder()->fromEntity(Product::class, 'p')
    ->andWhereFullText('p', $userInput)
    ->addFullTextRank('p', resultAlias: 'relevance')
    ->addOrderByFullTextRank('p')->addOrderBy('p.id')
    ->getQuery()->getResult();
foreach ($rows as $row) {
    $product = $row[0];          // Managed Product, with the usual Identity Map.
    $score = $row['relevance'];  // float; no score property is added to the entity.
}
```

The score alias defaults to `rank`. Adding it switches object hydration to the existing mixed entity/scalar result format. Scalar aliases must be distinct identifiers beginning with a letter. Ordering alone retains the ordinary entity result shape.

When an entity has several full-text indexes, specify the attribute's **index name** on every helper; no index is selected implicitly:

```php
$builder->andWhereFullText('p', $userInput, index: 'product_search')
    ->addFullTextRank('p', index: 'product_search')
    ->addOrderByFullTextRank('p', index: 'product_search');
```

Both ranking helpers reuse the parameters of an earlier `andWhereFullText()` on the same alias/index. Each alias/index accepts one search per builder. Clone an unsearched base builder for alternative queries. Different indexes and explicitly mapped joined aliases can be searched independently; their predicates are combined with AND. Builder clones and `getQuery()` snapshots retain independent bindings and scalar mappings. Internal `__orm_fts_*` parameter names are reserved for the helpers; existing bindings with these names are skipped during allocation.

Stored vectors follow database INSERT/UPDATE automatically, including writes outside the ORM. Direct SQL remains available for custom ranking, OR composition across vectors, highlighting or other PostgreSQL search functions. See PostgreSQL's documentation for [stored search vectors](https://www.postgresql.org/docs/17/textsearch-tables.html) and [query syntax and ranking](https://www.postgresql.org/docs/17/textsearch-controls.html).

## Supported changes and explicit migrations

Changing weights, concatenation order or configuration generates `ALTER COLUMN ... SET EXPRESSION AS (...)`. PostgreSQL recomputes stored vectors for existing rows and retains the GIN index. This may rewrite data and acquire locks; the generated SQL is a regular migration, not an online migration strategy. Index creation is not concurrent. See [ALTER TABLE](https://www.postgresql.org/docs/17/sql-altertable.html).

Removing or renaming an attribute, changing source columns or source column types, and additional indexes on the technical column require an explicit migration. The first version supports ordinary tables, not partitioned tables. It does not create dictionaries/configurations or track changes inside them.

For a source-set change or removal, explicitly drop the old vector (its index is dropped with it), apply the mapping/source-column changes, and generate the replacement schema SQL if needed. Do not simply erase ownership comments to bypass validation. Removing an attribute while its owned column remains is rejected before SQL execution. Dropping the whole mapped table is supported.

Metadata uses cache salt V13 (including the later metadata/sequence restoration fixes). Clear/version deployment query caches and restart workers when changing mapping definitions. The former [schema prototype](full-text-schema-prototype.md) is retained as historical feasibility evidence; the production implementation does not use its temporary-table canonicalization.
