<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[ORM\Entity]
#[ORM\Table(name: 'bookings')]
#[ORM\Index(name: 'active_codes', fields: ['code'], where: 'active = true', unique: true)]
#[ORM\Index(name: 'inactive_codes', columns: ['code'], where: 'active = false')]
#[ORM\Exclude(name: 'no_overlaps', elements: ['resource WITH =', 'int4range(starts, ends) WITH &&'], where: "active AND code <> 'ignored'")]
final class AdvancedBooking
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $code;

    #[ORM\Column]
    public bool $active = true;

    #[ORM\Column]
    public int $resource = 1;

    #[ORM\Column]
    public int $starts = 10;

    #[ORM\Column]
    public int $ends = 20;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $rate = '2.50';

    #[ORM\Column(type: 'decimal', precision: 16, scale: 2, nullable: true, generated: '(ends - starts) * rate')]
    public ?string $total = null;
}

final class AdvancedSchemaTest extends TestCase
{
    private EntityManager $em;
    private string $schema;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_advanced_' . bin2hex(random_bytes(6));
        $config = new Configuration();
        $config->setSchemaAssetsFilter(fn (string $name): bool => str_starts_with($name, $this->schema . '.'));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $config), ORMSetup::createAttributeMetadataConfig([__DIR__], true));
        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        $this->em->getConnection()->close();
    }

    public static function schemaApis(): iterable
    {
        yield 'classic' => [false];
        yield 'editor' => [true];
    }

    #[DataProvider('schemaApis')]
    public function testMigrationRoundTripAndRuntimeSemantics(bool $editor): void
    {
        $this->em->getConfiguration()->setUseDbalEditorApi($editor);
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $this->apply($tool->getSchemaMigrationSql([$metadata], true)['up']);
        $connection = $this->em->getConnection();
        $role = $connection->quoteSingleIdentifier($this->schema . '_reader');
        $connection->executeStatement('CREATE ROLE ' . $role);
        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET TRANSACTION READ ONLY');
            $connection->executeStatement('SET LOCAL ROLE ' . $role);
            self::assertFalse($connection->fetchOne("SELECT has_schema_privilege(?, 'USAGE')", [$this->schema]));
            self::assertSame([], $tool->getUpdateSchemaSql([$metadata]));
            self::assertSame(['up' => [], 'down' => []], $tool->getSchemaMigrationSql([$metadata]));
        } finally {
            $connection->rollBack();
            $connection->executeStatement('DROP ROLE ' . $role);
        }

        $first = new AdvancedBooking();
        $first->id = 1;
        $first->code = 'occupied';
        $first->total = '999.99';
        $this->em->persist($first);
        $this->em->flush();
        self::assertSame('25.00', $first->total);
        $first->rate = '3.00';
        $this->em->flush();
        self::assertSame('30.00', $first->total);
        $this->em->clear();
        self::assertSame('30.00', $this->em->find(AdvancedBooking::class, 1)?->total);

        $table = $this->schema . '.bookings';
        $this->assertSqlState('23P01', "INSERT INTO $table VALUES (2, 'other', true, 1, 15, 20, 1)");
        $this->assertSqlState('23505', "INSERT INTO $table VALUES (2, 'occupied', true, 1, 20, 30, 1)");
        $this->em->getConnection()->executeStatement("INSERT INTO $table VALUES (2, 'occupied', false, 1, 15, 20, 1)");
        $this->em->getConnection()->executeStatement("INSERT INTO $table VALUES (3, 'next', true, 1, 20, 30, 1)");
        $connection = $this->em->getConnection();
        $connection->executeStatement("ALTER TABLE $table ADD COLUMN unmanaged integer, ADD UNIQUE (unmanaged)");
        $connection->executeStatement("ALTER TABLE $table ADD FOREIGN KEY (unmanaged) REFERENCES $table (id)");
        $connection->executeStatement("CREATE INDEX unmanaged_resource ON $table (resource)");

        $withoutExclude = clone $metadata;
        $withoutExclude->exclusions = [];
        unset($withoutExclude->table['indexes']['inactive_codes']);
        self::assertSame([], $tool->getUpdateSchemaSql([$withoutExclude]));
        $this->assertSqlState('23P01', "INSERT INTO $table VALUES (4, 'other', true, 1, 15, 20, 1)");

        $returned = $this->em->bulk()->update(AdvancedBooking::class, ['rate' => '4.00'], 'id = 1', BulkPolicy::BYPASS, returning: ['total']);
        self::assertSame('40.00', $returned->returnedRows[0]['total']);
        try {
            $this->em->bulk()->update(AdvancedBooking::class, ['total' => '100.00'], 'id = 1', BulkPolicy::BYPASS);
            self::fail('Expected bulk writes to reject a generated field');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('cannot assign generated column', $failure->getMessage());
        }
    }

    public static function unsafeChanges(): iterable
    {
        yield 'index predicate' => ['index'];
        yield 'exclusion predicate' => ['exclude'];
        yield 'generated expression' => ['expression'];
        yield 'generated removal' => ['remove'];
    }

    #[DataProvider('unsafeChanges')]
    public function testUnsafeChangesRequireExplicitMigration(string $change): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        match ($change) {
            'index' => $metadata->table['indexes']['active_codes']['options']['where'] = 'active = false',
            'exclude' => $metadata->exclusions = [new ORM\Exclude('no_overlaps', ['resource WITH =', 'int4range(starts, ends) WITH &&'], where: 'NOT active')],
            'expression' => $metadata->fieldMappings['total']->storedExpression = '(ends - starts) * rate * 2',
            'remove' => $metadata->fieldMappings['total']->storedExpression = null,
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit migration');
        $tool->getSchemaMigrationSql([$metadata]);
    }

    public function testGeneratedColumnCanBeAddedBeforeAnIndexInAnExistingTable(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $existing = clone $metadata;
        unset($existing->fieldMappings['total']);
        $tool->createSchema([$existing]);

        $metadata->table['indexes']['positive_totals'] = [
            'fields' => ['total'],
            'options' => ['where' => 'total > 0', 'modernorm_declared_predicate' => true],
        ];
        $plan = $tool->getSchemaMigrationSql([$metadata]);
        $this->apply($plan['up']);
        self::assertSame([], $tool->getUpdateSchemaSql([$metadata]));
        $this->apply($plan['down']);
        self::assertSame([], $tool->getUpdateSchemaSql([$existing]));
    }

    public function testExclusionCannotReplaceAnUnmanagedIndexWithTheSameName(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $existing = clone $metadata;
        $existing->exclusions = [];
        $tool->createSchema([$existing]);
        $this->em->getConnection()->executeStatement('CREATE INDEX no_overlaps ON ' . $this->schema . '.bookings (resource)');

        $this->expectExceptionMessage('conflicts with an existing relation');
        $tool->getSchemaMigrationSql([$metadata]);
    }

    public function testCatalogDriftInvalidatesTheAppliedDefinitionStamp(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        $connection = $this->em->getConnection();
        $table = $this->schema . '.bookings';
        $lookup = "SELECT pg_catalog.col_description(attrelid, attnum) FROM pg_catalog.pg_attribute WHERE attrelid = pg_catalog.to_regclass(?) AND attname = 'total'";
        $comment = $connection->fetchOne($lookup, [$table]);
        self::assertIsString($comment);
        self::assertStringContainsString('modernorm:definition:', $comment);
        $connection->executeStatement("ALTER TABLE $table ALTER COLUMN total SET EXPRESSION AS (rate * 4)");
        self::assertSame($comment, $connection->fetchOne($lookup, [$table]));

        $this->expectExceptionMessage('changed or ambiguous PostgreSQL definition');
        $tool->getSchemaMigrationSql([$metadata]);
    }

    private function metadata(): ORM\ClassMetadata
    {
        $metadata = $this->em->getClassMetadata(AdvancedBooking::class);
        $metadata->table['schema'] = $this->schema;

        return $metadata;
    }

    /** @param list<string> $statements */
    private function apply(array $statements): void
    {
        $connection = $this->em->getConnection();
        $connection->transactional(static function () use ($connection, $statements): void {
            foreach ($statements as $sql) {
                $connection->executeStatement($sql);
            }
        });
    }

    private function assertSqlState(string $state, string $statement): void
    {
        try {
            $this->em->getConnection()->executeStatement($statement);
            self::fail('Expected PostgreSQL to reject the statement');
        } catch (DriverException $failure) {
            self::assertSame($state, $failure->getSQLState());
        }
    }
}
