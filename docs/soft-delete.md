# SoftDelete policy

SoftDelete is an opt-in, built-in policy, not a general-purpose ORM Filters API. It is currently unreleased. Enable it on a mutable entity:

```php
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\Mapping as ORM;

#[ORM\Entity, ORM\SoftDelete]
class Document
{
    use SoftDeleteTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column]
    public string $title;
}
```

The trait maps a nullable `datetime_immutable` property `deletedAt` to `deleted_at`, with `getDeletedAt()` and `isDeleted()`. It does not require lifecycle callbacks or listener registration. `#[SoftDelete]` requires that mapped, writable field; custom declarations must preserve its type and nullability. Embeddables, MappedSuperclass markers, Immutable/read-only classes and generated/read-only deletion fields are rejected.

## Writes and transactions

```php
$em->remove($document); // Sets deletedAt; schedules an ordinary entity UPDATE.
$em->flush();

$deleted = $em->findIncludingDeleted(Document::class, $id);
$em->restore($deleted); // Sets deletedAt to null; requires a managed entity.
$em->flush();

$em->hardDelete($deleted); // Explicit physical DELETE on the next flush.
$em->flush();
```

Repeated logical removal preserves the original deletion time. Removing a pending INSERT cancels insertion. Both logical deletion and restoration use ordinary UPDATE change tracking, optimistic versions and PreUpdate/PostUpdate events; they do not invoke PreRemove/PostRemove. Timestampable/Blameable update behavior therefore also applies. Hard deletion keeps normal physical-deletion events and foreign-key semantics.

SQL writes are deferred until flush; loading an association may still execute SELECTs. The SQL visibility predicates reflect the database state; flush before expecting repository/query results to reflect pending mutations. The normal Identity Map can retain locally modified objects. After rollback or a failed flush, discard/reset the EntityManager rather than reusing its snapshots. Objects individually marked read-only in the UnitOfWork also reject logical removal/restoration instead of silently ignoring it.

## Ordinary reads and explicit inclusion

`find()`, `getReference()`, repositories (`findBy`, `findOneBy`, `findAll`, `count`), ordinary association loaders and OneToMany/ManyToMany batch loaders exclude rows with non-null deletedAt. For SoftDelete classes, getReference resolves visibility immediately; it does not return an unchecked lazy ghost.

```php
$active = $em->getRepository(Document::class)->findAll();
$oneIncludingDeleted = $em->findIncludingDeleted(Document::class, $id);

$includingDeleted = $em->createQueryBuilder()
    ->withDeleted() // Choose visibility before fromEntity().
    ->fromEntity(Document::class, 'd')
    ->orderBy('d.id', 'ASC')
    ->getQuery()->getResult();
```

Visibility is local to the query; there is no global enable/disable flag to leak between requests/jobs. `findIncludingDeleted()` bypasses L2, while respecting the Identity Map. withDeleted includes deleted rows in explicitly mapped projections, not in later independently loaded associations.

A fromEntity projection uses a filtered table expression, so replacing WHERE, adding OR predicates, pagination and aggregate SQL over that projection do not accidentally remove the visibility predicate. Raw SQL (`createQuery`, `createNativeQuery`, DBAL), unregistered SQL joins and custom result mappings follow the SQL explicitly supplied by the caller; ModernORM does not parse/rewrite arbitrary PostgreSQL. Add the appropriate predicate yourself. SoftDelete is not an authorization or tenant-isolation boundary.

For a soft-deletable target in a manually mapped collection join, register its projection before the SQL join so that visibility applies inside the joined table expression:

```php
$qb = $em->createQueryBuilder()->fromEntity(Folder::class, 'f');
$qb->addJoinedEntity(Document::class, 'd', 'f', 'documents')
    ->leftJoin('f', 'document', 'd', 'd.folder_id = f.id');
```

The reverse registration order is explicitly rejected for SoftDelete targets. Normal non-SoftDelete joins retain their existing API. Including the predicate inside the joined table preserves an owner with only deleted children when using LEFT JOIN.

## Association boundary

A deleted target must not leave an uninitializable proxy in a nullable to-one property. Nullable to-one associations targeting SoftDelete entities therefore use eager loading; repository selects and fromEntity root projections fetch-join owning to-one targets. Explicit batchSize on those to-one associations does not override the eager visibility requirement. More deeply nested or explicitly supplied SQL hydration may resolve a nullable target individually.

Mandatory to-one foreign keys or non-nullable PHP properties targeting SoftDelete entities are rejected. This first contract also rejects cascade remove/orphanRemoval where the source or target is SoftDelete, and EXTRA_LAZY associations targeting SoftDelete entities. Ordinary lazy and batch to-many collections remain supported; no collection L2 is introduced.

Soft deletion does not update foreign keys, delete join-table memberships, trigger database ON DELETE actions or cascade to related entities. A hidden nullable target is represented as null in the loaded graph while its FK remains in the database, so restore can reconnect it after reloading. Assigning null to an already-hidden/null property is not a change; to explicitly unlink it, load the association with deleted visibility or use explicit SQL/bulk with the appropriate UnitOfWork policy.

Existing PHP references and initialized collections are not rewritten globally when another entity is deleted/restored. Clear/reload to rebuild a graph with current visibility. This policy applies to ORM loading, not to arbitrary references already held by application code.

## L2 cache

Only the existing flat entity cache scope is supported. A cacheable SoftDelete entity must use:

```php
#[ORM\Cacheable(writePolicy: \ModernORM\Cache\CacheWritePolicy::INVALIDATE)]
```

READ_ONLY cache and Immutable are incompatible with the required UPDATE. Logical deletion/restoration use the existing generation-based pre/post invalidation, commit/rollback handling and top-level ORM-owned cached-write transaction requirement. Cached tombstones are rejected; inclusion queries bypass L2 and do not publish deleted payloads. These writes retain the existing commit/invalidation crash-window and external-writer limitations; this feature does not add transactional outbox or caller-owned cached-write transaction support.

## Bulk and unique constraints

Bulk DELETE and direct SQL DELETE remain physical, including deleted rows. Bulk writes bypass lifecycle callbacks and retain the documented BYPASS/CLEAR state policies and cache invalidation. Use explicit bulk UPDATE of deleted_at for a mass logical deletion; timestamps/actors must also be supplied explicitly.

A normal unique constraint still includes deleted rows. For uniqueness among active rows, declare a PostgreSQL partial unique index, for example:

```php
#[ORM\UniqueConstraint(
    name: 'document_active_code',
    columns: ['code'],
    options: ['where' => 'deleted_at IS NULL'],
)]
```

Restoration can conflict if another active row reused the same value. The database rejects the UPDATE, the transaction rolls back and the failed EntityManager must be discarded. ModernORM does not rename conflicting values or silently delete the replacement row.

When adopting this mapping, clear/version query-plan caches and restart workers. Metadata cache salt V11 separates the new metadata contract from earlier releases.
