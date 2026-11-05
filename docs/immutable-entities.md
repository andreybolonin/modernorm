# Immutable entities

`Immutable` is a class-level dirty-checking policy for concrete entities:

```php
use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Immutable]
class Currency
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $code;
}
```

## Persistence contract

- **INSERT is allowed.** `persist()` and cascade persist create rows normally, including generated IDs and initial version values.
- **DELETE is allowed.** Explicit remove, cascade remove, and applicable orphan removal retain their normal behavior.
- **Entity field changes do not produce UPDATE.** Changes to scalar/embedded state and owning to-one FK fields are ignored during managed-entity synchronization. PHP objects remain mutable; local values remain until refreshed or reloaded.
- **Associated entities retain their own policies.** Mutable children can be updated, new associated entities can cascade-persist, and collections can synchronize join-table links. Read-only does not propagate to related objects.
- **SchemaTool manages the table normally.** Read-only does not designate a database VIEW and does not exclude a table from schema creation or deletion.

The legacy `#[Entity(readOnly: true)]` option uses the same class-level policy. The separate marker is the preferred spelling. Apply it to a concrete Entity, not a MappedSuperclass or Embeddable. All mapping attributes use the `ModernORM\Mapping` namespace; Doctrine attribute aliases are not provided.

## Associations and versions

Reassigning an owning to-one relation does not update the FK on the read-only row. Cascade persist can still insert the newly referenced entity. Since the old FK remains unchanged, removing an entity it references is subject to the usual database constraints. Reassigning an ignored to-one relation does not itself schedule deletion of its former orphan.

Mutable children remain independently tracked. For a OneToMany relation, the child's owning side determines its FK; changing only the inverse collection does not replace that requirement. Collection orphan removal and cascade remove remain active according to their mapping.

Owning ManyToMany links can be added, removed, or replaced without updating the read-only owner row. Ordinary field/FK or collection changes do not increment that owner's version in ModernORM. INSERT still initializes the version. No PreUpdate/PostUpdate callbacks run for ignored owner changes; normal persist/remove callbacks remain available.

## Loading and change tracking

Identity Map, native lazy references, lazy ManyToOne batching, and refresh preserve the class-level read-only policy. `UnitOfWork::isReadOnly()` reports it for mapped objects. The marker survives serialized metadata caches; the metadata cache salt is `__MODERNORM_ENTITY_CACHE_WRITE_POLICY_V9__`.

Managed read-only entities skip scalar dirty checking. Entities with associations still process the association graph so cascades and collections remain functional. Current hydration/snapshot state is retained for existing ORM contracts; this implementation does not promise Hibernate-style snapshot memory savings. Latency and memory gains need separate benchmarks.

Direct changeset computation, recomputation, property-change notifications, and ordinary update execution respect the class policy. Changes made while an entity is still scheduled for insertion can be recomputed, including from onFlush.

This is a UnitOfWork policy, not a database write restriction. Direct SQL/bulk operations bypass it. Internal FK fixups used to complete cyclic inserts may still require an UPDATE as part of initial persistence. It is separate from the READ_ONLY L2 cache strategy and from PHP `readonly` properties/classes.

## Fixtures and database views

Read-only entities can be populated with persist/flush and deleted by fixture cleanup. This marker does not make fixtures purgers skip the table. A database VIEW needs separate schema/purge exclusions. ModernORMBundle provides a separate fixture adapter; these core tests do not certify arbitrary third-party purgers.

## Verification

`ImmutableEntityTest` covers SchemaTool create/drop, generated-ID INSERT/DELETE and callbacks, ignored scalar/embedded/FK changes, related-entity persistence, child updates, orphan/cascade removal, ManyToMany links/replacement, versions, lazy batches, Identity Map, refresh, internal changeset paths, and legacy mapping.

`ImmutableMappingTest` covers serialized metadata cache and invalid marker placement. These tests verify the selected contract, not every combination of association mappings or external lifecycle integrations.
