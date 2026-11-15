<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class InstantiationHooks
{
    public static int $constructors = 0;
    public static int $clones = 0;
    public static int $wakeups = 0;
}

#[ORM\Embeddable]
final readonly class ConstructorlessLeaf
{
    #[ORM\Column(type: 'string')]
    public string $code;

    public function __construct(string $required)
    {
        ++InstantiationHooks::$constructors;
        $this->code = $required;
    }
    public function __clone(): void
    {
        ++InstantiationHooks::$clones;
    }
    public function __wakeup(): void
    {
        ++InstantiationHooks::$wakeups;
    }
}

#[ORM\Embeddable]
final readonly class ConstructorlessAddress
{
    #[ORM\Column(type: 'string')]
    public string $line;
    #[ORM\Embedded(class: ConstructorlessLeaf::class)]
    public ConstructorlessLeaf $leaf;

    public function __construct(string $required)
    {
        ++InstantiationHooks::$constructors;
        $this->line = $required;
        $this->leaf = new ConstructorlessLeaf($required);
    }
    public function __clone(): void
    {
        ++InstantiationHooks::$clones;
    }
    public function __wakeup(): void
    {
        ++InstantiationHooks::$wakeups;
    }
}

#[ORM\Entity]
final class ConstructorlessRecord
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'string')]
    public readonly string $token;
    #[ORM\Embedded(class: ConstructorlessAddress::class)]
    public ?ConstructorlessAddress $address = null;
    public array $defaults = ['initial'];

    public function __construct(string $required)
    {
        ++InstantiationHooks::$constructors;
        $this->token = $required;
    }
    public function __clone(): void
    {
        ++InstantiationHooks::$clones;
    }
    public function __wakeup(): void
    {
        ++InstantiationHooks::$wakeups;
    }
}

final class ConstructorlessInstantiationTest extends TestCase
{
    protected function setUp(): void
    {
        InstantiationHooks::$constructors = InstantiationHooks::$clones = InstantiationHooks::$wakeups = 0;
    }

    public function testStandaloneMetadataCreatesIndependentObjectsWithoutInvokingHooks(): void
    {
        $metadata = new ClassMetadata(ConstructorlessRecord::class);
        $first = $metadata->newInstance();
        $second = $metadata->newInstance();
        self::assertInstanceOf(ConstructorlessRecord::class, $first);
        self::assertInstanceOf(ConstructorlessRecord::class, $second);
        self::assertNotSame($first, $second);
        self::assertNull($first->address);
        self::assertFalse((new ReflectionProperty($first, 'token'))->isInitialized($first));
        $first->defaults[] = 'changed';
        self::assertSame(['initial'], $second->defaults);
        $this->assertNoHooks();
    }

    public function testRestoredMetadataBuildsIndependentNestedReadonlyEmbeddablesWithoutHooks(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
        $metadata = unserialize(serialize($em->getClassMetadata(ConstructorlessRecord::class)));
        self::assertInstanceOf(ClassMetadata::class, $metadata);
        $metadata->wakeupReflection(new RuntimeReflectionService());
        $first = $metadata->newInstance();
        $second = $metadata->newInstance();
        foreach ([[$first, 'first'], [$second, 'second']] as [$entity, $value]) {
            $metadata->getPropertyAccessor('token')->setValue($entity, $value);
            $metadata->getPropertyAccessor('address.line')->setValue($entity, $value . '-line');
            $metadata->getPropertyAccessor('address.leaf.code')->setValue($entity, $value . '-code');
        }
        self::assertInstanceOf(ConstructorlessRecord::class, $first);
        self::assertInstanceOf(ConstructorlessRecord::class, $second);
        self::assertInstanceOf(ConstructorlessAddress::class, $first->address);
        self::assertInstanceOf(ConstructorlessAddress::class, $second->address);
        self::assertNotSame($first->address, $second->address);
        self::assertNotSame($first->address->leaf, $second->address->leaf);
        self::assertSame('first', $first->token);
        self::assertSame('first-line', $first->address->line);
        self::assertSame('first-code', $first->address->leaf->code);
        self::assertSame('second-code', $second->address->leaf->code);
        self::assertFalse($em->getConnection()->isConnected());
        $this->assertNoHooks();
    }

    private function assertNoHooks(): void
    {
        self::assertSame(0, InstantiationHooks::$constructors);
        self::assertSame(0, InstantiationHooks::$clones);
        self::assertSame(0, InstantiationHooks::$wakeups);
    }
}
