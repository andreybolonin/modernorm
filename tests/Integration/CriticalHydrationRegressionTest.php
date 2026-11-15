<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query;
use ModernORM\UnitOfWork;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'documents')]
final class CriticalDocument
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\OneToOne(targetEntity: CriticalFile::class, inversedBy: 'document', cascade: ['persist'])]
    public ?CriticalFile $file = null;
}

#[ORM\Entity, ORM\Table(name: 'files')]
final class CriticalFile
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $name = 'file';
    #[ORM\OneToOne(targetEntity: CriticalDocument::class, mappedBy: 'file')]
    public ?CriticalDocument $document = null;
}

#[ORM\Entity, ORM\Table(name: 'owners')]
final class CriticalOwner
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    /** @var Collection<int, CriticalItem> */
    #[ORM\OneToMany(targetEntity: CriticalItem::class, mappedBy: 'owner')]
    public Collection $items;
}

#[ORM\Entity, ORM\Table(name: 'items')]
final class CriticalItem
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: CriticalOwner::class, inversedBy: 'items', fetch: 'EAGER', cascade: ['persist'])]
    public ?CriticalOwner $owner = null;
}

final class CriticalHydrationRegressionTest extends TestCase
{
    private EntityManager $em;
    private string $schema;

    protected function setUp(): void
    {
        $this->schema = 'critical_hydration_' . bin2hex(random_bytes(5));
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $classes = array_map($this->em->getClassMetadata(...), [CriticalDocument::class, CriticalFile::class, CriticalOwner::class, CriticalItem::class]);
        foreach ($classes as $metadata) {
            $metadata->table['schema'] = $this->schema;
        }
        (new SchemaTool($this->em))->createSchema($classes);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $connection = $this->em->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $connection->close();
        }
    }

    public static function localToOneChanges(): iterable
    {
        foreach (['null', 'existing', 'new'] as $replacement) {
            foreach ([false, true] as $computed) {
                yield $replacement . ($computed ? '-computed' : '-uncomputed') => [$replacement, $computed];
            }
        }
    }

    /** @param 'null'|'existing'|'new' $replacement */
    #[DataProvider('localToOneChanges')]
    public function testOldOneToOneReferenceCannotOverwriteLocalChange(string $replacement, bool $computed): void
    {
        $document = new CriticalDocument();
        $document->file = new CriticalFile();
        $existing = new CriticalFile();
        $this->em->persist($document);
        $this->em->persist($existing);
        $this->em->flush();
        [$id, $existingId] = [$document->id, $existing->id];
        $this->em->clear();
        $document = $this->em->find(CriticalDocument::class, $id);
        $old = $document->file;
        self::assertTrue((new \ReflectionClass($old))->isUninitializedLazyObject($old));
        $document->file = $new = match ($replacement) {
            'null' => null,
            'existing' => $this->em->find(CriticalFile::class, $existingId),
            'new' => new CriticalFile(),
        };
        if ($computed) {
            $this->em->getUnitOfWork()->computeChangeSets();
        }
        $baseline = $this->em->getUnitOfWork()->getOriginalEntityData($document);
        $this->em->initializeObject($old);
        self::assertSame($new, $document->file);
        self::assertSame($baseline, $this->em->getUnitOfWork()->getOriginalEntityData($document));
        $this->em->flush();
        $newId = $new?->id;
        $this->em->clear();
        self::assertSame($newId, $this->em->find(CriticalDocument::class, $id)->file?->id);
    }

    /** @param 'null'|'existing'|'new' $replacement */
    #[DataProvider('localToOneChanges')]
    public function testEagerRefetchPreservesLocalToOneAndItsBaseline(string $replacement, bool $computed): void
    {
        $item = new CriticalItem();
        $item->owner = new CriticalOwner();
        $existing = new CriticalOwner();
        $this->em->persist($item);
        $this->em->persist($existing);
        $this->em->flush();
        [$id, $existingId] = [$item->id, $existing->id];
        $this->em->clear();
        $item = $this->em->find(CriticalItem::class, $id);
        $item->owner = $new = match ($replacement) {
            'null' => null,
            'existing' => $this->em->find(CriticalOwner::class, $existingId),
            'new' => new CriticalOwner(),
        };
        if ($computed) {
            $this->em->getUnitOfWork()->computeChangeSets();
        }
        $baseline = $this->em->getUnitOfWork()->getOriginalEntityData($item);
        self::assertSame($item, $this->em->getRepository(CriticalItem::class)->findOneBy(['id' => $id]));
        self::assertSame($new, $item->owner);
        self::assertSame($baseline, $this->em->getUnitOfWork()->getOriginalEntityData($item));
        $this->em->flush();
        $newId = $new?->id;
        $this->em->clear();
        self::assertSame($newId, $this->em->find(CriticalItem::class, $id)->owner?->id);
    }

    public function testExplicitRefreshStillRestoresTheDatabaseRelation(): void
    {
        $item = new CriticalItem();
        $item->owner = new CriticalOwner();
        $this->em->persist($item);
        $this->em->flush();
        $original = $item->owner;
        $item->owner = null;
        $this->em->refresh($item);
        self::assertSame($original, $item->owner);
        self::assertSame($original, $this->em->getUnitOfWork()->getOriginalEntityData($item)['owner']);
        $item->owner = null;
        $query = $this->em->createQueryBuilder()->fromEntity(CriticalItem::class, 'i');
        $ownerTable = $this->em->getConfiguration()->getQuoteStrategy()->getTableName($this->em->getClassMetadata(CriticalOwner::class), $this->em->getConnection()->getDatabasePlatform());
        $query->addJoinedEntity(CriticalOwner::class, 'o', 'i', 'owner')->leftJoin('i', $ownerTable, 'o', 'o.id = i.owner_id');
        $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        self::assertSame($original, $item->owner);
    }

    public function testOrphanReplacementSurvivesLazyOldTargetInitializationDuringFlush(): void
    {
        $this->em->getClassMetadata(CriticalDocument::class)->associationMappings['file']->orphanRemoval = true;
        $document = new CriticalDocument();
        $document->file = new CriticalFile();
        $this->em->persist($document);
        $this->em->flush();
        [$id, $oldId] = [$document->id, $document->file->id];
        $this->em->clear();
        $document = $this->em->find(CriticalDocument::class, $id);
        $document->file = $new = new CriticalFile();
        $this->em->flush();
        $newId = $new->id;
        $this->em->clear();
        self::assertSame($newId, $this->em->find(CriticalDocument::class, $id)->file->id);
        self::assertNull($this->em->find(CriticalFile::class, $oldId));
        self::assertNotNull($this->em->find(CriticalFile::class, $newId));
    }

    public static function eagerTargetFacts(): iterable
    {
        yield 'new target, deferred' => [false, true];
        yield 'new target, immediate' => [false, false];
        yield 'managed proxy, deferred' => [true, true];
        yield 'managed proxy, disabled hint' => [true, false];
    }

    #[DataProvider('eagerTargetFacts')]
    public function testToOnePlanExecutionPreservesIdentityAndDeferredHintBehavior(bool $managed, bool $defer): void
    {
        $item = new CriticalItem();
        $item->owner = new CriticalOwner();
        $this->em->persist($item);
        $this->em->flush();
        $itemId = $item->id;
        $ownerId = $item->owner->id;
        $this->em->clear();
        $reference = $managed ? $this->em->getReference(CriticalOwner::class, $ownerId) : null;
        $query = $this->em->createQueryBuilder()->fromEntity(CriticalItem::class, 'i')
            ->where('i.id = :id')->setParameter('id', $itemId)->getQuery();
        $loaded = $query->setHint(UnitOfWork::HINT_DEFEREAGERLOAD, $defer)->getSingleResult();
        $owner = $loaded->owner;
        self::assertSame($ownerId, $owner->id);
        if ($reference !== null) {
            self::assertSame($reference, $owner);
        }
        // Existing eager proxies retain the historical "hint present but false" queue behavior.
        self::assertSame($managed && ! $defer, $this->em->isUninitializedObject($owner));
        $this->em->getUnitOfWork()->triggerEagerLoads();
        self::assertFalse($this->em->isUninitializedObject($owner));
        self::assertSame($owner, $this->em->find(CriticalOwner::class, $ownerId));
        self::assertSame($owner, $this->em->getUnitOfWork()->getOriginalEntityData($loaded)['owner']);
        $this->em->flush();
    }

}
