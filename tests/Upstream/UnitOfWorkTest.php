<?php

declare(strict_types=1);

namespace ModernORM\Tests\Upstream;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\UnitOfWork;
use ModernORM\Mapping\{Entity, Id, Column};
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class UnitOfWorkTest extends TestCase
{
    private UnitOfWork $_unitOfWork;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
        $this->_unitOfWork = $em->getUnitOfWork();
    }

    #[DataProvider('entitiesWithValidIdentifiersProvider')]
    public function testAddToIdentityMapValidIdentifiers(object $entity, string $idHash): void
    {
        $this->_unitOfWork->persist($entity);
        $this->_unitOfWork->addToIdentityMap($entity);

        self::assertSame($entity, $this->_unitOfWork->getByIdHash($idHash, $entity::class));
    }

    public static function entitiesWithValidIdentifiersProvider(): array
    {
        $emptyString = new EntityWithStringIdentifier();

        $emptyString->id = '';

        $nonEmptyString = new EntityWithStringIdentifier();

        $nonEmptyString->id = uniqid('id', true);

        $booleanTrue = new EntityWithBooleanIdentifier();

        $booleanTrue->id = true;

        $booleanFalse = new EntityWithBooleanIdentifier();

        $booleanFalse->id = false;

        return [
            'empty string, single field'     => [$emptyString, ''],
            'non-empty string, single field' => [$nonEmptyString, $nonEmptyString->id],
            'boolean true'                   => [$booleanTrue, '1'],
            'boolean false'                  => [$booleanFalse, ''],
        ];
    }

    public function testRegisteringAManagedInstanceRequiresANonEmptyIdentifier(): void
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->registerManaged(new EntityWithBooleanIdentifier(), [], []);
    }

    #[DataProvider('entitiesWithInvalidIdentifiersProvider')]
    public function testAddToIdentityMapInvalidIdentifiers(object $entity, array $identifier): void
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->registerManaged($entity, $identifier, []);
    }

    public static function entitiesWithInvalidIdentifiersProvider(): array
    {
        return [
            'null string, single field'      => [new EntityWithStringIdentifier(), ['id' => null]],
        ];
    }

    public function testItThrowsWhenLookingUpIdentifierForUnknownEntity(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->_unitOfWork->getEntityIdentifier(new stdClass());
    }

}

#[Entity]
class EntityWithStringIdentifier
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    public string|null $id = null;
}

#[Entity]
class EntityWithBooleanIdentifier
{
    #[Id]
    #[Column(type: 'boolean')]
    public bool|null $id = null;
}

#[Entity]
class EntityWithCompositeStringIdentifier
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    public string|null $id1 = null;

    #[Id]
    #[Column(type: 'string', length: 255)]
    public string|null $id2 = null;
}
