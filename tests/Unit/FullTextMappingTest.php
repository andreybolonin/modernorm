<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
#[ORM\FullTextIndex(name: 'product_search', fields: ['name' => 'A', 'description' => 'B'], configuration: 'pg_catalog.russian')]
class FullTextMappingProduct
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'text')]
    public string $name;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;
}

final class FullTextMappingTest extends TestCase
{
    private function manager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testMetadataSerializationKeepsFullTextSeparateFromEntityState(): void
    {
        $metadata = $this->manager()->getClassMetadata(FullTextMappingProduct::class);
        $restored = unserialize(serialize($metadata));
        $restored->assertSupportedMapping();
        self::assertEquals($metadata->fullTextIndexes, $restored->fullTextIndexes);
        self::assertSame(['name' => 'A', 'description' => 'B'], $restored->fullTextIndexes[0]->fields);
        self::assertFalse($restored->hasField('search_vector'));
        self::assertSame(['id', 'name', 'description'], array_keys($restored->fieldMappings));
    }

    public static function invalidMappings(): iterable
    {
        yield 'empty fields' => ['search', [], 'pg_catalog.russian', 'search_vector'];
        yield 'unknown field' => ['search', ['missing' => 'A'], 'pg_catalog.russian', 'search_vector'];
        yield 'nontext field' => ['search', ['id' => 'A'], 'pg_catalog.russian', 'search_vector'];
        yield 'weight' => ['search', ['name' => 'Z'], 'pg_catalog.russian', 'search_vector'];
        yield 'unqualified config' => ['search', ['name' => 'A'], 'russian', 'search_vector'];
        yield 'config SQL' => ['search', ['name' => 'A'], "pg_catalog.russian'); DROP TABLE x;--", 'search_vector'];
        yield 'column collision' => ['search', ['name' => 'A'], 'pg_catalog.russian', 'description'];
        yield 'index identifier' => ['search;drop', ['name' => 'A'], 'pg_catalog.russian', 'search_vector'];
        yield 'truncated identifier' => [str_repeat('x', 64), ['name' => 'A'], 'pg_catalog.russian', 'search_vector'];
        yield 'column identifier' => ['search', ['name' => 'A'], 'pg_catalog.russian', 'search.vector'];
    }

    #[DataProvider('invalidMappings')]
    public function testInvalidMappingsFailBeforeSql(string $name, array $fields, string $configuration, string $column): void
    {
        $metadata = $this->manager()->getClassMetadata(FullTextMappingProduct::class);
        $metadata->fullTextIndexes = [new ORM\FullTextIndex($name, $fields, $configuration, $column)];
        $this->expectException(MappingException::class);
        $metadata->assertSupportedMapping();
    }

    public function testDuplicateColumnsAreRejected(): void
    {
        $metadata = $this->manager()->getClassMetadata(FullTextMappingProduct::class);
        $metadata->fullTextIndexes[] = new ORM\FullTextIndex('other_search', ['name' => 'D'], 'pg_catalog.simple');
        $this->expectException(MappingException::class);
        $metadata->assertSupportedMapping();
    }

    public function testRawDbalSchemaExportFailsInsteadOfSilentlyOmittingFullText(): void
    {
        $em = $this->manager();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DBAL Schema cannot represent FullTextIndex');
        (new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(FullTextMappingProduct::class)]);
    }

}
