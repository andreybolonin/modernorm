<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use LengthException;
use ModernORM\EntityManager;
use ModernORM\EntityManagerInterface;
use ModernORM\Id\IdentityGenerator;
use ModernORM\Mapping\AnsiQuoteStrategy;
use ModernORM\Query\ResultSetMappingBuilder;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_pg_write')]
final class PostgreSqlWriteRecord
{
    #[ORM\Id, ORM\Column(name: '`RecordId`'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'initial';
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_pg_empty')]
final class PostgreSqlEmptyRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_pg_big')]
final class PostgreSqlBigRecord
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue]
    public ?int $id = null;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_pg_generated')]
final class PostgreSqlGeneratedRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'initial';

    #[ORM\Column(insertable: false, updatable: false, generated: 'ALWAYS', options: ['default' => 'pending'])]
    public string $computed;

    #[ORM\Version, ORM\Column(type: 'integer')]
    public int $version;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_pg_sequence')]
final class PostgreSqlSequenceRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'modernorm_test_pg_explicit_seq', allocationSize: 3)]
    public ?int $id = null;
}

enum PostgreSqlAssignedId: string
{
    case FIRST = 'first';
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_pg_assigned')]
final class PostgreSqlAssignedRecord
{
    #[ORM\Id, ORM\Column(type: 'string', enumType: PostgreSqlAssignedId::class)]
    public PostgreSqlAssignedId $id = PostgreSqlAssignedId::FIRST;

    #[ORM\Column(insertable: false, updatable: false, generated: 'ALWAYS', options: ['default' => 'db-value'])]
    public string $computed;
}

final class ObservedIdentityGenerator extends IdentityGenerator
{
    public int $calls = 0;

