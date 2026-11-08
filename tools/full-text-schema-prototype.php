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

use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';

#[ORM\Entity, ORM\Table(name: 'products')]
final class FullTextPrototypeProduct
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column(name: '`ProductName`', type: 'text')]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;
}

function fullTextPrototypeCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
    'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
    'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
]);
$em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
$metadata = $em->getClassMetadata(FullTextPrototypeProduct::class);
$schema = 'modernorm_fts_prototype_' . bin2hex(random_bytes(6));
$metadata->table['schema'] = $schema;
$tool = new SchemaTool($em);
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteSingleIdentifier(...);
$table = $quote($schema) . '.' . $quote('products');
$column = 'search_vector';
$index = 'idx_product_search';
$configuration = 'pg_catalog.russian';
$weights = ['name' => 'A', 'description' => 'B'];
$expression = static function (array $fields, string $config) use ($connection, $metadata, $quote): string {
    $parts = [];
    foreach ($fields as $field => $weight) {
        fullTextPrototypeCheck(isset($metadata->fieldMappings[$field]), 'Unknown scalar field.');
        fullTextPrototypeCheck(in_array($weight, ['A', 'B', 'C', 'D'], true), 'Invalid weight.');
        $mapping = $metadata->fieldMappings[$field];
        fullTextPrototypeCheck(in_array($mapping->type, ['text', 'string'], true), 'Only text fields are supported.');
        $parts[] = 'setweight(to_tsvector(' . $connection->quote($config) . '::regconfig, coalesce('
            . $quote($mapping->columnName) . "::text, ''::text)), " . $connection->quote($weight) . '::"char")';
    }
    fullTextPrototypeCheck($parts !== [], 'At least one source field is required.');
    return implode(' || ', $parts);
};
$columnState = static function (string $relation, string $field) use ($connection): array|false {
    return $connection->fetchAssociative(
        "SELECT a.attgenerated, format_type(a.atttypid, a.atttypmod) AS type, pg_get_expr(d.adbin, d.adrelid) AS expression
         FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
         WHERE a.attrelid = CAST(? AS regclass) AND a.attname = ? AND NOT a.attisdropped",
        [$relation, $field],
    );
};
$indexState = static function () use ($connection, $schema, $index): array|false {
    return $connection->fetchAssociative(
        'SELECT am.amname AS method, i.indisvalid AS valid, i.indisready AS ready, pg_get_indexdef(c.oid) AS definition
         FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_am am ON am.oid = c.relam
         WHERE n.nspname = ? AND c.relname = ?',
        [$schema, $index],
    );
};
$connection->beginTransaction();
try {
    fullTextPrototypeCheck((int) $connection->fetchOne("SELECT current_setting('server_version_num')") >= 170000, 'This prototype requires PostgreSQL 17+.');
    $connection->executeStatement('SET LOCAL search_path TO pg_catalog, ' . $quote($schema) . ', pg_temp');
    $baseCreate = $tool->getCreateSchemaSql([$metadata]);
    foreach ($baseCreate as $sql) {
        $connection->executeStatement($sql);
    }
    $createFullText = [
        'ALTER TABLE ' . $table . ' ADD COLUMN ' . $quote($column) . ' tsvector GENERATED ALWAYS AS (' . $expression($weights, $configuration) . ') STORED',
        'CREATE INDEX ' . $quote($index) . ' ON ' . $table . ' USING GIN (' . $quote($column) . ')',
    ];
    foreach ($createFullText as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->executeStatement('INSERT INTO ' . $table . ' (id, "ProductName", description) VALUES (?, ?, ?)', [1, 'промышленное оборудование', null]);
    $connection->executeStatement('INSERT INTO ' . $table . ' (id, "ProductName", description) VALUES (?, ?, ?)', [2, 'станок', 'промышленное оборудование']);

    $manager = $connection->createSchemaManager();
    $nativeTable = $manager->introspectTable($schema . '.products');
    $nativeColumn = $nativeTable->getColumn($column);
    $nativeIntrospection = [
        'mapped_type' => $nativeColumn->getType()::class,
        'column_definition' => $nativeColumn->getColumnDefinition(),
        'index_flags' => $nativeTable->getIndex($index)->getFlags(),
        'index_options' => $nativeTable->getIndex($index)->getOptions(),
    ];
    $desiredTable = static fn () => $tool->getSchemaFromMetadata([$metadata])->getTable($schema . '.products');
    $nativeDiff = $platform->getAlterTableSQL($manager->createComparator()->compareTables(
        $manager->introspectTable($schema . '.products'),
        $desiredTable(),
    ));
    fullTextPrototypeCheck($nativeDiff !== [], 'Native schema comparison unexpectedly preserved the unmapped technical assets.');

    // Prototype-only normalization: let PostgreSQL parse the expected expression on a LIKE copy.
    // Production schema diff must not acquire these temporary-DDL side effects implicitly.
    $canonicalExpression = static function (string $sql) use ($connection, $table, $quote, $columnState): string {
        $temporary = 'modernorm_fts_expected_' . bin2hex(random_bytes(6));
        $relation = 'pg_temp.' . $quote($temporary);
        $connection->executeStatement('CREATE TEMP TABLE ' . $quote($temporary) . ' (LIKE ' . $table . ') ON COMMIT DROP');
        try {
            $connection->executeStatement('ALTER TABLE ' . $relation . ' ADD COLUMN expected_vector tsvector GENERATED ALWAYS AS (' . $sql . ') STORED');
            $state = $columnState($relation, 'expected_vector');
            fullTextPrototypeCheck($state !== false && is_string($state['expression']), 'Cannot canonicalize the desired expression.');
            return $state['expression'];
        } finally {
            $connection->executeStatement('DROP TABLE ' . $relation);
        }
    };
    $diff = static function (array $fields, string $config) use ($manager, $platform, $schema, $table, $column, $index, $quote, $expression, $canonicalExpression, $columnState, $indexState, $desiredTable): array {
        $actual = $manager->introspectTable($schema . '.products');
        // Exclude exactly the two prototype-owned assets from ordinary DBAL comparison.
        if ($actual->hasIndex($index)) {
            $actual->dropIndex($index);
        }
        $state = $columnState($table, $column);
        if ($actual->hasColumn($column)) {
            $actual->dropColumn($column);
        }
        $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($actual, $desiredTable()));
        $desired = $expression($fields, $config);
        if ($state === false) {
            $sql[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $quote($column) . ' tsvector GENERATED ALWAYS AS (' . $desired . ') STORED';
        } else {
            fullTextPrototypeCheck($state['attgenerated'] === 's' && $state['type'] === 'tsvector', 'Technical-column collision: refusing to replace an ordinary column.');
            if ($state['expression'] !== $canonicalExpression($desired)) {
                $sql[] = 'ALTER TABLE ' . $table . ' ALTER COLUMN ' . $quote($column) . ' SET EXPRESSION AS (' . $desired . ')';
            }
        }
        $indexInfo = $indexState();
        if ($indexInfo === false) {
            $sql[] = 'CREATE INDEX ' . $quote($index) . ' ON ' . $table . ' USING GIN (' . $quote($column) . ')';
        } else {
            fullTextPrototypeCheck($indexInfo['method'] === 'gin' && $indexInfo['valid'] && $indexInfo['ready'], 'Index collision or invalid GIN index.');
            fullTextPrototypeCheck(str_ends_with($indexInfo['definition'], ' USING gin (' . $column . ')'), 'Unexpected index keys, predicate or operator class.');
        }
        return $sql;
    };
    $repeat = $diff($weights, $configuration);
    fullTextPrototypeCheck($repeat === [], 'The initial repeated diff is not empty.');
    $ranked = static fn (): array => $connection->fetchFirstColumn(
        'SELECT id FROM ' . $table . ' WHERE search_vector @@ websearch_to_tsquery(?::regconfig, ?) ORDER BY ts_rank(search_vector, websearch_to_tsquery(?::regconfig, ?)) DESC, id',
        [$configuration, 'промышленное оборудование', $configuration, 'промышленное оборудование'],
    );
    $beforeRanking = array_map('intval', $ranked());
    fullTextPrototypeCheck($beforeRanking === [1, 2], 'Initial weights did not prioritize the name field.');
    $weights = ['name' => 'B', 'description' => 'A'];
    $weightSql = $diff($weights, $configuration);
    fullTextPrototypeCheck(count($weightSql) === 1 && str_contains($weightSql[0], ' SET EXPRESSION AS '), 'Weight changes must update the expression without dropping data/indexes.');
    foreach ($weightSql as $sql) {
        $connection->executeStatement($sql);
    }
    $afterRanking = array_map('intval', $ranked());
    fullTextPrototypeCheck($afterRanking === [2, 1], 'Existing stored vectors were not recomputed with the new weights.');
    fullTextPrototypeCheck($diff($weights, $configuration) === [], 'Diff is not empty after changing weights.');
    fullTextPrototypeCheck($indexState()['method'] === 'gin', 'Weight migration lost the GIN index.');

    $metadata->fieldMappings['description']->options['default'] = 'pending';
    $ordinarySql = $diff($weights, $configuration);
    fullTextPrototypeCheck(count($ordinarySql) === 1 && str_contains($ordinarySql[0], 'SET DEFAULT'), 'An ordinary mapped-column change was lost.');
    foreach ($ordinarySql as $sql) {
        $connection->executeStatement($sql);
    }
    fullTextPrototypeCheck($diff($weights, $configuration) === [], 'Ordinary mapping change did not converge.');
    $connection->executeStatement('DROP INDEX ' . $quote($schema) . '.' . $quote($index));
    $missingIndexSql = $diff($weights, $configuration);
    fullTextPrototypeCheck(count($missingIndexSql) === 1 && str_contains($missingIndexSql[0], 'USING GIN'), 'A missing GIN index was not detected.');
    $connection->executeStatement($missingIndexSql[0]);
    fullTextPrototypeCheck($diff($weights, $configuration) === [], 'Restored index did not converge.');

    $connection->executeStatement('ALTER TABLE ' . $table . ' DROP COLUMN ' . $quote($column));
    $missingColumnSql = $diff($weights, $configuration);
    fullTextPrototypeCheck(count($missingColumnSql) === 2, 'A missing technical column must recreate both the vector and its index.');
    foreach ($missingColumnSql as $sql) {
        $connection->executeStatement($sql);
    }
    fullTextPrototypeCheck($diff($weights, $configuration) === [], 'Restored technical column did not converge.');
    fullTextPrototypeCheck(array_map('intval', $ranked()) === [2, 1], 'Recreated vectors do not preserve the configured weights.');

    $normalize = static fn (array $statements): array => array_map(static fn (string $sql): string => str_replace($schema, 'prototype_schema', $sql), $statements);
    echo json_encode([
        'php' => PHP_VERSION,
        'postgresql' => $connection->fetchOne('SHOW server_version'),
        'dbal' => \Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
        'configuration' => $configuration,
        'native_dbal_introspection' => $nativeIntrospection,
        'native_dbal_diff' => $normalize($nativeDiff),
        'create_sql' => $normalize([...$baseCreate, ...$createFullText]),
        'repeat_diff' => $repeat,
        'weight_change_sql' => $normalize($weightSql),
        'ranking_before' => $beforeRanking,
        'ranking_after' => $afterRanking,
        'ordinary_mapping_change_sql' => $normalize($ordinarySql),
        'missing_index_sql' => $normalize($missingIndexSql),
        'missing_column_sql' => $normalize($missingColumnSql),
        'final_diff' => $diff($weights, $configuration),
        'transaction_policy' => 'All changes, including the random schema, are rolled back.',
        'prototype_limit' => 'Expected expressions are normalized using temporary DDL; this is not a production schema-diff API.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    $connection->close();
}
