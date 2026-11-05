<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Event\LoadClassMetadataEventArgs;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Exception\MappingException;
use ModernORM\PersistentCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
class CompositeIdentifier
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Id, ORM\Column] public string $scope;
}

#[ORM\Entity]
class ScalarIdentifier { #[ORM\Id, ORM\Column] public int $id; }

#[ORM\Entity]
class AssociationIdentifier
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: ScalarIdentifier::class)]
    #[ORM\JoinColumn(nullable: false)] public ScalarIdentifier $owner;
}

final class IdentifierScopeTest extends TestCase
{
    private function manager(?ArrayAdapter $cache = null): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        if ($cache !== null) {
            $config->setMetadataCache($cache);
        }
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public static function unsupportedIdentifiers(): array
    {
        return [[CompositeIdentifier::class], [AssociationIdentifier::class]];
    }

    #[DataProvider('unsupportedIdentifiers')]
    public function testUnsupportedIdentifierIsRejectedBeforeConnecting(string $class): void
    {
        $em = $this->manager();
        try {
            $em->getClassMetadata($class);
            self::fail('Expected unsupported primary key rejection');
        } catch (MappingException $exception) {
            self::assertStringContainsString('one scalar field', $exception->getMessage());
        }
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testMetadataListenerCannotIntroduceCompositeIdentifier(): void
    {
        $em = $this->manager();
        $em->getEventManager()->addEventListener([Events::loadClassMetadata], new class {
            public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
            {
                $args->getClassMetadata()->identifier[] = 'scope';
            }
        });
        $this->expectException(MappingException::class);
        $em->getClassMetadata(ScalarIdentifier::class);
    }

    public function testCachedCompositeIdentifierCannotBypassValidation(): void
    {
        $cache = new ArrayAdapter();
        $metadata = clone $this->manager($cache)->getClassMetadata(ScalarIdentifier::class);
        $metadata->identifier[] = 'scope';
        $key = array_key_first($cache->getValues());
        $cache->save($cache->getItem($key)->set($metadata));
        $this->expectException(MappingException::class);
        $this->manager($cache)->getClassMetadata(ScalarIdentifier::class);
    }

    public function testMatchingIsRejectedWithoutInitializingPersistentCollection(): void
    {
        $em = $this->manager();
        $collection = new PersistentCollection($em, $em->getClassMetadata(ScalarIdentifier::class), new ArrayCollection());
        $collection->setInitialized(false);
        try {
            $collection->matching(Criteria::create());
            self::fail('Expected unsupported matching rejection');
        } catch (\BadMethodCallException $exception) {
            self::assertStringContainsString('QueryBuilder', $exception->getMessage());
        }
        self::assertFalse($collection->isInitialized());
        self::assertFalse($em->getConnection()->isConnected());
    }
}
