<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use WeakReference;

#[ORM\Entity, ORM\Table(name: 'modernorm_worker_customers')]
final class WorkerCustomer
{
    #[ORM\Id, ORM\Column]
    public int $id = 1;

    #[ORM\Column]
    public string $name = 'customer';

    /** @var Collection<int, WorkerOrder> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: WorkerOrder::class)]
    public Collection $orders;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_worker_orders')]
final class WorkerOrder
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\Column] public string $value,
        #[ORM\ManyToOne(targetEntity: WorkerCustomer::class, batchSize: 4)]
        #[ORM\JoinColumn(nullable: false)] public WorkerCustomer $customer,
    ) {
    }
}

final class WorkerContextTest extends TestCase
{
    private EntityManager $em;
    private Connection $connection;
    private Configuration $configuration;
    private Statistics $statistics;

    private function manager(): EntityManager
    {
        return new EntityManager($this->connection, $this->configuration);
    }

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $this->configuration = ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter());
        $this->em = $this->manager();
        $schema = new SchemaTool($this->em);
        $metadata = array_map($this->em->getClassMetadata(...), [WorkerCustomer::class, WorkerOrder::class]);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $customer = new WorkerCustomer();
        foreach ([$customer, new WorkerOrder(1, 'first', $customer), new WorkerOrder(2, 'second', $customer)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->executeStatement('DROP TABLE IF EXISTS modernorm_worker_orders, modernorm_worker_customers');
        $this->connection->close();
    }

    public function testRepeatedPartialLazyFlushClearAndFailureRecoveryReleaseObjectGraphs(): void
    {
        $samples = [];
        $start = hrtime(true);
        for ($cycle = 1; $cycle <= 200; ++$cycle) {
            if ($cycle === 101) {
                $failed = $this->em->find(WorkerOrder::class, 2);
                $failed->value = 'rolled-back';
                $this->em->getEventManager()->addEventListener([Events::preUpdate], new class () {
                    public function preUpdate(): void
                    {
                        throw new RuntimeException('worker write failed');
                    }
                });
                try {
                    $this->em->flush();
                    self::fail('Expected worker failure.');
                } catch (RuntimeException $error) {
                    self::assertSame('worker write failed', $error->getMessage());
                }
                self::assertFalse($this->em->isOpen());
                self::assertSame(0, $this->em->getUnitOfWork()->size());
                self::assertSame('second', $this->connection->fetchOne('SELECT value FROM modernorm_worker_orders WHERE id = 2'));
                $oldContext = WeakReference::create($this->em);
                $failedReference = WeakReference::create($failed);
                unset($failed, $error);
                $this->em = $this->manager();
                gc_collect_cycles();
                self::assertNull($oldContext->get());
                self::assertNull($failedReference->get());
            }
            $mapping = new ResultSetMapping();
            $mapping->addEntityResult(WorkerOrder::class, 'o');
            $mapping->addFieldResult('o', 'id', 'id');
            $mapping->addFieldResult('o', 'value', 'value');
            $mapping->partialAliases['o'] = true;
            $order = $this->em->createNativeQuery('SELECT id, value FROM modernorm_worker_orders WHERE id = 1', $mapping)->getSingleResult();
            self::assertInstanceOf(WorkerOrder::class, $order);
            $order->value = 'cycle-' . $cycle;
            $peer = $this->em->find(WorkerOrder::class, 2);
            $customer = $peer->customer;
            self::assertSame('customer', $customer->name);
            self::assertSame($customer, $order->customer);
            if ($cycle % 5 === 0) {
                $members = $customer->orders->toArray();
                self::assertCount(2, $members);
                self::assertContains($order, $members);
                self::assertContains($peer, $members);
                unset($members);
            }
            $references = [WeakReference::create($order), WeakReference::create($peer), WeakReference::create($customer)];
            $this->statistics->reset();
            $this->em->flush();
            self::assertSame(1, $this->statistics->snapshot()->dml->update->count);
            self::assertSame('cycle-' . $cycle, $this->em->getUnitOfWork()->getOriginalEntityData($order)['value']);
            $this->statistics->reset();
            $this->em->flush();
            self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
            $this->em->clear();
            self::assertSame(0, $this->em->getUnitOfWork()->size());
            self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityUpdates());
            unset($order, $peer, $customer, $mapping);
            gc_collect_cycles();
            foreach ($references as $reference) {
                self::assertNull($reference->get());
            }
            if (in_array($cycle, [20, 100, 200], true)) {
                $samples[] = ['cycle' => $cycle, 'used_memory_bytes' => memory_get_usage(false)];
            }
        }
        self::assertSame('cycle-200', $this->connection->fetchOne('SELECT value FROM modernorm_worker_orders WHERE id = 1'));
        if ($path = getenv('MODERNORM_WORKER_EVIDENCE')) {
            file_put_contents($path, json_encode([
                'php' => PHP_VERSION, 'cycles' => 200, 'context_replacements_after_failure' => 1,
                'elapsed_seconds' => (hrtime(true) - $start) / 1e9, 'memory_samples' => $samples,
                'assertion' => 'All three per-cycle entities and the failed EntityManager were collectible; no false UPDATE on the second flush.',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
