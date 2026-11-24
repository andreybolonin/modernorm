<?php

declare(strict_types=1);

namespace ModernORM\Tools\PostgreSql;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use RuntimeException;

/** @internal Projects PostgreSQL-specific catalog state into the ordinary DBAL diff. */
final readonly class AdvancedSchema
{
    private const EXCLUSIONS = <<<'SQL'
        SELECT c.conname, i.relname AS backing_index, pg_catalog.pg_get_constraintdef(c.oid) AS definition,
               pg_catalog.obj_description(c.oid, 'pg_constraint') AS object_comment,
               pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(
                   ROW(c.condeferrable, c.condeferred, c.conexclop, ix.indkey, ix.indclass, ix.indcollation,
                       ix.indexprs::text, ix.indpred::text, pg_catalog.pg_get_constraintdef(c.oid))::text, 'UTF8')), 'hex') AS fingerprint
        FROM pg_catalog.pg_constraint c
        JOIN pg_catalog.pg_class i ON i.oid = c.conindid
        JOIN pg_catalog.pg_index ix ON ix.indexrelid = i.oid
        JOIN pg_catalog.pg_class t ON t.oid = c.conrelid
        JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
        WHERE c.contype = 'x' AND n.nspname = ? AND t.relname = ?
        SQL;

    private const GENERATED = <<<'SQL'
        SELECT a.attname
        FROM pg_catalog.pg_attribute a
        JOIN pg_catalog.pg_class t ON t.oid = a.attrelid
        JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
        WHERE n.nspname = ? AND t.relname = ? AND a.attnum > 0 AND NOT a.attisdropped AND a.attgenerated <> ''
        SQL;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<ClassMetadata> $classes
     * @return array{up: list<string>, down: list<string>}
     */
    public function plan(array $classes, Schema $target, ?Schema $current): array
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $config = $this->em->getConfiguration();
        $up = $down = $seen = $exclusionNames = $stamps = [];
        foreach ($classes as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedClass || isset($seen[$class->name])
                || in_array($class->name, $config->getSchemaIgnoreClasses(), true)) {
                continue;
            }
            $seen[$class->name] = true;
            if (! $platform instanceof PostgreSQLPlatform) {
                if ($class->exclusions !== []) {
                    throw new RuntimeException($class->name . ' declares EXCLUDE constraints, which require PostgreSQL.');
                }
                continue;
            }

            $mappedName = $config->getQuoteStrategy()->getTableName($class, $platform);
            $table = $target->getTable($mappedName);
            $old = $current?->hasTable($mappedName) ? $current->getTable($mappedName) : null;
            if ($old === null && $class->exclusions === []) {
                continue;
            }
            $identity = $table->getObjectName();
            $folding = $platform->getUnquotedIdentifierFolding();
            $schema = $identity->getQualifier()?->toNormalizedValue($folding)
                ?? $connection->fetchOne('SELECT pg_catalog.current_schema()');
            if (! is_string($schema) || $schema === '') {
                throw new RuntimeException($class->name . ' requires a resolved PostgreSQL schema.');
            }
            $schemaSql = $platform->quoteSingleIdentifier($schema);
            $tableName = $identity->getUnqualifiedName()->toNormalizedValue($folding);
            $tableSql = $schemaSql . '.' . $platform->quoteSingleIdentifier($tableName);
            $relation = [$schema, $tableName];

            $actual = [];
            if ($old !== null) {
                foreach ($connection->fetchAllAssociative(self::EXCLUSIONS, $relation) as $exclusion) {
                    $actual[$exclusion['conname']] = $exclusion;
                    $index = $exclusion['backing_index'];
                    if ($table->hasIndex($index)) {
                        throw new RuntimeException($tableSql . ' index ' . $index . ' conflicts with an EXCLUDE backing index.');
                    }
                    if ($old->hasIndex($index)) {
                        $old->dropIndex($index);
                    }
                }
                $this->protectGeneratedColumns($old, $table, $tableSql, $relation);
                $this->protectPartialIndexes($old, $table, $tableSql);
            }

            foreach ($class->exclusions as $exclude) {
                $name = $platform->quoteSingleIdentifier($exclude->name);
                $key = $tableSql . '.' . $name;
                if (isset($exclusionNames[$key])) {
                    throw new RuntimeException('Duplicate EXCLUDE declaration: ' . $key);
                }
                $exclusionNames[$key] = true;
                if ($table->hasIndex($exclude->name)) {
                    throw new RuntimeException($key . ' conflicts with a mapped index.');
                }
                $definition = 'EXCLUDE USING gist (' . implode(', ', $exclude->elements) . ')'
                    . ($exclude->where === null ? '' : ' WHERE (' . $exclude->where . ')');
                if (isset($actual[$exclude->name])) {
                    $this->requireEquivalent($definition, $actual[$exclude->name]['definition'], $actual[$exclude->name], $key);
                    continue;
                }
                if ($current !== null && $connection->fetchOne(
                    'SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?)',
                    [$schema, $exclude->name],
                )) {
                    throw new RuntimeException($key . ' conflicts with an existing relation named like its backing index.');
                }
                if ($old !== null && $connection->fetchOne(
                    'SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class t ON t.oid = c.conrelid'
                        . ' JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?)',
                    [...$relation, $exclude->name],
                )) {
                    throw new RuntimeException($key . ' conflicts with an existing constraint.');
                }
                $up[$key] = 'ALTER TABLE ' . $tableSql . ' ADD CONSTRAINT ' . $name . ' ' . $definition;
                $down[$key] = 'ALTER TABLE ' . $tableSql . ' DROP CONSTRAINT ' . $name;
                $stamps[] = CatalogDefinition::stamp(
                    $connection,
                    'CONSTRAINT ' . $name . ' ON ' . $tableSql,
                    $definition,
                    self::EXCLUSIONS . ' AND c.conname = ?',
                    [...$relation, $exclude->name]
                );
            }

            if ($old !== null && ($class->exclusions !== [] || $actual !== [])) {
                $this->preserveUnmappedAssets($old, $table);
            }
        }

        ksort($up);
        ksort($down);

        return ['up' => [...array_values($up), ...$stamps], 'down' => array_values($down)];
    }

    private function preserveUnmappedAssets(Table $old, Table $target): void
    {
        // Current declarations cannot establish ownership of an omitted asset.
        foreach ($old->getUniqueConstraints() as $constraint) {
            $name = $constraint->getName();
            if (! $target->hasUniqueConstraint($name) && ! $target->hasIndex($name)) {
                $old->removeUniqueConstraint($name);
            }
        }
        foreach ($old->getIndexes() as $index) {
            $name = $index->getName();
            if (! $index->isPrimary() && ! $target->hasIndex($name) && ! $target->hasUniqueConstraint($name)) {
                $old->dropIndex($name);
            }
        }
        foreach ($old->getForeignKeys() as $key) {
            if (! $target->hasForeignKey($key->getName())
                && ! array_any($target->getForeignKeys(), static fn ($candidate): bool => $candidate->getLocalColumns() === $key->getLocalColumns())) {
                $old->removeForeignKey($key->getName());
            }
        }
        foreach ($old->getColumns() as $column) {
            $name = $column->getObjectName()->getIdentifier()->toSQL($this->em->getConnection()->getDatabasePlatform());
            if (! $target->hasColumn($name)) {
                $old->dropColumn($name);
            }
        }
    }

    /** @param list<string> $relation */
    private function protectGeneratedColumns(Table $old, Table $target, string $tableSql, array $relation): void
    {
        $connection = $this->em->getConnection();
        foreach ($connection->fetchFirstColumn(self::GENERATED, $relation) as $column) {
            $name = $connection->quoteSingleIdentifier($column);
            if ($target->hasColumn($name)) {
                throw new RuntimeException($tableSql . '.' . $name . ' is a generated column in PostgreSQL. Manage its schema with an explicit migration.');
            }
            if (! $old->hasColumn($name)) {
                continue;
            }
            foreach ($old->getIndexes() as $index) {
                if (! $target->hasIndex($index->getName()) && in_array($column, $index->getColumns(), true)) {
                    $old->dropIndex($index->getName());
                }
            }
            $old->dropColumn($name);
        }
    }

    private function protectPartialIndexes(Table $old, Table $target, string $tableSql): void
    {
        foreach ($old->getIndexes() as $index) {
            if (! $index->hasOption('where')) {
                continue;
            }
            if (! $target->hasIndex($index->getName())) {
                $old->dropIndex($index->getName());
            } elseif (! $target->getIndex($index->getName())->hasOption('where')) {
                throw new RuntimeException($tableSql . '.' . $index->getName() . ' removes a partial index predicate. Use an explicit migration.');
            }
        }
    }

    /** @param array<string,mixed> $actual */
    private function requireEquivalent(string $source, mixed $definition, array $actual, string $context): void
    {
        if (! is_string($definition) || ! CatalogDefinition::equivalent($source, $definition, $actual)) {
            throw new RuntimeException($context . ' has a changed or ambiguous PostgreSQL definition. Use an explicit migration.');
        }
    }
}
