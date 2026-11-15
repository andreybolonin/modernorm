<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Event\PreUpdateEventArgs;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'generated_tracking'), ORM\HasLifecycleCallbacks]
class GeneratedTrackingRecord
{
    public static array $preUpdates = [];
    public static int $postUpdates = 0;
    public static bool $editInCallback = false;
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $name = 'initial';
    #[ORM\Column(insertable: false, updatable: false, generated: 'ALWAYS', options: ['default' => 'database'])]
    public string $computed;
    #[ORM\Column(updatable: false)]
    public string $insertOnly = 'inserted';
    #[ORM\Column(insertable: false, options: ['default' => 'default value'])]
    public string $updateOnly = 'not inserted';
    #[ORM\Version, ORM\Column(type: 'integer')]
    public int $version;
    #[ORM\PreUpdate]
    public function beforeUpdate(PreUpdateEventArgs $event): void
    {
        self::$preUpdates[] = $event->getEntityChangeSet();
        if (self::$editInCallback) {
            $this->computed = 'callback generated edit';
            $this->insertOnly = 'callback insert-only edit';
            $this->name = 'callback name';
        }
    }
    #[ORM\PostUpdate]
    public function afterUpdate(): void
    {
        ++self::$postUpdates;
    }
}

final class GeneratedFieldTrackingRegressionTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'generated_tracking_' . bin2hex(random_bytes(5));
        $this->statistics = new Statistics(enabled: true);
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->statistics)]);
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $configuration);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = $this->em->getClassMetadata(GeneratedTrackingRecord::class);
        $metadata->table['schema'] = $this->schema;
        (new SchemaTool($this->em))->createSchema([$metadata]);
        GeneratedTrackingRecord::$preUpdates = [];
        GeneratedTrackingRecord::$postUpdates = 0;
        GeneratedTrackingRecord::$editInCallback = false;
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
    private function persisted(): GeneratedTrackingRecord
    {
        $entity = new GeneratedTrackingRecord();
        $this->em->persist($entity);
        $this->em->flush();
        // insertable:false and updatable:true is intentionally a writable UPDATE control.
        $this->em->refresh($entity);
        return $entity;
    }
    public static function loads(): iterable
    {
        yield 'persisted' => ['persisted'];
        yield 'hydrated' => ['hydrated'];
        yield 'lazy' => ['lazy'];
        yield 'deferred explicit' => ['explicit'];
    }
    #[DataProvider('loads')]
    public function testGeneratedOnlyEditDoesNotScheduleUpdateOrDispatchEvents(string $load): void
    {
        $entity = $this->persisted();
        $id = $entity->id;
        if ($load === 'hydrated' || $load === 'lazy') {
            $this->em->clear();
            $entity = $load === 'lazy' ? $this->em->getReference(GeneratedTrackingRecord::class, $id) : $this->em->find(GeneratedTrackingRecord::class, $id);
        }
        if ($load === 'explicit') {
            $this->em->getClassMetadata($entity::class)->setChangeTrackingPolicy(ORM\ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT);
            $this->em->persist($entity);
        }
        self::assertSame('database', $entity->computed);
        $entity->computed = 'local generated edit';
        $this->statistics->reset();
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();
        self::assertFalse($uow->isScheduledForUpdate($entity));
        self::assertSame([], $uow->getEntityChangeSet($entity));
        $this->em->flush();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame([], GeneratedTrackingRecord::$preUpdates);
        self::assertSame(0, GeneratedTrackingRecord::$postUpdates);
        self::assertSame(1, $entity->version);
    }
    public function testRecomputeIgnoresNonUpdatableFields(): void
    {
        $entity = $this->persisted();
        $entity->computed = 'local generated edit';
        $entity->insertOnly = 'local insert-only edit';
        $uow = $this->em->getUnitOfWork();
        $uow->recomputeSingleEntityChangeSet($this->em->getClassMetadata($entity::class), $entity);
        self::assertFalse($uow->isScheduledForUpdate($entity));
        self::assertSame([], $uow->getEntityChangeSet($entity));
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame([], GeneratedTrackingRecord::$preUpdates);
    }
    public function testRealUpdateAndCallbackRecomputeExcludeGeneratedFields(): void
    {
        $entity = $this->persisted();
        $entity->computed = 'local generated edit';
        $entity->insertOnly = 'local insert-only edit';
        $entity->name = 'changed';
        GeneratedTrackingRecord::$editInCallback = true;
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();
        self::assertSame(['name'], array_keys($uow->getEntityChangeSet($entity)));
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(1, $this->statistics->snapshot()->dml->update->count);
        self::assertSame([['name' => ['initial', 'changed']]], GeneratedTrackingRecord::$preUpdates);
        self::assertSame(1, GeneratedTrackingRecord::$postUpdates);
        self::assertSame('callback name', $entity->name);
        self::assertSame('database', $entity->computed);
        self::assertSame(2, $entity->version);
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(1, GeneratedTrackingRecord::$postUpdates);
        $this->em->clear();
        $stored = $this->em->find(GeneratedTrackingRecord::class, $entity->id);
        self::assertSame('callback name', $stored->name);
        self::assertSame('inserted', $stored->insertOnly);
    }
    public function testInsertOnlyAndUpdateOnlyMappingPermissionsStayIndependent(): void
    {
        $entity = new GeneratedTrackingRecord();
        $entity->insertOnly = 'custom insert value';
        $entity->computed = 'must not insert';
        $this->em->persist($entity);
        $this->em->getUnitOfWork()->computeChangeSets();
        $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata($entity::class), $entity);
        $this->em->flush();
        $this->em->refresh($entity);
        self::assertSame('custom insert value', $entity->insertOnly);
        self::assertSame('database', $entity->computed);
        self::assertSame('default value', $entity->updateOnly);
        $entity->updateOnly = 'updated';
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(1, $this->statistics->snapshot()->dml->update->count);
        $this->em->clear();
        self::assertSame('updated', $this->em->find(GeneratedTrackingRecord::class, $entity->id)->updateOnly);
    }
}
