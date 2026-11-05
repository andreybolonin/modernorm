<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use ModernORM\EntityManager;
use ModernORM\Exception\CycleDetectedException;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use ModernORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_contract_nodes')]
final class ContractNode
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, cascade: ['persist', 'detach'])]
    public ?self $peer = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $ordinary = null;

    public function __construct(#[ORM\Column] public string $name = 'original')
    {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_explicit_nodes')]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
final class ExplicitNode
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, cascade: ['persist'])]
    public ?self $child = null;

    public function __construct(#[ORM\Column] public string $name = 'original')
    {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_required_nodes')]
final class RequiredNode
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    public self $peer;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_assigned_nodes')]
final class AssignedNode
{
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: false)]
    public self $peer;

    public function __construct(#[ORM\Id, ORM\Column] public int $id)
    {
        $this->peer = $this;
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_cycle_heads')]
final class CycleHead
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CycleTail::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    public CycleTail $tail;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_cycle_tails')]
final class CycleTail
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CycleHead::class, cascade: ['persist'])]
    public ?CycleHead $head = null;
}

final class ContractSqlRecorder extends AbstractLogger
{
    public array $sql = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class UnitOfWorkContractsTest extends TestCase
{
    private EntityManager $em;
    private ContractSqlRecorder $queries;

    protected function setUp(): void
    {
        $this->queries = new ContractSqlRecorder();
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->queries)]);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [
            ContractNode::class, ExplicitNode::class, RequiredNode::class,
            AssignedNode::class, CycleHead::class, CycleTail::class,
        ]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->queries->sql = [];
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_contract_nodes, modernorm_test_explicit_nodes, modernorm_test_required_nodes, modernorm_test_assigned_nodes, modernorm_test_cycle_heads, modernorm_test_cycle_tails CASCADE');
        $connection->close();
    }

    public function testDetachDiscardsDirtyStateWithoutCascadingOrdinaryAssociation(): void
    {
        $parent = new ContractNode('parent');
        $other = new ContractNode('other');
        $parent->ordinary = $other;
        $this->em->persist($other);
        $this->em->persist($parent);
        $this->em->flush();
        $parent->name = 'discarded';
        $this->em->detach($parent);
        self::assertFalse($this->em->contains($parent));
        self::assertSame(UnitOfWork::STATE_DETACHED, $this->em->getUnitOfWork()->getEntityState($parent));
        self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($parent));
        self::assertTrue($this->em->contains($other));
        $other->name = 'saved';
        $this->em->flush();
        $loaded = $this->em->find(ContractNode::class, $parent->id);
        self::assertNotSame($parent, $loaded);
        self::assertSame('parent', $loaded->name);
        self::assertSame($other, $loaded->ordinary);
        $this->em->clear();
        self::assertSame('saved', $this->em->find(ContractNode::class, $other->id)->name);
    }

    public function testDetachCancelsPendingInsert(): void
    {
        $node = new ContractNode();
        $this->em->persist($node);
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($node));
        $this->em->detach($node);
        self::assertFalse($this->em->getUnitOfWork()->isScheduledForInsert($node));
        self::assertFalse($this->em->contains($node));
        $this->em->flush();
        self::assertNull($node->id);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_contract_nodes'));
    }

    public function testDetachCancelsPendingDelete(): void
    {
        $node = new ContractNode();
        $this->em->persist($node);
        $this->em->flush();
        $this->em->remove($node);
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForDelete($node));
        $this->em->detach($node);
        self::assertFalse($this->em->getUnitOfWork()->isScheduledForDelete($node));
        self::assertSame(UnitOfWork::STATE_DETACHED, $this->em->getUnitOfWork()->getEntityState($node));
        self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($node));
        $this->em->flush();
        $loaded = $this->em->find(ContractNode::class, $node->id);
        self::assertNotNull($loaded);
        self::assertNotSame($node, $loaded);
    }

    public function testCascadeDetachTraversesCyclicGraphWithoutRecursion(): void
    {
        $first = new ContractNode('first');
        $second = new ContractNode('second');
        $first->peer = $second;
        $second->peer = $first;
        $this->em->persist($first);
        $this->em->flush();
        $first->name = $second->name = 'discarded';
        $this->queries->sql = [];
        $this->em->detach($first);
        self::assertFalse($this->em->contains($first));
        self::assertFalse($this->em->contains($second));
        $this->em->flush();
        self::assertSame([], $this->queries->sql);
        $loaded = $this->em->find(ContractNode::class, $first->id);
        self::assertSame('first', $loaded->name);
        self::assertSame('second', $loaded->peer->name);
        self::assertSame($loaded, $loaded->peer->peer);
    }

    public function testDetachOfRemovedRootAlsoCascadesToDirtyPeer(): void
    {
        $root = new ContractNode('root');
        $peer = new ContractNode('peer');
        $root->peer = $peer;
        $this->em->persist($root);
        $this->em->flush();
        $peer->name = 'discarded';
        $this->em->remove($root);
        $this->em->detach($root);
        self::assertFalse($this->em->contains($root));
        self::assertFalse($this->em->contains($peer));
        self::assertFalse($this->em->getUnitOfWork()->isScheduledForDelete($root));
        $this->queries->sql = [];
        $this->em->flush();
        self::assertSame([], $this->queries->sql);
        $loaded = $this->em->find(ContractNode::class, $root->id);
        self::assertSame('root', $loaded->name);
        self::assertSame('peer', $loaded->peer->name);
    }

    public function testExplicitTrackingRequiresPersistForEachFlush(): void
    {
        $node = new ExplicitNode();
        $this->em->persist($node);
        $this->em->flush();
        $node->name = 'unregistered';
        $this->queries->sql = [];
        $this->em->flush();
        self::assertSame([], $this->queries->sql);
        self::assertSame('original', $this->em->getUnitOfWork()->getOriginalEntityData($node)['name']);
        self::assertSame('original', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_explicit_nodes WHERE id = ?', [$node->id]));
        $this->em->persist($node);
        $node->name = 'registered';
        $this->em->flush();
        self::assertSame('registered', $this->em->getUnitOfWork()->getOriginalEntityData($node)['name']);
        $node->name = 'unregistered-again';
        $this->em->flush();
        $this->em->clear();
        self::assertSame('registered', $this->em->find(ExplicitNode::class, $node->id)->name);
    }

    public function testExplicitPersistCascadesDirtyCheckToExistingChild(): void
    {
        $parent = new ExplicitNode('parent');
        $child = new ExplicitNode('child');
        $parent->child = $child;
        $this->em->persist($parent);
        $this->em->flush();
        self::assertNotNull($child->id);
        $child->name = 'changed-child';
        $this->em->flush();
        self::assertSame('child', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_explicit_nodes WHERE id = ?', [$child->id]));
        $this->em->persist($parent);
        $this->em->flush();
        $this->em->clear();
        $loaded = $this->em->find(ExplicitNode::class, $parent->id);
        self::assertSame('parent', $loaded->name);
        self::assertSame('changed-child', $loaded->child->name);
        $childId = $child->id;
        $this->em->remove($loaded->child);
        $loaded->child = null;
        $this->em->persist($loaded);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(ExplicitNode::class, $childId));
        self::assertNull($this->em->find(ExplicitNode::class, $parent->id)->child);
    }

    public function testNullableCycleInsertsThenCompletesForeignKeysAndCanBeUnlinked(): void
    {
        $first = new ContractNode('first');
        $second = new ContractNode('second');
        $third = new ContractNode('third');
        $first->peer = $second;
        $second->peer = $third;
        $third->peer = $first;
        $this->em->persist($first);
        $this->em->flush();
        self::assertNotNull($first->id);
        self::assertNotNull($second->id);
        self::assertNotNull($third->id);
        self::assertNotEmpty(array_filter($this->queries->sql, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')));
        $this->em->clear();
        $loaded = $this->em->find(ContractNode::class, $first->id);
        self::assertSame('second', $loaded->peer->name);
        self::assertSame('third', $loaded->peer->peer->name);
        self::assertSame($loaded, $loaded->peer->peer->peer);
        $nodes = [$loaded, $loaded->peer, $loaded->peer->peer];
        foreach ($nodes as $node) {
            $node->peer = null;
        }
        $this->em->flush();
        foreach ($nodes as $node) {
            $this->em->remove($node);
        }
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_contract_nodes'));
    }

    public function testCycleBreaksAtNullableEdgeWhileRequiredForeignKeyIsSatisfied(): void
    {
        $head = new CycleHead();
        $tail = new CycleTail();
        $head->tail = $tail;
        $tail->head = $head;
        $this->em->persist($head);
        $this->em->flush();
        self::assertNotNull($head->id);
        self::assertNotNull($tail->id);
        $connection = $this->em->getConnection();
        self::assertSame($tail->id, (int) $connection->fetchOne('SELECT tail_id FROM modernorm_test_cycle_heads WHERE id = ?', [$head->id]));
        self::assertSame($head->id, (int) $connection->fetchOne('SELECT head_id FROM modernorm_test_cycle_tails WHERE id = ?', [$tail->id]));
        $this->em->clear();
        $loaded = $this->em->find(CycleHead::class, $head->id);
        self::assertSame($loaded, $loaded->tail->head);
    }

    public function testRequiredGeneratedIdCycleFailsBeforeInsertsAndClosesManager(): void
    {
        $first = new RequiredNode();
        $second = new RequiredNode();
        $first->peer = $second;
        $second->peer = $first;
        $this->em->persist($first);
        try {
            $this->em->flush();
            self::fail('Expected an unresolvable insertion cycle');
        } catch (CycleDetectedException $exception) {
            self::assertContains($first, $exception->getCycle());
            self::assertContains($second, $exception->getCycle());
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertNull($first->id);
        self::assertNull($second->id);
        self::assertSame([], array_values(array_filter($this->queries->sql, static fn (string $sql): bool => str_starts_with($sql, 'INSERT'))));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_required_nodes'));
    }

    public function testAssignedIdentifierAllowsRequiredSelfReference(): void
    {
        $node = new AssignedNode(42);
        $this->em->persist($node);
        $this->em->flush();
        self::assertSame(42, (int) $this->em->getConnection()->fetchOne('SELECT peer_id FROM modernorm_test_assigned_nodes WHERE id = 42'));
        $this->em->clear();
        $loaded = $this->em->find(AssignedNode::class, 42);
        self::assertSame($loaded, $loaded->peer);
        $this->em->remove($loaded);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(AssignedNode::class, 42));
    }
}
