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

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\AbstractAsset;
use ModernORM\EntityManager;
use ModernORM\Id\SequenceGenerator;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'accounts')]
final class RoundTripAccount
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    public string $code = 'account';
}

#[ORM\Entity, ORM\Table(name: '`Ledger`')]
#[ORM\Index(name: 'ledger_active_idx', fields: ['code'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\UniqueConstraint(name: 'ledger_account_code', fields: ['account', 'code'])]
final class RoundTripLedger
{
    #[ORM\Id, ORM\Column]
    public string $id = 'a';

    #[ORM\Column(name: '`ExternalCode`', length: 32)]
    public string $code = 'entry';

    #[ORM\Column(length: 128, nullable: true, options: ['default' => 'initial'])]
    public ?string $note = 'initial';

    #[ORM\Column(options: ['default' => 0])]
    public int $amount = 0;

    #[ORM\Column(options: ['default' => false])]
    public bool $enabled = false;

    #[ORM\Column(options: ['default' => ''])]
    public string $empty = '';

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: RoundTripAccount::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public ?RoundTripAccount $account = null;
}

#[ORM\Entity, ORM\Table(name: 'sequence_records')]
final class RoundTripSequence
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'roundtrip_ids', allocationSize: 3, initialValue: 10)]
    public ?int $id = null;
}

