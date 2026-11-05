# Timestampable entities

`ModernORM\Behavior\TimestampableTrait` provides mapped `createdAt` and `updatedAt` fields without an external listener or a Symfony dependency. Enable lifecycle callbacks on each entity that uses it:

```php
use ModernORM\Behavior\TimestampableTrait;
use ModernORM\Mapping as ORM;

#[ORM\Entity, ORM\HasLifecycleCallbacks]
class PaymentMethod
{
    use TimestampableTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column]
    public string $name;
}
```

Both fields use `datetime_immutable` and non-null database columns. Their nullable PHP properties start as `null`; `getCreatedAt()` and `getUpdatedAt()` return `?DateTimeImmutable`. The default naming strategy uses column names `createdAt` and `updatedAt`; a configured naming strategy may change these names.

## Lifecycle

- `PrePersist` initializes missing dates using the same current instant. Explicitly supplied dates are preserved independently. This happens when the entity is persisted, rather than at transaction commit.
- `PreUpdate` replaces `updatedAt` when a managed entity is scheduled for an ORM update. The callback's change is included in the database update. `createdAt` remains unchanged unless explicitly changed through its setter.
- A flush without entity changes does not advance the dates. Changes only to collection membership do not automatically advance the owner's timestamp.
- `#[Immutable]` entities receive initial dates, but ignored field changes do not invoke `PreUpdate`.
- Bulk operations and direct SQL bypass these callbacks. Supply timestamps explicitly in those operations.

Import creation dates with fluent setters:

```php
$method
    ->setCreatedAt(new \DateTimeImmutable('2020-01-02 03:04:05'))
    ->setUpdatedAt(new \DateTimeImmutable('2021-02-03 04:05:06'));
$em->persist($method);
```

This preservation applies to insertion. Subsequent ORM updates replace `updatedAt`, including a timestamp explicitly assigned before that update.

Dates use PHP's current default timezone. Standard DBAL `datetime_immutable` persistence has second precision and does not preserve timezone information or microseconds; configure a consistent timezone for application processes, typically UTC. These timestamps are application wall-clock values, not optimistic locking versions or commit-order guarantees.

The trait does not provide Gedmo's conditional field-change rules, actor tracking or automatic activation without `#[HasLifecycleCallbacks]`. It supplies its own mapping and should replace the Gedmo timestamp trait on a migrated entity, rather than being combined with it. If an entity declares fields or callbacks with the same names, resolve the conflict explicitly.
