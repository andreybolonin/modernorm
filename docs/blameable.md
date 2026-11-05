# Blameable entities

`BlameableTrait` supplies `createdBy` and `updatedBy` accessors. `BlameableListener` fills these associations using an application-provided `ActorProviderInterface`. No dependency on Symfony Security is required.

## Entity mapping

Implement `BlameableInterface` to opt into the listener. Declare the association mapping in the entity, where the concrete user class is known:

```php
use ModernORM\Behavior\BlameableInterface;
use ModernORM\Behavior\BlameableTrait;
use ModernORM\Mapping as ORM;

#[ORM\Entity]
class Document implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column]
    public string $title;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    protected ?object $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    protected ?object $updatedBy = null;
}
```

The trait's properties intentionally have no mapping attributes. Redeclare them with exactly the same visibility, native type (`?object`) and default (`null`); PHP requires compatible property declarations when composing traits. The `targetEntity` defines the concrete association type. Both getters return `?object`; both setters accept `?object` and return `static`. The supplied object must match the mapped target entity.

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
