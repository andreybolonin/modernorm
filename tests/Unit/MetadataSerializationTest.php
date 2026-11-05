<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\EntityManagerInterface;
use ModernORM\Id\SequenceGenerator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

enum SerializedAccessorState: string
{
    case Active = 'active';
}

#[ORM\Embeddable]
final class SerializedAccessorAddress
{
    #[ORM\Column]
    private string $city;
}

#[ORM\Entity]
final class SerializedAccessorEntity
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(enumType: SerializedAccessorState::class)]
    private SerializedAccessorState $state;

    #[ORM\Column]
    public readonly string $token;

    #[ORM\Embedded(class: SerializedAccessorAddress::class)]
    private SerializedAccessorAddress|null $address = null;
}

final class MetadataSerializationTest extends TestCase
{
    public function testRestoredMetadataAccessesEnumsReadonlyAndEmbeddedFields(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
        $metadata = unserialize(serialize($em->getClassMetadata(SerializedAccessorEntity::class)));
        self::assertInstanceOf(ClassMetadata::class, $metadata);
        $metadata->wakeupReflection(new RuntimeReflectionService());
        $entity = $metadata->newInstance();
        $metadata->getSingleIdPropertyAccessor()->setValue($entity, 7);
        $metadata->getPropertyAccessor('state')->setValue($entity, 'active');
        $metadata->getPropertyAccessor('token')->setValue($entity, 'original');
        $metadata->getPropertyAccessor('address.city')->setValue($entity, 'Kazan');

        self::assertSame(7, $metadata->getSingleIdPropertyAccessor()->getValue($entity));
        self::assertSame('active', $metadata->getPropertyAccessor('state')->getValue($entity));
        self::assertSame(SerializedAccessorState::Active, $metadata->getPropertyAccessor('state')->getUnderlyingReflector()->getValue($entity));
        self::assertSame('original', $entity->token);
        self::assertSame('Kazan', $metadata->getPropertyAccessor('address.city')->getValue($entity));
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testSerializedSequenceGeneratorRetainsSequenceAndAllocationSize(): void
    {
        $platform = new PostgreSQLPlatform();
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::exactly(2))->method('fetchOne')
            ->with($platform->getSequenceNextValSQL('invoice_id_seq'))
            ->willReturnOnConsecutiveCalls('40', '43');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $generator = unserialize(serialize(new SequenceGenerator('invoice_id_seq', 3)));
        self::assertInstanceOf(SequenceGenerator::class, $generator);
        $ids = [];
        for ($i = 0; $i < 4; ++$i) {
            $ids[] = $generator->generateId($em, null);
        }
        self::assertSame([40, 41, 42, 43], $ids);
    }
}
