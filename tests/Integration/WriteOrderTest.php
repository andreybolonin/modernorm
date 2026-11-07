<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_write_order_nodes')]
class WriteOrderNode
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $parent = null;
    #[ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(onDelete: 'CASCADE')]
    public ?self $cascadePeer = null;
    #[ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?self $setNullPeer = null;
    public function __construct(#[ORM\Column] public string $name)
    {
    }
}

final class WriteOrderTest extends TestCase
{
    private EntityManager $em;
    private array $events = [];

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $schema = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(WriteOrderNode::class)];
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $listener = new class ($this->events) {
            public function __construct(public array &$events)
            {
            }
            public function postPersist(\ModernORM\Event\PostPersistEventArgs $args): void
            {
                $this->events[] = ['insert', $args->getObject()->name];
            }
            public function postRemove(\ModernORM\Event\PostRemoveEventArgs $args): void
            {
                $this->events[] = ['delete', $args->getObject()->name];
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist, Events::postRemove], $listener);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_write_order_nodes CASCADE');
        $this->em->getConnection()->close();
    }

    public function testInsertDependencyControlsIdAvailabilityAndCallbackOrder(): void
    {
        $parent = new WriteOrderNode('parent');
        $child = new WriteOrderNode('child');
        $child->parent = $parent;
        $this->em->persist($child);
        $this->em->persist($parent);
        $this->em->flush();
        self::assertSame([['insert', 'parent'], ['insert', 'child']], $this->events);
        self::assertSame($parent->id, (int) $this->em->getConnection()->fetchOne('SELECT parent_id FROM modernorm_test_write_order_nodes WHERE id = ?', [$child->id]));
        $this->em->flush();
        self::assertCount(2, $this->events);
    }

    public function testDeleteDependencyOverridesSchedulingOrder(): void
    {
        $parent = new WriteOrderNode('parent');
        $child = new WriteOrderNode('child');
        $child->parent = $parent;
        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->flush();
        $this->events = [];
        $this->em->remove($parent);
        $this->em->remove($child);
        $this->em->flush();
        self::assertSame([['delete', 'child'], ['delete', 'parent']], $this->events);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_write_order_nodes'));
    }

    public function testCascadeCyclePreservesRepresentativeAndCallbackOrder(): void
    {
        $a = new WriteOrderNode('a');
        $b = new WriteOrderNode('b');
        $a->cascadePeer = $b;
        $b->cascadePeer = $a;
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();
        $this->events = [];
        $this->em->remove($a);
        $this->em->remove($b);
        $this->em->flush();
        self::assertSame([['delete', 'b'], ['delete', 'a']], $this->events);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_write_order_nodes'));
    }

    public function testSetNullKeepsSchedulingOrderAndLetsDatabaseClearForeignKey(): void
    {
        $parent = new WriteOrderNode('parent');
        $child = new WriteOrderNode('child');
        $child->setNullPeer = $parent;
        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->flush();
        $this->events = [];
        $this->em->remove($parent);
        $this->em->remove($child);
        $this->em->flush();
        self::assertSame([['delete', 'parent'], ['delete', 'child']], $this->events);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_write_order_nodes'));
    }
}
