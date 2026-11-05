# SQL query API

ModernORM uses SQL and explicit hydration mappings. DQL, lexer/parser/AST walkers, DQL executors/expressions/functions, run-dql and AST-based paginators are removed. Mapping, UnitOfWork, persisters, native lazy objects and hydrators remain; query compatibility with Doctrine ORM is not implied.

## Entity SELECT

```php
$query = $em->createQueryBuilder()
    ->fromEntity(Project::class, 'p')
    ->where('p.name = :name')->setParameter('name', 'alpha')
    ->orderBy('p.id')->setMaxResults(20)->getQuery();
$projects = $query->getResult();
```

QueryBuilder extends DBAL's SQL builder. `where`, `orderBy`, `groupBy`, `join`, `set` and other expressions use **SQL column names**, not PHP properties or association paths. There is no automatic conversion of `p.owner` to a foreign key or `p.address.city` to an embedded column. Resolve a mapped property explicitly when needed:

```php
$builder = $em->createQueryBuilder()->fromEntity(Contact::class, 'c');
$builder->where($builder->column('c', 'address.city').' = :city')
    ->setParameter('city', 'Kazan');
```

`fromEntity()` constructs a complete field/FK projection, SQL and ResultSetMapping for a concrete entity. MappedSuperclass fields belong to its concrete entity table; the superclass is not a query root. Replacing the projection with `select()` after `fromEntity()` is rejected to prevent accidental managed partial entities. Use a separate SQL builder for projections.

## Rows and scalars

```php
$rows = $em->createQueryBuilder()
    ->select('p.id', 'p.name')->from('projects', 'p')
    ->where('p.name = :name')->setParameter('name', 'alpha')
    ->executeQuery()->fetchAllAssociative();

$total = $em->createQueryBuilder()->from('tasks', 't')
    ->addScalar('COUNT(*)', 'total', 'total', 'integer')
    ->getQuery()->getSingleScalarResult();
```

Rows do not enter UnitOfWork. `addScalar()` adds an SQL expression and explicit scalar mapping; scalars can also accompany entity projections for mixed hydration. Arbitrary SQL can use `createQuery($sql, $rsm)` or `createNativeQuery($sql, $rsm)`. Both require an explicit ResultSetMapping. Query is a mapped SQL SELECT, not a parser.

Bind values through `setParameter()`. SQL structure/identifiers are trusted application code; QueryBuilder does not make arbitrary fragments safe. Positional placeholders are `?` with zero-based bind indexes. Use the appropriate DBAL ArrayParameterType for IN arrays.

## Association fetching

```php
$builder = $em->createQueryBuilder()->fromEntity(Project::class, 'p')
    ->leftJoin('p', 'tasks', 't', 't.project_id = p.id')
    ->addJoinedEntity(Task::class, 't', 'p', 'tasks')
    ->orderBy('p.id')->addOrderBy('t.title');
$projects = $builder->getQuery()->getResult();
```

The SQL JOIN is explicit. `addJoinedEntity()` maps hydration for an already joined table and checks the association target. A SQL join alone is not a fetch mapping. All entity results must be concrete; discriminator RSM mappings are rejected.

A to-many fetch mapping denotes a **complete collection**. Applications must select every member; a filtered join must not be presented as a complete collection. Empty collections and root deduplication are covered. Queries preserve Identity Map state and do not overwrite existing managed fields without HINT_REFRESH/refresh().

`toIterable()` supports streaming without collection fetch joins. Collection-fetch streaming and mixed streaming are rejected rather than returning incomplete graphs. Query limits/offsets are also rejected for collection fetch mappings. Read a page of roots and then load their associations when batching.

## Root pagination with collections

Paginator accepts two explicit SQL queries: ordered root IDs for the page and a hydration query with an ID parameter.

