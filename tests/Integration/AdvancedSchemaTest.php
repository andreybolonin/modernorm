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
#[ORM\Index(name: 'inactive_codes', columns: ['code'], options: ['where' => 'active = false'])]
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
    public function testMigrationRoundTripAndExternallyManagedAssets(bool $editor): void
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

        $table = $this->schema . '.bookings';
        $connection->executeStatement("CREATE UNIQUE INDEX active_codes ON $table (code) WHERE active = true");
        $connection->executeStatement("ALTER TABLE $table ADD COLUMN total numeric GENERATED ALWAYS AS ((ends - starts) * rate) STORED");
        self::assertSame([], $tool->getUpdateSchemaSql([$metadata]));
        self::assertSame(['up' => [], 'down' => []], $tool->getSchemaMigrationSql([$metadata]));
        $this->em->bulk()->insert(AdvancedBooking::class, [[
            'id' => 1, 'code' => 'occupied', 'active' => true, 'resource' => 1,
            'starts' => 10, 'ends' => 20, 'rate' => '2.50',
        ]], BulkPolicy::BYPASS);
        self::assertSame('25.00', $connection->fetchOne("SELECT total FROM $table WHERE id = 1"));

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
    }

    public function testChangedExclusionRequiresExplicitMigration(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        $metadata->exclusions = [new ORM\Exclude('no_overlaps', ['resource WITH =', 'int4range(starts, ends) WITH &&'], where: 'NOT active')];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit migration');
        $tool->getSchemaMigrationSql([$metadata]);
    }

    public function testGeneratedColumnCannotBeReplacedByMappedColumn(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        $table = $this->schema . '.bookings';
        $this->em->getConnection()->executeStatement("ALTER TABLE $table ADD COLUMN total numeric GENERATED ALWAYS AS ((ends - starts) * rate) STORED");
        $metadata->mapField(['fieldName' => 'total', 'type' => 'decimal']);

        $this->expectExceptionMessage('is a generated column in PostgreSQL');
        $tool->getSchemaMigrationSql([$metadata]);
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
        $lookup = "SELECT pg_catalog.obj_description(oid, 'pg_constraint') FROM pg_catalog.pg_constraint WHERE conrelid = pg_catalog.to_regclass(?) AND conname = 'no_overlaps'";
        $comment = $connection->fetchOne($lookup, [$table]);
        self::assertIsString($comment);
        self::assertStringContainsString('modernorm:definition:', $comment);
        $connection->executeStatement("ALTER TABLE $table DROP CONSTRAINT no_overlaps, ADD CONSTRAINT no_overlaps EXCLUDE USING gist (resource WITH =, int4range(starts, ends) WITH &&) WHERE (NOT active)");
        $connection->executeStatement("COMMENT ON CONSTRAINT no_overlaps ON $table IS " . $connection->quote($comment));

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
