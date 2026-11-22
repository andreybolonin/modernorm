<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[ORM\Entity]
#[ORM\Table(name: '`Check Orders`')]
#[ORM\Check(name: 'Ordered Range', expression: '"End" >= "Start"')]
#[ORM\Check(name: 'Nonnegative', expression: '"Start" >= 0')]
final class CheckedOrder
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column(name: '`Start`')]
    public int $start;

    #[ORM\Column(name: '`End`', nullable: true)]
    public ?int $end;
}

#[ORM\Entity]
#[ORM\Check(name: 'duplicate', expression: 'true')]
#[ORM\Check(name: 'duplicate', expression: 'false')]
final class DuplicateCheck
{
    #[ORM\Id, ORM\Column]
    public int $id;
}

final class CheckConstraintTest extends TestCase
{
    private EntityManager $em;
    private string $schema;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_check_' . bin2hex(random_bytes(8));
        $config = new Configuration();
        $config->setSchemaAssetsFilter(static fn (): bool => false);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $config), ORMSetup::createAttributeMetadataConfig([__DIR__], true));
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP SCHEMA IF EXISTS ' . $connection->quoteSingleIdentifier($this->schema) . ' CASCADE');
        $connection->close();
    }

    private function metadata(): ORM\ClassMetadata
    {
        $metadata = $this->em->getClassMetadata(CheckedOrder::class);
        $metadata->table['schema'] = $this->schema;

        return $metadata;
    }

    private function table(): string
    {
        return $this->schema . '."Check Orders"';
    }

    public function testCreationEnforcementAndEmptyDiff(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $connection = $this->em->getConnection();
        $plan = $tool->getSchemaMigrationSql([$metadata], true);
        $connection->transactional(static function () use ($connection, $plan): void {
            foreach ($plan['up'] as $sql) {
                $connection->executeStatement($sql);
            }
        });
        self::assertSame([], $tool->getUpdateSchemaSql([$metadata]));
        self::assertSame(['up' => [], 'down' => []], $tool->getSchemaMigrationSql([$metadata]));

        $table = $this->table();
        $connection->executeStatement('INSERT INTO ' . $table . ' VALUES (1, 10, NULL)');
        $this->assertCheckViolation('INSERT INTO ' . $table . ' VALUES (2, 10, 9)');
        $this->assertCheckViolation('UPDATE ' . $table . ' SET "End" = 9 WHERE id = 1');
        $this->assertCheckViolation('INSERT INTO ' . $table . ' VALUES (3, -1, NULL)');

        $equivalent = clone $metadata;
        $equivalent->checks[0] = new ORM\Check('Ordered Range', '( ("End" >= "Start") )');
        self::assertSame([], $tool->getUpdateSchemaSql([$equivalent]));

        foreach ($plan['down'] as $sql) {
            $connection->executeStatement($sql);
        }
        self::assertNull($connection->fetchOne('SELECT to_regclass(?)', [$table]));
    }

    public function testAbsentDeclarationsLeaveDatabaseChecksAlone(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        $connection = $this->em->getConnection();
        $connection->executeStatement('ALTER TABLE ' . $this->table() . ' ADD CHECK (id <> 42)');
        $withoutChecks = clone $metadata;
        $withoutChecks->checks = [];

        self::assertSame([], $tool->getUpdateSchemaSql([$withoutChecks]));
        self::assertSame(['up' => [], 'down' => []], $tool->getSchemaMigrationSql([$withoutChecks]));
        $this->assertCheckViolation('INSERT INTO ' . $this->table() . ' VALUES (42, 0, NULL)');
    }

    public function testChangedCheckBlocksDiff(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        $changed = clone $metadata;
        $changed->checks[0] = new ORM\Check('Ordered Range', '"End" > "Start"');

        $this->expectExceptionMessage('definition_drift');
        $tool->getUpdateSchemaSql([$changed]);
    }

    public function testMigrationForExistingTableAddsAndRevertsOnlyTheMissingCheck(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $initial = clone $metadata;
        array_pop($initial->checks);
        $tool->createSchema([$initial]);
        $connection = $this->em->getConnection();
        $table = $this->table();
        $connection->executeStatement('INSERT INTO ' . $table . ' VALUES (1, -1, NULL)');
        $plan = $tool->getSchemaMigrationSql([$metadata]);
        self::assertCount(1, $plan['up']);
        self::assertCount(1, $plan['down']);
        $this->assertCheckViolation($plan['up'][0]);

        $connection->executeStatement('UPDATE ' . $table . ' SET "Start" = 0');
        $connection->executeStatement($plan['up'][0]);
        self::assertSame([], $tool->getUpdateSchemaSql([$metadata]));
        $connection->executeStatement($plan['down'][0]);
        self::assertSame([], $tool->getUpdateSchemaSql([$initial]));
        $this->assertCheckViolation('UPDATE ' . $table . ' SET "End" = -1');
    }

    public function testUnvalidatedCheckBlocksDiff(): void
    {
        $metadata = $this->metadata();
        $initial = clone $metadata;
        $initial->checks = [];
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$initial]);
        $this->em->getConnection()->executeStatement(
            'ALTER TABLE ' . $this->table() . ' ADD CONSTRAINT "Nonnegative" CHECK ("Start" >= 0) NOT VALID',
        );

        $this->expectExceptionMessage('unsupported CHECK definition');
        $tool->getUpdateSchemaSql([$metadata]);
    }

    public function testReadOnlyComparisonReportsAmbiguityWithoutBreakingTransaction(): void
    {
        $metadata = $this->metadata();
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$metadata]);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION READ ONLY');

        try {
            $tool->getUpdateSchemaSql([$metadata]);
            self::fail('Expected an ambiguous CHECK definition');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('ambiguous_definition', $failure->getMessage());
            self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        }
    }

    public function testDuplicateNameFailsDuringMetadataCompilation(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('duplicate CHECK name');
        $this->em->getClassMetadata(DuplicateCheck::class);
    }

    private function assertCheckViolation(string $sql): void
    {
        try {
            $this->em->getConnection()->executeStatement($sql);
            self::fail('Expected PostgreSQL to reject the write');
        } catch (DriverException $failure) {
            self::assertSame('23514', $failure->getSQLState());
        }
    }
}