final class SchemaRoundTripTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    private \Closure $filter;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_roundtrip_' . bin2hex(random_bytes(6));
        $this->filter = static fn (): bool => false;
        $dbal = new DbalConfiguration();
        $dbal->setSchemaAssetsFilter($this->filter);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        foreach ($this->metadata() as $class) {
            $class->table['schema'] = $this->schema;
        }
        $sequence = $this->em->getClassMetadata(RoundTripSequence::class);
        $definition = $sequence->sequenceGeneratorDefinition;
        $definition['sequenceName'] = $this->schema . '.roundtrip_ids';
        $sequence->setSequenceGeneratorDefinition($definition);
        $sequence->setIdGenerator(new SequenceGenerator($definition['sequenceName'], 3));
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

    /** @return list<\ModernORM\Mapping\ClassMetadata> */
    private function metadata(): array
    {
        return array_map($this->em->getClassMetadata(...), [RoundTripAccount::class, RoundTripLedger::class, RoundTripSequence::class]);
    }

    public static function schemaApis(): iterable
    {
        yield 'classic DBAL schema API' => [false];
        if ((new \ReflectionClass(Schema::class))->hasMethod('edit')) {
            yield 'DBAL editor API' => [true];
        }
    }

    private function create(bool $editor): SchemaTool
    {
        $this->em->getConfiguration()->setUseDbalEditorApi($editor);
        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->metadata());
        return $tool;
    }

    private function assertConverged(SchemaTool $tool): void
    {
        $this->assertEmptyDiff($tool);
        $this->assertEmptyDiff($tool);
        self::assertSame($this->filter, $this->em->getConnection()->getConfiguration()->getSchemaAssetsFilter());
    }

    private function assertEmptyDiff(SchemaTool $tool): void
    {
        self::assertSame([], $tool->getUpdateSchemaSql($this->metadata()));
    }

    #[DataProvider('schemaApis')]
    public function testCreateAndIntrospectionConvergeWithRealIdentifiers(bool $editor): void
    {
        $tool = $this->create($editor);
        $guard = $this->em->getConnection()->quoteSingleIdentifier($this->schema) . '."UnmanagedGuard"';
        $this->em->getConnection()->executeStatement('CREATE TABLE ' . $guard . ' (id INT)');
        $this->assertConverged($tool);
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT to_regclass(?)', [$guard]));
        $account = new RoundTripAccount();
        $ledger = new RoundTripLedger();
        $ledger->account = $account;
        $sequence = new RoundTripSequence();
        foreach ([$account, $ledger, $sequence] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        self::assertNotNull($account->id);
        self::assertSame(10, $sequence->id);
        $this->assertConverged($tool);
        $this->em->clear();
        self::assertSame('entry', $this->em->find(RoundTripLedger::class, 'a')->code);
    }

    #[DataProvider('schemaApis')]
    public function testAlterDefaultsColumnsIndexesForeignKeysAndSequenceConverges(bool $editor): void
    {
        $tool = $this->create($editor);
        $this->assertConverged($tool);
        $ledger = $this->em->getClassMetadata(RoundTripLedger::class);
        $ledger->fieldMappings['note']->length = 256;
        $ledger->fieldMappings['note']->nullable = false;
        $ledger->fieldMappings['note']->options['default'] = "updated 'quoted' default";
        $ledger->table['indexes']['ledger_note_idx'] = ['fields' => ['note']];
        $ledger->table['indexes']['ledger_active_idx']['options']['where'] = 'deleted_at IS NOT NULL';
        $ledger->associationMappings['account']->joinColumns[0]->onDelete = 'SET NULL';
        $sequence = $this->em->getClassMetadata(RoundTripSequence::class);
        $definition = $sequence->sequenceGeneratorDefinition;
        $definition['allocationSize'] = 5;
        $sequence->setSequenceGeneratorDefinition($definition);
        $sequence->setIdGenerator(new SequenceGenerator($definition['sequenceName'], 5));
        $sql = $tool->getUpdateSchemaSql($this->metadata());
        self::assertNotSame([], $sql);
        foreach ($sql as $statement) {
            self::assertStringNotContainsString('DROP TABLE', $statement);
            $this->em->getConnection()->executeStatement($statement);
        }
        $this->assertConverged($tool);
        $connection = $this->em->getConnection();
        $table = $connection->quoteSingleIdentifier($this->schema) . '."Ledger"';
        $connection->executeStatement('INSERT INTO ' . $table . ' (id, "ExternalCode") VALUES (?, ?)', ['raw', 'raw']);
        self::assertSame("updated 'quoted' default", $connection->fetchOne('SELECT note FROM ' . $table . ' WHERE id = ?', ['raw']));
        self::assertSame(5, (int) $connection->fetchOne('SELECT increment_by FROM pg_sequences WHERE schemaname = ? AND sequencename = ?', [$this->schema, 'roundtrip_ids']));
        $record = new RoundTripSequence();
        $this->em->persist($record);
        $this->em->flush();
        self::assertSame(10, $record->id);
    }

    #[DataProvider('schemaApis')]
    public function testRepeatedDropHasNoRemainingMappedAssets(bool $editor): void
    {
        $tool = $this->create($editor);
        $this->assertConverged($tool);
        // Drop introspection must see the owned assets even with the application's restrictive filter.
        $this->em->getConnection()->getConfiguration()->setSchemaAssetsFilter(fn (string|AbstractAsset $asset): bool => str_starts_with($asset instanceof AbstractAsset ? $asset->getName() : $asset, $this->schema . '.'));
        $tool->dropSchema($this->metadata());
        self::assertSame([], $tool->getDropSchemaSQL($this->metadata()));
        $tool->dropSchema($this->metadata());
    }
    #[DataProvider('schemaApis')]
    public function testSimplePartialPredicateConvergesAndRealChangeIsDetected(bool $editor): void
    {
        $this->em->getConfiguration()->setUseDbalEditorApi($editor);
        $account = $this->em->getClassMetadata(RoundTripAccount::class);
        $account->table['indexes']['account_positive_idx'] = ['fields' => ['id'], 'options' => ['where' => 'id > 0']];
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$account]);
        $this->assertAccountConverged($tool, $account);
        $account->table['indexes']['account_positive_idx']['options']['where'] = 'id > 1';
        $sql = $tool->getUpdateSchemaSql([$account]);
        self::assertNotSame([], $sql);
        foreach ($sql as $statement) {
            $this->em->getConnection()->executeStatement($statement);
        }
        self::assertSame([], $tool->getUpdateSchemaSql([$account]));
    }

    #[DataProvider('schemaApis')]
    public function testDropUsesActualForeignKeyNames(bool $editor): void
    {
        $this->em->getConfiguration()->setUseDbalEditorApi($editor);
        $account = $this->em->getClassMetadata(RoundTripAccount::class);
        $ledger = $this->em->getClassMetadata(RoundTripLedger::class);
        $ledger->table['name'] = 'ledger_unquoted';
        unset($ledger->table['quoted'], $ledger->table['indexes']['ledger_active_idx']);
        $tool = new SchemaTool($this->em);
        $tool->createSchema([$account, $ledger]);
        $this->em->getConnection()->getConfiguration()->setSchemaAssetsFilter(fn (string|AbstractAsset $asset): bool => str_starts_with($asset instanceof AbstractAsset ? $asset->getName() : $asset, $this->schema . '.'));
        $tool->dropSchema([$account, $ledger]);
        self::assertSame([], $tool->getDropSchemaSQL([$account, $ledger]));
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT tablename FROM pg_tables WHERE schemaname = ?', [$this->schema]));
    }

    private function assertAccountConverged(SchemaTool $tool, \ModernORM\Mapping\ClassMetadata $account): void
    {
        self::assertSame([], $tool->getUpdateSchemaSql([$account]));
    }

}
