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
    public const DECLARED_PREDICATE = 'modernorm_declared_predicate';

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
        SELECT a.attname, a.attgenerated, pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS expression,
               pg_catalog.col_description(a.attrelid, a.attnum) AS object_comment,
               pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(
                   ROW(a.attgenerated, a.atttypid, a.atttypmod, a.attcollation, d.adbin::text, pg_catalog.pg_get_expr(d.adbin, d.adrelid))::text, 'UTF8')), 'hex') AS fingerprint
        FROM pg_catalog.pg_attribute a
        LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
        JOIN pg_catalog.pg_class t ON t.oid = a.attrelid
        JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
        WHERE n.nspname = ? AND t.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
        SQL;

    private const PARTIAL = <<<'SQL'
        SELECT pg_catalog.pg_get_expr(i.indpred, i.indrelid) AS predicate,
               pg_catalog.obj_description(c.oid, 'pg_class') AS object_comment,
               pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(
                   ROW(a.amname, i.indisunique, i.indkey, i.indclass, i.indcollation, i.indoption,
                       i.indexprs::text, i.indpred::text, pg_catalog.pg_get_indexdef(i.indexrelid))::text, 'UTF8')), 'hex') AS fingerprint,
               a.amname AS method, (i.indisvalid AND i.indisready) AS valid
        FROM pg_catalog.pg_index i
        JOIN pg_catalog.pg_class c ON c.oid = i.indexrelid
        JOIN pg_catalog.pg_am a ON a.oid = c.relam
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = ? AND c.relname = ?
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
            $hasDeclarations = $class->exclusions !== []
                || array_any($class->fieldMappings, static fn ($field): bool => $field->storedExpression !== null)
                || array_any($table->getIndexes(), static fn ($index): bool => $index->hasOption(self::DECLARED_PREDICATE));
            if ($old === null && ! $hasDeclarations) {
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
                $this->reconcileGeneratedColumns($class, $old, $table, $tableSql, $relation);
                $this->reconcilePartialIndexes($old, $table, $tableSql, $schema);
            }

            foreach ($class->fieldMappings as $field) {
                if ($field->storedExpression === null) {
                    continue;
                }
                $column = $table->getColumn($config->getQuoteStrategy()->getColumnName($field->fieldName, $class, $platform));
                $name = $column->getObjectName()->getIdentifier()->toNormalizedValue($folding);
                $quoted = $platform->quoteSingleIdentifier($name);
                if ($old === null || ! $old->hasColumn($quoted)) {
                    $stamps[] = CatalogDefinition::stamp(
                        $connection,
                        'COLUMN ' . $tableSql . '.' . $quoted,
                        $field->storedExpression,
                        self::GENERATED . ' AND a.attname = ?',
                        [...$relation, $name]
                    );
                }
            }
            foreach ($table->getIndexes() as $index) {
                if (! $index->hasOption(self::DECLARED_PREDICATE) || ($old !== null && $old->hasIndex($index->getName()))) {
                    continue;
                }
                $indexName = $index->getObjectName()->getIdentifier()->toNormalizedValue($folding);
                $qualified = $schemaSql . '.' . $platform->quoteSingleIdentifier($indexName);
                $stamps[] = CatalogDefinition::stamp(
                    $connection,
                    'INDEX ' . $qualified,
                    $index->getPredicate() ?? '',
                    self::PARTIAL,
                    [$schema, $indexName]
                );
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

            if ($old !== null && ($hasDeclarations || $actual !== [])) {
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
    private function reconcileGeneratedColumns(ClassMetadata $class, Table $old, Table $target, string $tableSql, array $relation): void
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $declared = [];
        foreach ($class->fieldMappings as $field) {
            if ($field->storedExpression !== null) {
                $column = $target->getColumn($quote->getColumnName($field->fieldName, $class, $platform));
                $name = $column->getObjectName()->getIdentifier()->toNormalizedValue($platform->getUnquotedIdentifierFolding());
                $declared[$name] = $field->storedExpression;
            }
        }
        foreach ($connection->fetchAllAssociative(self::GENERATED, $relation) as $actual) {
            $name = $platform->quoteSingleIdentifier($actual['attname']);
            $context = $tableSql . '.' . $name;
            if (! $target->hasColumn($name)) {
                if ($actual['attgenerated'] !== '' && $old->hasColumn($name)) {
                    // Keep externally managed generated columns and their indexes in the database.
                    foreach ($old->getIndexes() as $index) {
                        if (! $target->hasIndex($index->getName()) && in_array($actual['attname'], $index->getColumns(), true)) {
                            $old->dropIndex($index->getName());
                        }
                    }
                    $old->dropColumn($name);
                }
                continue;
            }

            $column = $target->getColumn($name);
            $isDeclared = isset($declared[$actual['attname']]);
            if (! $isDeclared && $actual['attgenerated'] === '') {
                continue;
            }
            if (! $isDeclared || $actual['attgenerated'] !== 's') {
                throw new RuntimeException($context . ' changes the generated column kind. Use an explicit migration.');
            }
            $this->requireEquivalent($declared[$actual['attname']], $actual['expression'], $actual, $context);
            // DBAL can report adbin as a DEFAULT. Compare the ordinary column properties separately.
            $oldColumn = $old->getColumn($name);
            $oldColumn->setDefault(null);
            $column->setComment(CatalogDefinition::preserveStamp($column->getComment(), $oldColumn->getComment()));
            $column->setColumnDefinition(null);
        }
    }

    private function reconcilePartialIndexes(Table $old, Table $target, string $tableSql, string $schema): void
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
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
        foreach ($target->getIndexes() as $index) {
            if (! $index->hasOption(self::DECLARED_PREDICATE) || ! $old->hasIndex($index->getName())) {
                continue;
            }
            $current = $old->getIndex($index->getName());
            $context = $tableSql . '.' . $index->getName();
            if (! $current->hasOption('where') || $index->isUnique() !== $current->isUnique()
                || $index->getColumns() !== $current->getColumns()) {
                throw new RuntimeException($context . ' changes the partial index shape. Use an explicit migration.');
            }
            $actualName = $current->getObjectName()->getIdentifier()->toNormalizedValue($platform->getUnquotedIdentifierFolding());
            $actual = $this->em->getConnection()->fetchAssociative(self::PARTIAL, [$schema, $actualName]);
            if ($actual === false || $actual['method'] !== 'btree' || ! $actual['valid']) {
                throw new RuntimeException($context . ' has an unsupported partial index definition. Use an explicit migration.');
            }
            $this->requireEquivalent($index->getPredicate() ?? '', $actual['predicate'], $actual, $context);
            $options = $index->getOptions();
            $options['where'] = $current->getOption('where');
            $target->dropIndex($index->getName());
            $columns = array_map(static fn ($column): string => $column->getColumnName()->toSQL($platform), $index->getIndexedColumns());
            if ($index->isUnique()) {
                $target->addUniqueIndex($columns, $index->getName(), $options);
            } else {
                $target->addIndex($columns, $index->getName(), $index->getFlags(), $options);
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
