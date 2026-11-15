<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\JsonbType;
use Doctrine\DBAL\Types\TypeRegistry;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
class JsonbQueryProduct
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(name: '`ProductData`', type: 'jsonb', nullable: true)]
    public mixed $attributes = null;
    #[ORM\Column(type: 'jsonb_object', nullable: true)]
    public mixed $objectData = null;
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public array $legacy = [];
    #[ORM\Column(type: 'json')]
    public array $ordinaryJson = [];
    #[ORM\Column(type: 'text')]
    public string $description = '';
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $parent = null;
}

final class JsonbQueryBuilderTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $dbal = new DbalConfiguration();
        $dbal->setTypeProvider(new TypeRegistry(['local_jsonb' => new JsonbType()]));
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17'], $dbal), $config);
    }

    private function builder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->fromEntity(JsonbQueryProduct::class, 'p');
    }

    public function testContainsUsesQuotedFieldAndBindsJsonWithoutReplacingFilters(): void
    {
        $builder = $this->builder()->where('p.id > :id')->setParameter('id', 1);
        $value = ['name' => "' OR TRUE;--", 'active' => true, 'value' => null];
        $builder->andWhere($builder->jsonb('p', 'attributes')->contains($value));
        self::assertStringContainsString('p."ProductData" @>', $builder->getSQL());
        self::assertStringContainsString('p.id > :id', $builder->getSQL());
        self::assertStringNotContainsString($value['name'], $builder->getSQL());
        self::assertSame($value, json_decode($builder->getParameter('__orm_jsonb_1'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testPathsBindLiteralKeysAndIndexesAndDoNotMutateTheRoot(): void
    {
        $builder = $this->builder();
        $root = $builder->jsonb('p', 'attributes');
        $nested = $root->path(['literal.dot', 'a"},b', "' OR TRUE --", -1]);
        $sql = $nested->text();
        self::assertSame('p."ProductData"', $root->json());
        self::assertStringContainsString('#>> ARRAY[', $sql);
        self::assertStringNotContainsString('literal.dot', $sql);
        self::assertSame(['literal.dot', 'a"},b', "' OR TRUE --", '-1'], array_values($builder->getParameters()));
    }

    public function testExistingGeneratedNamesAreNotOverwrittenAndClonesBindIndependently(): void
    {
        $base = $this->builder()->setParameter('__orm_jsonb_1', 7);
        $first = clone $base;
        $first->andWhere($first->jsonb('p', 'attributes')->compare('=', null));
        $snapshot = $first->getQuery();
        $second = clone $base;
        $second->andWhere($second->jsonb('p', 'attributes')->compare('=', false));
        self::assertSame(7, $first->getParameter('__orm_jsonb_1'));
        self::assertSame('null', $snapshot->getParameter('__orm_jsonb_2')->getValue());
        self::assertSame('false', $second->getParameter('__orm_jsonb_2'));
        self::assertSame(['__orm_jsonb_1' => 7], $base->getParameters());
    }

    public function testResettingBindingsDoesNotReuseAnOlderPredicatesParameterName(): void
    {
        $builder = $this->builder();
        $builder->andWhere($builder->jsonb('p', 'attributes')->contains(['first' => true]));
        $builder->setParameters([]);
        $builder->andWhere($builder->jsonb('p', 'attributes')->contains(['second' => true]));
        self::assertStringContainsString(':__orm_jsonb_1', $builder->getSQL());
        self::assertStringContainsString(':__orm_jsonb_2', $builder->getSQL());
        self::assertArrayNotHasKey('__orm_jsonb_1', $builder->getParameters());
        self::assertSame('{"second":true}', $builder->getParameter('__orm_jsonb_2'));
    }

    public function testObjectLegacyAndConnectionLocalJsonbMappingsAreAccepted(): void
    {
        $builder = $this->builder();
        self::assertSame('p.objectData', $builder->jsonb('p', 'objectData')->json());
        self::assertSame('p.legacy', $builder->jsonb('p', 'legacy')->json());
        $this->em->getClassMetadata(JsonbQueryProduct::class)->fieldMappings['attributes']->type = 'local_jsonb';
        self::assertSame('p."ProductData"', $builder->jsonb('p', 'attributes')->json());
    }

    public function testJoinedAliasesResolveTheirOwnMappedColumn(): void
    {
        $builder = $this->builder()->leftJoin('p', 'JsonbQueryProduct', 'parent', 'parent.id = p.parent_id')
            ->addJoinedEntity(JsonbQueryProduct::class, 'parent', 'p', 'parent');
        self::assertSame('parent."ProductData"', $builder->jsonb('parent', 'attributes')->json());
    }

    public static function invalidFields(): iterable
    {
        yield 'unknown alias' => ['unknown', 'attributes'];
        yield 'unknown field' => ['p', 'missing'];
        yield 'ordinary JSON' => ['p', 'ordinaryJson'];
        yield 'text' => ['p', 'description'];
        yield 'association' => ['p', 'parent'];
    }

    #[DataProvider('invalidFields')]
    public function testNonJsonbFieldsAreRejected(string $alias, string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->jsonb($alias, $field);
    }

    public function testCustomColumnDefinitionIsRejected(): void
    {
        $this->em->getClassMetadata(JsonbQueryProduct::class)->fieldMappings['attributes']->columnDefinition = 'TEXT';
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->jsonb('p', 'attributes');
    }

    public static function invalidPaths(): iterable
    {
        yield 'associative path' => [['key' => 'value']];
        yield 'null segment' => [[null]];
        yield 'array segment' => [[[1]]];
        yield 'float segment' => [[1.5]];
        yield 'boolean segment' => [[false]];
    }

    #[DataProvider('invalidPaths')]
    public function testMalformedPathsFailBeforeBinding(array $path): void
    {
        $builder = $this->builder();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $builder->jsonb('p', 'attributes')->path($path);
        } finally {
            self::assertSame([], $builder->getParameters());
        }
    }

    public static function invalidNumbers(): iterable
    {
        yield 'not a number' => ['not numeric'];
        yield 'SQL fragment' => ['1); SELECT 1;--'];
        yield 'NaN float' => [NAN];
        yield 'infinite float' => [INF];
        yield 'infinite string' => ['Infinity'];
    }

    #[DataProvider('invalidNumbers')]
    public function testInvalidNumbersFailBeforeBinding(int|float|string $value): void
    {
        $builder = $this->builder();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $builder->jsonb('p', 'attributes')->path(['price'])->compareNumber('>', $value);
        } finally {
            self::assertSame([], $builder->getParameters());
        }
    }

    public function testNumericComparisonPreservesDecimalStringsAndGuardsDocumentType(): void
    {
        $builder = $this->builder();
        $sql = $builder->jsonb('p', 'attributes')->path(['price'])->compareNumber('>=', '9007199254740993.000001');
        self::assertStringContainsString(" = 'number' THEN CAST(", $sql);
        self::assertContains('9007199254740993.000001', $builder->getParameters());
    }

    public function testComparisonOperatorCannotInjectSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->jsonb('p', 'attributes')->compareText('= ANY(SELECT password FROM users)', 'x');
    }

    public function testOrderedJsonComparisonRequiresAnExplicitType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->jsonb('p', 'attributes')->compare('>', 10);
    }

    public function testJsonSerializationErrorsDoNotLeavePartialPathBindings(): void
    {
        $builder = $this->builder();
        $this->expectException(\JsonException::class);
        try {
            $builder->jsonb('p', 'attributes')->path(['key'])->contains(NAN);
        } finally {
            self::assertSame([], $builder->getParameters());
        }
    }

    public function testMissingRequiresAPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->jsonb('p', 'attributes')->isMissing();
    }
}
