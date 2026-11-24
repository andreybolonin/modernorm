<?php

declare(strict_types=1);

namespace ModernORM\Tools;

use ModernORM\Exception\ToolsException;
use BackedEnum;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\DefaultExpression;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\NamedObject;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\FieldMapping;
use ModernORM\Mapping\JoinColumnMapping;
use ModernORM\Mapping\ManyToManyOwningSideMapping;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping\QuoteStrategy;
use ModernORM\Tools\Event\GenerateSchemaEventArgs;
use ModernORM\Tools\PostgreSql\AdvancedSchema;
use ModernORM\Tools\Event\GenerateSchemaTableEventArgs;
use ModernORM\Exception\MissingColumnException;
use ModernORM\Exception\NotSupported;
use RuntimeException;
use Throwable;

use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_map;
use function array_values;
use function assert;
use function count;
use function current;
use function implode;
use function in_array;
use function interface_exists;
use function is_numeric;
use function sprintf;
use function strtolower;

/**
 * The SchemaTool is a tool to create/drop/update database schemas based on
 * <tt>ClassMetadata</tt> class descriptors.
 *
 * @link    www.doctrine-project.org
 */
class SchemaTool
{
    private const KNOWN_COLUMN_OPTIONS = ['comment', 'unsigned', 'fixed', 'default', 'values'];

    private readonly AbstractPlatform $platform;
    private readonly QuoteStrategy $quoteStrategy;
    private readonly AbstractSchemaManager $schemaManager;

    /**
     * Initializes a new SchemaTool instance that uses the connection of the
     * provided EntityManager.
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->platform         = $em->getConnection()->getDatabasePlatform();
        $this->quoteStrategy    = $em->getConfiguration()->getQuoteStrategy();
        $this->schemaManager    = $em->getConnection()->createSchemaManager();
    }

    /**
     * Creates the database schema for the given array of ClassMetadata instances.
     *
     * @phpstan-param list<ClassMetadata> $classes
     *
     * @throws ToolsException
     */
    public function createSchema(array $classes): void
    {
        $createSchemaSql = $this->getCreateSchemaSql($classes);
        $conn            = $this->em->getConnection();

        $apply = static function () use ($conn, $createSchemaSql): void {
            foreach ($createSchemaSql as $sql) {
                try {
                    $conn->executeStatement($sql);
                } catch (Throwable $e) {
                    throw ToolsException::schemaToolFailure($sql, $e);
                }
            }
        };
        if ($this->hasTriggerDeclarations($classes)) {
            $conn->transactional($apply);
        } else {
            $apply();
        }
    }

    /**
     * Gets the list of DDL statements that are required to create the database schema for
     * the given list of ClassMetadata instances.
     *
     * @phpstan-param list<ClassMetadata> $classes
     *
     * @return list<string> The SQL statements needed to create the schema for the classes.
     */
    public function getCreateSchemaSql(array $classes): array
    {
        $schema = $this->getOrdinarySchemaFromMetadata($classes);

        $checks = (new Check\SchemaExtension($this->em))->plan($classes, $schema, true);

        $triggers = (new Trigger\SchemaExtension($this->em))->plan($classes, $schema, true);
        $current = null;
        $advanced = (new AdvancedSchema($this->em))->plan($classes, $schema, $current);

        return [...$schema->toSql($this->platform), ...(new FullText\SchemaExtension($this->em))->createSql($classes, $schema),
            ...$triggers['functions'], ...$checks['up'], ...$advanced['up'], ...$triggers['up']];
    }

    /**
     * Detects instances of ClassMetadata that don't need to be processed in the SchemaTool context.
     *
     * @phpstan-param array<string, bool> $processedClasses
     */
    private function processingNotRequired(
        ClassMetadata $class,
        array $processedClasses,
    ): bool {
        $class->assertSupportedMapping();
        return isset($processedClasses[$class->name]) ||
            $class->isMappedSuperclass ||
            $class->isEmbeddedClass ||
            in_array($class->name, $this->em->getConfiguration()->getSchemaIgnoreClasses());
    }

