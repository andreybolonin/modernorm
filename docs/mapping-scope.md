# Mapping scope

ModernORM uses PHP attributes and one independent metadata root per concrete entity. Entity inheritance and discriminator-based polymorphic hydration are unsupported. These are intentional differences from Doctrine ORM, not compatibility switches.

## Identifiers and explicit queries

Each entity has one scalar field PK: integer, string or UUID through the appropriate DBAL type. BackedEnum identifiers normalize to their scalar value. Composite entity IDs and association-as-ID are rejected, including metadata restored from caches or changed by listeners. Composite UNIQUE constraints and many-to-many join-table primary keys remain supported.

Use a surrogate scalar PK and a multi-column UNIQUE constraint when replacing a natural composite entity key. Associations reference the entity PK. `find()` and `getReference()` accept a scalar or an array containing the single named identifier; an entity object is not an identifier. Assigned, IDENTITY, SEQUENCE and custom generation remain available within the tested scope.

`findBy()` and `count()` accept array conditions for equality, IN and NULL. Use SQL QueryBuilder for complex predicates. Criteria visitors, repository matching, LazyCriteriaCollection and ORM Filters are removed. `PersistentCollection::matching()` retains the inherited interface signature but rejects the operation without initializing the collection. EXTRA_LAZY count/contains/containsKey/get/slice retain their owner-based loading paths.

Applications supply tenant/access predicates for every read/write path; automatic tenant isolation is not provided. Proxy generation, proxy directories/namespaces, alternate lazy-object switches and optional ID-collision compatibility switches are removed. PHP native lazy objects and Identity Map collision checks are always used.

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

## Cache formats and migration

Current internal salts are `__MODERNORM_TYPED_MAPPING_V10__` for metadata and `sql_entity_v3_` for entity projection plans. They reject old internal formats; they are not an automatic mapping fingerprint. Applications must version metadata/query cache namespaces and create new EntityManagers when their own mappings change.

Move XML/programmatic sources to attributes. Removing inheritance attributes alone does not migrate an inheritance schema: choose concrete models/composition and explicitly migrate their tables/data. A discriminator column may remain as an ordinary business field, but no automatic PHP subclass dispatch occurs. Separate entities are not a polymorphic Identity Map substitute.

[Mapping transformation manifest](../tools/mapping-simplification.json) and [persistence simplification manifest](../tools/persistence-simplification.json) record the historical changes. [Provenance](../tools/upstream.json) preserves source hashes; MIT notices remain in the distribution.
