<?php

declare(strict_types=1);

namespace ModernORM\Tools\Check;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use RuntimeException;

/** @internal Plans PostgreSQL CHECK constraints outside the DBAL schema model. */
final readonly class SchemaExtension
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<ClassMetadata> $classes
     * @return array{up: list<string>, down: list<string>}
     */
    public function plan(array $classes, Schema $target, bool $fromEmptySchema): array
    {
        $connection = $this->em->getConnection();
        $configuration = $this->em->getConfiguration();
        $up = [];
        $down = [];
        $seen = [];

        foreach ($classes as $class) {
            if ($class->checks === [] || isset($seen[$class->name])
                || in_array($class->name, $configuration->getSchemaIgnoreClasses(), true)) {
                continue;
            }
            $seen[$class->name] = true;
            $platform = $connection->getDatabasePlatform();
            if (! $platform instanceof PostgreSQLPlatform) {
                throw new RuntimeException($class->name . ' declares CHECK constraints, which require PostgreSQL.');
            }

            $mappedName = $configuration->getQuoteStrategy()->getTableName($class, $platform);
            $table = $target->getTable($mappedName)->getObjectName();
            $folding = $platform->getUnquotedIdentifierFolding();
            $tableName = $table->getUnqualifiedName()->toNormalizedValue($folding);
            $schemaName = $table->getQualifier()?->toNormalizedValue($folding)
                ?? $connection->fetchOne('SELECT pg_catalog.current_schema()');
            if (! is_string($schemaName) || $schemaName === '') {
                throw new RuntimeException($class->name . ' CHECK constraints require a resolved PostgreSQL schema.');
            }
            $tableSql = $platform->quoteSingleIdentifier($schemaName) . '.' . $platform->quoteSingleIdentifier($tableName);

            foreach ($class->checks as $check) {
                $name = $platform->quoteSingleIdentifier($check->name);
                $key = $tableSql . '.' . $name;
                $context = $class->name . ' ' . $tableSql . ' CHECK ' . $name;
                if (array_key_exists($key, $down)) {
                    throw new RuntimeException($context . ' has duplicate declarations on the same table.');
                }

                // Track declarations independently of whether they need an addition.
                $down[$key] = null;
                $actual = $fromEmptySchema ? false : $this->readConstraint($schemaName, $tableName, $check->name);
                if ($actual !== false) {
                    $this->assertEquivalent($actual, $tableSql, $check->expression, $context);
                    continue;
                }

                $up[$key] = 'ALTER TABLE ' . $tableSql . ' ADD CONSTRAINT ' . $name . ' CHECK (' . $check->expression . ')';
                $down[$key] = 'ALTER TABLE ' . $tableSql . ' DROP CONSTRAINT ' . $name;
            }
        }

        ksort($up, SORT_STRING);
        ksort($down, SORT_STRING);

        return ['up' => array_values($up), 'down' => array_values(array_filter($down, is_string(...)))];
    }

    /** @return array<string, mixed>|false */
    private function readConstraint(string $schema, string $table, string $constraint): array|false
    {
        return $this->em->getConnection()->fetchAssociative(<<<'SQL'
            SELECT c.contype,
                   CASE WHEN c.convalidated AND NOT c.connoinherit AND c.coninhcount = 0
                        AND coalesce(pg_catalog.to_jsonb(c)->>'conenforced', 'true') = 'true'
                        THEN 1 ELSE 0 END AS supported,
                   pg_catalog.pg_get_expr(c.conbin, c.conrelid) AS expression,
                   c.conbin::text AS expression_tree
            FROM pg_catalog.pg_constraint c
            JOIN pg_catalog.pg_class t ON t.oid = c.conrelid
            JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?
            ORDER BY (c.contype = 'c') ASC
            LIMIT 1
            SQL, [$schema, $table, $constraint]);
    }

    /** @param array<string, mixed> $actual */
    private function assertEquivalent(array $actual, string $table, string $expression, string $context): void
    {
        if ($actual['contype'] !== 'c' || (int) $actual['supported'] !== 1) {
            throw new RuntimeException($context . ': blocked by an existing constraint with the same name or an unsupported CHECK definition.');
        }
        $detail = ' Requested (' . $expression . '), database (' . $actual['expression'] . ').';
        try {
            $normalized = $this->normalize($table, $expression);
        } catch (Exception $failure) {
            throw new RuntimeException($context . ': ambiguous_definition; PostgreSQL could not normalize the predicate on an isolated table. Explicit migration required.' . $detail, 0, $failure);
        }
        if ($normalized === false
            || ! is_string($normalized['expression_tree'])
            || ! is_string($actual['expression_tree'])) {
            throw new RuntimeException($context . ': ambiguous_definition; explicit migration required.' . $detail);
        }

        // Keep PostgreSQL's resolved object OIDs and types. Only source offsets are irrelevant.
        $sourceOffsets = '/(?<!\\\\) :location -?[0-9]+(?=[ }])/';
        if ($actual['expression'] !== $normalized['expression']
            || preg_replace($sourceOffsets, '', $actual['expression_tree']) !== preg_replace($sourceOffsets, '', $normalized['expression_tree'])) {
            $reason = $actual['expression'] === $normalized['expression'] ? 'ambiguous_definition' : 'definition_drift';
            throw new RuntimeException($context . ': ' . $reason . '; explicit migration required.' . $detail);
        }
    }

    /** @return array<string, mixed>|false */
    private function normalize(string $table, string $expression): array|false
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $name = 'modernorm_check_' . bin2hex(random_bytes(12));
        $quoted = $platform->quoteSingleIdentifier($name);

        // A savepoint also isolates validation errors from a caller-owned transaction.
        $connection->beginTransaction();
        try {
            $connection->executeStatement(
                'CREATE TEMP TABLE ' . $quoted . ' (LIKE ' . $table . ', CONSTRAINT modernorm_probe CHECK (' . $expression . '))',
            );
            $schema = $connection->fetchOne('SELECT nspname FROM pg_catalog.pg_namespace WHERE oid = pg_catalog.pg_my_temp_schema()');

            return is_string($schema) ? $this->readConstraint($schema, $name, 'modernorm_probe') : false;
        } finally {
            $connection->rollBack();
        }
    }
}
