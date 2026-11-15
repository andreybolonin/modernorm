<?php

declare(strict_types=1);

namespace ModernORM\IssueAudit;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use ModernORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'owners')]
class Owner
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $name = 'owner';
    /** @var Collection<int, Child> */
    #[ORM\OneToMany(targetEntity: Child::class, mappedBy: 'owner', cascade: ['persist', 'refresh'], indexBy: 'name')]
    public Collection $children;
    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'owners', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'owner_tags')]
    public Collection $tags;
    /** @var Collection<int, CustomRow> */
    #[ORM\OneToMany(targetEntity: CustomRow::class, mappedBy: 'owner', cascade: ['persist'], indexBy: 'token')]
    public Collection $customRows;
    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->customRows = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'children')]
class Child
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $name = 'child';
    #[ORM\ManyToOne(targetEntity: Owner::class, inversedBy: 'children')]
    public ?Owner $owner = null;
}

#[ORM\Entity, ORM\Table(name: 'tags')]
class Tag
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $name = 'tag';
    /** @var Collection<int, Owner> */
    #[ORM\ManyToMany(targetEntity: Owner::class, mappedBy: 'tags')]
    public Collection $owners;
    public function __construct()
    {
        $this->owners = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'hooked_ids')]
class HookedId
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public int $id {
        get => $this->id;
        set => $this->id = $value;
    }
}

#[ORM\Entity, ORM\Table(name: 'readonly_dates')]
class ReadonlyDate
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $date;
    public function __construct()
    {
        $this->date = new \DateTimeImmutable('2025-01-02 03:04:05');
    }
}

#[ORM\Entity, ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\OneToOne(targetEntity: File::class, cascade: ['persist', 'remove'], orphanRemoval: true, inversedBy: 'document')]
    public ?File $file = null;
}

#[ORM\Entity, ORM\Table(name: 'files'), ORM\HasLifecycleCallbacks]
class File
{
    public static array $removed = [];
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $name = 'file';
    #[ORM\OneToOne(targetEntity: Document::class, mappedBy: 'file')]
    public ?Document $document = null;
    #[ORM\PostRemove]
    public function removed(): void
    {
        self::$removed[] = $this->name;
    }
}

#[ORM\Entity, ORM\Table(name: '`user`')]
class QuotedRow
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(name: '`SelectValue`', type: 'string')]
    public string $value = 'quoted';
}

#[ORM\MappedSuperclass]
class SequenceParent
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'issue_explicit_sequence', allocationSize: 1)]
    public ?int $id = null;
}

#[ORM\Entity, ORM\Table(name: 'sequence_rows')]
class SequenceRow extends SequenceParent
{
}

#[ORM\Entity]
class InferredVersion
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column, ORM\Version]
    public int $version;
}

#[ORM\Entity]
class ConflictingMapping
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'integer'), ORM\ManyToOne(targetEntity: Owner::class)]
    public ?Owner $owner = null;
}

final class Token
{
    public function __toString(): string
    {
        return $this->value;
    }
    public function __construct(public readonly string $value)
    {
    }
}

class TokenType extends \Doctrine\DBAL\Types\StringType
{
    public function convertToDatabaseValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof Token) {
            throw new \InvalidArgumentException('TokenType requires a Token value object.');
        }
        return 'token:' . $value->value;
    }
    public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): ?Token
    {
        return $value === null ? null : new Token(substr($value, 6));
    }

}

final class SqlTokenType extends TokenType
{
    public function convertToDatabaseValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
    {
        return 'LOWER(' . $sqlExpr . ')';
    }
}

#[ORM\Entity, ORM\Table(name: 'custom_rows')]
class CustomRow
{
    #[ORM\Id, ORM\Column(name: 'row_id', type: 'integer')]
    public int $id = 1;
    #[ORM\Column(name: '`TokenValue`', type: 'issue_token')]
    public Token $token;
    #[ORM\ManyToOne(targetEntity: Owner::class, inversedBy: 'customRows')]
    public ?Owner $owner = null;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $date;
    public function __construct()
    {
        $this->token = new Token('UPPER');
        $this->date = new \DateTimeImmutable('2025-01-02 03:04:05');
    }
}

#[ORM\Entity, ORM\Table(name: 'generated_rows')]
class GeneratedRow
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id = 1;
    #[ORM\Column(type: 'string', insertable: false, updatable: false, generated: 'ALWAYS', options: ['default' => 'generated'])]
    public string $value;
}

#[ORM\Embeddable]
class Address
{
    #[ORM\Column(type: 'string')]
    public string $city = 'city';
}

#[ORM\Entity, ORM\Table(name: 'embedded_rows')]
class EmbeddedRow
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id = 1;
    #[ORM\Embedded(class: Address::class)]
    public Address $address;
    public function __construct()
    {
        $this->address = new Address();
    }
}


enum CustomState: string
{
    case Active = 'active';
}

final class StateType extends \Doctrine\DBAL\Types\StringType
{
    public function convertToDatabaseValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof CustomState) {
            throw new \InvalidArgumentException('StateType expects the domain enum, not its raw backing value.');
        }
        return $value->value;
    }
    public function convertToPHPValue(mixed $value, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): ?CustomState
    {
        return $value === null ? null : CustomState::from($value);
    }
}

#[ORM\Entity, ORM\Table(name: 'enum_rows')]
class EnumRow
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id = 1;
    #[ORM\Column(type: 'issue_enum')]
    public CustomState $state = CustomState::Active;
}


#[ORM\Entity, ORM\Table(name: 'cycle_parents')]
class CycleParent
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: CycleChild::class)]
    public ?CycleChild $featured = null;
    /** @var Collection<int, CycleChild> */
    #[ORM\OneToMany(targetEntity: CycleChild::class, mappedBy: 'parent', cascade: ['persist', 'remove'])]
    public Collection $children;
    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'cycle_children')]
class CycleChild
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: CycleParent::class, inversedBy: 'children')]
    public ?CycleParent $parent = null;
}

namespace ModernORM\IssueAudit\First;

#[\ModernORM\Mapping\MappedSuperclass]
class FirstBase
{
    #[\ModernORM\Mapping\Id, \ModernORM\Mapping\Column(type: 'integer')]
    public int $id;
}

namespace ModernORM\IssueAudit\Second;

#[\ModernORM\Mapping\MappedSuperclass]
class SecondBase extends \ModernORM\IssueAudit\First\FirstBase
{
    #[\ModernORM\Mapping\Column(type: 'string')]
    public string $name;
}

namespace ModernORM\IssueAudit\Third;

#[\ModernORM\Mapping\Entity]
class ConcreteRow extends \ModernORM\IssueAudit\Second\SecondBase
{
}
