<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\AbstractHydrator;
use ModernORM\Internal\Hydration\ObjectHydrator;
use ModernORM\Internal\Hydration\SimpleObjectHydrator;
use ModernORM\Internal\UnitOfWork\FlatSqlHydrationPlan;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use TypeError;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_flat_sql_rows'), ORM\HasLifecycleCallbacks]
class PlannedSqlRow
{
    public static int $constructors = 0;
    public static int $loads = 0;
    public static int $hooks = 0;
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] private string $name;
    #[ORM\Column] protected int $quantity = 7;
    #[ORM\Column] public float $price = 1.25;
    #[ORM\Column] public bool $active = true;
    #[ORM\Column] public string $hooked = 'seed' {
        set {
            ++self::$hooks;
            $this->hooked = $value;
        }
    }
    public function __construct(string $name)
    {
        ++self::$constructors;
        $this->name = $name;
    }
    public function name(): string
    {
        return $this->name;
    }
    public function rename(string $name): void
    {
        $this->name = $name;
    }
    #[ORM\PostLoad]
    public function onLoad(): void
    {
        ++self::$loads;
    }
}

#[ORM\Entity]
class InvalidPlannedSqlRow
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $prefix;
    #[ORM\Column(type: 'json')] public string $bad;
    #[ORM\Column] public string $tail;
}

final class FlatSqlHydrationPlanTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $schema = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(PlannedSqlRow::class)];
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        PlannedSqlRow::$constructors = PlannedSqlRow::$loads = PlannedSqlRow::$hooks = 0;
    }
    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_flat_sql_rows CASCADE');
        $this->em->getConnection()->close();
    }
    private function seed(int $count = 1): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->em->persist(new PlannedSqlRow('name-' . $i));
        }
        $this->em->flush();
        $this->em->clear();
    }
    private function mapping(string $class = PlannedSqlRow::class): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult($class, 'r');
        foreach ($this->em->getClassMetadata($class)->fieldMappings as $field => $_) {
            $rsm->addFieldResult('r', $field, $field);
        }
        return $rsm;
    }
    public static function hydrators(): array
    {
        return ['object' => [ObjectHydrator::class], 'simple' => [SimpleObjectHydrator::class]];
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testRealSqlRowsKeepIdentityValuesAndCallbacks(string $class): void
    {
        $this->seed(3);
        $hydrator = new $class($this->em);
        $statement = $this->em->getConnection()->executeQuery('SELECT name, id, quantity, price, active, hooked, 123 AS ignored FROM modernorm_test_flat_sql_rows ORDER BY id');
        $iterator = $hydrator->toIterable($statement, $this->mapping());
        $first = $iterator->current();
        $hints = (new ReflectionProperty(AbstractHydrator::class, 'hints'))->getValue($hydrator);
        if (FlatSqlHydrationPlan::available()) {
            self::assertInstanceOf(FlatSqlHydrationPlan::class, $hints[FlatSqlHydrationPlan::HINT] ?? null);
        } else {
            self::assertArrayNotHasKey(FlatSqlHydrationPlan::HINT, $hints);
        }
        $result = [];
        while ($iterator->valid()) {
            $row = $iterator->current();
            $result[] = $row;
            self::assertSame('name-' . count($result), $row->name());
            self::assertSame(1.25, $row->price);
            self::assertTrue($row->active);
            self::assertSame('seed', $row->hooked);
            self::assertSame($row, $this->em->getUnitOfWork()->tryGetById(['id' => $row->id], PlannedSqlRow::class));
            self::assertArrayNotHasKey('ignored', (array) $row);
            $iterator->next();
        }
        self::assertSame($first, $result[0]);
        self::assertCount(3, $result);
        self::assertSame(3, PlannedSqlRow::$constructors);
        self::assertSame(3, PlannedSqlRow::$loads);
        self::assertSame(0, PlannedSqlRow::$hooks);
    }
    public function testQueryHintsSnapshotsRefreshAndDirtyCheckingRemainIntact(): void
    {
        $this->seed();
        $query = $this->em->createQueryBuilder()->fromEntity(PlannedSqlRow::class, 'r')->getQuery();
        $row = $query->getResult()[0];
        self::assertArrayNotHasKey(FlatSqlHydrationPlan::HINT, $query->getHints());
        $row->rename('modified');
        $this->em->flush();
        self::assertSame('modified', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_flat_sql_rows'));
        $this->em->getConnection()->executeStatement("UPDATE modernorm_test_flat_sql_rows SET name = 'external'");
        $this->em->refresh($row);
        self::assertSame('external', $row->name());
        self::assertSame('external', $this->em->getUnitOfWork()->getOriginalEntityData($row)['name']);
        $this->em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($row));
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testAssignmentFailureKeepsRegisteredPrefixAndFreesResult(string $class): void
    {
        $hydrator = new $class($this->em);
        $statement = $this->em->getConnection()->executeQuery("SELECT 1 AS id, 'written' AS prefix, '[]' AS bad, 'must-not-write' AS tail");
        try {
            $hydrator->hydrateAll($statement, $this->mapping(InvalidPlannedSqlRow::class));
            self::fail('JSON array cannot be assigned to a string property.');
        } catch (TypeError) {
            $entity = $this->em->getUnitOfWork()->tryGetById(['id' => 1], InvalidPlannedSqlRow::class);
            self::assertInstanceOf(InvalidPlannedSqlRow::class, $entity);
            self::assertSame('written', $entity->prefix);
            self::assertFalse(isset($entity->bad));
            self::assertFalse(isset($entity->tail));
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT 1'));
        }
    }
}
