<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_batch_currencies'), ORM\Cacheable, ORM\HasLifecycleCallbacks]
final class L2BatchCurrency
{
    public static array $loaded = [];
    public static ?\Closure $onLoad = null;
    #[ORM\Id, ORM\Column(length: 3)] public string $code;
    #[ORM\Column] public string $name;
    #[ORM\Column(type: 'datetime', nullable: true)] public ?\DateTime $changedAt = null;
    #[ORM\PostLoad] public function loaded(): void
    {
        self::$loaded[] = $this->code;
        if (self::$onLoad !== null) {
            (self::$onLoad)($this);
        }
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_batch_customers')]
final class L2BatchCustomer
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_batch_documents')]
final class L2BatchDocument
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: L2BatchCurrency::class, batchSize: 2)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)] public L2BatchCurrency $currency;
    #[ORM\ManyToOne(targetEntity: L2BatchCustomer::class, batchSize: 2)]
    #[ORM\JoinColumn(nullable: false)] public L2BatchCustomer $customer;
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: L2BatchLine::class, indexBy: 'code', batchSize: 2)]
    #[ORM\OrderBy(['position' => 'ASC'])] public Collection $lines;
    #[ORM\ManyToMany(targetEntity: L2BatchTag::class, inversedBy: 'documents', indexBy: 'name', batchSize: 2)]
    #[ORM\JoinTable(name: 'modernorm_test_l2_batch_tags_link', joinColumns: [new ORM\JoinColumn(name: 'document_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'tag_id')])]
    #[ORM\OrderBy(['position' => 'ASC'])] public Collection $tags;
    #[ORM\ManyToMany(targetEntity: L2BatchCurrency::class, indexBy: 'code', batchSize: 2)]
    #[ORM\JoinTable(name: 'modernorm_test_l2_batch_currencies_link', joinColumns: [new ORM\JoinColumn(name: 'document_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code')])]
    #[ORM\OrderBy(['code' => 'ASC'])] public Collection $currencies;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_batch_documents')]
final class L2BatchSingleDocument
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: L2BatchCurrency::class, batchSize: 1)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code')] public L2BatchCurrency $currency;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_batch_lines')]
final class L2BatchLine
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $code;
    #[ORM\Column] public int $position;
    #[ORM\Column] public string $name;
    #[ORM\ManyToOne(targetEntity: L2BatchDocument::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)] public L2BatchDocument $document;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_batch_tags')]
final class L2BatchTag
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Column] public int $position;
    #[ORM\ManyToMany(targetEntity: L2BatchDocument::class, mappedBy: 'tags', batchSize: 2)] public Collection $documents;
}

final class L2BatchRecorder extends AbstractLogger
{
    public array $sql = [];
    public array $parameters = [];
    public ?\Closure $onSelect = null;
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
            $this->parameters[] = $context['params'] ?? [];
        }
        if ($this->onSelect !== null && isset($context['sql']) && str_starts_with($context['sql'], 'SELECT')) {
            $callback = $this->onSelect;
            $this->onSelect = null;
            $callback();
        }
    }
}

final class L2BatchPool extends ArrayAdapter
{
    public ?\Closure $beforePut = null;
    public bool $failPayloads = false;
    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        if ($item->get() instanceof \ModernORM\Cache\ReadOnlyEntityCacheEntry) {
            if ($this->failPayloads) {
                return false;
            }
            if ($this->beforePut !== null) {
                $callback = $this->beforePut;
                $this->beforePut = null;
                $callback();
            }
        }
        return parent::save($item);
    }
}

final class L2BatchOperationRecorder implements \ModernORM\Statistics\OperationObserver
{
    public array $events = [];
    public ?\Closure $onEvent = null;
    public function completed(\ModernORM\Statistics\OperationEvent $event): void
    {
        $this->events[] = $event;
        if ($this->onEvent !== null) {
            ($this->onEvent)($event);
        }
    }
}

