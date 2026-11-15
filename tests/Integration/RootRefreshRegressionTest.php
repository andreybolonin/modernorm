<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\PersistentCollection;
use ModernORM\Tools\SchemaTool;
use ModernORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'refresh_owners')]
class RootRefreshOwner
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $name = 'database';
    /** @var Collection<int, RootRefreshChild>|array<RootRefreshChild> */
    #[ORM\OneToMany(targetEntity: RootRefreshChild::class, mappedBy: 'owner', cascade: ['all'])]
    public Collection|array $children;
    /** @var Collection<int, RootRefreshTag> */
    #[ORM\ManyToMany(targetEntity: RootRefreshTag::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'refresh_links')]
    public Collection $tags;
    #[ORM\ManyToOne(targetEntity: RootRefreshChild::class)]
    public ?RootRefreshChild $favorite = null;
    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'refresh_children')]
class RootRefreshChild
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $name = 'database';
    #[ORM\ManyToOne(targetEntity: RootRefreshOwner::class, inversedBy: 'children')]
    public ?RootRefreshOwner $owner = null;
}

#[ORM\Entity, ORM\Table(name: 'refresh_tags')]
class RootRefreshTag
{
    #[ORM\Id, ORM\Column]
    public string $id;
    #[ORM\Column]
    public string $name = 'database';
    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

final class RootRefreshRegressionTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'root_refresh_' . bin2hex(random_bytes(5));
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [RootRefreshOwner::class, RootRefreshChild::class, RootRefreshTag::class]);
        foreach ($metadata as $class) {
            $class->table['schema'] = $this->schema;
            foreach ($class->associationMappings as $association) {
                if ($association->isManyToManyOwningSide()) {
                    $association->joinTable->schema = $this->schema;
                }
            }
        }
        (new SchemaTool($this->em))->createSchema($metadata);
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
    public static function collections(): iterable
    {
        yield 'OneToMany initialized' => ['children', true];
        yield 'OneToMany uninitialized' => ['children', false];
        yield 'ManyToMany initialized, assigned IDs' => ['tags', true];
        yield 'ManyToMany uninitialized, assigned IDs' => ['tags', false];
    }
    #[DataProvider('collections')]
    public function testRefreshDiscardsTransientCollectionMembers(string $field, bool $initialize): void
    {
        $owner = new RootRefreshOwner();
        $stored = $field === 'children' ? new RootRefreshChild() : new RootRefreshTag('stored');
        if ($stored instanceof RootRefreshChild) {
            $stored->owner = $owner;
        }
        $owner->$field->add($stored);
        $this->em->persist($owner);
        $this->em->flush();
        $ownerId = $owner->id;
        $storedId = $stored->id;
        $this->em->clear();
        $owner = $this->em->find(RootRefreshOwner::class, $ownerId);
        self::assertInstanceOf(PersistentCollection::class, $owner->$field);
        if ($initialize) {
            $owner->$field->toArray();
        }
        self::assertSame($initialize, $owner->$field->isInitialized());
        $new = $field === 'children' ? new RootRefreshChild() : new RootRefreshTag('new');
        if ($new instanceof RootRefreshChild) {
            $new->owner = $owner;
        }
        $owner->$field->add($new);
        $owner->name = 'local';
        $this->em->refresh($owner);
        self::assertSame('database', $owner->name);
        self::assertSame([$storedId], array_map(static fn ($item) => $item->id, $owner->$field->toArray()));
        self::assertFalse($this->em->contains($new));
        self::assertSame(UnitOfWork::STATE_NEW, $this->em->getUnitOfWork()->getEntityState($new));
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository($stored::class)->count([]));
    }
    public function testRefreshSkipsTransientToOneAndRestoresDatabaseReference(): void
    {
        $owner = new RootRefreshOwner();
        $original = new RootRefreshChild();
        $owner->favorite = $original;
        $this->em->persist($original);
        $this->em->persist($owner);
        $this->em->flush();
        $new = new RootRefreshChild();
        $owner->favorite = $new;
        $this->em->refresh($owner);
        self::assertSame($original, $owner->favorite);
        self::assertFalse($this->em->contains($new));
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository(RootRefreshChild::class)->count([]));
    }
    public function testRefreshPreservesDirtyManagedChildrenInCycles(): void
    {
        $owner = new RootRefreshOwner();
        $stored = new RootRefreshChild();
        $stored->owner = $owner;
        $owner->children->add($stored);
        $this->em->persist($owner);
        $this->em->flush();
        $stored->name = 'local child';
        $owner->name = 'local owner';
        $owner->children->add(new RootRefreshChild());
        $this->em->refresh($owner);
        self::assertSame('local child', $stored->name);
        self::assertSame('database', $owner->name);
        self::assertSame($owner, $stored->owner);
        self::assertSame([$stored], $owner->children->toArray());
        $this->em->flush();
    }
    public function testPlainArrayWithTransientChildCanBeRefreshed(): void
    {
        $owner = new RootRefreshOwner();
        $this->em->persist($owner);
        $this->em->flush();
        $owner->children = [new RootRefreshChild()];
        $this->em->refresh($owner);
        self::assertInstanceOf(PersistentCollection::class, $owner->children);
        self::assertTrue($owner->children->isEmpty());
    }
    public function testExplicitlyScheduledChildInsertIsPreserved(): void
    {
        $owner = new RootRefreshOwner();
        $this->em->persist($owner);
        $this->em->flush();
        $child = new RootRefreshChild();
        $child->owner = $owner;
        $owner->children->add($child);
        $this->em->persist($child);
        $this->em->refresh($owner);
        self::assertTrue($owner->children->isEmpty());
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($child));
        $this->em->flush();
        self::assertNotNull($child->id);
        self::assertSame(1, $this->em->getRepository(RootRefreshChild::class)->count([]));
    }
    public function testRefreshDoesNotOverwriteDirtyEagerTarget(): void
    {
        $this->em->getClassMetadata(RootRefreshOwner::class)->associationMappings['favorite']->fetch = ORM\ClassMetadata::FETCH_EAGER;
        $owner = new RootRefreshOwner();
        $child = new RootRefreshChild();
        $owner->favorite = $child;
        $this->em->persist($child);
        $this->em->persist($owner);
        $this->em->flush();
        $owner->name = 'local owner';
        $child->name = 'local child';
        $this->em->refresh($owner);
        self::assertSame('database', $owner->name);
        self::assertSame($child, $owner->favorite);
        self::assertSame('local child', $child->name);
        $this->em->flush();
        $this->em->clear();
        self::assertSame('local child', $this->em->find(RootRefreshChild::class, $child->id)->name);
    }
    public function testAllStillCascadesPersistAndRemove(): void
    {
        $owner = new RootRefreshOwner();
        $child = new RootRefreshChild();
        $child->owner = $owner;
        $owner->children->add($child);
        $this->em->persist($owner);
        $this->em->flush();
        self::assertNotNull($child->id);
        self::assertSame(1, $this->em->getRepository(RootRefreshChild::class)->count([]));
        $this->em->remove($owner);
        $this->em->flush();
        self::assertSame(0, $this->em->getRepository(RootRefreshOwner::class)->count([]));
        self::assertSame(0, $this->em->getRepository(RootRefreshChild::class)->count([]));
    }
    public function testDetachDoesNotInitializeRootOrDetachItsManagedChild(): void
    {
        $owner = new RootRefreshOwner();
        $child = new RootRefreshChild();
        $child->owner = $owner;
        $owner->children->add($child);
        $this->em->persist($owner);
        $this->em->flush();
        $ownerId = $owner->id;
        $childId = $child->id;
        $this->em->clear();
        $reference = $this->em->getReference(RootRefreshOwner::class, $ownerId);
        $child = $this->em->find(RootRefreshChild::class, $childId);
        self::assertTrue($this->em->isUninitializedObject($reference));
        $this->em->detach($reference);
        self::assertTrue($this->em->isUninitializedObject($reference));
        self::assertFalse($this->em->contains($reference));
        self::assertTrue($this->em->contains($child));
    }
    public function testDetachOnlyCancelsRootInsertAndPreservesPendingChildInsert(): void
    {
        $owner = new RootRefreshOwner();
        $child = new RootRefreshChild();
        $owner->children->add($child);
        $this->em->persist($owner);
        $this->em->detach($owner);
        self::assertFalse($this->em->getUnitOfWork()->isScheduledForInsert($owner));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($child));
        $this->em->flush();
        self::assertNull($owner->id);
        self::assertNotNull($child->id);
        self::assertSame(0, $this->em->getRepository(RootRefreshOwner::class)->count([]));
        self::assertSame(1, $this->em->getRepository(RootRefreshChild::class)->count([]));
    }
    public function testDirectRefreshOfTransientRootStillThrows(): void
    {
        $this->expectException(ORMInvalidArgumentException::class);
        $this->em->refresh(new RootRefreshOwner());
    }
    public function testRefreshReplacesDetachedAssociationWithoutTraversingIt(): void
    {
        $child = new RootRefreshChild();
        $owner = new RootRefreshOwner();
        $this->em->persist($child);
        $this->em->persist($owner);
        $this->em->flush();
        $this->em->detach($child);
        $owner->favorite = $child;
        $this->em->refresh($owner);
        self::assertNull($owner->favorite);
        self::assertFalse($this->em->contains($child));
    }
}
