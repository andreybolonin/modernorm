<?php

declare(strict_types=1);

namespace ModernORM\Tools\Check;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Tools\PostgreSql\CatalogDefinition;
use RuntimeException;

/** @internal Plans PostgreSQL CHECK constraints outside the DBAL schema model. */
final readonly class SchemaExtension
{
    private const CONSTRAINT = <<<'SQL'
    SELECT c.contype,
           CASE WHEN c.convalidated AND NOT c.connoinherit AND c.coninhcount = 0
                AND coalesce(pg_catalog.to_jsonb(c)->>'conenforced', 'true') = 'true'
                THEN 1 ELSE 0 END AS supported,
           pg_catalog.pg_get_expr(c.conbin, c.conrelid) AS expression,
           pg_catalog.obj_description(c.oid, 'pg_constraint') AS object_comment,
           pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(ROW(c.conbin::text, pg_catalog.pg_get_expr(c.conbin, c.conrelid))::text, 'UTF8')), 'hex') AS fingerprint
    FROM pg_catalog.pg_constraint c
    JOIN pg_catalog.pg_class t ON t.oid = c.conrelid
    JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
    WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?
    ORDER BY (c.contype = 'c') ASC
    LIMIT 1
    SQL;

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
        $stamps = [];
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
                    $this->assertEquivalent($actual, $check->expression, $context);
                    continue;
                }

                $up[$key] = 'ALTER TABLE ' . $tableSql . ' ADD CONSTRAINT ' . $name . ' CHECK (' . $check->expression . ')';
                $down[$key] = 'ALTER TABLE ' . $tableSql . ' DROP CONSTRAINT ' . $name;
                $stamps[$key] = CatalogDefinition::stamp(
                    $connection,
                    'CONSTRAINT ' . $name . ' ON ' . $tableSql,
                    $check->expression,
                    self::CONSTRAINT,
                    [$schemaName, $tableName, $check->name]
                );
            }
        }

        ksort($up, SORT_STRING);
        ksort($down, SORT_STRING);
        ksort($stamps, SORT_STRING);

        return ['up' => [...array_values($up), ...array_values($stamps)], 'down' => array_values(array_filter($down, is_string(...)))];
    }

    /** @return array<string, mixed>|false */
    private function readConstraint(string $schema, string $table, string $constraint): array|false
    {
        return $this->em->getConnection()->fetchAssociative(self::CONSTRAINT, [$schema, $table, $constraint]);
    }

    /** @param array<string, mixed> $actual */
    private function assertEquivalent(array $actual, string $expression, string $context): void
    {
        if ($actual['contype'] !== 'c' || (int) $actual['supported'] !== 1) {
            throw new RuntimeException($context . ': blocked by an existing constraint with the same name or an unsupported CHECK definition.');
        }
        if (! is_string($actual['expression']) || ! CatalogDefinition::equivalent($expression, $actual['expression'], $actual)) {
            throw new RuntimeException($context . ': definition_drift or ambiguous_definition; explicit migration required. Requested ('
                . $expression . '), database (' . $actual['expression'] . ').');
        }
    }
}