final class L2BatchFetchingTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private StatisticsCacheLogger $cacheLogger;
    private L2BatchRecorder $recorder;
    private L2BatchPool $pool;
    private array $metadata;
    private L2BatchOperationRecorder $operationObserver;

    private function manager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setSecondLevelCache($this->pool, logger: $this->cacheLogger);
        return new EntityManager($this->em->getConnection(), $config);
    }

    protected function setUp(): void
    {
        L2BatchCurrency::$loaded = [];
        L2BatchCurrency::$onLoad = null;
        $this->operationObserver = new L2BatchOperationRecorder();
        $this->statistics = new Statistics(enabled: true, observer: $this->operationObserver);
        $this->cacheLogger = new StatisticsCacheLogger();
        $this->recorder = new L2BatchRecorder();
        $this->pool = new L2BatchPool(storeSerialized: true);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics), new LoggingMiddleware($this->recorder)]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setSecondLevelCache($this->pool, logger: $this->cacheLogger);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
            'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $this->metadata = array_map($this->em->getClassMetadata(...), [L2BatchCurrency::class, L2BatchCustomer::class, L2BatchDocument::class, L2BatchLine::class, L2BatchTag::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($this->metadata);
        $schema->createSchema($this->metadata);
        $connection = $this->em->getConnection();
        foreach (['USD', 'EUR', 'GBP'] as $index => $code) {
            $id = $index + 1;
            $connection->insert('modernorm_test_l2_batch_currencies', ['code' => $code, 'name' => $code, 'changedAt' => '2026-01-01 00:00:00']);
            $connection->insert('modernorm_test_l2_batch_customers', ['id' => $id, 'name' => 'customer-' . $id]);
            $connection->insert('modernorm_test_l2_batch_documents', ['id' => $id, 'currency_code' => $code, 'customer_id' => $id]);
            $connection->insert('modernorm_test_l2_batch_tags', ['id' => $id, 'name' => 'tag-' . $id, 'position' => $id]);
        }
        foreach ([[11, 1, 'a', 2], [12, 1, 'b', 1], [31, 3, 'c', 1]] as [$id, $owner, $code, $position]) {
            $connection->insert('modernorm_test_l2_batch_lines', ['id' => $id, 'document_id' => $owner, 'code' => $code, 'position' => $position, 'name' => 'original']);
        }
        foreach ([[1, 1], [1, 2], [2, 1], [3, 3]] as [$document, $tag]) {
            $connection->insert('modernorm_test_l2_batch_tags_link', ['document_id' => $document, 'tag_id' => $tag]);
        }
        foreach ([[1, 'USD'], [1, 'EUR'], [2, 'EUR'], [3, 'GBP']] as [$document, $currency]) {
            $connection->insert('modernorm_test_l2_batch_currencies_link', ['document_id' => $document, 'currency_code' => $currency]);
        }
    }

    protected function tearDown(): void
    {
        L2BatchCurrency::$onLoad = null;
        L2BatchCurrency::$loaded = [];
        $this->pool->beforePut = null;
        $this->pool->failPayloads = false;
        $this->recorder->onSelect = null;
        $this->operationObserver->onEvent = null;
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        (new SchemaTool($this->em))->dropSchema($this->metadata);
        $connection->close();
    }

    private function reset(): void
    {
        L2BatchCurrency::$loaded = [];
        $this->operationObserver->events = [];
        $this->statistics->reset();
        $this->recorder->sql = [];
        $this->recorder->parameters = [];
        $this->cacheLogger->reset();
    }

    private function documents(): array
    {
        return $this->em->getRepository(L2BatchDocument::class)->findBy([], ['id' => 'ASC']);
    }

    private function warmCurrencies(): void
    {
        $warm = $this->manager();
        foreach (['USD', 'EUR', 'GBP'] as $code) {
            $warm->find(L2BatchCurrency::class, $code);
        }
        $warm->clear();
    }

    public static function cacheStates(): array
    {
        return ['cold' => [false], 'warm' => [true]];
    }

    #[DataProvider('cacheStates')]
    public function testCachedAndUncachedBatchesCoexist(bool $warm): void
    {
        if ($warm) {
            $this->warmCurrencies();
        }
        $documents = $this->documents();
        $this->reset();
        self::assertSame('customer-1', $documents[0]->customer->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->customer));
        self::assertTrue($this->em->isUninitializedObject($documents[2]->customer));
        self::assertSame($documents[1]->customer, $this->em->find(L2BatchCustomer::class, 2));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertStringContainsString(' IN (', $this->recorder->sql[0]);
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->currency));
        self::assertTrue($this->em->isUninitializedObject($documents[2]->currency));
        self::assertSame($warm ? 1 : 2, $this->statistics->snapshot()->sqlCount);
        self::assertSame($warm ? 2 : 0, $this->cacheLogger->getHitCount());
        self::assertSame($warm ? 0 : 2, $this->cacheLogger->getMissCount());
        foreach ($documents as $document) {
            $document->currency->name;
            $document->customer->name;
        }
        self::assertSame($warm ? 2 : 4, $this->statistics->snapshot()->sqlCount);
        self::assertSame($warm ? 3 : 0, $this->cacheLogger->getHitCount());
        self::assertSame($warm ? 0 : 3, $this->cacheLogger->getPutCount());
        $batch = $this->statistics->snapshot()->batch;
        self::assertSame(4, $batch->count);
        self::assertSame(6, $batch->selectedReferences);
        self::assertSame(0, $batch->selectedCollections);
        self::assertSame($warm ? 3 : 0, $batch->cacheHits);
        self::assertSame($warm ? 0 : 3, $batch->cacheMisses);
        self::assertSame($warm ? 0 : 3, $batch->cachePuts);
        $events = array_values(array_filter($this->operationObserver->events, static fn ($event) => $event->kind === \ModernORM\Statistics\OperationKind::BATCH));
        self::assertCount(4, $events);
        self::assertSame([2, 2, 1, 1], array_column($events, 'selectedReferences'));
        foreach ($documents as $document) {
            self::assertSame($document->currency, $this->em->find(L2BatchCurrency::class, $document->currency->code));
        }
    }

    public function testCachedReadsBypassL2InTransactionWithoutDisablingUncachedBatching(): void
    {
        $this->warmCurrencies();
        $documents = $this->documents();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("UPDATE modernorm_test_l2_batch_currencies SET name = 'private' WHERE code = 'USD'");
        $this->reset();
        self::assertSame('customer-1', $documents[0]->customer->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->customer));
        self::assertSame('private', $documents[0]->currency->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->currency));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        self::assertSame(0, $this->cacheLogger->getHitCount());
        self::assertSame(0, $this->cacheLogger->getPutCount());
        $connection->rollBack();
        self::assertSame('USD', $this->manager()->find(L2BatchCurrency::class, 'USD')->name);
    }

    public function testUncachedCollectionsBatchWhileCachedManyToManyKeepsItsSqlLoader(): void
    {
        $this->warmCurrencies();
        $documents = $this->documents();
        $this->reset();
        self::assertSame(['b', 'a'], $documents[0]->lines->getKeys());
        self::assertTrue($documents[1]->lines->isInitialized());
        self::assertTrue($documents[1]->lines->isEmpty());
        self::assertSame($documents[0], $documents[0]->lines->first()->document);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame(['tag-1', 'tag-2'], $documents[0]->tags->getKeys());
        self::assertTrue($documents[1]->tags->isInitialized());
        self::assertSame($documents[0]->tags->get('tag-1'), $documents[1]->tags->get('tag-1'));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        foreach ($documents as $document) {
            self::assertFalse($document->currencies->isInitialized());
        }
        $batch = $this->statistics->snapshot()->batch;
        self::assertSame(2, $batch->count);
        self::assertSame(4, $batch->selectedCollections);
        self::assertSame(1, $batch->byKind['one_to_many']->count);
        self::assertSame(1, $batch->byKind['many_to_many']->count);
        $this->reset();
        self::assertSame(['EUR', 'USD'], $documents[0]->currencies->getKeys());
        self::assertFalse($documents[1]->currencies->isInitialized());
        self::assertSame($documents[0]->currency, $documents[0]->currencies->get('USD'));
        foreach ($documents as $document) {
            $document->currencies->toArray();
        }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
        self::assertSame(['cached_target' => 3], $this->statistics->snapshot()->batch->fallbackCounts);
        self::assertSame(0, $this->cacheLogger->getHitCount());
        self::assertSame(0, $this->cacheLogger->getPutCount());
    }

    public function testDirtyEntitiesAndPendingCollectionChangesSurviveBatchingWithL2(): void
    {
        $documents = $this->documents();
        $line = $this->em->find(L2BatchLine::class, 11);
        $line->name = 'local';
        $pending = new L2BatchLine();
        $pending->id = 99;
        $pending->document = $documents[0];
        $pending->code = 'pending';
        $pending->position = 3;
        $pending->name = 'added';
        $this->em->persist($pending);
        $documents[0]->lines->add($pending);
        $tag = new L2BatchTag();
        $tag->id = 99;
        $tag->name = 'pending';
        $tag->position = 99;
        $this->em->persist($tag);
        $documents[0]->tags->add($tag);
        $this->reset();
        self::assertSame($line, $documents[0]->lines->get('a'));
        self::assertSame('local', $line->name);
        self::assertTrue($documents[0]->lines->contains($pending));
        self::assertTrue($documents[0]->tags->contains($tag));
        self::assertSame($documents[0]->tags->get('tag-1'), $documents[1]->tags->get('tag-1'));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame('local', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_l2_batch_lines WHERE id = 11'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_l2_batch_lines WHERE id = 99'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_l2_batch_tags_link WHERE document_id = 1 AND tag_id = 99'));
    }

    public function testManagersWithSharedL2KeepIndependentBatchQueuesAndIdentities(): void
    {
        $this->warmCurrencies();
        $documents = $this->documents();
        $other = $this->manager();
        $peers = $other->getRepository(L2BatchDocument::class)->findBy([], ['id' => 'ASC']);
        $this->reset();
        self::assertSame('customer-1', $peers[0]->customer->name);
        self::assertTrue($this->em->isUninitializedObject($documents[0]->customer));
        self::assertFalse($other->isUninitializedObject($peers[1]->customer));
        self::assertSame(['b', 'a'], $peers[0]->lines->getKeys());
        self::assertFalse($documents[0]->lines->isInitialized());
        self::assertTrue($peers[1]->lines->isInitialized());
        self::assertSame('USD', $peers[0]->currency->name);
        self::assertTrue($this->em->isUninitializedObject($documents[0]->currency));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        $other->clear();
        $this->reset();
        self::assertSame('customer-1', $documents[0]->customer->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->customer));
        self::assertNotSame($peers[0]->customer, $documents[0]->customer);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }
    public static function mixedTriggers(): array
    {
        return [[0], [1]];
    }

    #[DataProvider('mixedTriggers')]
    public function testOnlyMissesAreSelectedWhenTriggerIsAHitOrMiss(int $trigger): void
    {
        $this->manager()->find(L2BatchCurrency::class, 'USD');
        $documents = $this->documents();
        $this->reset();
        self::assertSame($trigger === 0 ? 'USD' : 'EUR', $documents[$trigger]->currency->name);
        self::assertFalse($this->em->isUninitializedObject($documents[0]->currency));
        self::assertFalse($this->em->isUninitializedObject($documents[1]->currency));
        self::assertTrue($this->em->isUninitializedObject($documents[2]->currency));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame(1, $this->cacheLogger->getHitCount());
        self::assertSame(1, $this->cacheLogger->getMissCount());
        self::assertSame(1, $this->cacheLogger->getPutCount());
        self::assertSame(['EUR'], array_values($this->recorder->parameters[0]));
        self::assertEqualsCanonicalizing(['USD', 'EUR'], L2BatchCurrency::$loaded);
        self::assertSame($documents[1]->currency, $this->em->find(L2BatchCurrency::class, 'EUR'));
    }

    public function testInvalidPeerEntryBecomesAMissInTheSameSqlBatch(): void
    {
        $this->warmCurrencies();
        $region = $this->em->getUnitOfWork()->getEntityPersister(L2BatchCurrency::class)->getCacheRegion();
        $key = new \ModernORM\Cache\EntityCacheKey(L2BatchCurrency::class, ['code' => 'EUR']);
        $entry = $region->get($key);
        $data = $entry->data;
        $data['code'] = 'USD';
        $region->put($key, new \ModernORM\Cache\ReadOnlyEntityCacheEntry(L2BatchCurrency::class, $data, $entry->mappingSignature));
        $documents = $this->documents();
        $this->reset();
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame('EUR', $documents[1]->currency->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame(['EUR'], array_values($this->recorder->parameters[0]));
        self::assertSame(1, $this->cacheLogger->getHitCount());
        self::assertSame(1, $this->cacheLogger->getMissCount());
    }

    public static function missingTargets(): array
    {
        return ['trigger' => ['USD'], 'peer' => ['EUR']];
    }

    #[DataProvider('missingTargets')]
    public function testMissingTriggerOrPeerDoesNotCreateNegativeCache(string $missing): void
    {
        $documents = $this->documents();
        $connection = $this->em->getConnection();
        foreach ($connection->createSchemaManager()->listTableForeignKeys('modernorm_test_l2_batch_documents') as $fk) {
            if ($fk->getForeignTableName() === 'modernorm_test_l2_batch_currencies') {
                $connection->executeStatement('ALTER TABLE modernorm_test_l2_batch_documents DROP CONSTRAINT ' . $connection->quoteIdentifier($fk->getName()));
            }
        }
        $connection->delete('modernorm_test_l2_batch_currencies_link', ['currency_code' => $missing]);
        $connection->delete('modernorm_test_l2_batch_currencies', ['code' => $missing]);
        $this->reset();
        try {
            $documents[0]->currency->name;
            self::assertSame('EUR', $missing);
            self::assertTrue($this->em->isUninitializedObject($documents[1]->currency));
            self::assertSame(1, $this->statistics->snapshot()->sqlCount);
            $documents[1]->currency->name;
            self::fail('Expected missing peer');
        } catch (\ModernORM\Exception\EntityNotFoundException) {
            self::assertFalse($this->em->getCache()->contains(L2BatchCurrency::class, $missing));
        }
        $found = $missing === 'USD' ? $documents[1]->currency : $documents[0]->currency;
        self::assertSame($missing === 'USD' ? 'EUR' : 'USD', $found->name);
        $connection->insert('modernorm_test_l2_batch_currencies', ['code' => $missing, 'name' => 'restored']);
        self::assertSame('restored', $this->manager()->find(L2BatchCurrency::class, $missing)->name);
    }

    public function testStringIdentifiersKeepLeadingZeroesOnMissesAndHits(): void
    {
        $connection = $this->em->getConnection();
        foreach (['01', '1'] as $index => $code) {
            $connection->insert('modernorm_test_l2_batch_currencies', ['code' => $code, 'name' => 'name-' . $code]);
            $connection->insert('modernorm_test_l2_batch_documents', ['id' => 4 + $index, 'currency_code' => $code, 'customer_id' => 1]);
        }
        foreach ([$this->em, $this->manager()] as $index => $em) {
            $documents = $em->getRepository(L2BatchDocument::class)->findBy(['id' => [4, 5]], ['id' => 'ASC']);
            $this->reset();
            self::assertSame('name-01', $documents[0]->currency->name);
            self::assertSame('name-1', $documents[1]->currency->name);
            self::assertNotSame($documents[0]->currency, $documents[1]->currency);
            self::assertSame($index === 0 ? 1 : 0, $this->statistics->snapshot()->sqlCount);
        }
    }

    #[DataProvider('cacheStates')]
    public function testWholeBatchIsHydratedBeforeCallbacksAndOnlyRawRowsArePublished(bool $warm): void
    {
        if ($warm) {
            $this->warmCurrencies();
        }
        $documents = $this->documents();
        $this->reset();
        L2BatchCurrency::$onLoad = static function (L2BatchCurrency $currency) use ($documents): void {
            if ($currency->code !== 'USD') {
                return;
            }
            self::assertSame('EUR', $documents[1]->currency->name);
            $documents[1]->currency->name = 'local-peer';
            $currency->name = 'local-trigger';
            $currency->changedAt->modify('+1 year');
        };
        self::assertSame('local-trigger', $documents[0]->currency->name);
        self::assertSame('local-peer', $documents[1]->currency->name);
        self::assertSame($warm ? 0 : 1, $this->statistics->snapshot()->sqlCount);
        self::assertEqualsCanonicalizing(['USD', 'EUR'], L2BatchCurrency::$loaded);
        self::assertSame('EUR', $this->em->getUnitOfWork()->getOriginalEntityData($documents[1]->currency)['name']);
        L2BatchCurrency::$onLoad = null;
        $other = $this->manager();
        self::assertSame('USD', $other->find(L2BatchCurrency::class, 'USD')->name);
        self::assertSame('2026-01-01', $other->find(L2BatchCurrency::class, 'USD')->changedAt->format('Y-m-d'));
        self::assertSame('EUR', $other->find(L2BatchCurrency::class, 'EUR')->name);
        self::assertNotSame($documents[0]->currency->changedAt, $other->find(L2BatchCurrency::class, 'USD')->changedAt);
    }

    public function testAlreadyManagedDirtyPeerIsExcludedAndKeepsItsSnapshot(): void
    {
        $documents = $this->documents();
        $peer = $this->em->createQueryBuilder()->fromEntity(L2BatchCurrency::class, 'c')->where('c.code = :code')->setParameter('code', 'EUR')->getQuery()->getSingleResult();
        $peer->name = 'local';
        $snapshot = $this->em->getUnitOfWork()->getOriginalEntityData($peer);
        $this->reset();
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame('local', $peer->name);
        self::assertSame($snapshot, $this->em->getUnitOfWork()->getOriginalEntityData($peer));
        self::assertSame($peer, $documents[1]->currency);
        self::assertSame('GBP', $documents[2]->currency->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertEqualsCanonicalizing(['USD', 'GBP'], array_values($this->recorder->parameters[0]));
    }

    public function testPartialPeerUsesSingleLoadWithoutLosingLocalFields(): void
    {
        $mapping = new \ModernORM\Query\ResultSetMapping();
        $mapping->addEntityResult(L2BatchCurrency::class, 'c');
        $mapping->addFieldResult('c', 'code', 'code');
        $mapping->addFieldResult('c', 'name', 'name');
        $mapping->partialAliases['c'] = true;
        $peer = $this->em->createNativeQuery("SELECT code, name FROM modernorm_test_l2_batch_currencies WHERE code = 'EUR'", $mapping)->getSingleResult();
        $peer->name = 'local';
        $documents = $this->documents();
        $this->reset();
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame($peer, $documents[1]->currency);
        self::assertTrue($this->em->isUninitializedObject($peer));
        self::assertSame('2026-01-01', $peer->changedAt->format('Y-m-d'));
        self::assertSame('local', $peer->name);
        self::assertSame('EUR', $this->em->getUnitOfWork()->getOriginalEntityData($peer)['name']);
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        self::assertSame(['partial_reference' => 1], $this->statistics->snapshot()->batch->fallbackCounts);
    }

    public function testFailedSqlDoesNotHydrateCacheHitsAndQueueRemainsRetryable(): void
    {
        $this->manager()->find(L2BatchCurrency::class, 'USD');
        $documents = $this->documents();
        $connection = $this->em->getConnection();
        $connection->executeStatement('ALTER TABLE modernorm_test_l2_batch_currencies RENAME COLUMN name TO temporary_name');
        try {
            $documents[0]->currency->name;
            self::fail('Expected SQL failure');
        } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException) {
            self::assertTrue($this->em->isUninitializedObject($documents[0]->currency));
            self::assertTrue($this->em->isUninitializedObject($documents[1]->currency));
            self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($documents[0]->currency));
        } finally {
            $connection->executeStatement('ALTER TABLE modernorm_test_l2_batch_currencies RENAME COLUMN temporary_name TO name');
        }
        $this->reset();
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->currency));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testCacheWriteFailurePropagatesBeforeHydrationAndCanBeRetriedExplicitly(): void
    {
        $documents = $this->documents();
        $this->pool->failPayloads = true;
        try {
            $documents[0]->currency->name;
            self::fail('Expected cache failure');
        } catch (\ModernORM\Exception\CacheException) {
            self::assertTrue($this->em->isUninitializedObject($documents[0]->currency));
            self::assertTrue($this->em->isUninitializedObject($documents[1]->currency));
        }
        $this->pool->failPayloads = false;
        $this->reset();
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame('EUR', $documents[1]->currency->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public static function evictionModes(): array
    {
        return [['key'], ['class']];
    }

    #[DataProvider('evictionModes')]
    public function testEvictionBetweenSelectAndPublicationMakesLateFillUnreachable(string $mode): void
    {
        $documents = $this->documents();
        $this->reset();
        $this->pool->beforePut = function () use ($mode): void {
            // A separate PostgreSQL connection commits an external update before
            // the first raw batch payload is actually saved by the original reader.
            $writer = DriverManager::getConnection($this->em->getConnection()->getParams());
            try {
                $writer->executeStatement("UPDATE modernorm_test_l2_batch_currencies SET name = 'external' WHERE code = 'USD'");
            } finally {
                $writer->close();
            }
            if ($mode === 'key') {
                $this->em->getCache()->evict(L2BatchCurrency::class, 'USD');
            } else {
                $this->em->getCache()->evictAll(L2BatchCurrency::class);
            }
        };
        self::assertSame('USD', $documents[0]->currency->name); // In-flight reader may finish with its old row.
        self::assertFalse($this->em->getCache()->contains(L2BatchCurrency::class, 'USD'));
        self::assertSame('external', $this->manager()->find(L2BatchCurrency::class, 'USD')->name);
    }

    public static function lifecycleModes(): array
    {
        return [['clear'], ['detach'], ['close']];
    }

    #[DataProvider('lifecycleModes')]
    public function testCachedQueueCleanupRetainsSingleGhostLoading(string $action): void
    {
        $documents = $this->documents();
        if ($action === 'detach') {
            $this->em->detach($documents[0]->currency);
            $this->reset();
            self::assertSame('EUR', $documents[1]->currency->name);
            self::assertTrue($this->em->isUninitializedObject($documents[0]->currency));
            self::assertFalse($this->em->isUninitializedObject($documents[2]->currency));
        } else {
            $this->em->$action();
            $this->reset();
            self::assertSame('USD', $documents[0]->currency->name);
            self::assertTrue($this->em->isUninitializedObject($documents[1]->currency));
        }
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testSharedReferenceBatchSizeOneOptOutIsStillRespected(): void
    {
        $documents = $this->documents();
        $single = $this->em->getRepository(L2BatchSingleDocument::class)->find(1);
        self::assertSame($single->currency, $documents[0]->currency);
        $this->reset();
        self::assertSame('USD', $single->currency->name);
        self::assertTrue($this->em->isUninitializedObject($documents[1]->currency));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame('EUR', $documents[1]->currency->name);
        self::assertFalse($this->em->isUninitializedObject($documents[2]->currency));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testDisabledAutoCommitBypassesL2ForTheWholeBatch(): void
    {
        $this->warmCurrencies();
        $documents = $this->documents();
        $connection = $this->em->getConnection();
        $connection->setAutoCommit(false);
        $connection->beginTransaction();
        try {
            $this->reset();
            self::assertSame('USD', $documents[0]->currency->name);
            self::assertFalse($this->em->isUninitializedObject($documents[1]->currency));
            self::assertSame(1, $this->statistics->snapshot()->sqlCount);
            self::assertSame(0, $this->cacheLogger->getHitCount());
            self::assertSame(0, $this->cacheLogger->getPutCount());
        } finally {
            $connection->rollBack();
            $connection->setAutoCommit(true);
        }
    }

    public function testTransactionStartedByMiddlewareCannotPublishPrivateRows(): void
    {
        $documents = $this->documents();
        $connection = $this->em->getConnection();
        $this->recorder->onSelect = static function () use ($connection): void {
            $connection->beginTransaction();
            $connection->executeStatement("UPDATE modernorm_test_l2_batch_currencies SET name = 'private' WHERE code = 'USD'");
        };
        try {
            $documents[0]->currency->name;
            self::fail('Expected transaction mutation rejection');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Callbacks must not change transaction', $error->getMessage());
        }
        self::assertTrue($this->em->isUninitializedObject($documents[0]->currency));
        self::assertFalse($this->em->getCache()->contains(L2BatchCurrency::class, 'USD'));
        $connection->rollBack();
        self::assertSame('USD', $documents[0]->currency->name);
    }
    public function testBatchFailureKeepsSqlErrorAndRecordsAttemptedLookups(): void
    {
        $this->manager()->find(L2BatchCurrency::class, 'USD');
        $documents = $this->documents();
        $connection = $this->em->getConnection();
        $connection->executeStatement('ALTER TABLE modernorm_test_l2_batch_currencies RENAME COLUMN name TO temporary_name');
        $this->reset();
        try {
            $documents[0]->currency->name;
            self::fail('Expected SQL failure');
        } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException) {
            $snapshot = $this->statistics->snapshot();
            $events = $this->operationObserver->events;
        } finally {
            $connection->executeStatement('ALTER TABLE modernorm_test_l2_batch_currencies RENAME COLUMN temporary_name TO name');
        }
        self::assertSame(1, $snapshot->sqlCount);
        self::assertSame(1, $snapshot->sqlErrors);
        self::assertSame(1, $snapshot->batch->count);
        self::assertSame(1, $snapshot->batch->errors);
        self::assertSame(2, $snapshot->batch->selectedReferences);
        self::assertSame(1, $snapshot->batch->cacheHits);
        self::assertSame(1, $snapshot->batch->cacheMisses);
        self::assertSame(0, $snapshot->batch->cachePuts);
        self::assertSame(\ModernORM\Statistics\OperationKind::BATCH, $events[array_key_last($events)]->kind);
        self::assertTrue($events[array_key_last($events)]->failed);
    }

    public function testFallbackReasonsAreDecisionsNotSelectedBatches(): void
    {
        $documents = $this->documents();
        $this->em->getRepository(L2BatchSingleDocument::class)->find(1);
        $this->reset();
        $documents[0]->currency->name;
        $documents[0]->currencies->toArray();
        self::assertSame(0, $this->statistics->snapshot()->batch->count);
        self::assertSame(['size_one' => 1, 'cached_target' => 1], $this->statistics->snapshot()->batch->fallbackCounts);
        $events = array_values(array_filter($this->operationObserver->events, static fn ($event) => $event->kind === \ModernORM\Statistics\OperationKind::BATCH_FALLBACK));
        self::assertCount(2, $events);
        self::assertSame(L2BatchDocument::class . '::currencies', $events[1]->association);
        self::assertSame(0, $events[1]->durationNanoseconds);
    }

    public function testReentrantLoadingIsCountedOnceWithoutInflatingSelectedBatchSize(): void
    {
        $documents = $this->documents();
        $this->reset();
        L2BatchCurrency::$onLoad = static function (L2BatchCurrency $currency) use ($documents): void {
            if ($currency->code === 'USD') {
                $documents[2]->currency->name;
            }
        };
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame('GBP', $documents[2]->currency->name);
        $snapshot = $this->statistics->snapshot();
        self::assertSame(2, $snapshot->sqlCount);
        self::assertSame(1, $snapshot->batch->count);
        self::assertSame(2, $snapshot->batch->selectedReferences);
        self::assertSame(2, $snapshot->batch->cacheMisses);
        self::assertSame(2, $snapshot->batch->cachePuts);
        self::assertSame(['reentrant' => 1], $snapshot->batch->fallbackCounts);
        self::assertSame(3, $this->cacheLogger->getPutCount()); // Includes the separate single load.
    }

    public function testDisabledStatisticsDoesNotChangeBatchOrL2Behavior(): void
    {
        $documents = $this->documents();
        $this->reset();
        $this->statistics->disable();
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertFalse($this->em->isUninitializedObject($documents[1]->currency));
        self::assertTrue($this->em->getCache()->contains(L2BatchCurrency::class, 'EUR'));
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(0, $this->statistics->snapshot()->batch->count);
        self::assertSame([], $this->operationObserver->events);
        $this->statistics->enable();
        self::assertSame('GBP', $documents[2]->currency->name);
        self::assertSame(1, $this->statistics->snapshot()->batch->count);
        self::assertSame(1, $this->statistics->snapshot()->batch->selectedReferences);
    }

    public function testResetDuringPostLoadDiscardsCompletionFromOldWorkerWindow(): void
    {
        $documents = $this->documents();
        $this->reset();
        L2BatchCurrency::$onLoad = function (L2BatchCurrency $currency): void {
            if ($currency->code !== 'USD') {
                return;
            }
            $this->statistics->reset();
            $this->operationObserver->events = [];
        };
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame(0, $this->statistics->snapshot()->batch->count);
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame([], $this->operationObserver->events);
        L2BatchCurrency::$onLoad = null;
        self::assertSame('GBP', $documents[2]->currency->name);
        self::assertSame(1, $this->statistics->snapshot()->batch->count);
        self::assertSame(1, $this->statistics->snapshot()->batch->selectedReferences);
        self::assertSame(1, $this->statistics->snapshot()->batch->cachePuts);
    }

    public function testFailingObserverCannotReplaceDatabaseErrorsOrChangeSuccessfulLoading(): void
    {
        $documents = $this->documents();
        $this->reset();
        $this->operationObserver->onEvent = static function (): void {
            throw new \RuntimeException('export failed');
        };
        self::assertSame('USD', $documents[0]->currency->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame(1, $this->statistics->snapshot()->batch->count);
        self::assertSame(2, $this->statistics->snapshot()->observerErrors);
        try {
            $this->em->getConnection()->executeQuery('SELECT nonexistent_column FROM modernorm_test_l2_batch_currencies');
            self::fail('Expected database error');
        } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException) {
        }
        self::assertSame(1, $this->statistics->snapshot()->sqlErrors);
        self::assertSame(3, $this->statistics->snapshot()->observerErrors);
    }
}
