# Blameable entities

`BlameableTrait` supplies `createdBy` and `updatedBy` accessors. `BlameableListener` fills these associations using an application-provided `ActorProviderInterface`. No dependency on Symfony Security is required.

## Entity mapping

Implement `BlameableInterface` and declare the actor class on the entity:

```php
use ModernORM\Behavior\BlameableInterface;
use ModernORM\Behavior\BlameableTrait;
use ModernORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Blameable(actorClass: User::class)]
class Document implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column]
    public string $title;
}
```

`#[Blameable]` creates owning, lazy ManyToOne associations for `createdBy` and `updatedBy`. Both are nullable, have no persistence/remove cascade, and default to `ON DELETE SET NULL`. Join-column names and referenced-column names follow the configured naming strategy: with DefaultNamingStrategy these are `createdBy_id`, `updatedBy_id` and referenced column `id`; with UnderscoreNamingStrategy the join columns are `created_by_id` and `updated_by_id`.

The attribute requires a concrete entity implementing `BlameableInterface` with writable instance properties `createdBy` and `updatedBy`, as supplied by the trait. The actor class must also be a concrete attribute-mapped entity. Invalid targets and conflicting scalar/association mappings fail during metadata loading. Clear the metadata cache when changing entity attributes.

### Explicit mapping and overrides

Explicit property mapping remains supported, with or without `#[Blameable]`. When the attribute is present, an existing ManyToOne targeting the same actor class is preserved; the attribute fills only missing associations. For example, an application can keep its existing column names and batch settings:

```php
#[ORM\ManyToOne(targetEntity: User::class, batchSize: 32)]
#[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'RESTRICT')]
protected ?object $createdBy = null;
```

Redeclare trait properties with the same visibility, native type (`?object`) and default (`null`); PHP requires compatible property declarations. Both getters return `?object`; both setters accept `?object` and return `static`. The supplied object must match the mapped target entity. Standard `#[AssociationOverrides]` can also customize generated join columns without redeclaring properties, including the referenced column when the actor's primary-key column differs from the naming strategy's default.

`#[HasLifecycleCallbacks]` is not needed for this global listener. If the entity also uses `TimestampableTrait`, enable lifecycle callbacks for that trait.

## Actor provider and listener registration

```php
use ModernORM\Behavior\ActorProviderInterface;
use ModernORM\Behavior\BlameableListener;
use ModernORM\Events;

// $actorProvider implements ActorProviderInterface::getActor(): ?object.
$em->getEventManager()->addEventListener(
    [Events::prePersist, Events::preUpdate],
    new BlameableListener($actorProvider),
);
```

The provider must return a user managed by this EntityManager, or `null`. A provider can obtain the current identity from Symfony Security, a CLI argument or a message envelope. If authentication uses another ORM, resolve its identity through ModernORM (for example, `getReference(User::class, $userId)`) rather than returning that ORM's entity instance. Detached or unmanaged actors are rejected with `InvalidArgumentException` before assignment; there is no implicit persistence or merge of users.

The listener asks the provider on each relevant event and stores no actor itself. Long-lived workers must still clear their provider's request/job context and resolve managed references again after `clear()` or an EntityManager reset. This change does not install an adapter or listener automatically in ModernORMBundle.

## Contract

| Operation | Behavior |
| --- | --- |
| INSERT with actor | Fill missing `createdBy` and `updatedBy` independently; preserve explicitly supplied import values |
| UPDATE with actor | Replace `updatedBy`; leave `createdBy` untouched |
| No current actor | Leave both values unchanged, including null values on anonymous inserts |
| Flush without entity changes | Do not resolve an actor or update the entity |
| Changes only to collection membership | Do not automatically change the owner's actor |
| `#[Immutable]` | Fill actors on insertion; ignored field changes do not invoke `PreUpdate` |
| Bulk writes / direct SQL | No listener invocation; supply actor IDs explicitly |
| DELETE | No `deletedBy` tracking or soft-delete behavior |

Insert attribution happens during `persist()`, while update attribution happens during flush. The actor is not a commit-time audit identity. Imported associations must also satisfy ordinary ORM persistence rules.

`createdBy` is not immutable metadata: application code can explicitly change it. This feature supplies attribution fields, not an append-only audit trail. Deleting a referenced user follows the application's foreign-key policy; `SET NULL` in the example also removes attribution from the database row. Cache invalidation for external SQL or database cascades remains the application's responsibility.