    /**
     * Resolves fields in index mapping to column names
     *
     * @param mixed[] $indexData index or unique constraint data
     *
     * @return list<string> Column names from combined fields and columns mappings
     */
    private function getIndexColumns(ClassMetadata $class, array $indexData): array
    {
        $columns = [];

        if (
            isset($indexData['columns'], $indexData['fields'])
            || (
                ! isset($indexData['columns'])
                && ! isset($indexData['fields'])
            )
        ) {
            throw MappingException::invalidIndexConfiguration(
                (string) $class,
                $indexData['name'] ?? 'unnamed',
            );
        }

        if (isset($indexData['columns'])) {
            $columns = $indexData['columns'];
        }

        if (isset($indexData['fields'])) {
            foreach ($indexData['fields'] as $fieldName) {
                if ($class->hasField($fieldName)) {
                    $columns[] = $this->quoteStrategy->getColumnName($fieldName, $class, $this->platform);
                } elseif ($class->hasAssociation($fieldName)) {
                    $assoc = $class->getAssociationMapping($fieldName);
                    assert($assoc->isToOneOwningSide());
                    foreach ($assoc->joinColumns as $joinColumn) {
                        $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Creates a Schema instance from a given set of metadata classes.
     *
     * @phpstan-param list<ClassMetadata> $classes
     *
     * @throws NotSupported
     */
    public function getSchemaFromMetadata(array $classes): Schema
    {
        foreach ($classes as $class) {
            if (($class->checks !== [] || $class->triggers !== [] || $class->exclusions !== []) && ! $this->processingNotRequired($class, [])) {
                throw new RuntimeException('DBAL Schema cannot represent CHECK constraints, EXCLUDE constraints or triggers. Use SchemaTool getCreateSchemaSql/getUpdateSchemaSql for complete migration SQL.');
            }
            if ($class->fullTextIndexes !== [] && ! $this->processingNotRequired($class, [])) {
                throw new RuntimeException('DBAL Schema cannot represent FullTextIndex assets. Use SchemaTool getCreateSchemaSql/getUpdateSchemaSql for complete migration SQL.');
            }
        }
        return $this->getOrdinarySchemaFromMetadata($classes);
    }

    /** @param list<ClassMetadata> $classes */
    private function getOrdinarySchemaFromMetadata(array $classes): Schema
    {
        // Reminder for processed classes, used for hierarchies
        $processedClasses     = [];
        $eventManager         = $this->em->getEventManager();
        $metadataSchemaConfig = $this->schemaManager->createSchemaConfig();

        $schema = new Schema([], [], $metadataSchemaConfig);

        $addedFks       = [];
        $blacklistedFks = [];

        foreach ($classes as $class) {
            if ($this->processingNotRequired($class, $processedClasses)) {
                continue;
            }

            $tableName = $this->quoteStrategy->getTableName($class, $this->platform);

            $table = new Table(
                name: $tableName,
                configuration: $metadataSchemaConfig->toTableConfiguration(),
                options: $metadataSchemaConfig->getDefaultTableOptions(),
            );

            // For new schema API: collect join tables to add after this entity table
            $joinTablesToAdd = [];

            $this->gatherColumns($class, $table);
            $this->gatherRelationsSql(
                $class,
                $table,
                $addedFks,
                $blacklistedFks,
                $metadataSchemaConfig,
                $joinTablesToAdd,
            );

            $pkColumns = [$this->quoteStrategy->getColumnName($class->identifier[0], $class, $this->platform)];

            if ($table->getPrimaryKeyConstraint() === null) {
                $table = $this->addPrimaryKeyConstraint($table, $pkColumns);
            }

            // there can be unique indexes automatically created for join column
            // if join column is also primary key we should keep only primary key on this column
            // so, remove indexes overruled by primary key
            $primaryKey = $table->getIndex('primary');

            foreach ($table->getIndexes() as $existingIndex) {
                if ($existingIndex !== $primaryKey && $primaryKey->spansColumns(self::getIndexedColumns($existingIndex))) {
                    $table = $table->edit()->dropIndex($existingIndex->getObjectName())->create();
                }
            }

            $this->gatherIndexes($class, $table);

            if (isset($class->table['uniqueConstraints'])) {
                foreach ($class->table['uniqueConstraints'] as $indexName => $indexData) {
                    $uniqIndex = new Index('tmp__' . $indexName, $this->getIndexColumns($class, $indexData), true, false, [], $indexData['options'] ?? []);

                    foreach ($table->getIndexes() as $tableIndex) {
                        if (self::isIndexRedundantForUniqueConstraint($tableIndex, $uniqIndex)) {
                            $table = $table->edit()->dropIndex($tableIndex->getObjectName())->create();
                            break;
                        }
                    }

                    $table = $this->addIndex($table, self::getIndexedColumns($uniqIndex), is_numeric($indexName) ? null : $indexName, true, options: $indexData['options'] ?? []);
                }
            }

            if (isset($class->table['options'])) {
                $table = $table->edit()->setOptions($class->table['options'] + $table->getOptions())->create();
            }

            $processedClasses[$class->name] = true;

            // Add the fully populated table to the schema
            $schemaEditor = $schema->edit();
            $schemaEditor->addTable($table);

            // Add any join tables collected during relation processing
            // This ensures join tables appear right after their owning entity table
            foreach ($joinTablesToAdd as $joinTable) {
                $schemaEditor->addTable($joinTable);
            }

            $schema = $schemaEditor->create();

            if ($class->isIdGeneratorSequence()) {
                $seqDef     = $class->sequenceGeneratorDefinition;
                $quotedName = $this->quoteStrategy->getSequenceName($seqDef, $class, $this->platform);
                if (! $schema->hasSequence($quotedName)) {
                    $schema = $schema->edit()->addSequence(new Sequence(
                        $quotedName,
                        (int) $seqDef['allocationSize'],
                        (int) $seqDef['initialValue'],
                    ))->create();
                }
            }

            $tableEventArgs = new GenerateSchemaTableEventArgs($class, $schema, $table);
            $eventManager->dispatchEvent(
                ToolEvents::postGenerateSchemaTable,
                $tableEventArgs,
            );

            // Always retrieve the schema (listener may have mutated it)
            $schema = $tableEventArgs->getSchema();

            // If the listener mutated the table, we need to replace it in the schema
            if ($tableEventArgs->classTableWasMutated()) {
                $originalTableName = $table->getObjectName();
                $newTable          = $tableEventArgs->getClassTable();

                $schema = $schema->edit()
                    ->dropTable($originalTableName)
                    ->addTable($newTable)
                    ->create();

                $table = $newTable;
            }
        }

        // After all classes have been processed and all FK metadata collected,
        // apply the non-conflicting FK constraints to their respective tables
        foreach ($addedFks as $compositeName => $fkData) {
            // Skip if this composite key was blacklisted due to conflicts
            if (isset($blacklistedFks[$compositeName])) {
                continue;
            }

            // the table might have been dropped by a listener, so we ignore this error
            if ($schema->hasTable($fkData['table']->getObjectName()->toString())) {
                $schema = $schema->edit()->modifyTable(
                    $fkData['table']->getObjectName(),
                    static function (TableEditor $tableEditor) use ($fkData): void {
                        $tableEditor->addForeignKeyConstraint(new ForeignKeyConstraint(
                            localColumnNames: $fkData['localColumns'],
                            foreignTableName: $fkData['foreignTableName'],
                            foreignColumnNames: $fkData['foreignColumns'],
                            options: $fkData['fkOptions'],
                            name: $fkData['name'],
                        ));
                    },
                )->create();
            }
        }

        $schemaEventArgs = new GenerateSchemaEventArgs($this->em, $schema);
        $eventManager->dispatchEvent(
            ToolEvents::postGenerateSchema,
            $schemaEventArgs,
        );

        // Always retrieve the schema (listener may have mutated it)
        return $schemaEventArgs->getSchema();
    }

    private function gatherIndexes(ClassMetadata $class, Table &$table): void
    {
        foreach ($class->table['indexes'] ?? [] as $indexName => $indexData) {
            $columns = $this->getIndexColumns($class, $indexData);
            $name = is_numeric($indexName) ? null : $indexName;
            $table = $this->addIndex($table, $columns, $name, false, (array) ($indexData['flags'] ?? []), $indexData['options'] ?? []);
        }
    }

    /**
     * Gathers the column definitions as required by the DBAL of all field mappings
     * found in the given class.
     */
    private function gatherColumns(ClassMetadata $class, Table &$table): void
    {
        foreach ($class->fieldMappings as $mapping) {
            $this->gatherColumn($class, $mapping, $table);
        }
    }

    /**
     * Creates a column definition as required by the DBAL from an ORM field mapping definition.
     *
     * @param ClassMetadata $class The class that owns the field mapping.
     * @phpstan-param FieldMapping $mapping The field mapping.
     */
    private function gatherColumn(
        ClassMetadata $class,
        FieldMapping $mapping,
        Table &$table,
    ): void {
        $columnName = $this->quoteStrategy->getColumnName($mapping->fieldName, $class, $this->platform);
        $columnType = $mapping->type;

        $options            = [];
        $options['length']  = $mapping->length ?? null;
        $options['notnull'] = isset($mapping->nullable) ? ! $mapping->nullable : true;

        $options['platformOptions']            = [];
        $options['platformOptions']['version'] = $class->isVersioned && $class->versionField === $mapping->fieldName;

        if (strtolower($columnType) === 'string' && $options['length'] === null) {
            $options['length'] = $this->em->getConfiguration()->getDefaultStringTypeSchemaLength();
        }

        if (isset($mapping->precision)) {
            $options['precision'] = $mapping->precision;
        }

        if (isset($mapping->scale)) {
            $options['scale'] = $mapping->scale;
        }

        if (isset($mapping->columnDefinition)) {
            $options['columnDefinition'] = $mapping->columnDefinition;
        }

        // the 'default' option can be overwritten here
        $options = $this->gatherColumnOptions($mapping) + $options;

        if (isset($options['default']) && interface_exists(DefaultExpression::class)) {
            if (
                in_array($mapping->type, [
                    Types::DATETIME_MUTABLE,
                    Types::DATETIME_IMMUTABLE,
                    Types::DATETIMETZ_MUTABLE,
                    Types::DATETIMETZ_IMMUTABLE,
                ], true)
                && $options['default'] === $this->platform->getCurrentTimestampSQL()
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/12252',
                    <<<'DEPRECATION'
                    Using "%s" as a default value for datetime fields is deprecated and
                    will not be supported in Doctrine ORM 4.0.
                    Pass a `Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp` instance instead.
                    DEPRECATION,
                    $this->platform->getCurrentTimestampSQL(),
                );
                $options['default'] = new CurrentTimestamp();
            }

            if (
                in_array($mapping->type, [Types::TIME_MUTABLE, Types::TIME_IMMUTABLE], true)
                && $options['default'] === $this->platform->getCurrentTimeSQL()
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/12252',
                    <<<'DEPRECATION'
                    Using "%s" as a default value for time fields is deprecated and
                    will not be supported in Doctrine ORM 4.0.
                    Pass a `Doctrine\DBAL\Schema\DefaultExpression\CurrentTime` instance instead.
                    DEPRECATION,
                    $this->platform->getCurrentTimeSQL(),
                );
                $options['default'] = new CurrentTime();
            }

            if (
                in_array($mapping->type, [Types::DATE_MUTABLE, Types::DATE_IMMUTABLE], true)
                && $options['default'] === $this->platform->getCurrentDateSQL()
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/12252',
                    <<<'DEPRECATION'
                    Using "%s" as a default value for date fields is deprecated and
                    will not be supported in Doctrine ORM 4.0.
                    Pass a `Doctrine\DBAL\Schema\DefaultExpression\CurrentDate` instance instead.
                    DEPRECATION,
                    $this->platform->getCurrentDateSQL(),
                );
                $options['default'] = new CurrentDate();
            }
        }

        if ($class->isIdGeneratorIdentity() && $class->getIdentifierFieldNames() === [$mapping->fieldName]) {
            $options['autoincrement'] = true;
        }

        $table = $this->addColumn($table, $columnName, $columnType, $options);

        $isUnique = $mapping->unique ?? false;
        if ($isUnique) {
            $table = $this->addIndex($table, [$columnName], null, true);
        }

        $isIndex = $mapping->index ?? false;
        if ($isIndex) {
            $table = $this->addIndex($table, [$columnName], null, false);
        }
    }

    /**
     * Gathers the SQL for properly setting up the relations of the given class.
     * This includes the SQL for foreign key constraints and join tables.
     *
     * @phpstan-param array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>,
     *                  localColumns: list<string>,
     *                  fkOptions: array{onDelete?: string, deferrable?: bool, deferred?: bool},
     *                  name: string|null,
     *                  table: Table
     *              }>                               $addedFks
     * @phpstan-param array<string, bool>              $blacklistedFks
     * @phpstan-param list<Table>                      $joinTablesToAdd
     *
     * @throws NotSupported
     */
    private function gatherRelationsSql(
        ClassMetadata $class,
        Table &$table,
        array &$addedFks,
        array &$blacklistedFks,
        SchemaConfig $schemaConfig,
        array &$joinTablesToAdd,
    ): void {
        foreach ($class->associationMappings as $mapping) {
            $foreignClass = $this->em->getClassMetadata($mapping->targetEntity);

            if ($mapping->isToOneOwningSide()) {
                $primaryKeyColumns = []; // PK is unnecessary for this relation-type

                $this->gatherRelationJoinColumns(
                    $mapping->joinColumns,
                    $table,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks,
                    null,  // For ToOne, FK name comes from JoinColumn, not a parameter
                );
            } elseif ($mapping instanceof ManyToManyOwningSideMapping) {
                // create join table
                $joinTable = $mapping->joinTable;

                $tableName = $this->quoteStrategy->getJoinTableName($mapping, $foreignClass, $this->platform);

                $theJoinTable = new Table(
                    name: $tableName,
                    options: $joinTable->options + $schemaConfig->getDefaultTableOptions(),
                    configuration: $schemaConfig->toTableConfiguration(),
                );

                $primaryKeyColumns = [];

                // Build first FK constraint (relation table => source table)
                $this->gatherRelationJoinColumns(
                    $joinTable->joinColumns,
                    $theJoinTable,
                    $class,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks,
                    $joinTable->foreignKeyName ?? null,
                );

                // Build second FK constraint (relation table => target table)
                $this->gatherRelationJoinColumns(
                    $joinTable->inverseJoinColumns,
                    $theJoinTable,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks,
                    $joinTable->inverseForeignKeyName ?? null,
                );

                $joinTablesToAdd[] = $this->addPrimaryKeyConstraint($theJoinTable, $primaryKeyColumns);
            }
        }
    }

    /** @phpstan-return array{ClassMetadata, string}|null */
    private function getDefiningClass(ClassMetadata $class, string $referencedColumnName): array|null
    {
        $fieldName = $class->getFieldName($referencedColumnName);
        return $class->hasField($fieldName) ? [$class, $fieldName] : null;
    }

    /**
     * Gathers columns and fk constraints that are required for one part of relationship.
     *
     * @phpstan-param list<JoinColumnMapping>          $joinColumns
     * @phpstan-param list<string>                     $primaryKeyColumns
     * @phpstan-param array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>,
     *                  localColumns: list<string>,
     *                  fkOptions: array{onDelete?: string, deferrable?: bool, deferred?: bool},
     *                  name: string|null,
     *                  table: Table
     *              }>                               $addedFks
     * @phpstan-param array<string,bool>               $blacklistedFks
     *
     * @throws MissingColumnException
     *
     * @phpstan-param-out array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>,
     *                  localColumns: list<string>,
     *                  fkOptions: array{onDelete?: string, deferrable?: bool, deferred?: bool},
     *                  name: string|null,
     *                  table: Table
     *              }>                               $addedFks
     */
    private function gatherRelationJoinColumns(
        array $joinColumns,
        Table &$theJoinTable,
        ClassMetadata $class,
        AssociationMapping $mapping,
        array &$primaryKeyColumns,
        array &$addedFks,
        array &$blacklistedFks,
        string|null $foreignKeyName = null,
    ): void {
        $localColumns   = [];
        $foreignColumns = [];
        /** @var array{onDelete?: string, deferrable?: bool, deferred?: bool} $fkOptions */
        $fkOptions         = [];
        $foreignTableName  = $this->quoteStrategy->getTableName($class, $this->platform);
        $uniqueConstraints = [];

        foreach ($joinColumns as $joinColumn) {
            [$definingClass, $referencedFieldName] = $this->getDefiningClass(
                $class,
                $joinColumn->referencedColumnName,
            );

            if (! $definingClass) {
                throw MissingColumnException::fromColumnSourceAndTarget(
                    $joinColumn->referencedColumnName,
                    $mapping->sourceEntity,
                    $mapping->targetEntity,
                );
            }

            $quotedColumnName    = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            $quotedRefColumnName = $this->quoteStrategy->getReferencedJoinColumnName(
                $joinColumn,
                $class,
                $this->platform,
            );

            $primaryKeyColumns[] = $quotedColumnName;
            $localColumns[]      = $quotedColumnName;
            $foreignColumns[]    = $quotedRefColumnName;

            if (! $theJoinTable->hasColumn($quotedColumnName)) {
                // Only add the column to the table if it does not exist already.
                // It might exist already if the foreign key is mapped into a regular
                // property as well.

                $fieldMapping = $definingClass->getFieldMapping($referencedFieldName);

                $columnOptions = ['notnull' => false];

                if (isset($joinColumn->columnDefinition)) {
                    $columnOptions['columnDefinition'] = $joinColumn->columnDefinition;
                } elseif (isset($fieldMapping->columnDefinition)) {
                    $columnOptions['columnDefinition'] = $fieldMapping->columnDefinition;
                }

                if (isset($joinColumn->nullable)) {
                    $columnOptions['notnull'] = ! $joinColumn->nullable;
                }

                $columnOptions += $this->gatherColumnOptions($fieldMapping);

                if (isset($fieldMapping->length)) {
                    $columnOptions['length'] = $fieldMapping->length;
                }

                if ($fieldMapping->type === 'decimal') {
                    $columnOptions['scale']     = $fieldMapping->scale;
                    $columnOptions['precision'] = $fieldMapping->precision;
                }

                $columnOptions = $this->gatherColumnOptions($joinColumn) + $columnOptions;

                $theJoinTable = $this->addColumn($theJoinTable, $quotedColumnName, $fieldMapping->type, $columnOptions);
            }

            if (isset($joinColumn->unique) && $joinColumn->unique === true) {
                $uniqueConstraints[] = ['columns' => [$quotedColumnName]];
            }

            if (isset($joinColumn->onDelete)) {
                $fkOptions['onDelete'] = $joinColumn->onDelete;
            }

            if (isset($joinColumn->deferrable)) {
                $fkOptions['deferrable'] = $joinColumn->deferrable;
            }
        }

        // Prefer unique constraints over implicit simple indexes created for foreign keys.
        // Also avoids index duplication.
        foreach ($uniqueConstraints as $indexName => $unique) {
            $theJoinTable = $this->addIndex($theJoinTable, $unique['columns'], is_numeric($indexName) ? null : $indexName, true);
        }

        // Extract and validate foreign key name from join columns
        $foreignKeyNames = [];

        foreach ($joinColumns as $joinColumn) {
            if ($joinColumn->foreignKeyName !== null) {
                $foreignKeyNames[] = $joinColumn->foreignKeyName;
            }
        }

        $extractedForeignKeyName = match (count($foreignKeyNames)) {
            0 => null,
            1 => $foreignKeyNames[0],
            default => throw new RuntimeException(sprintf(
                'Only one JoinColumn in a composite foreign key may specify foreignKeyName. ' .
                '%s::$%s has %d join columns with foreignKeyName specified.',
                $mapping->sourceEntity,
                $mapping->fieldName,
                count($foreignKeyNames),
            )),
        };

        // Use parameter FK name (from JoinTable) if provided, otherwise use the name extracted from join columns
        $finalForeignKeyName = $foreignKeyName ?? $extractedForeignKeyName;

        $compositeName = $this->getAssetName($theJoinTable) . '.' . implode('', $localColumns);

        // Check if an FK constraint already exists for this composite key (table + columns)
        if (isset($addedFks[$compositeName])) {
            $existingFk = $addedFks[$compositeName];

            // Determine if the new FK targets the same table/columns as the existing one.
            $isForeignTableIdentical    = $foreignTableName === $existingFk['foreignTableName'];
            $areForeignColumnsIdentical = count(array_diff($foreignColumns, $existingFk['foreignColumns'])) === 0
                && count(array_diff($existingFk['foreignColumns'], $foreignColumns)) === 0;

            if ($isForeignTableIdentical && $areForeignColumnsIdentical) {
                // Both associations reference the same foreign table and columns, so a
                // single foreign key can satisfy both, even if their JoinColumn
                // configuration (e.g. nullable, onDelete, deferrable) differs. There is no
                // single definition that could honor two conflicting configurations at
                // once, so the association registered first keeps "winning" here, exactly
                // like before the two-pass rewrite introduced in GH-12528: the constraint
                // is no longer silently dropped just because the JoinColumn options disagree.
                return;
            }

            // FK exists but targets a different table/columns (conflicting FK) - blacklist
            // this composite key. No FK will be added for this composite key, but we need
            // to ensure an index exists since FKs normally create indexes automatically.
            if (! isset($blacklistedFks[$compositeName])) {
                // Add an index for the local columns since we won't be adding a FK
                // (FKs normally create implicit indexes).
                $theJoinTable = $this->addIndex($theJoinTable, $localColumns, null, false);
            }

            $blacklistedFks[$compositeName] = true;
        } elseif (! isset($blacklistedFks[$compositeName])) {
            // No existing FK and not blacklisted - store FK metadata for application phase
            $addedFks[$compositeName] = [
                'foreignTableName' => $foreignTableName,
                'foreignColumns' => $foreignColumns,
                'localColumns' => $localColumns,
                'fkOptions' => $fkOptions,
                'name' => $finalForeignKeyName ?? $this->generatedAssetName($theJoinTable, $localColumns, 'fk'),
                'table' => $theJoinTable,
            ];
        }
    }

    /** @return mixed[] */
    private function gatherColumnOptions(JoinColumnMapping|FieldMapping $mapping): array
    {
        $mappingOptions = $mapping->options ?? [];

        if (isset($mapping->enumType)) {
            $mappingOptions['enumType'] = $mapping->enumType;
        }

        if (($mappingOptions['default'] ?? null) instanceof BackedEnum) {
            $mappingOptions['default'] = $mappingOptions['default']->value;
        }

        if (empty($mappingOptions)) {
            return [];
        }

        $options                    = array_intersect_key($mappingOptions, array_flip(self::KNOWN_COLUMN_OPTIONS));
        $options['platformOptions'] = array_diff_key($mappingOptions, $options);

        return $options;
    }

    /**
     * Drops the database schema for the given classes.
     *
     * In any way when an exception is thrown it is suppressed since drop was
     * issued for all classes of the schema and some probably just don't exist.
     *
     * @phpstan-param list<ClassMetadata> $classes
     */
    public function dropSchema(array $classes): void
    {
        $dropSchemaSql = $this->getDropSchemaSQL($classes);
        $conn          = $this->em->getConnection();

        foreach ($dropSchemaSql as $sql) {
            try {
                $conn->executeStatement($sql);
            } catch (Throwable) {
                // ignored
            }
        }
    }

    /**
     * Drops all elements in the database of the current connection.
     */
    public function dropDatabase(): void
    {
        $dropSchemaSql = $this->getDropDatabaseSQL();
        $conn          = $this->em->getConnection();

        foreach ($dropSchemaSql as $sql) {
            $conn->executeStatement($sql);
        }
    }

    /**
     * Gets the SQL needed to drop the database schema for the connections database.
     *
     * @return list<string>
     */
    public function getDropDatabaseSQL(): array
    {
        return $this->schemaManager
            ->introspectSchema()
            ->toDropSql($this->platform);
    }

    /**
     * Gets SQL to drop the tables defined by the passed classes.
     *
     * @phpstan-param list<ClassMetadata> $classes
     *
     * @return list<string>
     */
    public function getDropSchemaSQL(array $classes): array
    {
        $schema = $this->getOrdinarySchemaFromMetadata($classes);

        $deployedSchema = $this->createSchemaForComparison($schema);

        $sequences = array_filter(
            $schema->getSequences(),
            fn (Sequence $sequence): bool => $deployedSchema->hasSequence($this->getAssetName($sequence)),
        );
        $sequenceNames = [];
        foreach ($sequences as $sequence) {
            $sequenceNames[$this->getAssetName($sequence)] = true;
        }

        // Use deployed tables so the drop SQL includes the actual constraint names.
        $deployedTables = [];
        foreach ($schema->getTables() as $table) {
            $name = $this->getAssetName($table);
            if (! $deployedSchema->hasTable($name)) {
                continue;
            }
            $deployedTables[] = $deployedSchema->getTable($name);
            $columns = $table->getPrimaryKeyConstraint()?->getColumnNames() ?? [];
            if (count($columns) === 1) {
                $checkSequence = $name . '_' . $columns[0]->toString() . '_seq';
                if ($deployedSchema->hasSequence($checkSequence) && ! isset($sequenceNames[$checkSequence])) {
                    $sequences[] = $deployedSchema->getSequence($checkSequence);
                    $sequenceNames[$checkSequence] = true;
                }
            }
        }
        $dropSchema = new Schema($deployedTables, $sequences, $this->schemaManager->createSchemaConfig());
        return $dropSchema->toDropSql($this->platform);
    }

    /**
     * Updates the database schema of the given classes by comparing the ClassMetadata
     * instances to the current database schema that is inspected.
     *
     * @param mixed[] $classes
     */
    public function updateSchema(array $classes): void
    {
        $conn = $this->em->getConnection();

        $statements = $this->getUpdateSchemaSql($classes);
        $apply = static function () use ($conn, $statements): void {
            foreach ($statements as $sql) {
                $conn->executeStatement($sql);
            }
        };
        if ($this->hasTriggerDeclarations($classes)) {
            $conn->transactional($apply);
        } else {
            $apply();
        }
    }

    /** @param list<ClassMetadata> $classes */
    private function hasTriggerDeclarations(array $classes): bool
    {
        foreach ($classes as $class) {
            if ($class->triggers !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the sequence of SQL statements that need to be performed in order
     * to bring the given class mappings in-synch with the relational schema.
     *
     * @param list<ClassMetadata> $classes The classes to consider.
     *
     * @return list<string> The sequence of SQL statements.
     * @phpstan-impure Reads the live database schema.
     */
    public function getUpdateSchemaSql(array $classes): array
    {
        $toSchema   = $this->getOrdinarySchemaFromMetadata($classes);
        $fromSchema = $this->createSchemaForComparison($toSchema);
        $checks = (new Check\SchemaExtension($this->em))->plan($classes, $toSchema, false);
        $triggers = (new Trigger\SchemaExtension($this->em))->plan($classes, $toSchema, false);
        $fullTextSql = (new FullText\SchemaExtension($this->em))->updateSql($classes, $fromSchema, $toSchema);
        $advanced = (new AdvancedSchema($this->em))->plan($classes, $toSchema, $fromSchema);
        $toSchema = $this->normalizeEquivalentIndexPredicates($fromSchema, $toSchema);

        $comparator = $this->schemaManager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false));

        $schemaDiff = $comparator->compareSchemas($fromSchema, $toSchema);
        $this->assertTableObjectsNotDropped($schemaDiff);

        return [...$this->getAlterSchemaSql($schemaDiff), ...$fullTextSql, ...$triggers['functions'], ...$checks['up'], ...$advanced['up'], ...$triggers['up']];
    }

    /** SQL for migration integrations, including PostgreSQL schema objects.
     *  @param list<ClassMetadata> $classes
     *  @return array{up: list<string>, down: list<string>}
     *  @phpstan-impure Reads the live database schema and configuration catalogs.
     */
    public function getSchemaMigrationSql(array $classes, bool $fromEmptySchema = false): array
    {
        $to = $this->getOrdinarySchemaFromMetadata($classes);
        $from = $fromEmptySchema
            ? new Schema([], [], $this->schemaManager->createSchemaConfig())
            : $this->createSchemaForComparison($to);
        $targetTables = [];
        foreach ($to->getTables() as $table) {
            $targetTables[$table->getObjectName()->toSQL($this->platform)] = true;
        }
        foreach ($from->getTables() as $table) {
            if (isset($targetTables[$table->getObjectName()->toSQL($this->platform)])) {
                continue;
            }
            foreach ($table->getColumns() as $column) {
                if (str_starts_with($column->getComment() ?? '', 'modernorm:fulltext:')) {
                    throw new RuntimeException('Dropping a table containing FullTextIndex assets requires an explicit migration.');
                }
            }
        }
        $fullText = (new FullText\SchemaExtension($this->em))->migrationSql($classes, $from, $to, $fromEmptySchema);
        $checks = (new Check\SchemaExtension($this->em))->plan($classes, $to, $fromEmptySchema);
        $triggers = (new Trigger\SchemaExtension($this->em))->plan($classes, $to, $fromEmptySchema);
        $current = $fromEmptySchema ? null : $from;
        $advanced = (new AdvancedSchema($this->em))->plan($classes, $to, $current);
        $from = $current ?? $from;
        $to = $this->normalizeEquivalentIndexPredicates($from, $to);
        $comparator = $this->schemaManager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false));
        $up = $comparator->compareSchemas($from, $to);
        $this->assertTableObjectsNotDropped($up);

        return [
            'up' => [...$this->getAlterSchemaSql($up), ...$fullText['up'], ...$triggers['functions'], ...$checks['up'], ...$advanced['up'], ...$triggers['up']],
            'down' => $triggers['functions'] !== []
                ? [Trigger\FunctionRegistry::irreversibleSql()]
                : [...$triggers['down'], ...$advanced['down'], ...$checks['down'], ...$fullText['down'], ...$this->getAlterSchemaSql($comparator->compareSchemas($to, $from))],
        ];
    }

    /** @return list<string> */
    private function getAlterSchemaSql(SchemaDiff $diff): array
    {
        $created = $diff->getCreatedSchemas();
        if ($created !== [] && $this->platform->supportsSchemas()) {
            // SchemaEditor derives namespaces from assets, omitting existing empty namespaces.
            $diff = new SchemaDiff(
                array_diff($created, $this->schemaManager->listSchemaNames()),
                $diff->getDroppedSchemas(),
                $diff->getCreatedTables(),
                $diff->getAlteredTables(),
                $diff->getDroppedTables(),
                $diff->getCreatedSequences(),
                $diff->getAlteredSequences(),
                $diff->getDroppedSequences(),
            );
        }

        return $this->platform->getAlterSchemaSQL($diff);
    }

    private function assertTableObjectsNotDropped(SchemaDiff $diff): void
    {
        if (! $this->platform instanceof PostgreSQLPlatform) {
            return;
        }
        $connection = $this->em->getConnection();
        foreach ($diff->getDroppedTables() as $table) {
            $name = $table->getObjectName()->toSQL($this->platform);
            $oid = $this->catalogTableOid($table);
            if ($oid !== false && $connection->fetchOne(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_constraint WHERE conrelid = ? AND contype IN ('c', 'x')
                    UNION ALL
                    SELECT 1 FROM pg_catalog.pg_trigger WHERE tgrelid = ? AND NOT tgisinternal
                    UNION ALL
                    SELECT 1 FROM pg_catalog.pg_index WHERE indrelid = ? AND indpred IS NOT NULL
                    UNION ALL
                    SELECT 1 FROM pg_catalog.pg_attribute WHERE attrelid = ? AND attgenerated <> ''
                )
                SQL, [$oid, $oid, $oid, $oid])) {
                throw new RuntimeException('Dropping table ' . $name . ' would remove CHECK constraints or triggers, EXCLUDE constraints, partial indexes or generated columns. Use an explicit migration.');
            }
        }
        foreach ($diff->getAlteredTables() as $alteration) {
            $table = $alteration->getOldTable()->getObjectName()->toSQL($this->platform);
            $droppedColumns = $alteration->getDroppedColumns();
            if ($droppedColumns === [] || ($oid = $this->catalogTableOid($alteration->getOldTable())) === false) {
                continue;
            }
            foreach ($droppedColumns as $column) {
                $name = $column->getObjectName()->getIdentifier()->toNormalizedValue($this->platform->getUnquotedIdentifierFolding());
                if ($connection->fetchOne(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM pg_catalog.pg_attribute a
                        JOIN pg_catalog.pg_constraint c ON c.conrelid = a.attrelid AND a.attnum = ANY(c.conkey)
                        WHERE a.attrelid = ? AND a.attname = ? AND c.contype IN ('c', 'x')
                    )
                    SQL, [$oid, $name])) {
                    throw new RuntimeException('Dropping column ' . $table . '.' . $name . ' would remove a CHECK or EXCLUDE constraint. Use an explicit migration.');
                }
                $index = $connection->fetchOne(<<<'SQL'
                    SELECT i.relname
                    FROM pg_catalog.pg_attribute a
                    JOIN pg_catalog.pg_depend d ON d.refobjid = a.attrelid AND d.refobjsubid = a.attnum
                    JOIN pg_catalog.pg_index ix ON ix.indexrelid = d.objid AND ix.indrelid = a.attrelid
                    JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid
                    WHERE a.attrelid = ? AND a.attname = ? AND ix.indpred IS NOT NULL
                      AND d.classid = 'pg_catalog.pg_class'::pg_catalog.regclass
                      AND d.refclassid = 'pg_catalog.pg_class'::pg_catalog.regclass
                    ORDER BY i.relname
                    LIMIT 1
                    SQL, [$oid, $name]);
                if ($index !== false) {
                    throw new RuntimeException('Dropping column ' . $table . '.' . $name . ' would remove partial index '
                        . $this->platform->quoteSingleIdentifier($index) . '. Use an explicit migration.');
                }
            }
        }
    }

    private function catalogTableOid(Table $table): mixed
    {
        $name = $table->getObjectName();
        $folding = $this->platform->getUnquotedIdentifierFolding();

        return $this->em->getConnection()->fetchOne(
            'SELECT c.oid FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace'
                . ' WHERE n.nspname = coalesce(?, pg_catalog.current_schema()) AND c.relname = ?',
            [$name->getQualifier()?->toNormalizedValue($folding), $name->getUnqualifiedName()->toNormalizedValue($folding)],
        );
    }

    /**
     * Creates the schema from the database, ensuring tables from the target schema are whitelisted for comparison.
     */
    private function createSchemaForComparison(Schema $toSchema): Schema
    {
        $connection = $this->em->getConnection();

        // backup schema assets filter
        $config         = $connection->getConfiguration();
        $previousFilter = $config->getSchemaAssetsFilter();

        if ($previousFilter === null) {
            return $this->normalizeIntrospectedSchema($this->schemaManager->introspectSchema());
        }

        // whitelist assets we already know about in $toSchema, use the existing filter otherwise
        $platform = $this->platform;
        $quotedNames = [];
        foreach ([...$toSchema->getTables(), ...$toSchema->getSequences()] as $asset) {
            if ($asset instanceof NamedObject) {
                $quotedNames[$asset->getObjectName()->toSQL($platform)] = true;
            }
        }
        $config->setSchemaAssetsFilter(static function ($asset) use ($previousFilter, $toSchema, $quotedNames, $platform): bool {
            $assetName = $asset instanceof NamedObject ? $asset->getObjectName()->toString() : $asset;
            if ($toSchema->hasTable($assetName) || $toSchema->hasSequence($assetName)) {
                return true;
            }
            // DBAL's legacy schema lookup does not normalize mixed quoting (schema."Table").
            // Compare the parsed SQL name as well as the legacy key.
            if ($quotedNames !== []) {
                $name = $asset instanceof NamedObject
                    ? $asset->getObjectName()
                    : (new Table($assetName))->getObjectName();
                if (isset($quotedNames[$name->toSQL($platform)])) {
                    return true;
                }
            }
            return $previousFilter($asset);
        });

        try {
            return $this->normalizeIntrospectedSchema($this->schemaManager->introspectSchema());
        } finally {
            // restore schema assets filter
            $config->setSchemaAssetsFilter($previousFilter);
        }
    }

    /** PostgreSQL wraps simple predicates in parentheses during introspection. */
    private function normalizeEquivalentIndexPredicates(Schema $fromSchema, Schema $toSchema): Schema
    {
        $editor = $toSchema->edit();
        foreach ($toSchema->getTables() as $table) {
            $name = $this->getAssetName($table);
            if (! $fromSchema->hasTable($name)) {
                continue;
            }
            $fromTable = $fromSchema->getTable($name);
            foreach ($table->getIndexes() as $indexName => $index) {
                if ($index->getPredicate() === null || ! $fromTable->hasIndex($indexName)) {
                    continue;
                }
                $actual = $fromTable->getIndex($indexName)->getPredicate();
                if ($actual !== '(' . $index->getPredicate() . ')') {
                    continue;
                }
                $editor->modifyTable($table->getObjectName(), static function (TableEditor $tableEditor) use ($index, $actual): void {
                    $tableEditor->dropIndex($index->getObjectName())->addIndex($index->edit()->setPredicate($actual)->create());
                });
            }
        }
        return $editor->create();
    }

    private function normalizeIntrospectedSchema(Schema $schema): Schema
    {
        $editor = $schema->edit();
        foreach ($schema->getTables() as $table) {
            $name = $table->getObjectName();
            $identifier = $name->getUnqualifiedName();
            if ($identifier->getValue() === Trigger\FunctionRegistry::TABLE
                && $table->getComment() === Trigger\FunctionRegistry::TABLE_MARKER) {
                $editor->dropTable($name);
                continue;
            }

            // Schema still uses string keys which cannot look up schema."Table" as "schema"."Table".
            $qualifier = $name->getQualifier();
            if ($identifier->isQuoted() && $qualifier !== null && ! $qualifier->isQuoted()) {
                $editor->modifyTable($name, static function (TableEditor $tableEditor) use ($identifier, $qualifier): void {
                    $tableEditor->setName(new OptionallyQualifiedName($identifier, Identifier::quoted($qualifier->getValue())));
                });
            }
        }

        return $editor->create();
    }

    /** @param non-empty-array<non-empty-string> $primaryKeyColumns */
    private function addPrimaryKeyConstraint(Table $table, array $primaryKeyColumns): Table
    {
        $columnNames = array_map(
            static fn (string $name): UnqualifiedName => $table->getColumn($name)->getObjectName(),
            $primaryKeyColumns,
        );

        return $table->edit()->addPrimaryKeyConstraint(new PrimaryKeyConstraint(null, array_values($columnNames), true))->create();
    }

    /** @param array<string, mixed> $options */
    private function addColumn(Table $table, string $name, string $type, array $options): Table
    {
        if ($table->getColumns() === []) {
            $config = $this->schemaManager->createSchemaConfig();

            return $table::editor()
                ->setName($table->getObjectName())
                ->setOptions($table->getOptions())
                ->setConfiguration($config->toTableConfiguration())
                ->setTypeProvider($config->getTypeProvider())
                ->addColumn(new Column($name, $type, $options))
                ->create();
        }

        if ($table->hasColumn($name)) {
            $column = $table->getColumn($name);
            $previous = array_diff_key($column->toArray(true), $column->getPlatformOptions(), ['name' => true, 'typeName' => true]);
            $previous['platformOptions'] = $column->getPlatformOptions();
            $replacement = new Column($name, $column->getTypeName(), $options + $previous);

            return $table->edit()->setColumns(...array_values(array_map(
                static fn (Column $candidate): Column => $candidate === $column ? $replacement : $candidate,
                $table->getColumns(),
            )))->create();
        }

        return $table->edit()->addColumn(new Column($name, $type, $options))->create();
    }

    /**
     * @param non-empty-list<string> $columns
     * @param list<string> $flags
     * @param array<string, mixed> $options
     */
    private function addIndex(Table $table, array $columns, ?string $name, bool $unique, array $flags = [], array $options = []): Table
    {
        $name ??= $this->generatedAssetName($table, $columns, $unique ? 'uniq' : 'idx');
        return $table->edit()->addIndex(new Index($name, $columns, $unique, false, $flags, $options))->create();
    }

    /** @param list<string> $columns */
    private function generatedAssetName(Table $table, array $columns, string $prefix): string
    {
        // Preserve names produced by the former DBAL mutators for existing installations.
        $name = $table->getObjectName();
        $qualifier = $name->getQualifier();
        $tableName = ($qualifier === null ? '' : $qualifier->getValue() . '.') . $name->getUnqualifiedName()->getValue();
        $hash = implode('', array_map(static fn (string $part): string => dechex(crc32($part)), [$tableName, ...$columns]));

        return strtoupper(substr($prefix . '_' . $hash, 0, $this->schemaManager->createSchemaConfig()->getMaxIdentifierLength()));
    }

    /** @return string[] */
    private static function getIndexedColumns(Index $index): array
    {
        return array_map(static fn (IndexedColumn $indexedColumn): string => $indexedColumn->getColumnName()->toString(), $index->getIndexedColumns());
    }

    /**
     * Unique constraints can replace ordinary indexes on the same columns, but not
     * platform-specific indexes such as fulltext or spatial whose flags they do not fulfill.
     */
    private static function isIndexRedundantForUniqueConstraint(Index $existingIndex, Index $uniqueIndex): bool
    {
        return $existingIndex->isFulfilledBy($uniqueIndex)
            && self::indexFlagsAreCompatible($existingIndex);
    }

    /**
     * Whether a unique constraint can replace $existingIndex given its platform-specific type.
     *
     * Unflagged indexes remain compatible with unique constraints so they can still be
     * deduplicated. Fulltext and spatial indexes keep their own semantics.
     */
    private static function indexFlagsAreCompatible(Index $existingIndex): bool
    {
        return ! in_array($existingIndex->getType(), [IndexType::FULLTEXT, IndexType::SPATIAL], true);
    }

    private function getAssetName(Table|Sequence $asset): string
    {
        return $asset->getObjectName()->toString();
    }
}
