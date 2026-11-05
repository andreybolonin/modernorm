<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use ModernORM\AbstractQuery;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query\Parameter;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Utility\PersisterHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

#[ORM\Entity]
final class ParameterTarget
{
    public function __construct(#[ORM\Id, ORM\Column(type: 'integer')] public int $id)
    {
    }
}

#[ORM\Entity]
final class ParameterOwner
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column]
    public string $name;
    #[ORM\ManyToOne(targetEntity: ParameterTarget::class)]
    public ParameterTarget $target;
}

enum ParameterState: string
{
    case Active = 'active';
}

final class ParameterProcessingTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
    }

    /** @return iterable<string, array{bool}> */
    public static function queryKinds(): iterable
    {
        yield 'SQL query' => [false];
        yield 'native query' => [true];
    }

    private function query(bool $native): AbstractQuery
    {
        return $native
            ? $this->em->createNativeQuery('SELECT 1', new ResultSetMapping())
            : $this->em->createQuery('SELECT 1', new ResultSetMapping());
    }

    #[DataProvider('queryKinds')]
    public function testLookupPreservesNamesIdentityDuplicatesAndExternalMutations(bool $native): void
    {
        $query = $this->query($native);
        self::assertNull($query->getParameter('missing'));
        $first = new Parameter(':name', 'first');
        $duplicate = new Parameter('name', 'second');
        $zero = new Parameter(0, 'zero');
        $padded = new Parameter('00', 'padded');
        $parameters = new ArrayCollection([4 => $first, 12 => $duplicate, 30 => $zero, 45 => $padded]);
        $query->setParameters($parameters);
        self::assertSame($first, $query->getParameter(':name'));
        self::assertSame($zero, $query->getParameter('0'));
        self::assertSame($padded, $query->getParameter('00'));
        $query->setParameter('name', 'updated', 'string');
        self::assertSame($first, $query->getParameter('name'));
        self::assertSame('updated', $first->getValue());
        self::assertSame('string', $first->getType());
        self::assertSame('second', $duplicate->getValue());
        self::assertSame([4, 12, 30, 45], $parameters->getKeys());
        $parameters->remove(4);
        self::assertSame($duplicate, $query->getParameter('name'));
        $parameters->clear();
        self::assertNull($query->getParameter('name'));
        $parameters->add($first);
        self::assertSame($first, $query->getParameter(':name'));
    }

    #[DataProvider('queryKinds')]
    public function testScalarArrayKeysAndInputArePreserved(bool $native): void
    {
        $values = ['null' => null, 9 => 0, 'boolean' => false, 'float' => 1.25, 'text' => 'value'];
        self::assertSame($values, $this->query($native)->processParameterValue($values));
        self::assertSame(['null' => null, 9 => 0, 'boolean' => false, 'float' => 1.25, 'text' => 'value'], $values);
        self::assertSame([], $this->query($native)->processParameterValue([]));
    }

    #[DataProvider('queryKinds')]
    public function testMixedQueryArraysKeepExistingConversionSemantics(bool $native): void
    {
        $target = new ParameterTarget(7);
        $object = new stdClass();
        $values = ['enum' => ParameterState::Active, 'entity' => $target, 'nested' => ['first' => ParameterState::Active, 'next' => 'other'], 'object' => $object, 'null' => null];
        $expected = ['enum' => 'active', 'entity' => 7, 'nested' => 'active', 'object' => $object, 'null' => null];
        $query = $this->query($native);
        self::assertSame($expected, $query->processParameterValue($values));
        self::assertSame($expected, $query->processParameterValue(new ArrayCollection($values)));
        self::assertSame($target, $values['entity']);
        self::assertSame(ParameterState::Active, $values['enum']);
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testPersisterExpansionPreservesValueTypeAndCriteriaOrder(): void
    {
        $first = new ParameterTarget(7);
        $second = new ParameterTarget(9);
        $persister = $this->em->getUnitOfWork()->getEntityPersister(ParameterOwner::class);
        [$values, $types] = $persister->expandParameters([
            'name' => ['first' => 'alpha', 'skip' => null, 'nested' => ['enum' => ParameterState::Active, 'text' => 'beta'], 'duplicate' => 'alpha'],
            'target' => [3 => $first, 'skip' => null, 19 => $second, 'duplicate' => $first],
            'id' => 0,
        ]);
        self::assertSame(['alpha', ['active', 'beta'], 'alpha', 7, 9, 7, 0], $values);
        self::assertSame(['string', ArrayParameterType::STRING, 'string', 'integer', 'integer', 'integer', 'integer'], $types);
        self::assertSame([[], []], $persister->expandParameters(['name' => null, 'target' => []]));
    }

    public function testHelperConversionPreservesArrayBoundariesAndObjectIdentity(): void
    {
        $target = new ParameterTarget(7);
        $object = new stdClass();
        $values = ['first' => null, 9 => ParameterState::Active, 'nested' => ['x' => 1, 'none' => null], 'entity' => $target, 'duplicate' => $target, 'object' => $object];
        self::assertSame([[null, 'active', [1, null], 7, 7, $object]], PersisterHelper::convertToParameterValue($values, $this->em));
        self::assertSame([[]], PersisterHelper::convertToParameterValue([], $this->em));
        self::assertSame($target, $values['entity']);
    }
}
