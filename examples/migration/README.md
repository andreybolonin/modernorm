# Minimal persistence migration example

This example uses ModernORM mapping attributes and a custom repository. It demonstrates adapting a small Doctrine-style persistence workflow to ModernORM; it is not a schema-migrations engine.

Use a dedicated PostgreSQL 17 database after installing project dependencies:

```sh
PGDATABASE=modernorm_test php examples/migration/run.php --test-schema
```

Connection options come from PGHOST, PGPORT, PGUSER, PGPASSWORD and PGDATABASE. The script creates/drops only `modernorm_example_notes` and refuses to overwrite an existing table.

## Application changes

1. Install `andreybolonin/modernorm` in place of Doctrine ORM for the migrated workflow. The separate Symfony bundle can coexist with DoctrineBundle, but entity/query migration is explicit.
2. Replace `use Doctrine\ORM\Mapping as ORM` with `use ModernORM\Mapping as ORM`. Legacy mapping aliases are not provided.
3. Import Configuration, EntityManager and AttributeDriver from ModernORM. DBAL connection options remain ordinary DBAL options.
4. Extend ModernORM\EntityRepository. Repository QueryBuilder builds SQL through fromEntity; predicates use SQL columns. Rewrite DQL while retaining the supported persist/flush/find/clear workflow. See the [SQL API](../../docs/sql-query-api.md).
5. Adapt ORM type hints, events/listeners and other extension classes to the ModernORM API. Generic/custom mapping drivers must satisfy the attributes-only contract.

The example omits cache adapters to keep runtime dependencies small. Application deployments can configure PSR-6 metadata/query-plan pools and use the separate ModernORMBundle migration integration. Docblock annotations and automatic compatibility with Symfony's Doctrine ServiceEntityRepository are not provided.

The [compatibility matrix](../../docs/compatibility.md) describes tested boundaries. Core CI runs this example after the runtime smoke without development packages.
