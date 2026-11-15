<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query\Expression\JsonbExpression;
use ModernORM\QueryBuilder;
use ModernORM\Tools\Pagination\CursorPaginator;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: '`JsonbCatalog`'), ORM\SoftDelete]
final class JsonbCatalogItem
{
    use SoftDeleteTrait;
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'integer')]
    public int $tenant = 1;
    #[ORM\Column(name: '`ProductData`', type: 'jsonb', nullable: true)]
    public mixed $attributes = null;
    #[ORM\Column(type: 'jsonb_object', nullable: true)]
    public mixed $objectData = null;
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    public mixed $legacy = null;
}

final class JsonbQueryApiTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    private string $table;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_jsonb_' . bin2hex(random_bytes(6));
        $this->table = '"' . $this->schema . '"."JsonbCatalog"';
        $dbal = new DbalConfiguration();
        $dbal->setSchemaAssetsFilter(static fn (): bool => false);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = $this->em->getClassMetadata(JsonbCatalogItem::class);
        $metadata->table['schema'] = $this->schema;
        (new SchemaTool($this->em))->createSchema([$metadata]);
        $documents = [
            1 => '{"status":"active","price":10,"brand":"Acme","nullable":null,"profile":{"city":"Москва"},"tags":["red","blue"],"nested":[{"value":1},{"value":2}]}',
            2 => '{"status":"active","price":2,"brand":"Beta","nullable":"null","profile":{"city":"Казань"},"tags":["green"]}',
            3 => '{"status":"inactive","price":"10","brand":false,"nullable":false,"profile":null,"tags":[]}',
            4 => '{}',
            5 => null,
            6 => 'null',
            7 => '{"nullable":null,"price":9007199254740993.000001}',
            8 => '{"price":null,"brand":"false"}',
            9 => '["red","blue",{"price":10}]',
            10 => '"red"',
            11 => '{"status":"active","price":999}',
            12 => '{"status":"active","price":10}',
        ];
        foreach ($documents as $id => $document) {
            $sqlDocument = $document === null ? 'NULL' : 'CAST(? AS pg_catalog.jsonb)';
            $params = [$id, $id === 11 ? 2 : 1];
            if ($document !== null) {
                $params[] = $document;
            }
            $connection->executeStatement('INSERT INTO ' . $this->table . ' (id, tenant, "ProductData", deleted_at) VALUES (?, ?, ' . $sqlDocument . ', ' . ($id === 12 ? 'NOW()' : 'NULL') . ')', $params);
        }
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        $connection->close();
    }

    private function builder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->fromEntity(JsonbCatalogItem::class, 'p')
            ->where('p.tenant = :tenant')->setParameter('tenant', 1);
    }

    public static function predicates(): iterable
    {
        yield 'object contains' => [static fn (JsonbExpression $json) => $json->contains(['status' => 'active']), [1, 2]];
        yield 'contains JSON null' => [static fn (JsonbExpression $json) => $json->contains(['nullable' => null]), [1, 7]];
        yield 'array contains' => [static fn (JsonbExpression $json) => $json->path(['tags'])->contains(['red']), [1]];
        yield 'key exists with null/false' => [static fn (JsonbExpression $json) => $json->exists('nullable'), [1, 2, 3, 7]];
        yield 'nested key exists' => [static fn (JsonbExpression $json) => $json->path(['profile'])->exists('city'), [1, 2]];
        yield 'native array/scalar string existence' => [static fn (JsonbExpression $json) => $json->exists('red'), [9, 10]];
        yield 'JSONB numeric equality' => [static fn (JsonbExpression $json) => $json->path(['price'])->compare('=', 10), [1]];
        yield 'JSONB string equality' => [static fn (JsonbExpression $json) => $json->path(['price'])->compare('=', '10'), [3]];
        yield 'boolean equality' => [static fn (JsonbExpression $json) => $json->path(['brand'])->compare('=', false), [3]];
        yield 'text comparison excludes JSON boolean' => [static fn (JsonbExpression $json) => $json->path(['brand'])->compareText('=', 'false'), [8]];
        yield 'numbers exclude numeric strings' => [static fn (JsonbExpression $json) => $json->path(['price'])->compareNumber('>=', 10), [1, 7]];
        yield 'decimal string preserves precision' => [static fn (JsonbExpression $json) => $json->path(['price'])->compareNumber('=', '9007199254740993.000001'), [7]];
        yield 'lexical text comparison' => [static fn (JsonbExpression $json) => $json->path(['price'])->compareText('<', '2'), [3]];
        yield 'root JSON null' => [static fn (JsonbExpression $json) => $json->isJsonNull(), [6]];
        yield 'root SQL null' => [static fn (JsonbExpression $json) => $json->isSqlNull(), [5]];
        yield 'path JSON null' => [static fn (JsonbExpression $json) => $json->path(['nullable'])->isJsonNull(), [1, 7]];
        yield 'path SQL null includes SQL NULL root' => [static fn (JsonbExpression $json) => $json->path(['nullable'])->isSqlNull(), [4, 5, 6, 8, 9, 10]];
        yield 'missing excludes SQL NULL root' => [static fn (JsonbExpression $json) => $json->path(['nullable'])->isMissing(), [4, 6, 8, 9, 10]];
        yield 'negative array index' => [static fn (JsonbExpression $json) => $json->path(['nested', -1, 'value'])->compareNumber('=', 2), [1]];
        yield 'wrong structure is missing' => [static fn (JsonbExpression $json) => $json->path(['tags', 'not_an_index'])->isMissing(), [1, 2, 3, 4, 6, 7, 8, 9, 10]];
    }

    #[DataProvider('predicates')]
    public function testPredicatesPreserveJsonTypesTenantAndSoftDeleteFilters(\Closure $predicate, array $expected): void
    {
        $builder = $this->builder();
        $items = $builder->andWhere($predicate($builder->jsonb('p', 'attributes')))->orderBy('p.id')->getQuery()->getResult();
        self::assertSame($expected, array_map(static fn (JsonbCatalogItem $item) => $item->id, $items));
    }

    public function testPathProjectionsPreserveJsonTypesAndTextNullSemantics(): void
    {
        $builder = $this->builder()->andWhere('p.id IN (1, 3, 5, 6, 7, 8)');
        $data = $builder->jsonb('p', 'attributes');
        $rows = $builder->addScalar($data->path(['price'])->json(), 'price_json', 'price', 'jsonb')
            ->addScalar($data->path(['price'])->text(), 'price_text', 'priceText')
            ->addScalar($data->isJsonNull(), 'json_null', 'jsonNull', 'boolean')
            ->orderBy('p.id')->getQuery()->getResult();
        self::assertSame(10, $rows[0]['price']);
        self::assertSame('10', $rows[0]['priceText']);
        self::assertSame('10', $rows[1]['price']);
        self::assertSame('10', $rows[1]['priceText']);
        self::assertNull($rows[2]['price']);
        self::assertNull($rows[3]['price']);
        self::assertNull($rows[2]['jsonNull']);
        self::assertTrue($rows[3]['jsonNull']);
        self::assertSame('9007199254740993.000001', $rows[4]['priceText']);
        self::assertNull($rows[5]['priceText']);
        self::assertSame($this->em->find(JsonbCatalogItem::class, 1), $rows[0][0]);
    }

    public function testLiteralKeysWithQuotesDotsAndBracesCannotAlterSql(): void
    {
        $key = 'a.b"},x';
        $value = "' OR TRUE; DROP TABLE items;--";
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->table . ' SET "ProductData" = CAST(? AS jsonb) WHERE id = 4', [json_encode([$key => [$value]], JSON_THROW_ON_ERROR)]);
        $builder = $this->builder();
        $items = $builder->andWhere($builder->jsonb('p', 'attributes')->path([$key, 0])->compareText('=', $value))->getQuery()->getResult();
        self::assertCount(1, $items);
        self::assertSame(4, $items[0]->id);
        self::assertStringNotContainsString($key, $builder->getSQL());
        self::assertStringNotContainsString($value, $builder->getSQL());
    }

    public function testObjectAndLegacyJsonbFieldsAreSupported(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->table . ' SET objectData = \'{"a":1}\', legacy = \'{"legacy":true}\' WHERE id = 1');
        $builder = $this->builder();
        $items = $builder->andWhere($builder->jsonb('p', 'objectData')->compare('=', (object) ['a' => 1]))
            ->andWhere($builder->jsonb('p', 'legacy')->exists('legacy'))->getQuery()->getResult();
        self::assertCount(1, $items);
        self::assertInstanceOf(\stdClass::class, $items[0]->objectData);
    }

    public function testOrCompositionAndCursorPaginationUseBoundExpressions(): void
    {
        $builder = $this->builder();
        $data = $builder->jsonb('p', 'attributes');
        $builder->andWhere($builder->expr()->or($data->contains(['status' => 'active']), $data->path(['nullable'])->isJsonNull()));
        $paginator = new CursorPaginator(2, ['id' => 'ASC']);
        $first = $paginator->paginate($builder);
        self::assertSame([1, 2], array_map(static fn (JsonbCatalogItem $item) => $item->id, $first->getItems()));
        $second = $paginator->paginate($builder, $first->getNextCursorAsString());
        self::assertSame([7], array_map(static fn (JsonbCatalogItem $item) => $item->id, $second->getItems()));
        self::assertSame(3, $first->getTotalCount());
    }

    public function testClonesAndQuerySnapshotsKeepIndependentJsonbFilters(): void
    {
        $base = $this->builder();
        $first = clone $base;
        $first->andWhere($first->jsonb('p', 'attributes')->path(['price'])->compareNumber('=', 10));
        $snapshot = $first->getQuery();
        $second = clone $base;
        $second->andWhere($second->jsonb('p', 'attributes')->path(['price'])->compareText('=', '10'));
        self::assertSame([1], array_map(static fn ($item) => $item->id, $snapshot->getResult()));
        self::assertSame([3], array_map(static fn ($item) => $item->id, $second->getQuery()->getResult()));
        self::assertSame(['tenant' => 1], $base->getParameters());
    }
}