    public function generateId(EntityManagerInterface $em, ?object $entity): int
    {
        ++$this->calls;
        return parent::generateId($em, $entity);
    }
}

final class PostgreSqlWriteTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [
            PostgreSqlWriteRecord::class, PostgreSqlEmptyRecord::class,
            PostgreSqlBigRecord::class, PostgreSqlGeneratedRecord::class, PostgreSqlAssignedRecord::class,
            PostgreSqlSequenceRecord::class,
        ]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        while ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        $conn->executeStatement('DROP TABLE IF EXISTS modernorm_test_pg_write, modernorm_test_pg_empty, modernorm_test_pg_big, modernorm_test_pg_generated, modernorm_test_pg_assigned, modernorm_test_pg_sequence CASCADE');
        $conn->executeStatement('DROP FUNCTION IF EXISTS modernorm_test_pg_write_trigger() CASCADE');
        $conn->executeStatement('DROP SEQUENCE IF EXISTS modernorm_test_pg_audit_seq, modernorm_test_pg_explicit_seq');
        $conn->close();
    }

    public function testIdentityBelongsToInsertedRowEvenWhenTriggerUsesAnotherSequence(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('CREATE SEQUENCE modernorm_test_pg_audit_seq START 9000');
        $conn->executeStatement("CREATE FUNCTION modernorm_test_pg_write_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM nextval('modernorm_test_pg_audit_seq'); RETURN NEW; END $$");
        $conn->executeStatement('CREATE TRIGGER audit_insert AFTER INSERT ON modernorm_test_pg_write FOR EACH ROW EXECUTE FUNCTION modernorm_test_pg_write_trigger()');
        $first = new PostgreSqlWriteRecord();
        $second = new PostgreSqlWriteRecord();
        $this->em->persist($first);
        $this->em->persist($second);
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(1, $first->id);
        self::assertSame(2, $second->id);
        self::assertSame($first, $this->em->find(PostgreSqlWriteRecord::class, 1));
        self::assertSame(2, $this->statistics->snapshot()->dml->insert->count);
        self::assertSame(2, $this->statistics->snapshot()->dml->insert->affectedRows);
        $first->name = 'updated';
        $this->em->flush();
        self::assertSame('updated', $conn->fetchOne('SELECT name FROM modernorm_test_pg_write WHERE "RecordId" = 1'));
    }

    public function testDefaultValuesInsertAndBigintIdentity(): void
    {
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_pg_big ALTER COLUMN id RESTART WITH 5000000000');
        $empty = new PostgreSqlEmptyRecord();
        $big = new PostgreSqlBigRecord();
        $this->em->persist($empty);
        $this->em->persist($big);
        $this->em->flush();
        self::assertSame(1, $empty->id);
        self::assertSame(5000000000, $big->id);
        $this->em->clear();
        self::assertSame(5000000000, $this->em->find(PostgreSqlBigRecord::class, $big->id)->id);
    }

    public function testCustomIdentitySubclassStillRunsItsGenerator(): void
    {
        $generator = new ObservedIdentityGenerator();
        $this->em->getClassMetadata(PostgreSqlWriteRecord::class)->setIdGenerator($generator);
        $record = new PostgreSqlWriteRecord();
        $this->em->persist($record);
        $this->em->flush();
        self::assertSame(1, $generator->calls);
        self::assertSame(1, $record->id);
    }

    public function testGeneratedFieldsObserveSeparateUpdatesFromAfterTriggers(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("CREATE FUNCTION modernorm_test_pg_write_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE modernorm_test_pg_generated SET computed = 'computed:' || NEW.name WHERE id = NEW.id; RETURN NEW; END $$");
        $conn->executeStatement('CREATE TRIGGER compute_fields AFTER INSERT OR UPDATE OF name ON modernorm_test_pg_generated FOR EACH ROW EXECUTE FUNCTION modernorm_test_pg_write_trigger()');
        $record = new PostgreSqlGeneratedRecord();
        $this->em->persist($record);
        $this->em->flush();
        self::assertSame('computed:initial', $record->computed);
        self::assertSame(1, $record->version);
        $record->name = 'changed';
        $this->em->flush();
        self::assertSame('computed:changed', $record->computed);
        self::assertSame(2, $record->version);
    }

    public function testAssignedEnumIdentifierCanFetchGeneratedValues(): void
    {
        $record = new PostgreSqlAssignedRecord();
        $this->em->persist($record);
        $this->em->flush();
        self::assertSame('db-value', $record->computed);
        self::assertSame(PostgreSqlAssignedId::FIRST, $record->id);
        self::assertSame($record, $this->em->find(PostgreSqlAssignedRecord::class, 'first'));
    }

    public function testSequenceAllocationAndRepeatedSchemaDrop(): void
    {
        $records = [];
        for ($i = 0; $i < 4; ++$i) {
            $records[] = $record = new PostgreSqlSequenceRecord();
            $this->em->persist($record);
        }
        $this->em->flush();
        self::assertSame([1, 2, 3, 4], array_map(static fn (PostgreSqlSequenceRecord $record): ?int => $record->id, $records));
        $schema = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(PostgreSqlSequenceRecord::class)];
        $schema->dropSchema($metadata);
        self::assertSame([], $schema->getDropSchemaSQL($metadata));
        $schema->dropSchema($metadata);
        self::assertFalse($this->em->getConnection()->createSchemaManager()->introspectSchema()->hasSequence('modernorm_test_pg_explicit_seq'));
    }

    public function testMixedCaseNativeResultAliasHydratesEntity(): void
    {
        $record = new PostgreSqlEmptyRecord();
        $this->em->persist($record);
        $this->em->flush();
        $this->em->clear();
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(PostgreSqlEmptyRecord::class, 'e', ['id' => 'MixedCaseId'], ResultSetMappingBuilder::COLUMN_RENAMING_CUSTOM);
        $sql = 'SELECT ' . $mapping->generateSelectClause() . ' FROM modernorm_test_pg_empty e';
        $loaded = $this->em->createNativeQuery($sql, $mapping)->getSingleResult();
        self::assertInstanceOf(PostgreSqlEmptyRecord::class, $loaded);
        self::assertSame($record->id, $loaded->id);
    }

    public function testAnsiQuoteStrategyAliasesHydrateOnPostgreSql(): void
    {
        $this->em->getConfiguration()->setQuoteStrategy(new AnsiQuoteStrategy());
        $record = new PostgreSqlEmptyRecord();
        $this->em->persist($record);
        $this->em->flush();
        $this->em->clear();
        $loaded = $this->em->find(PostgreSqlEmptyRecord::class, $record->id);
        self::assertInstanceOf(PostgreSqlEmptyRecord::class, $loaded);
        self::assertSame($record->id, $loaded->id);
    }

    public function testSkippedInsertCannotReuseAnEarlierIdentity(): void
    {
        $first = new PostgreSqlWriteRecord();
        $this->em->persist($first);
        $this->em->flush();
        $conn = $this->em->getConnection();
        $conn->executeStatement('CREATE FUNCTION modernorm_test_pg_write_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
        $conn->executeStatement('CREATE TRIGGER skip_insert BEFORE INSERT ON modernorm_test_pg_write FOR EACH ROW EXECUTE FUNCTION modernorm_test_pg_write_trigger()');
        $skipped = new PostgreSqlWriteRecord();
        $this->em->persist($skipped);
        try {
            $this->em->flush();
            self::fail('Expected an error for an INSERT without a returned identifier.');
        } catch (LengthException $error) {
            self::assertSame('INSERT RETURNING did not return an identifier.', $error->getMessage());
        }
        self::assertNull($skipped->id);
        self::assertFalse($this->em->isOpen());
        self::assertFalse($conn->isTransactionActive());
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM modernorm_test_pg_write'));
    }
}
