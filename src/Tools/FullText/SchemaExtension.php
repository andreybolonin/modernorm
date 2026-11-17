<?php

declare(strict_types=1);

namespace ModernORM\Tools\FullText;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\FullTextIndex;
use RuntimeException;

/** @internal Owns the FTS assets that DBAL's ordinary schema model cannot represent. */
final class SchemaExtension
{
    private const OWNER = 'modernorm:fulltext:v1:';
    private readonly Connection $connection;
    private readonly Expression $reader;
    private ?string $defaultSchema = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
        $this->reader = new Expression($this->connection);
    }

    /** @param list<ClassMetadata> $classes
     *  @return list<string>
     */
    public function createSql(array $classes, Schema $schema): array
    {
        $sql = [];
        foreach ($this->classes($classes, $schema) as $class) {
            foreach ($class->fullTextIndexes as $index) {
                $this->assertTarget($class, $index, $schema);
                $sql = [...$sql, ...$this->createColumn($class, $index), ...$this->createIndex($class, $index, $this->schemaName($schema, $class))];
            }
        }
        return $sql;
    }

    /** Removes only validated, owned assets from DBAL's in-memory comparison schema.
     *  @param list<ClassMetadata> $classes
     *  @return list<string>
     */
    public function updateSql(array $classes, Schema $from, Schema $to): array
    {
        return $this->plan($classes, $from, $to, false, false)['up'];
    }

    /** @param list<ClassMetadata> $classes
     *  @return array{up: list<string>, down: list<string>}
     */
    public function migrationSql(array $classes, Schema $from, Schema $to, bool $fromEmptySchema): array
    {
        return $this->plan($classes, $from, $to, true, $fromEmptySchema);
    }

    /** @param list<ClassMetadata> $classes
     *  @return array{up: list<string>, down: list<string>}
     */
    private function plan(array $classes, Schema $from, Schema $to, bool $reverse, bool $fromEmptySchema): array
    {
        $sql = [];
        $down = [];
        foreach ($this->classes($classes, $to) as $class) {
            $tableSql = $this->tableSql($class);
            $relation = $fromEmptySchema ? false : $this->connection->fetchAssociative(
                'SELECT c.oid, c.relkind, n.nspname AS schema_name FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = pg_catalog.to_regclass(?)',
                [$tableSql],
            );
            if ($relation === false) {
                foreach ($class->fullTextIndexes as $index) {
                    $this->assertTarget($class, $index, $to);
                    $sql = [...$sql, ...$this->createColumn($class, $index), ...$this->createIndex($class, $index, $this->schemaName($to, $class))];
                }
                continue;
            }
            $columns = $this->connection->fetchAllAssociative(
                "SELECT a.attname, a.attnum, a.attgenerated, a.atttypid = 'pg_catalog.tsvector'::pg_catalog.regtype AS vector,
                        pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS expression,
                        pg_catalog.col_description(a.attrelid, a.attnum) AS comment
                 FROM pg_catalog.pg_attribute a
                 LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE a.attrelid = ? AND a.attnum > 0 AND NOT a.attisdropped",
                [$relation['oid']],
            );
            $columns = array_column($columns, null, 'attname');
            $desired = [];
            foreach ($class->fullTextIndexes as $index) {
                $desired[$index->column] = self::OWNER . $index->name;
            }
            foreach ($columns as $name => $state) {
                if (str_starts_with($state['comment'] ?? '', self::OWNER) && ($desired[$name] ?? null) !== $state['comment']) {
                    throw new RuntimeException('Removing or renaming a FullTextIndex requires an explicit migration: ' . $tableSql . '.' . $name);
                }
            }
            $fromTable = $this->schemaTable($from, $class);
            $toTable = $this->schemaTable($to, $class);
            foreach ($class->fullTextIndexes as $index) {
                $this->assertTarget($class, $index, $to);
                if ($relation['relkind'] !== 'r') {
                    throw new RuntimeException('FullTextIndex currently supports ordinary PostgreSQL tables only.');
                }
                $state = $columns[$index->column] ?? null;
                if ($state !== null) {
                    if ($state['comment'] !== self::OWNER . $index->name || $state['attgenerated'] !== 's' || ! $state['vector']) {
                        throw new RuntimeException('FullTextIndex column collision; refusing to replace an unowned or non-STORED tsvector column: ' . $index->column);
                    }
                    $actual = $this->reader->read($state['expression']);
                    $expected = $this->reader->read($this->expression($class, $index));
                    $actualColumns = $this->reader->columns($actual);
                    $expectedColumns = $this->reader->columns($expected);
                    sort($actualColumns);
                    sort($expectedColumns);
                    if ($actualColumns !== $expectedColumns) {
                        throw new RuntimeException('Changing FullTextIndex source columns requires an explicit migration.');
                    }
                    foreach ($expectedColumns as $column) {
                        $old = $fromTable->getColumn($column);
                        $new = $toTable->getColumn($column);
                        if ($old->getTypeName() !== $new->getTypeName() || $old->getLength() !== $new->getLength()
                            || $old->getFixed() !== $new->getFixed() || $old->getCollation() !== $new->getCollation()) {
                            throw new RuntimeException('Changing a FullTextIndex source column type requires an explicit migration.');
                        }
                    }
                    if ($actual !== $expected) {
                        if ($reverse) {
                            $down[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $this->quote($index->column)
                                . ' SET EXPRESSION AS (' . $this->reader->render($actual) . ')';
                        }
                        $sql[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $this->quote($index->column)
                            . ' SET EXPRESSION AS (' . $this->expression($class, $index) . ')';
                    }
                } else {
                    $sql = [...$sql, ...$this->createColumn($class, $index)];
                    if ($reverse) {
                        $down[] = 'ALTER TABLE ' . $tableSql . ' DROP COLUMN ' . $this->quote($index->column);
                    }
                }
                $indexState = $this->connection->fetchAssociative(
                    "SELECT i.indrelid, am.amname, i.indisvalid, i.indisready, i.indisunique,
                            i.indnatts, i.indnkeyatts, i.indkey::text AS keys,
                            i.indexprs IS NULL AND i.indpred IS NULL AS plain,
                            opc.opcname, ns.nspname AS opclass_schema,
                            pg_catalog.obj_description(c.oid, 'pg_class') AS comment
                     FROM pg_catalog.pg_class c
                     JOIN pg_catalog.pg_class t ON t.oid = ? AND t.relnamespace = c.relnamespace
                     LEFT JOIN pg_catalog.pg_index i ON i.indexrelid = c.oid
                     LEFT JOIN pg_catalog.pg_am am ON am.oid = c.relam
                     LEFT JOIN pg_catalog.pg_opclass opc ON opc.oid = i.indclass[0]
                     LEFT JOIN pg_catalog.pg_namespace ns ON ns.oid = opc.opcnamespace
                     WHERE c.relname = ?",
                    [$relation['oid'], $index->name],
                );
                if ($indexState === false) {
                    $sql = [...$sql, ...$this->createIndex($class, $index, $relation['schema_name'])];
                    if ($reverse && $state !== null) {
                        $down[] = 'DROP INDEX ' . $this->quote($relation['schema_name']) . '.' . $this->quote($index->name);
                    }
                } else {
                    if ($state === null || $indexState['comment'] !== self::OWNER . $index->column
                        || $indexState['indrelid'] != $relation['oid'] || $indexState['amname'] !== 'gin'
                        || ! $indexState['indisvalid'] || ! $indexState['indisready'] || $indexState['indisunique']
                        || (int) $indexState['indnatts'] !== 1 || (int) $indexState['indnkeyatts'] !== 1
                        || $indexState['keys'] !== (string) $state['attnum'] || ! $indexState['plain']
                        || $indexState['opcname'] !== 'tsvector_ops' || $indexState['opclass_schema'] !== 'pg_catalog') {
                        throw new RuntimeException('FullTextIndex index collision or unsupported GIN definition: ' . $index->name);
                    }
                    $fromTable->dropIndex($index->name);
                }
                foreach ($fromTable->getIndexes() as $other) {
                    $otherColumns = array_map(
                        fn ($column) => $column->getColumnName()->getIdentifier()->toNormalizedValue($this->connection->getDatabasePlatform()->getUnquotedIdentifierFolding()),
                        $other->getIndexedColumns(),
                    );
                    if (in_array($index->column, $otherColumns, true)) {
                        throw new RuntimeException('Additional indexes on a FullTextIndex technical column require an explicit migration.');
                    }
                }
                if ($state !== null) {
                    $fromTable->dropColumn($index->column);
                }
            }
        }
        return ['up' => $sql, 'down' => $down];
    }

    /** @param list<ClassMetadata> $classes
     *  @return list<ClassMetadata>
     */
    private function classes(array $classes, Schema $schema): array
    {
        $result = [];
        $indexes = [];
        $versionChecked = false;
        foreach ($classes as $class) {
            $class->assertSupportedMapping();
            if ($class->isMappedSuperclass || $class->isEmbeddedClass
                || in_array($class->name, $this->em->getConfiguration()->getSchemaIgnoreClasses(), true)) {
                continue;
            }
            if (isset($result[$class->name])) {
                continue;
            }
            if ($class->fullTextIndexes !== [] && ! $versionChecked) {
                $this->assertSupportedServer();
                $versionChecked = true;
            }
            foreach ($class->fullTextIndexes as $index) {
                $key = $this->schemaName($schema, $class) . '.' . $index->name;
                if (isset($indexes[$key])) {
                    throw new RuntimeException('FullTextIndex names must be unique within a database schema: ' . $index->name);
                }
                $indexes[$key] = true;
            }
            $result[$class->name] = $class;
        }
        return array_values($result);
    }

    private function assertSupportedServer(): void
    {
        $version = $this->connection->getServerVersion();
        if (version_compare($version, '17', '<')) {
            throw new RuntimeException('FullTextIndex requires PostgreSQL 17 or later; server version: ' . $version);
        }
    }

    private function assertTarget(ClassMetadata $class, FullTextIndex $index, Schema $schema): void
    {
        $table = $this->schemaTable($schema, $class);
        if ($table->hasColumn($index->column) || $table->hasIndex($index->name)) {
            throw new RuntimeException('FullTextIndex assets collide with the ordinary generated schema.');
        }
    }

    private function schemaTable(Schema $schema, ClassMetadata $class): Table
    {
        return $schema->getTable($this->tableSql($class));
    }

    private function schemaName(Schema $schema, ClassMetadata $class): string
    {
        $qualifier = $this->schemaTable($schema, $class)->getObjectName()->getQualifier();
        if ($qualifier !== null) {
            return $qualifier->toNormalizedValue($this->connection->getDatabasePlatform()->getUnquotedIdentifierFolding());
        }
        if ($this->defaultSchema === null) {
            $name = $this->connection->fetchOne('SELECT pg_catalog.current_schema()');
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('FullTextIndex requires a resolved PostgreSQL schema.');
            }
            $this->defaultSchema = $name;
        }
        return $this->defaultSchema;
    }

    private function tableSql(ClassMetadata $class): string
    {
        return $this->em->getConfiguration()->getQuoteStrategy()->getTableName($class, $this->connection->getDatabasePlatform());
    }

    private function quote(string $name): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($name);
    }

    private function expression(ClassMetadata $class, FullTextIndex $index): string
    {
        $parts = [];
        foreach ($index->fields as $field => $weight) {
            $column = $this->em->getConfiguration()->getQuoteStrategy()->getColumnName($field, $class, $this->connection->getDatabasePlatform());
            $parts[] = 'pg_catalog.setweight(pg_catalog.to_tsvector(' . $this->connection->quote($index->configuration)
                . '::pg_catalog.regconfig, COALESCE(' . $column . "::pg_catalog.text, ''::pg_catalog.text)), "
                . $this->connection->quote($weight) . '::pg_catalog."char")';
        }
        return implode(' || ', $parts);
    }

    /** @return list<string> */
    private function createColumn(ClassMetadata $class, FullTextIndex $index): array
    {
        $column = $this->tableSql($class) . '.' . $this->quote($index->column);
        return [
            'ALTER TABLE ' . $this->tableSql($class) . ' ADD COLUMN ' . $this->quote($index->column)
                . ' pg_catalog.tsvector GENERATED ALWAYS AS (' . $this->expression($class, $index) . ') STORED',
            'COMMENT ON COLUMN ' . $column . ' IS ' . $this->connection->quote(self::OWNER . $index->name),
        ];
    }

    /** @return list<string> */
    private function createIndex(ClassMetadata $class, FullTextIndex $index, string $schema): array
    {
        $qualified = $this->quote($schema) . '.';
        return [
            'CREATE INDEX ' . $this->quote($index->name) . ' ON ' . $this->tableSql($class) . ' USING GIN (' . $this->quote($index->column) . ')',
            'COMMENT ON INDEX ' . $qualified . $this->quote($index->name) . ' IS ' . $this->connection->quote(self::OWNER . $index->column),
        ];
    }
}
