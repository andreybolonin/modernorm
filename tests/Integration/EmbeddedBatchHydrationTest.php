<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Embeddable]
class StoredBatchAddress
{
    public static int $constructors = 0;
    #[ORM\Column] public string $street;
    #[ORM\Column] public string $city;
    #[ORM\Column] public string $country;
    #[ORM\Column] public string $zip;
    #[ORM\Column] public string $region;
    #[ORM\Column] public string $district;
    #[ORM\Column] public string $building;
    #[ORM\Column(nullable: true)] public ?string $unit;
    public function __construct()
    {
        ++self::$constructors;
        foreach (['street', 'city', 'country', 'zip', 'region', 'district', 'building', 'unit'] as $field) {
            $this->$field = $field . '-value';
        }
    }
}

#[ORM\Entity, ORM\Table(name: 'batch_owners')]
class StoredBatchOwner
{
    public static int $constructors = 0;
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Embedded(class: StoredBatchAddress::class)] public StoredBatchAddress $address;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $created;
    public function __construct()
    {
        ++self::$constructors;
        $this->address = new StoredBatchAddress();
        $this->created = new DateTimeImmutable('2026-01-01');
    }
}

final class EmbeddedBatchHydrationTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'embedded_batch_' . bin2hex(random_bytes(5));
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = $this->em->getClassMetadata(StoredBatchOwner::class);
        $metadata->table['schema'] = $this->schema;
        (new SchemaTool($this->em))->createSchema([$metadata]);
        StoredBatchOwner::$constructors = StoredBatchAddress::$constructors = 0;
    }
    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->em->getConnection()->close();
        }
    }
    public function testSqlHydrationRefreshSnapshotsAndEmbeddedDirtyChecking(): void
    {
        $owner = new StoredBatchOwner();
        $this->em->persist($owner);
        $this->em->flush();
        $id = $owner->id;
        $this->em->clear();
        $loaded = $this->em->find(StoredBatchOwner::class, $id);
        self::assertInstanceOf(StoredBatchOwner::class, $loaded);
        self::assertNotSame($owner, $loaded);
        foreach (['street', 'city', 'country', 'zip', 'region', 'district', 'building', 'unit'] as $field) {
            self::assertSame($field . '-value', $loaded->address->$field);
        }
        $address = $loaded->address;
        $created = $loaded->created;
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->schema . '.batch_owners SET address_city = ?, address_unit = NULL WHERE id = ?', ['database-city', $id]);
        $this->em->refresh($loaded);
        self::assertSame($address, $loaded->address);
        self::assertSame('database-city', $address->city);
        self::assertNull($address->unit);
        self::assertSame($created, $loaded->created);
        self::assertSame($created, $this->em->getUnitOfWork()->getOriginalEntityData($loaded)['created']);
        $this->em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($loaded));
        $address->city = 'application-city';
        $this->em->flush();
        $this->em->clear();
        $reloaded = $this->em->find(StoredBatchOwner::class, $id);
        self::assertInstanceOf(StoredBatchOwner::class, $reloaded);
        self::assertSame('application-city', $reloaded->address->city);
        self::assertNull($reloaded->address->unit);
        self::assertSame(1, StoredBatchOwner::$constructors);
        self::assertSame(1, StoredBatchAddress::$constructors);
    }
}
