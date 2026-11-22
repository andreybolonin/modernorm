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

#[ORM\Entity, ORM\Table(name: 'trigger_orders')]
#[ORM\Trigger(name: 'trg_order_audit', timing: 'after', events: ['delete', 'UPDATE', 'insert'], functionSql: 'audit.sql')]
#[ORM\Check(name: 'positive_id', expression: 'id > 0')]
final class TriggerOrder
{
    #[ORM\Id, ORM\Column]
    public int $id;
}

#[ORM\Entity]
#[ORM\Trigger(name: 'audit', timing: 'BEFORE', events: ['INSERT'])]
final class InvalidTriggerOrder
{
    #[ORM\Id, ORM\Column]
    public int $id;
}

final class TriggerSchemaTest extends TestCase
{
    private EntityManager $em;
    private SchemaTool $tool;
    private string $schema;
    private string $directory;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_tr_' . bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir() . '/' . $this->schema;
        mkdir($this->directory);
        $config = new Configuration();
        $config->setSchemaAssetsFilter(fn (string $name): bool => str_starts_with($name, $this->schema . '.')
            && ! str_ends_with($name, '.audit_log'));
        $orm = ORMSetup::createAttributeMetadataConfig([__DIR__], true);
        $orm->setTriggerFunctionSqlRoot($this->directory);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $config), $orm);
        $this->tool = new SchemaTool($this->em);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        $connection->close();
        foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAppliedFunctionVersionAndTriggerConvergeAcrossBodyUpdates(): void
    {
        $metadata = $this->metadata();
        $first = $this->writeSource('audit.sql', 'first');
        $plan = $this->tool->getSchemaMigrationSql([$metadata], true);
        $connection = $this->em->getConnection();
        self::assertFalse($connection->fetchOne('SELECT to_regclass(?) IS NOT NULL', [$this->schema . '.modernorm_trigger_functions']));
        $second = $this->writeSource('audit.sql', 'second');
        $this->apply($plan['up']);
        $table = $this->schema . '.trigger_orders';
        $connection->executeStatement('CREATE TABLE ' . $this->schema . '.audit_log (entry text)');
        $connection->executeStatement('INSERT INTO ' . $table . ' VALUES (1)');
        $connection->executeStatement('UPDATE ' . $table . ' SET id = 2');
        $connection->executeStatement('DELETE FROM ' . $table);
        self::assertSame(['first:INSERT', 'first:UPDATE', 'first:DELETE'], $connection->fetchFirstColumn('SELECT entry FROM ' . $this->schema . '.audit_log'));
        self::assertSame(hash('sha256', $first), $this->appliedHash());
        $triggerOid = $this->triggerOid();

        $update = $this->tool->getSchemaMigrationSql([$metadata]);
        self::assertContains($second, $update['up']);
        self::assertCount(1, array_filter($update['up'], static fn (string $sql): bool => str_starts_with($sql, 'CREATE OR REPLACE FUNCTION')));
        self::assertSame([], array_filter($update['up'], static fn (string $sql): bool => str_starts_with($sql, 'CREATE TRIGGER')));
        self::assertSame(hash('sha256', $first), $this->appliedHash());

        try {
            $this->apply([...$update['up'], 'SELECT 1 / 0']);
            self::fail('Expected migration to roll back');
        } catch (DriverException) {
            self::assertSame(hash('sha256', $first), $this->appliedHash());
        }
        $connection->executeStatement('INSERT INTO ' . $table . ' VALUES (3)');
        $this->apply($update['up']);
        $connection->executeStatement('INSERT INTO ' . $table . ' VALUES (4)');
        self::assertSame(['first:INSERT', 'first:UPDATE', 'first:DELETE', 'first:INSERT', 'second:INSERT'], $connection->fetchFirstColumn('SELECT entry FROM ' . $this->schema . '.audit_log'));
        self::assertSame(hash('sha256', $second), $this->appliedHash());
        self::assertSame($triggerOid, $this->triggerOid());
        self::assertSame(['up' => [], 'down' => []], $this->tool->getSchemaMigrationSql([$metadata]));
        self::assertSame([], $this->tool->getUpdateSchemaSql([$metadata]));

        try {
            $this->apply($update['down']);
            self::fail('Managed function migration must require explicit rollback');
        } catch (DriverException $failure) {
            self::assertStringContainsString('explicit rollback', $failure->getMessage());
        }
    }

    public function testExternalFunctionAndUnsupportedTriggerDrift(): void
    {
        $metadata = $this->metadata();
        $metadata->checks = [];
        $metadata->triggers = [new ORM\Trigger('custom trigger', 'BEFORE', ['INSERT'], $this->schema . '.ExplicitFn')];
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $connection->executeStatement('CREATE FUNCTION ' . $this->schema . '."ExplicitFn"() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END; $$');
        $this->apply($this->tool->getSchemaMigrationSql([$metadata])['up']);
        self::assertSame([], $this->tool->getUpdateSchemaSql([$metadata]));

        $withoutTrigger = clone $metadata;
        $withoutTrigger->triggers = [];
        self::assertSame([], $this->tool->getUpdateSchemaSql([$withoutTrigger]));
        $connection->executeStatement('ALTER TABLE ' . $this->schema . '.trigger_orders DISABLE TRIGGER "custom trigger"');
        $this->expectExceptionMessage('blocked trigger drift');
        $this->tool->getUpdateSchemaSql([$metadata]);
    }

    public function testConflictingSharedFunctionFilesAreBlocked(): void
    {
        $metadata = $this->metadata();
        $this->writeSource('audit.sql', 'first');
        $this->writeSource('second.sql', 'second');
        $metadata->triggers[] = new ORM\Trigger('other', 'AFTER', ['INSERT'], 'fn_order_audit', 'second.sql');
        $this->expectExceptionMessage('conflicting SQL definitions');
        $this->tool->getSchemaMigrationSql([$metadata], true);
    }

    public function testExistingUnownedFunctionIsRejected(): void
    {
        $metadata = $this->metadata();
        $sql = $this->writeSource('audit.sql', 'first');
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $connection->executeStatement($sql);
        $this->expectExceptionMessage('conflicts with an external');
        $this->tool->getSchemaMigrationSql([$metadata]);
    }

    public function testSharedFunctionIsInstalledOnlyOnce(): void
    {
        $metadata = $this->metadata();
        $source = $this->writeSource('audit.sql', 'first');
        $metadata->triggers[] = new ORM\Trigger('other', 'AFTER', ['INSERT'], 'fn_order_audit', 'audit.sql');
        $statements = $this->tool->getSchemaMigrationSql([$metadata], true)['up'];
        self::assertCount(1, array_keys($statements, $source, true));
        self::assertCount(2, array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'CREATE TRIGGER')));
    }

    public function testMissingExternalFunctionRollsBackTableCreation(): void
    {
        $metadata = $this->metadata();
        $metadata->triggers = [new ORM\Trigger('trg_unavailable', 'BEFORE', ['INSERT'])];
        try {
            $this->tool->createSchema([$metadata]);
            self::fail('Expected PostgreSQL to report a missing trigger function');
        } catch (\ModernORM\Exception\ToolsException $failure) {
            self::assertStringContainsString('fn_unavailable', $failure->getMessage());
            self::assertNull($this->em->getConnection()->fetchOne('SELECT pg_catalog.to_regnamespace(?)', [$this->schema]));
        }
    }

    public function testDiffCannotImplicitlyRemoveAUserTriggerByDroppingItsTable(): void
    {
        $metadata = $this->metadata();
        $this->writeSource('audit.sql', 'first');
        $this->tool->createSchema([$metadata]);

        $this->expectExceptionMessage('would remove CHECK constraints or triggers');
        $this->tool->getSchemaMigrationSql([]);
    }

    public function testFileWithAdditionalStatementIsRejected(): void
    {
        $metadata = $this->metadata();
        $sql = $this->writeSource('audit.sql', 'first');
        file_put_contents($this->directory . '/audit.sql', $sql . "\nSELECT 1;");
        $this->expectExceptionMessage('requires one schema-qualified CREATE OR REPLACE FUNCTION');
        $this->tool->getSchemaMigrationSql([$metadata], true);
    }

    public function testMissingPrefixAndExplicitFunctionAreRejectedDuringMapping(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('requires a trg_ prefix or an explicit function');
        $this->em->getClassMetadata(InvalidTriggerOrder::class);
    }

    private function metadata(): ORM\ClassMetadata
    {
        $metadata = $this->em->getClassMetadata(TriggerOrder::class);
        $metadata->table['schema'] = $this->schema;

        return $metadata;
    }

    private function writeSource(string $file, string $version): string
    {
        $sql = sprintf(<<<'SQL'
            CREATE OR REPLACE FUNCTION %1$s.fn_order_audit()
            RETURNS trigger LANGUAGE plpgsql AS $body$
            BEGIN
                INSERT INTO %1$s.audit_log(entry) VALUES ('%2$s:' || TG_OP);
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $body$;
            SQL, $this->schema, $version) . "\n";
        file_put_contents($this->directory . '/' . $file, $sql);

        return $sql;
    }

    /** @param list<string> $statements */
    private function apply(array $statements): void
    {
        $connection = $this->em->getConnection();
        $connection->transactional(static function () use ($connection, $statements): void {
            foreach ($statements as $statement) {
                $connection->executeStatement($statement);
            }
        });
    }

    private function appliedHash(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT source_hash FROM ' . $this->schema . '.modernorm_trigger_functions');
    }

    private function triggerOid(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT oid FROM pg_catalog.pg_trigger WHERE tgrelid = pg_catalog.to_regclass(?) AND tgname = ?',
            [$this->schema . '.trigger_orders', 'trg_order_audit'],
        );
    }
}
