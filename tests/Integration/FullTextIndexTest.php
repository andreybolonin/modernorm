<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: '`Products`')]
#[ORM\FullTextIndex(name: 'ProductSearch', fields: ['name' => 'A', 'description' => 'B'], configuration: 'pg_catalog.russian', column: 'SearchVector')]
final class FullTextProduct
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(name: '`ProductName`', type: 'text')]
    public string $name;
    #[ORM\Column(type: 'string', length: 250, nullable: true)]
    public ?string $description = null;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $note = null;
}

final class FullTextIndexTest extends TestCase
{
    private EntityManager $em;
    private ORM\ClassMetadata $metadata;
    private string $schema;
    private string $table;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_fts_' . bin2hex(random_bytes(6));
        $dbal = new DbalConfiguration();
        $dbal->setSchemaAssetsFilter(static fn (): bool => false);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $this->metadata = $this->em->getClassMetadata(FullTextProduct::class);
        $this->metadata->table['schema'] = $this->schema;
        $this->table = '"' . $this->schema . '"."Products"';
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        $connection->close();
    }

    public static function schemaApis(): iterable
    {
        yield 'classic' => [false];
        yield 'editor' => [true];
    }

    private function create(bool $editor = false): SchemaTool
    {
        $this->em->getConfiguration()->setUseDbalEditorApi($editor);
        $tool = new SchemaTool($this->em);
        $create = $tool->getCreateSchemaSql([$this->metadata]);
        self::assertStringContainsString('USING GIN ("SearchVector")', implode("\n", $create));
        $tool->createSchema([$this->metadata]);
        return $tool;
    }

    private function assertConverged(SchemaTool $tool): void
    {
        self::assertSame([], $tool->getUpdateSchemaSql([$this->metadata]));
        self::assertSame([], $tool->getUpdateSchemaSql([$this->metadata]));
    }

    private function apply(array $sql): void
    {
        foreach ($sql as $statement) {
            $this->em->getConnection()->executeStatement($statement);
        }
    }

    private function ranking(): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM ' . $this->table . ' WHERE "SearchVector" @@ websearch_to_tsquery(?::regconfig, ?)'
                . ' ORDER BY ts_rank("SearchVector", websearch_to_tsquery(?::regconfig, ?)) DESC, id',
            ['pg_catalog.russian', 'промышленное оборудование', 'pg_catalog.russian', 'промышленное оборудование'],
        ));
    }

    #[DataProvider('schemaApis')]
    public function testStoredVectorsWeightsAndOrdinaryChangesConverge(bool $editor): void
    {
        $tool = $this->create($editor);
        $this->assertConverged($tool);
        foreach ([[1, 'промышленное оборудование', null], [2, 'станок', 'промышленное оборудование']] as [$id, $name, $description]) {
            $entity = new FullTextProduct();
            $entity->id = $id;
            $entity->name = $name;
            $entity->description = $description;
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();
        self::assertSame([1, 2], $this->ranking());
        $query = $this->em->createQueryBuilder()->fromEntity(FullTextProduct::class, 'p')
            ->where('p."SearchVector" @@ websearch_to_tsquery(:config::regconfig, :query)')
            ->setParameter('config', 'pg_catalog.russian')->setParameter('query', 'оборудование')->orderBy('p.id')->getQuery();
        self::assertSame([1, 2], array_map(static fn ($entity) => $entity->id, $query->getResult()));
        self::assertFalse($this->metadata->hasField('SearchVector'));

        $this->metadata->fullTextIndexes = [new ORM\FullTextIndex('ProductSearch', ['name' => 'B', 'description' => 'A'], 'pg_catalog.russian', 'SearchVector')];
        $sql = $tool->getUpdateSchemaSql([$this->metadata]);
        self::assertCount(1, $sql);
        self::assertStringContainsString('SET EXPRESSION AS', $sql[0]);
        $this->apply($sql);
        self::assertSame([2, 1], $this->ranking());
        $this->assertConverged($tool);

        $this->metadata->fieldMappings['note']->options['default'] = 'pending';
        $sql = $tool->getUpdateSchemaSql([$this->metadata]);
        self::assertCount(1, $sql);
        self::assertStringContainsString('SET DEFAULT', $sql[0]);
        $this->apply($sql);
        $this->assertConverged($tool);
        $entity = $this->em->find(FullTextProduct::class, 1);
        $entity->name = 'новое';
        $this->em->flush();
        self::assertSame([2], $this->ranking());
        $dropSql = $tool->getDropSchemaSQL([$this->metadata]);
        self::assertNotEmpty($dropSql);
        $tool->dropSchema([$this->metadata]);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT to_regclass(?)', [$this->table]));
    }

    #[DataProvider('schemaApis')]
    public function testMissingAssetsAreRepairedAndNewTableCanBeCreatedByUpdate(bool $editor): void
    {
        $this->em->getConfiguration()->setUseDbalEditorApi($editor);
        $tool = new SchemaTool($this->em);
        $tool->updateSchema([$this->metadata]);
        $this->assertConverged($tool);
        $this->em->getConnection()->executeStatement('DROP INDEX "' . $this->schema . '"."ProductSearch"');
        $sql = $tool->getUpdateSchemaSql([$this->metadata]);
        self::assertCount(2, $sql);
        $this->apply($sql);
        $this->assertConverged($tool);
        $this->em->getConnection()->executeStatement('ALTER TABLE ' . $this->table . ' DROP COLUMN "SearchVector"');
        $sql = $tool->getUpdateSchemaSql([$this->metadata]);
        self::assertCount(4, $sql);
        $this->apply($sql);
        $this->assertConverged($tool);
    }

    public function testDiffRunsInReadOnlyTransactionAndSurvivesSearchPathChanges(): void
    {
        $tool = $this->create();
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TEXT SEARCH CONFIGURATION "' . $this->schema . '".russian (COPY = pg_catalog.simple)');
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION READ ONLY');
        $connection->executeStatement('SET LOCAL search_path TO ' . '"' . $this->schema . '", pg_catalog');
        $this->assertConverged($tool);
        $this->metadata->fullTextIndexes = [new ORM\FullTextIndex('ProductSearch', ['name' => 'B', 'description' => 'A'], 'pg_catalog.russian', 'SearchVector')];
        self::assertCount(1, $tool->getUpdateSchemaSql([$this->metadata]));
        $connection->rollBack();
        $this->metadata->fullTextIndexes = [new ORM\FullTextIndex('ProductSearch', ['name' => 'A', 'description' => 'B'], 'pg_catalog.simple', 'SearchVector')];
        $sql = $tool->getUpdateSchemaSql([$this->metadata]);
        self::assertCount(1, $sql);
        $this->apply($sql);
        $this->assertConverged($tool);
    }

    public function testRemovingAttributeRequiresExplicitMigration(): void
    {
        $tool = $this->create();
        $this->metadata->fullTextIndexes = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Removing or renaming');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }

    public function testRenamingIndexRequiresExplicitMigration(): void
    {
        $tool = $this->create();
        $this->metadata->fullTextIndexes = [new ORM\FullTextIndex('NewSearch', ['name' => 'A'], 'pg_catalog.russian', 'SearchVector')];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Removing or renaming');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }

    public function testChangingSourcesRequiresExplicitMigration(): void
    {
        $tool = $this->create();
        $this->metadata->fullTextIndexes = [new ORM\FullTextIndex('ProductSearch', ['name' => 'A'], 'pg_catalog.russian', 'SearchVector')];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('source columns');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }

    public function testChangingSourceTypeRequiresExplicitMigration(): void
    {
        $tool = $this->create();
        $this->metadata->fieldMappings['description']->length = 500;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('source column type');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }

    public function testUnownedColumnIsRejected(): void
    {
        $tool = $this->create();
        $connection = $this->em->getConnection();
        $connection->executeStatement('ALTER TABLE ' . $this->table . ' DROP COLUMN "SearchVector"');
        $connection->executeStatement('ALTER TABLE ' . $this->table . ' ADD COLUMN "SearchVector" text');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('column collision');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }

    public function testImplicitSchemaUsesTheResolvedNamespaceForIndexComments(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE SCHEMA "' . $this->schema . '"');
        $connection->executeStatement('SET search_path TO "' . $this->schema . '", pg_catalog');
        unset($this->metadata->table['schema']);
        $tool = $this->create();
        $this->assertConverged($tool);
        $connection->executeStatement('DROP INDEX "' . $this->schema . '"."ProductSearch"');
        $tool->updateSchema([$this->metadata]);
        $this->assertConverged($tool);
    }

    public function testMultipleVectorsOnOneEntityConverge(): void
    {
        $this->metadata->fullTextIndexes[] = new ORM\FullTextIndex('OtherSearch', ['note' => 'D'], 'pg_catalog.simple', 'OtherVector');
        $tool = $this->create();
        $this->assertConverged($tool);
    }

    public function testUnownedGeneratedVectorIsNotAdopted(): void
    {
        $tool = $this->create();
        $this->em->getConnection()->executeStatement('COMMENT ON COLUMN ' . $this->table . '."SearchVector" IS NULL');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('column collision');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }

    public static function wrongIndexes(): iterable
    {
        yield 'btree' => ['(id)', true];
        yield 'partial GIN' => ['USING GIN ("SearchVector") WHERE id > 0', true];
        yield 'expression GIN' => ["USING GIN (to_tsvector('pg_catalog.simple'::regconfig, note))", true];
        yield 'unowned GIN' => ['USING GIN ("SearchVector")', false];
    }

    #[DataProvider('wrongIndexes')]
    public function testWrongIndexShapeIsRejectedEvenWithOwnershipComment(string $definition, bool $owned): void
    {
        $tool = $this->create();
        $connection = $this->em->getConnection();
        $index = '"' . $this->schema . '"."ProductSearch"';
        $connection->executeStatement('DROP INDEX ' . $index);
        $connection->executeStatement('CREATE INDEX "ProductSearch" ON ' . $this->table . ' ' . $definition);
        if ($owned) {
            $connection->executeStatement("COMMENT ON INDEX " . $index . " IS 'modernorm:fulltext:v1:SearchVector'");
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('index collision');
        $tool->getUpdateSchemaSql([$this->metadata]);
    }
}
