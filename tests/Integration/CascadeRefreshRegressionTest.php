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
class CascadeRefreshOwner
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $name = 'database';
    /** @var Collection<int, CascadeRefreshChild>|array<CascadeRefreshChild> */
    #[ORM\OneToMany(targetEntity: CascadeRefreshChild::class, mappedBy: 'owner', cascade: ['persist', 'refresh'])]
    public Collection|array $children;
    /** @var Collection<int, CascadeRefreshTag> */
    #[ORM\ManyToMany(targetEntity: CascadeRefreshTag::class, cascade: ['persist', 'refresh'])]
    #[ORM\JoinTable(name: 'refresh_links')]
    public Collection $tags;
    #[ORM\ManyToOne(targetEntity: CascadeRefreshChild::class, cascade: ['refresh'])]
    public ?CascadeRefreshChild $favorite = null;
    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'refresh_children')]
class CascadeRefreshChild
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $name = 'database';
    #[ORM\ManyToOne(targetEntity: CascadeRefreshOwner::class, inversedBy: 'children', cascade: ['refresh'])]
    public ?CascadeRefreshOwner $owner = null;
}

#[ORM\Entity, ORM\Table(name: 'refresh_tags')]
class CascadeRefreshTag
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

final class CascadeRefreshRegressionTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'cascade_refresh_' . bin2hex(random_bytes(5));
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [CascadeRefreshOwner::class, CascadeRefreshChild::class, CascadeRefreshTag::class]);
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
        $owner = new CascadeRefreshOwner();
        $stored = $field === 'children' ? new CascadeRefreshChild() : new CascadeRefreshTag('stored');
        if ($stored instanceof CascadeRefreshChild) {
            $stored->owner = $owner;
        }
        $owner->$field->add($stored);
        $this->em->persist($owner);
        $this->em->flush();
        $ownerId = $owner->id;
        $storedId = $stored->id;
        $this->em->clear();
        $owner = $this->em->find(CascadeRefreshOwner::class, $ownerId);
        self::assertInstanceOf(PersistentCollection::class, $owner->$field);
        if ($initialize) {
            $owner->$field->toArray();
        }
        self::assertSame($initialize, $owner->$field->isInitialized());
        $new = $field === 'children' ? new CascadeRefreshChild() : new CascadeRefreshTag('new');
        if ($new instanceof CascadeRefreshChild) {
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
        $owner = new CascadeRefreshOwner();
        $original = new CascadeRefreshChild();
        $owner->favorite = $original;
        $this->em->persist($original);
        $this->em->persist($owner);
        $this->em->flush();
        $new = new CascadeRefreshChild();
        $owner->favorite = $new;
        $this->em->refresh($owner);
        self::assertSame($original, $owner->favorite);
        self::assertFalse($this->em->contains($new));
        $this->em->flush();
        self::assertSame(1, $this->em->getRepository(CascadeRefreshChild::class)->count([]));
    }
    public function testRefreshStillRefreshesManagedChildrenAndHandlesCycles(): void
    {
        $owner = new CascadeRefreshOwner();
        $stored = new CascadeRefreshChild();
        $stored->owner = $owner;
        $owner->children->add($stored);
        $this->em->persist($owner);
        $this->em->flush();
        $stored->name = 'local child';
        $owner->name = 'local owner';
        $owner->children->add(new CascadeRefreshChild());
        $this->em->refresh($owner);
        self::assertSame('database', $stored->name);
        self::assertSame('database', $owner->name);
        self::assertSame($owner, $stored->owner);
        self::assertSame([$stored], $owner->children->toArray());
        $this->em->flush();
    }
    public function testPlainArrayWithTransientChildCanBeRefreshed(): void
    {
        $owner = new CascadeRefreshOwner();
        $this->em->persist($owner);
        $this->em->flush();
        $owner->children = [new CascadeRefreshChild()];
        $this->em->refresh($owner);
        self::assertInstanceOf(PersistentCollection::class, $owner->children);
        self::assertTrue($owner->children->isEmpty());
    }
    public function testExplicitlyScheduledChildInsertIsPreserved(): void
    {
        $owner = new CascadeRefreshOwner();
        $this->em->persist($owner);
        $this->em->flush();
        $child = new CascadeRefreshChild();
        $child->owner = $owner;
        $owner->children->add($child);
        $this->em->persist($child);
        $this->em->refresh($owner);
        self::assertTrue($owner->children->isEmpty());
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($child));
        $this->em->flush();
        self::assertNotNull($child->id);
        self::assertSame(1, $this->em->getRepository(CascadeRefreshChild::class)->count([]));
    }
    public function testDirectRefreshOfTransientRootStillThrows(): void
    {
        $this->expectException(ORMInvalidArgumentException::class);
        $this->em->refresh(new CascadeRefreshOwner());
    }
    public function testDetachedAssociationStillThrows(): void
    {
        $child = new CascadeRefreshChild();
        $owner = new CascadeRefreshOwner();
        $this->em->persist($child);
        $this->em->persist($owner);
        $this->em->flush();
        $this->em->detach($child);
        $owner->favorite = $child;
        $this->expectException(ORMInvalidArgumentException::class);
        $this->em->refresh($owner);
    }
}
