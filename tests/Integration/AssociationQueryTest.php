<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\PersistentCollection;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: '`Owners`')]
final class QueryOwner
{
    #[ORM\Id, ORM\Column(name: '`OwnerID`', type: 'integer')]
    public int $id;
    /** @var Collection<int, QueryEntry> */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: QueryEntry::class)]
    #[ORM\OrderBy(['position' => 'DESC'])]
    public Collection $entries;
    /** @var Collection<int, QueryTag> */
    #[ORM\ManyToMany(targetEntity: QueryTag::class, inversedBy: 'owners')]
    #[ORM\JoinTable(name: '`OwnerTags`')]
    #[ORM\JoinColumn(name: '`OwnerKey`', referencedColumnName: '`OwnerID`')]
    #[ORM\InverseJoinColumn(name: '`TagKey`', referencedColumnName: '`TagID`')]
    public Collection $tags;
    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'entries'), ORM\SoftDelete]
final class QueryEntry
{
    use \ModernORM\Behavior\SoftDeleteTrait;
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\ManyToOne(targetEntity: QueryOwner::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: '`OwnerRef`', referencedColumnName: '`OwnerID`', nullable: false)]
    public QueryOwner $owner;
    #[ORM\Column(type: 'string')]
    public string $status = 'active';
    #[ORM\Column(name: '`Position`', type: 'integer')]
    public int $position = 1;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $note = null;
}

#[ORM\Entity, ORM\Table(name: 'tags'), ORM\SoftDelete]
final class QueryTag
{
    use \ModernORM\Behavior\SoftDeleteTrait;
    #[ORM\Id, ORM\Column(name: '`TagID`', type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'string')]
    public string $label;
    /** @var Collection<int, QueryOwner> */
    #[ORM\ManyToMany(targetEntity: QueryOwner::class, mappedBy: 'tags')]
    public Collection $owners;
    public function __construct()
    {
        $this->owners = new ArrayCollection();
    }
}