```php
use ModernORM\Tools\Pagination\Paginator;

$roots = $em->getConnection()->createQueryBuilder()
    ->select('id')->from('projects')->orderBy('name')->addOrderBy('id')
    ->setFirstResult(20)->setMaxResults(20);
$query = $em->createQueryBuilder()->fromEntity(Project::class, 'p')
    ->leftJoin('p', 'tasks', 't', 't.project_id = p.id')
    ->addJoinedEntity(Task::class, 't', 'p', 'tasks')
    ->where('p.id IN (:__orm_page_ids)')
    ->orderBy('p.name')->addOrderBy('p.id')->addOrderBy('t.id')
    ->getQuery();
$page = new Paginator($query, $roots);
$total = count($page);
$projects = iterator_to_array($page);
```

The root query returns one unique non-null scalar ID per root; joined queries need appropriate DISTINCT/GROUP BY. Count wraps the root query without page limits. An empty page skips hydration. Composite IDs are unsupported.

Applications keep predicates/tenant scope consistent between both queries and provide matching stable ordering with a tie-breaker. IN does not preserve order. Data can change between SELECTs; choose transaction isolation explicitly when a consistent snapshot is required.

## SQL writes and bulk operations

```php
$affected = $em->createQueryBuilder()->update('projects')
    ->set('name', ':name')->where('id = :id')
    ->setParameter('name', 'updated')->setParameter('id', 42)
    ->executeStatement();
$rows = $em->getConnection()->executeQuery(
    'UPDATE projects SET name = :name WHERE id = :id RETURNING id, name',
    ['name' => 'updated', 'id' => 42],
)->fetchAllAssociative();
```

Direct SQL does not implicitly flush, invoke entity callbacks, add optimistic version guards or synchronize managed entities/collections. RETURNING yields rows, not automatic UnitOfWork/L2 synchronization. Use explicit flush/refresh/clear and version predicates as required. DBAL executeQuery handles row-producing SQL; executeStatement returns an affected count.

DBAL 4.3+ supplies CTE construction. Other PostgreSQL constructs can use raw SQL. The separate [bulk API](bulk-api.md) adds mapped-field operations, explicit BYPASS/CLEAR policies and declared L2 write-set invalidation; direct DBAL writes do not automatically use that protocol.

## Metadata and projection-plan caches

`Configuration::setMetadataCache()` stores metadata in PSR-6. `setQueryCache()` stores EntityResultPlan: a concrete entity/alias projection and field/FK mapping. Arbitrary SQL is not compiled by the ORM; parameter values/types are not projection-key components and are passed to DBAL at execution. Raw SQL+RSM does not use this projection cache.

Change metadata and query-pool namespaces together when mappings/configuration change, then create new EntityManagers/builders. Namespaces separate applications and mapping/SQL configurations; there is no automatic fingerprint. Ordinary PSR-6 TTL/clear/delete are available. Legacy useQueryCache/expireQueryCache/setQueryCacheLifetime are removed. Result cache and entity L2 remain separate mechanisms without an added consistency guarantee.

ORM Filters and Criteria SQL are absent. Applications add tenant/access predicates explicitly on every access path.

## Migrating from Doctrine queries

- Replace mapping imports with `ModernORM\Mapping`; adapt unsupported identifiers/inheritance as described in [mapping scope](mapping-scope.md).
- EntityRepository::createQueryBuilder(alias) starts with fromEntity. A predicate such as `n.title = :title` works only when title is the SQL column name.
- Replace DQL strings, association paths and custom functions/walkers with SQL.
- Replace `select('p')->from(Project::class, 'p')` with `fromEntity(Project::class, 'p')`. Ordinary SQL `from()` accepts a table name.
- Replace bulk getQuery()->execute with SQL executeStatement or the structured bulk API. Bind IDs rather than entity objects in FK predicates.
- Use DBAL's expression builder. Replace old paginators with the explicit root-ID helper or application SQL.

There is no drop-in doctrine/orm compatibility layer. Retained persistence concepts do not imply query API compatibility.
