<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\ScalarHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use PHPUnit\Framework\TestCase;

use function array_merge;
use function iterator_to_array;

enum ScalarHydrationState: string
{
    case Active = 'active';
}

#[ORM\Entity]
final class ScalarHydrationRecord
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'boolean')]
    public bool $enabled;
    #[ORM\Column(enumType: ScalarHydrationState::class)]
    public ScalarHydrationState $state;
    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;
}

final class ScalarHydrationTest extends TestCase
{
    private Statistics $statistics;
    private ScalarHydrator $hydrator;

    protected function setUp(): void
    {
        $this->statistics = new Statistics(true);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ], $dbal), $config);
        $this->hydrator = new ScalarHydrator($em);
    }

    private function statement(array $rows): Result
    {
        $statement = $this->createMock(Result::class);
        $statement->method('fetchAssociative')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        $statement->expects(self::once())->method('free');
        return $statement;
    }

    private function entityMapping(string $field = 'id'): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(ScalarHydrationRecord::class, 'r');
        $rsm->addFieldResult('r', 'value', $field);
        return $rsm;
    }

    public function testEntityMetaAndScalarColumnsKeepTheirExistingConversions(): void
    {
        $rsm = $this->entityMapping();
        $rsm->addFieldResult('r', 'flag', 'enabled');
        $rsm->addFieldResult('r', 'state', 'state');
        $rsm->addEnumResult('state', ScalarHydrationState::class);
        $rsm->addMetaResult('r', 'related', 'related_id', false, 'integer');
        $rsm->addScalarResult('total', 0, 'integer');
        $rows = $this->hydrator->hydrateAll($this->statement([
            ['value' => '12', 'flag' => '1', 'state' => 'active', 'related' => '34', 'total' => '17', 'ignored' => 'unused'],
            ['value' => null, 'flag' => '0', 'state' => null, 'related' => null, 'total' => null],
        ]), $rsm);
        self::assertSame([
            ['r_id' => 12, 'r_enabled' => true, 'r_state' => 'active', 'r_related_id' => 34, 0 => '17'],
            ['r_id' => null, 'r_enabled' => false, 'r_state' => null, 'r_related_id' => null, 0 => null],
        ], $rows);
        self::assertSame(2, $this->statistics->snapshot()->hydration->rows);
        self::assertSame(0, $this->statistics->snapshot()->hydration->entitiesCreated);
    }

    public function testPlanResetsAfterAbandonedStreamAndDifferentMappings(): void
    {
        $rsm = $this->entityMapping();
        $stream = $this->hydrator->toIterable($this->statement([['value' => '5'], ['value' => '6']]), $rsm);
        $stream->rewind();
        self::assertSame(['r_id' => 5], $stream->current());
        unset($stream);
        $scalars = new ResultSetMapping();
        $scalars->addScalarResult('value', 'number', 'integer');
        self::assertSame([['number' => '12']], $this->hydrator->hydrateAll($this->statement([['value' => '12']]), $scalars));
        self::assertSame([['r_id' => 13], ['r_id' => null]], iterator_to_array($this->hydrator->toIterable(
            $this->statement([['value' => '13'], ['value' => null]]),
            $rsm,
        )));
        $snapshot = $this->statistics->snapshot()->hydration;
        self::assertSame(3, $snapshot->count);
        self::assertSame(4, $snapshot->rows);
        self::assertSame(1, $snapshot->incomplete);
        self::assertSame(0, $snapshot->errors);
    }

    public function testPlanResetsAfterConversionFailure(): void
    {
        try {
            $this->hydrator->hydrateAll($this->statement([['value' => 'invalid-date']]), $this->entityMapping('createdAt'));
            self::fail('Expected a mapped date conversion error.');
        } catch (InvalidFormat) {
        }
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('value', 'raw', 'integer');
        self::assertSame([['raw' => '42']], $this->hydrator->hydrateAll($this->statement([['value' => '42']]), $rsm));
        self::assertSame(2, $this->statistics->snapshot()->hydration->count);
        self::assertSame(1, $this->statistics->snapshot()->hydration->errors);
    }
}
