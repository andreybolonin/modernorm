<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use ModernORM\Configuration;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\FullTextIndex;
use ModernORM\Tools\FullText\SchemaExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FullTextVersionRecord
{
    public int $id;
    public string $title;
}

final class FullTextServerVersionTest extends TestCase
{
    private function metadata(): ClassMetadata
    {
        $class = new ClassMetadata(FullTextVersionRecord::class);
        $class->setPrimaryTable(['name' => 'fts_version_record', 'schema' => 'public']);
        $class->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $class->mapField(['fieldName' => 'title', 'type' => 'text']);
        $class->fullTextIndexes = [new FullTextIndex('fts_version_search', ['title' => 'A'], 'pg_catalog.simple')];
        return $class;
    }

    private function extension(Connection $connection, ?Configuration $configuration = null): SchemaExtension
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getConfiguration')->willReturn($configuration ?? new Configuration());
        return new SchemaExtension($em);
    }

    public static function operations(): array
    {
        return ['create' => ['create'], 'update' => ['update'], 'migration' => ['migration'], 'initial migration' => ['initial']];
    }

    #[DataProvider('operations')]
    public function testUnsupportedServerFailsBeforeCatalogReadsOrSchemaMutation(string $operation): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getServerVersion')->willReturn('16.14');
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('fetchAllAssociative');
        $connection->expects(self::never())->method('executeStatement');
        $extension = $this->extension($connection);
        $from = new Schema();
        $to = new Schema();
        try {
            match ($operation) {
                'create' => $extension->createSql([$this->metadata()], $to),
                'update' => $extension->updateSql([$this->metadata()], $from, $to),
                default => $extension->migrationSql([$this->metadata()], $from, $to, $operation === 'initial'),
            };
            self::fail('Expected unsupported PostgreSQL version');
        } catch (RuntimeException $error) {
            self::assertSame('FullTextIndex requires PostgreSQL 17 or later; server version: 16.14', $error->getMessage());
        }
        self::assertSame([], $from->getTables());
        self::assertSame([], $to->getTables());
    }

    public static function supportedVersions(): array
    {
        return ['17' => ['17'], '17 minor' => ['17.11'], '18 build' => ['18.7 (Homebrew)']];
    }

    #[DataProvider('supportedVersions')]
    public function testSupportedServerCanGenerateInitialFullTextDdl(string $version): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getServerVersion')->willReturn($version);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => (new PostgreSQLPlatform())->quoteStringLiteral($value));
        $connection->expects(self::never())->method('executeStatement');
        $schema = new Schema();
        $table = $schema->createTable('public.fts_version_record');
        $table->addColumn('id', 'integer');
        $table->addColumn('title', 'text');
        $sql = $this->extension($connection)->createSql([$this->metadata()], $schema);
        self::assertCount(4, $sql);
        self::assertStringContainsString('GENERATED ALWAYS AS', $sql[0]);
        self::assertStringContainsString('USING GIN', $sql[2]);
    }

    public function testIgnoredOrAbsentFullTextMappingsDoNotProbeTheVersion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('getServerVersion');
        $connection->expects(self::never())->method('fetchOne');
        $class = $this->metadata();
        $configuration = new Configuration();
        $configuration->setSchemaIgnoreClasses([$class->name]);
        self::assertSame([], $this->extension($connection, $configuration)->createSql([$class], new Schema()));
        $class->fullTextIndexes = [];
        self::assertSame([], $this->extension($connection)->createSql([$class], new Schema()));
    }
}
