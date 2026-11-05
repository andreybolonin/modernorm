<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\Mapping as ORM;
use ModernORM\EntityManager;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
#[ORM\Table(name: 'modernorm_test_records')]
final class Record
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'initial';
}

final class PersistenceTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig(
            [__DIR__],
            true,
            cache: new ArrayAdapter(),
        ));
        $schema = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(Record::class)];
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_records');
        $this->em->getConnection()->close();
    }

    public function testCrudAndIdentityMap(): void
    {
        $record = new Record();
        $this->em->persist($record);
        $this->em->flush();
        self::assertNotNull($record->id);
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(Record::class, $id);
        self::assertNotSame($record, $loaded);
        self::assertSame($loaded, $this->em->find(Record::class, $id));
        $loaded->name = 'updated';
        $this->em->flush();
        $this->em->clear();
        self::assertSame('updated', $this->em->find(Record::class, $id)->name);
        $this->em->remove($this->em->find(Record::class, $id));
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(Record::class, $id));
    }

    public function testRollbackDoesNotPersistChanges(): void
    {
        try {
            $this->em->wrapInTransaction(function (EntityManager $em): void {
                $em->persist(new Record());
                $em->flush();
                throw new \RuntimeException('rollback');
            });
            self::fail('Expected rollback exception');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertFalse($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_records'));
    }
}
