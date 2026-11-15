<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use ModernORM\AbstractQuery;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Query\ResultSetMappingBuilder;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Embeddable]
class ProjectionAddress
{
    #[ORM\Column] public string $city = 'Paris';
}
#[ORM\Entity, ORM\Table(name: 'projection_records')]
class ProjectionRecord
{
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\Column] public string $title = 'stored';
    #[ORM\Column(nullable: true)] public ?string $note = null;
    #[ORM\Embedded(class: ProjectionAddress::class)] public ProjectionAddress $address;
    #[ORM\ManyToOne(targetEntity: ProjectionParent::class, inversedBy: 'records')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)] public ?ProjectionParent $parent = null;
    public function __construct()
    {
        $this->address = new ProjectionAddress();
    }
}
#[ORM\Entity, ORM\Table(name: 'projection_parents')]
class ProjectionParent
{
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\Column] public string $name = 'parent';
    /** @var Collection<int, ProjectionRecord> */
    #[ORM\OneToMany(targetEntity: ProjectionRecord::class, mappedBy: 'parent')]
    public Collection $records;
    public function __construct()
    {
        $this->records = new ArrayCollection();
    }
}
final class ProjectionDto
{
    public function __construct(public string $title)
    {
    }
}

final class CompleteEntityProjectionTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'full_projection_' . bin2hex(random_bytes(5));
        $this->statistics = new Statistics(enabled: true);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [ProjectionRecord::class, ProjectionParent::class]);
        foreach ($metadata as $class) {
            $class->table['schema'] = $this->schema;
        }
        (new SchemaTool($this->em))->createSchema($metadata);
        $this->em->persist(new ProjectionRecord());
        $this->em->flush();
        $this->em->clear();
        $this->statistics->reset();
    }
    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $db = $this->em->getConnection();
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $db->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $db->close();
        }
    }
    private function mapping(?string $omit = null): ResultSetMapping
    {
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(ProjectionRecord::class, 'r');
        foreach ($this->em->getClassMetadata(ProjectionRecord::class)->fieldMappings as $field => $definition) {
            if ($field !== $omit) {
                $mapping->addFieldResult('r', $definition->columnName, $field);
            }
        }
        if ($omit !== 'parent_id') {
            $mapping->addMetaResult('r', 'parent_id', 'parent_id', false, 'integer');
        }
        return $mapping;
    }
    public static function incompleteMappings(): iterable
    {
        foreach (['id', 'note', 'address.city', 'parent_id'] as $missing) {
            foreach ([false, true] as $simple) {
                yield $missing . ':' . ($simple ? 'simple' : 'object') => [$missing, $simple];
            }
        }
    }
    #[DataProvider('incompleteMappings')]
    public function testIncompleteMappingsFailBeforeSqlEvenWithNoResults(string $missing, bool $simple): void
    {
        $mode = $simple ? AbstractQuery::HYDRATE_SIMPLEOBJECT : AbstractQuery::HYDRATE_OBJECT;
        $query = $this->em->createNativeQuery('SELECT * FROM ' . $this->schema . '.projection_records WHERE false', $this->mapping($missing));
        try {
            $query->getResult($mode);
            self::fail('Incomplete entity projection must be rejected.');
        } catch (MappingException $error) {
            self::assertStringContainsString($missing, $error->getMessage());
        }
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(0, $this->em->getUnitOfWork()->size());
    }
    public function testStreamingIncompleteMappingFailsBeforeSql(): void
    {
        $query = $this->em->createQuery('SELECT id FROM ' . $this->schema . '.projection_records', $this->mapping('note'));
        $this->expectException(MappingException::class);
        try {
            iterator_to_array($query->toIterable());
        } finally {
            self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        }
    }
    public static function managedModes(): iterable
    {
        yield 'object' => [false];
        yield 'simple object' => [true];
    }
    #[DataProvider('managedModes')]
    public function testMissingSqlColumnDoesNotModifyExistingReference(bool $simple): void
    {
        $mode = $simple ? AbstractQuery::HYDRATE_SIMPLEOBJECT : AbstractQuery::HYDRATE_OBJECT;
        $reference = $this->em->getReference(ProjectionRecord::class, 1);
        $query = $this->em->createNativeQuery('SELECT id, title, address_city, parent_id FROM ' . $this->schema . '.projection_records', $this->mapping());
        try {
            $query->getResult($mode);
            self::fail('SQL must return all declared entity columns.');
        } catch (MappingException $error) {
            self::assertStringContainsString('missing column note', $error->getMessage());
        }
        self::assertTrue($this->em->isUninitializedObject($reference));
        self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($reference));
        self::assertSame($reference, $this->em->getReference(ProjectionRecord::class, 1));
    }
    public function testEntityArgumentsInDtosStillRequireFullEntityProjection(): void
    {
        $mapping = $this->mapping('note');
        $mapping->nestedEntities['r'] = ['ownerIndex' => 0, 'argIndex' => 0];
        $this->expectException(MappingException::class);
        try {
            $this->em->createNativeQuery('SELECT * FROM ' . $this->schema . '.projection_records', $mapping)->getResult();
        } finally {
            self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        }
    }
    public function testJoinedAliasAlsoRequiresCompleteProjection(): void
    {
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(ProjectionParent::class, 'p');
        $mapping->addJoinedEntityResult(ProjectionRecord::class, 'r', 'p', 'records');
        $mapping->addFieldResult('r', 'record_id', 'id');
        $this->expectException(MappingException::class);
        try {
            $this->em->createNativeQuery('SELECT 1 WHERE false', $mapping)->getResult();
        } finally {
            self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        }
    }
    public static function partialHints(): iterable
    {
        yield ['isPartial'];
        yield ['modernorm.partial'];
        yield ['doctrine.forcePartialLoad'];
    }
    #[DataProvider('partialHints')]
    public function testLegacyPartialHintsCannotEnablePartialEntities(string $hint): void
    {
        $query = $this->em->createNativeQuery('SELECT * FROM ' . $this->schema . '.projection_records', $this->mapping());
        $this->expectException(MappingException::class);
        $query->setHint($hint, true);
    }
    public function testLegacyPartialMarkerIsRejected(): void
    {
        $this->expectException(MappingException::class);
        $this->mapping()->markPartialEntityResult('r');
    }
    public function testLegacyPartialJoinArgumentIsRejected(): void
    {
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(ProjectionParent::class, 'p');
        $this->expectException(MappingException::class);
        $mapping->addJoinedEntityResult(ProjectionRecord::class, 'r', 'p', 'records', true);
    }
    #[DataProvider('managedModes')]
    public function testDirectHydratorCannotBypassMappingValidation(bool $simple): void
    {
        $mode = $simple ? AbstractQuery::HYDRATE_SIMPLEOBJECT : AbstractQuery::HYDRATE_OBJECT;
        $statement = $this->em->getConnection()->executeQuery('SELECT * FROM ' . $this->schema . '.projection_records');
        $this->expectException(MappingException::class);
        try {
            $this->em->newHydrator($mode)->hydrateAll($statement, $this->mapping('note'));
        } finally {
            self::assertSame(0, $this->em->getUnitOfWork()->size());
            self::assertTrue($this->em->isOpen());
        }
    }
    public function testSerializedPartialMappingCannotEnablePartialEntities(): void
    {
        $mapping = $this->mapping();
        $mapping->partialAliases['r'] = true;
        $mapping = unserialize(serialize($mapping));
        $this->expectException(MappingException::class);
        $this->em->createNativeQuery('SELECT 1', $mapping);
    }
    #[DataProvider('managedModes')]
    public function testFullProjectionSupportsNullsEmbeddablesIdentityAndCleanFlush(bool $simple): void
    {
        $mode = $simple ? AbstractQuery::HYDRATE_SIMPLEOBJECT : AbstractQuery::HYDRATE_OBJECT;
        $reference = $this->em->getReference(ProjectionRecord::class, 1);
        $query = $this->em->createNativeQuery('SELECT * FROM ' . $this->schema . '.projection_records', $this->mapping());
        $entity = $query->getSingleResult($mode);
        self::assertSame($reference, $entity);
        self::assertNull($entity->note);
        self::assertNull($entity->parent);
        self::assertSame('Paris', $entity->address->city);
        self::assertFalse($this->em->isUninitializedObject($entity));
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
    }
    public function testRowsScalarsAndDtosCanSelectOnlyRequestedFields(): void
    {
        $rows = $this->em->createQueryBuilder()->select('title')->from($this->schema . '.projection_records')->fetchAllAssociative();
        self::assertSame([['title' => 'stored']], $rows);
        $arrayMapping = new ResultSetMapping();
        $arrayMapping->addEntityResult(ProjectionRecord::class, 'r');
        $arrayMapping->addFieldResult('r', 'id', 'id');
        $arrayMapping->addFieldResult('r', 'title', 'title');
        self::assertSame([['id' => 1, 'title' => 'stored']], $this->em->createNativeQuery('SELECT id, title FROM ' . $this->schema . '.projection_records', $arrayMapping)->getArrayResult());
        $mapping = new ResultSetMapping();
        $mapping->addScalarResult('title', 'title');
        $query = $this->em->createNativeQuery('SELECT title FROM ' . $this->schema . '.projection_records', $mapping);
        self::assertSame(['stored'], $query->getSingleColumnResult());
        $mapping->newObjectMappings['title'] = ['className' => ProjectionDto::class, 'objIndex' => 0, 'argIndex' => 0];
        $dto = $this->em->createNativeQuery('SELECT title FROM ' . $this->schema . '.projection_records', $mapping)->getSingleResult();
        self::assertInstanceOf(ProjectionDto::class, $dto);
        self::assertSame('stored', $dto->title);
        self::assertSame(0, $this->em->getUnitOfWork()->size());
    }
    public function testFullFetchJoinPreservesEmptyCollectionsAndNullableRows(): void
    {
        $first = new ProjectionParent();
        $empty = new ProjectionParent();
        $empty->id = 2;
        $record = $this->em->find(ProjectionRecord::class, 1);
        $record->parent = $first;
        $this->em->persist($first);
        $this->em->persist($empty);
        $this->em->flush();
        $this->em->clear();
        $mapping = new ResultSetMappingBuilder($this->em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);
        $mapping->addRootEntityFromClassMetadata(ProjectionParent::class, 'p');
        $mapping->addJoinedEntityFromClassMetadata(ProjectionRecord::class, 'r', 'p', 'records');
        $sql = 'SELECT ' . $mapping->generateSelectClause(['p' => 'p', 'r' => 'r']) . ' FROM ' . $this->schema . '.projection_parents p LEFT JOIN '
            . $this->schema . '.projection_records r ON r.parent_id = p.id ORDER BY p.id';
        $parents = $this->em->createNativeQuery($sql, $mapping)->getResult();
        self::assertCount(2, $parents);
        self::assertCount(1, $parents[0]->records);
        self::assertSame($parents[0], $parents[0]->records->first()->parent);
        self::assertTrue($parents[1]->records->isEmpty());
    }
}
