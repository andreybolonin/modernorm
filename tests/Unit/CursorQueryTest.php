<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\QueryBuilder;
use ModernORM\Tools\Pagination\Cursor;
use ModernORM\Tools\Pagination\CursorPaginator;
use ModernORM\Tools\Pagination\Exception\InvalidCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
class CursorQueryEntity
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)]
    public ?string $name = null;
    #[ORM\Column(type: 'json')]
    public array $data = [];
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $parent = null;
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    public Collection $children;
}

final class CursorQueryTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
        return $em->createQueryBuilder()->fromEntity(CursorQueryEntity::class, 'r');
    }

    public function testCompoundPredicateUsesTupleAndKeepsServerDefinedFieldsAndBindings(): void
    {
        $source = $this->builder()->where('r.id > :__orm_cursor_1')->setParameter('__orm_cursor_1', 10)->orderBy('r.id', 'DESC');
        $original = $source->getSQL();
        $cursorQuery = $source->getCursorPaginationSource(['createdAt' => 'ASC', 'id' => 'ASC']);
        $query = $cursorQuery->keyQuery(new Cursor(['createdAt' => '2026-01-01 01:02:03.123456', 'id' => 20]));
        self::assertStringContainsString('(morm_page.orm_cursor_key_0, morm_page.orm_cursor_key_1) >', $query->getSQL());
        self::assertStringContainsString('r.id > :__orm_cursor_1', $query->getSQL());
        self::assertSame(10, $query->getParameter('__orm_cursor_1'));
        self::assertSame('2026-01-01 01:02:03.123456', $query->getParameter('__orm_cursor_2'));
        self::assertSame($original, $source->getSQL());
        self::assertNull($source->getMaxResults());
    }

    public function testMixedPredicateHasALeadingRangeBoundAndReversesAllDirections(): void
    {
        $source = $this->builder()->getCursorPaginationSource(['createdAt' => 'DESC', 'id' => 'ASC']);
        $query = $source->keyQuery(new Cursor(['createdAt' => '2026-01-01', 'id' => 2], false));
        self::assertStringContainsString('morm_page.orm_cursor_key_0 >=', $query->getSQL());
        self::assertStringContainsString(' OR ', $query->getSQL());
        self::assertStringContainsString('ORDER BY morm_page.orm_cursor_key_0 ASC, morm_page.orm_cursor_key_1 DESC', $query->getSQL());
    }

    public static function invalidOrders(): iterable
    {
        yield 'no tie breaker' => [['createdAt' => 'ASC']];
        yield 'tie breaker first' => [['id' => 'ASC', 'createdAt' => 'ASC']];
        yield 'nullable' => [['name' => 'ASC', 'id' => 'ASC']];
        yield 'JSON' => [['data' => 'ASC', 'id' => 'ASC']];
        yield 'association' => [['parent' => 'ASC', 'id' => 'ASC']];
        yield 'joined field' => [['parent.id' => 'ASC', 'id' => 'ASC']];
        yield 'expression' => [['ts_rank(vector, query)' => 'DESC', 'id' => 'ASC']];
    }

    #[DataProvider('invalidOrders')]
    public function testUnsupportedSortingIsRejectedBeforeSql(array $order): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CursorPaginator(2, $order))->paginate($this->builder());
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'zero limit' => [0, ['id' => 'ASC']];
        yield 'negative limit' => [-1, ['id' => 'ASC']];
        yield 'overflow' => [PHP_INT_MAX, ['id' => 'ASC']];
        yield 'empty order' => [2, []];
        yield 'bad direction' => [2, ['id' => 'ASC NULLS LAST']];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRejected(int $limit, array $order): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CursorPaginator($limit, $order);
    }

    public static function unsupportedSources(): iterable
    {
        yield 'offset' => [static fn (QueryBuilder $query) => $query->setFirstResult(1)];
        yield 'limit' => [static fn (QueryBuilder $query) => $query->setMaxResults(1)];
        yield 'mixed scalar' => [static fn (QueryBuilder $query) => $query->addScalar('1', 'score', 'score')];
        yield 'positional' => [static fn (QueryBuilder $query) => $query->where('r.id > ?')->setParameter(0, 1)];
        yield 'update' => [static fn (QueryBuilder $query) => $query->update('records')->set('name', ':name')];
        yield 'delete' => [static fn (QueryBuilder $query) => $query->delete('records')];
        yield 'insert' => [static fn (QueryBuilder $query) => $query->insert('records')];
        yield 'union' => [static fn (QueryBuilder $query) => $query->union('SELECT 1')];
        yield 'CTE' => [static fn (QueryBuilder $query) => $query->with('extra', 'SELECT 1')];
        yield 'row lock' => [static fn (QueryBuilder $query) => $query->forUpdate()];
        yield 'collection fetch' => [static fn (QueryBuilder $query) => $query->leftJoin('r', 'records', 'c', 'c.parent_id = r.id')->addJoinedEntity(CursorQueryEntity::class, 'c', 'r', 'children')];
    }

    #[DataProvider('unsupportedSources')]
    public function testUnsupportedQueryShapesAreRejected(\Closure $change): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CursorPaginator(2, ['id' => 'ASC']))->paginate($change($this->builder()));
    }

    public function testCursorCannotChooseColumnsOrOmitPivots(): void
    {
        $source = $this->builder()->getCursorPaginationSource(['createdAt' => 'ASC', 'id' => 'ASC']);
        $this->expectException(InvalidCursor::class);
        $source->keyQuery(new Cursor(['id' => 1, 'r.id) OR TRUE --' => 2]));
    }
}