final class AssociationSqlRecorder extends AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    /** @return list<string> */
    public function statements(): array
    {
        return $this->sql;
    }
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class AssociationQueryTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    private QueryOwner $owner;
    private AssociationSqlRecorder $recorder;

    protected function setUp(): void
    {
        $this->schema = 'association_query_' . bin2hex(random_bytes(5));
        $this->recorder = new AssociationSqlRecorder();
        $config = new Configuration();
        $config->setMiddlewares([new Middleware($this->recorder)]);
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $config);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $classes = array_map($this->em->getClassMetadata(...), [QueryOwner::class, QueryEntry::class, QueryTag::class]);
        foreach ($classes as $class) {
            $class->table['schema'] = $this->schema;
        }
        $mapping = $classes[0]->associationMappings['tags'];
        assert($mapping instanceof ORM\ManyToManyOwningSideMapping);
        $mapping->joinTable->schema = $this->schema;
        (new SchemaTool($this->em))->createSchema($classes);
        $db->executeStatement('INSERT INTO ' . $this->schema . '."Owners" ("OwnerID") VALUES (1), (2)');
        $db->executeStatement('INSERT INTO ' . $this->schema . '.entries (id, "OwnerRef", status, "Position", note, deleted_at) VALUES (11,1,\'active\',1,NULL,NULL),(12,1,\'active\',1,\'present\',NULL),(13,1,\'inactive\',2,NULL,NULL),(14,1,\'active\',3,NULL,NOW()),(21,2,\'active\',1,NULL,NULL)');
        $db->executeStatement('INSERT INTO ' . $this->schema . '.tags ("TagID", label, deleted_at) VALUES (31,\'alpha\',NULL),(32,\'beta\',NULL),(33,\'hidden\',NOW()),(34,\'foreign\',NULL)');
        $db->executeStatement('INSERT INTO ' . $this->schema . '."OwnerTags" ("OwnerKey", "TagKey") VALUES (1,31),(1,32),(1,33),(2,31),(2,34)');
        $this->owner = $this->em->find(QueryOwner::class, 1);
        $this->recorder->sql = [];
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $db = $this->em->getConnection();
            $db->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $db->close();
        }
    }

    public function testCountExistsAndFiltersDoNotLoadCollections(): void
    {
        $query = $this->em->association($this->owner, 'entries');
        self::assertSame([], $this->recorder->statements(), 'Creating a query performs no SQL.');
        self::assertSame(3, $query->count());
        self::assertTrue($query->exists(11));
        self::assertFalse($query->exists(14), 'Soft-deleted targets are excluded.');
        self::assertFalse($query->exists(21), 'Foreign owners are excluded.');
        $active = $query->where('status', 'active');
        self::assertSame(2, $active->count());
        self::assertFalse($active->exists(13));
        self::assertSame(3, $query->count(), 'Filtering is immutable.');
        self::assertSame(1, $active->where('note', null)->count());
        self::assertSame(1, $active->where('note', null, '!=')->count());
        self::assertSame(1, $query->where('position', 1, '>')->count());
        self::assertSame(0, $query->where('status', "active' OR 1=1 --")->count());
        self::assertInstanceOf(PersistentCollection::class, $this->owner->entries);
        self::assertFalse($this->owner->entries->isInitialized());
        self::assertStringContainsString('SELECT COUNT(*)', implode('\n', $this->recorder->statements()));
        self::assertStringContainsString('SELECT EXISTS', implode('\n', $this->recorder->statements()));
    }

    public function testKeysetNavigationWithTiesAndImmutableOrdering(): void
    {
        $query = $this->em->association($this->owner, 'entries');
        $ordered = $query->orderBy('position');
        $page = $ordered->page(limit: 2);
        self::assertSame([11, 12], array_map(static fn ($item) => $item->id, $page->getItems()));
        self::assertTrue($page->hasNextPage());
        $next = $ordered->page(limit: 2, after: $page->getNextCursorAsString());
        self::assertSame([13], array_map(static fn ($item) => $item->id, $next->getItems()));
        self::assertSame(3, $next->getTotalCount());
        $previous = $ordered->page(limit: 2, after: $next->getPreviousCursor());
        self::assertSame([11, 12], array_map(static fn ($item) => $item->id, $previous->getItems()));
        $descending = $ordered->orderBy('position', 'DESC')->addOrderBy('id', 'DESC')->page(3);
        self::assertSame([13, 12, 11], array_map(static fn ($item) => $item->id, $descending->getItems()));
        self::assertSame(13, $query->page(1)->getItems()[0]->id);
        self::assertInstanceOf(PersistentCollection::class, $this->owner->entries);
        self::assertFalse($this->owner->entries->isInitialized());
        self::assertStringNotContainsString('OFFSET', implode('\n', $this->recorder->sql));
    }

    public function testDirtyCollectionAndIdentityMapHaveExplicitDatabaseSemantics(): void
    {
        $entry = $this->em->find(QueryEntry::class, 11);
        $entry->status = 'local change';
        $this->owner->entries->removeElement($entry);
        $new = new QueryEntry();
        $new->id = 99;
        $new->owner = $this->owner;
        $this->owner->entries->add($new);
        $this->em->persist($new);
        $this->em->remove($entry);
        $this->recorder->sql = [];
        $query = $this->em->association($this->owner, 'entries')->where('status', 'active');
        self::assertSame(2, $query->count());
        self::assertTrue($query->exists(11));
        self::assertFalse($query->exists(99));
        self::assertSame($entry, $query->page(1)->getItems()[0]);
        self::assertSame('local change', $entry->status);
        self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE)\b/', implode('\n', $this->recorder->sql));
    }

    public function testManyToManyBothSidesRemainScopedAndLazy(): void
    {
        $mapping = $this->em->getClassMetadata(QueryOwner::class)->associationMappings['tags'];
        assert($mapping instanceof ORM\ManyToManyOwningSideMapping);
        $mapping->inversedBy = null; // Owning-side queries do not require an inverse property.
        $query = $this->em->association($this->owner, 'tags');
        self::assertSame(2, $query->count());
        self::assertFalse($query->exists(33));
        self::assertFalse($query->exists(34));
        self::assertSame(1, $query->where('label', 'alpha')->count());
        $first = $query->orderBy('label')->page(1);
        self::assertSame(31, $first->getItems()[0]->id);
        self::assertSame(32, $query->orderBy('label')->page(1, $first->getNextCursor())->getItems()[0]->id);
        $tag = $first->getItems()[0];
        $inverse = $this->em->association($tag, 'owners');
        self::assertSame(2, $inverse->count());
        self::assertTrue($inverse->exists(2));
        self::assertFalse($inverse->exists(3));
        self::assertSame(2, $inverse->page(10)->getTotalCount());
        self::assertFalse($tag->owners->isInitialized());
        self::assertInstanceOf(PersistentCollection::class, $this->owner->tags);
        self::assertFalse($this->owner->tags->isInitialized());
    }

    public function testCurrentTransactionAndEmptyPages(): void
    {
        $db = $this->em->getConnection();
        $query = $this->em->association($this->owner, 'entries');
        $db->beginTransaction();
        try {
            $db->executeStatement('UPDATE ' . $this->schema . '.entries SET deleted_at = NOW() WHERE id = 11');
            self::assertSame(2, $query->count());
            self::assertFalse($query->exists(11));
        } finally {
            $db->rollBack();
        }
        self::assertSame(3, $query->count());
        $empty = $query->where('position', 99);
        self::assertSame(0, $empty->count());
        self::assertFalse($empty->exists(11));
        $page = $empty->page(10);
        self::assertSame([], $page->getItems());
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
        self::assertSame(0, $page->getTotalCount());
    }

    public function testManagedReferenceDoesNotLoadOwnerAndDecoratorForwardsApi(): void
    {
        $this->em->clear();
        $owner = $this->em->getReference(QueryOwner::class, 1);
        $reflection = new \ReflectionClass($owner);
        self::assertTrue($reflection->isUninitializedLazyObject($owner));
        $decorator = new class ($this->em) extends \ModernORM\Decorator\EntityManagerDecorator {
        };
        $this->recorder->sql = [];
        $query = $decorator->association($owner, 'tags');
        self::assertSame([], $this->recorder->statements());
        self::assertSame(2, $query->count());
        self::assertTrue($reflection->isUninitializedLazyObject($owner));
    }

    public function testNewAndDetachedOwnersAreRejectedWithoutSql(): void
    {
        foreach ([new QueryOwner(), clone $this->owner] as $owner) {
            try {
                $this->em->association($owner, 'entries');
                self::fail('Expected owner validation failure.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('persisted owner', $error->getMessage());
            }
        }
        $new = new QueryOwner();
        $new->id = 3;
        $this->em->persist($new);
        $this->expectException(\InvalidArgumentException::class);
        $this->em->association($new, 'entries');
    }

    public function testToOneAndUnsafeExpressionsAreRejected(): void
    {
        $entry = $this->em->find(QueryEntry::class, 11);
        try {
            $this->em->association($entry, 'owner');
            self::fail('Expected to-many validation failure.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('to-many', $error->getMessage());
        }
        $query = $this->em->association($this->owner, 'entries');
        foreach ([static fn () => $query->where('id OR TRUE', 1), static fn () => $query->where('id', 1, '= 1 OR'), static fn () => $query->orderBy('id', 'DESC NULLS FIRST'), static fn () => $query->exists([]), static fn () => $query->where('note', null, '>'), static fn () => $query->page(0), static fn () => $query->orderBy('note')->page(1)] as $invalid) {
            try {
                $invalid();
                self::fail('Expected query validation failure.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
