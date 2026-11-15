<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
#[ORM\FullTextIndex(name: 'product_search', fields: ['name' => 'A'], configuration: 'pg_catalog.russian', column: 'SearchVector')]
class FullTextQueryProduct
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'text')]
    public string $name;
    #[ORM\ManyToOne(targetEntity: FullTextQueryGroup::class)]
    public ?FullTextQueryGroup $group = null;
}

#[ORM\Entity]
#[ORM\FullTextIndex(name: 'group_search', fields: ['name' => 'A'], configuration: 'pg_catalog.simple')]
class FullTextQueryGroup
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'text')]
    public string $name;
}

final class FullTextQueryBuilderTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    private function builder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->fromEntity(FullTextQueryProduct::class, 'p');
    }

    public function testSearchUsesQuotedMappingAndParametersWithoutReplacingFilters(): void
    {
        $input = "' OR 1=1; -- промышленное оборудование";
        $builder = $this->builder()->where('p.id > :minimum')->setParameter('minimum', 10)
            ->andWhereFullText('p', $input)->addOrderByFullTextRank('p')->addOrderBy('p.id');
        self::assertStringContainsString('p.id > :minimum', $builder->getSQL());
        self::assertStringContainsString('p."SearchVector" @@ pg_catalog.websearch_to_tsquery(', $builder->getSQL());
        self::assertStringContainsString('pg_catalog.ts_rank(p."SearchVector",', $builder->getSQL());
        self::assertStringNotContainsString($input, $builder->getSQL());
        self::assertSame([10, 'pg_catalog.russian', $input], array_values($builder->getParameters()));
        self::assertSame(ParameterType::STRING, $builder->getParameterType('__orm_fts_2'));
        $query = $builder->getQuery();
        self::assertSame($input, $query->getParameter('__orm_fts_2')->getValue());
    }

    public function testGeneratedBindingsDoNotOverwriteExistingParameters(): void
    {
        $builder = $this->builder()->where('p.id = :__orm_fts_1')->setParameter('__orm_fts_1', 3)
            ->andWhereFullText('p', 'pump');
        self::assertSame(3, $builder->getParameter('__orm_fts_1'));
        self::assertSame('pg_catalog.russian', $builder->getParameter('__orm_fts_2'));
        self::assertSame('pump', $builder->getParameter('__orm_fts_3'));
    }

    public function testDistinctIndexesRequireExplicitSelectionAndKeepSeparateBindings(): void
    {
        $metadata = $this->em->getClassMetadata(FullTextQueryProduct::class);
        $metadata->fullTextIndexes[] = new ORM\FullTextIndex('other_search', ['name' => 'D'], 'pg_catalog.simple', 'OtherVector');
        $builder = $this->builder()->andWhereFullText('p', 'pump', index: 'product_search')
            ->andWhereFullText('p', 'motor', index: 'other_search')
            ->addOrderByFullTextRank('p', index: 'other_search');
        self::assertStringContainsString('p."OtherVector" @@', $builder->getSQL());
        self::assertStringContainsString('pg_catalog.ts_rank(p."OtherVector",', $builder->getSQL());
        self::assertSame(['pg_catalog.russian', 'pump', 'pg_catalog.simple', 'motor'], array_values($builder->getParameters()));
    }

    public function testClonesAndBuiltQueriesKeepIndependentSearchesAndScalarMappings(): void
    {
        $base = $this->builder();
        $first = (clone $base)->andWhereFullText('p', 'pump')->addFullTextRank('p');
        $snapshot = $first->getQuery();
        $first->addFullTextRank('p', 'secondScore');
        $second = (clone $base)->andWhereFullText('p', 'motor')->addFullTextRank('p');
        self::assertSame([], $base->getParameters());
        self::assertSame('pump', $snapshot->getParameter('__orm_fts_2')->getValue());
        self::assertSame('motor', $second->getQuery()->getParameter('__orm_fts_2')->getValue());
        self::assertStringNotContainsString('orm_fts_rank_2', $snapshot->getSQL());
        self::assertStringContainsString('orm_fts_rank_2', $first->getSQL());
    }

    public function testJoinedEntityUsesItsOwnFullTextMapping(): void
    {
        $builder = $this->builder()->leftJoin('p', 'FullTextQueryGroup', 'g', 'g.id = p.group_id')
            ->addJoinedEntity(FullTextQueryGroup::class, 'g', 'p', 'group')->andWhereFullText('g', 'tools')
            ->addOrderByFullTextRank('g', 'asc');
        self::assertStringContainsString('g."search_vector" @@', $builder->getSQL());
        self::assertSame(['pg_catalog.simple', 'tools'], array_values($builder->getParameters()));
        self::assertStringContainsString(') ASC', $builder->getSQL());
    }

    public function testAmbiguousIndexIsRejectedBeforeChangingTheBuilder(): void
    {
        $metadata = $this->em->getClassMetadata(FullTextQueryProduct::class);
        $metadata->fullTextIndexes[] = new ORM\FullTextIndex('other_search', ['name' => 'D'], 'pg_catalog.simple', 'other_vector');
        $builder = $this->builder();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $builder->andWhereFullText('p', 'pump');
        } finally {
            self::assertSame([], $builder->getParameters());
        }
    }

    public function testUnregisteredAliasIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('unknown', 'pump');
    }

    public function testEntityWithoutAFullTextIndexIsRejected(): void
    {
        $this->em->getClassMetadata(FullTextQueryProduct::class)->fullTextIndexes = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('p', 'pump');
    }

    public function testUnknownIndexIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('p', 'pump', index: 'unknown');
    }

    public function testBlankSearchIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('p', " \n\t");
    }

    public function testRankingRequiresAnExistingSearch(): void
    {
        $this->expectException(\LogicException::class);
        $this->builder()->addOrderByFullTextRank('p');
    }

    public function testDuplicateSearchIsRejectedInsteadOfChangingEarlierRankBindings(): void
    {
        $this->expectException(\LogicException::class);
        $this->builder()->andWhereFullText('p', 'pump')->addOrderByFullTextRank('p')->andWhereFullText('p', 'motor');
    }

    public function testInvalidRankOrderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('p', 'pump')->addOrderByFullTextRank('p', 'DESC NULLS LAST; SELECT 1');
    }

    public function testDuplicateScalarResultAliasIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('p', 'pump')->addScalar('p.id', 'existing', 'rank', 'integer')->addFullTextRank('p');
    }

    public function testNumericScalarAliasCannotOverwriteTheEntityInMixedResults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->andWhereFullText('p', 'pump')->addFullTextRank('p', '0');
    }
}
