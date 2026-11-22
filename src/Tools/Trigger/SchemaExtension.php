<?php

declare(strict_types=1);

namespace ModernORM\Tools\Trigger;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use RuntimeException;

/** @internal */
final readonly class SchemaExtension
{
    private const EVENT_BITS = ['INSERT' => 4, 'UPDATE' => 16, 'DELETE' => 8];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<ClassMetadata> $classes
     * @return array{functions: list<string>, up: list<string>, down: list<string>}
     */
    public function plan(array $classes, Schema $target, bool $fromEmptySchema): array
    {
        $connection = $this->em->getConnection();
        $config = $this->em->getConfiguration();
        $registry = new FunctionRegistry($connection);
        $triggers = [];
        $sources = [];
        $additions = [];
        $removals = [];
        $visited = [];

        foreach ($classes as $class) {
            if ($class->triggers === [] || isset($visited[$class->name]) || in_array($class->name, $config->getSchemaIgnoreClasses(), true)) {
                continue;
            }
            $visited[$class->name] = true;
            $platform = $connection->getDatabasePlatform();
            if (! $platform instanceof PostgreSQLPlatform) {
                throw new RuntimeException($class->name . ' declares triggers, which require PostgreSQL.');
            }

            $quote = $platform->quoteSingleIdentifier(...);
            $mappedName = $config->getQuoteStrategy()->getTableName($class, $platform);
            $table = $target->getTable($mappedName)->getObjectName();
            $folding = $platform->getUnquotedIdentifierFolding();
            $tableName = $table->getUnqualifiedName()->toNormalizedValue($folding);
            $schemaName = $table->getQualifier()?->toNormalizedValue($folding)
                ?? $connection->fetchOne('SELECT pg_catalog.current_schema()');
            if (! is_string($schemaName) || $schemaName === '') {
                throw new RuntimeException($class->name . ' triggers require a resolved PostgreSQL schema.');
            }
            $tableSql = $quote($schemaName) . '.' . $quote($tableName);

            foreach ($class->triggers as $trigger) {
                $name = $quote($trigger->name);
                $key = $tableSql . '.' . $name;
                $context = $class->name . ' ' . $tableSql . ' trigger ' . $name;
                if (isset($triggers[$key])) {
                    throw new RuntimeException($context . ' has duplicate declarations on the same table.');
                }
                $triggers[$key] = true;
                $parts = explode('.', $trigger->functionName($context), 2);
                [$functionSchema, $functionName] = count($parts) === 2 ? $parts : [$schemaName, $parts[0]];
                $function = $quote($functionSchema) . '.' . $quote($functionName);
                if ($trigger->functionSql !== null) {
                    $source = FunctionSource::read($config->getTriggerFunctionSqlRoot(), $trigger->functionSql, $functionSchema, $functionName, $context);
                    if (isset($sources[$function]) && $sources[$function][2]->hash !== $source->hash) {
                        throw new RuntimeException($context . ' has conflicting SQL definitions for shared function ' . $function);
                    }
                    $sources[$function] = [$functionSchema, $functionName, $source];
                }

                $timing = strtoupper($trigger->timing);
                $events = $trigger->eventNames($context);
                $actual = $fromEmptySchema ? false : $this->readTrigger($schemaName, $tableName, $trigger->name);
                if ($actual !== false) {
                    $type = ($timing === 'BEFORE' ? 3 : 1) + array_sum(array_map(static fn (string $event): int => self::EVENT_BITS[$event], $events));
                    if ((int) $actual['supported'] !== 1 || (int) $actual['tgtype'] !== $type
                        || $actual['function_schema'] !== $functionSchema || $actual['function_name'] !== $functionName) {
                        throw new RuntimeException($context . ': blocked trigger drift. Requested ' . $timing . ' ' . implode(' OR ', $events)
                            . ' FOR EACH ROW → ' . $function . '(). Database: ' . $actual['definition']
                            . '; enabled state: ' . $actual['tgenabled'] . '. Use an explicit migration.');
                    }
                    continue;
                }

                $additions[$key] = 'CREATE TRIGGER ' . $name . ' ' . $timing . ' ' . implode(' OR ', $events)
                    . ' ON ' . $tableSql . ' FOR EACH ROW EXECUTE FUNCTION ' . $function . '()';
                $removals[$key] = 'DROP TRIGGER ' . $name . ' ON ' . $tableSql;
            }
        }

        ksort($sources, SORT_STRING);
        ksort($additions, SORT_STRING);
        ksort($removals, SORT_STRING);
        $functions = [];
        foreach ($sources as [$schema, $function, $source]) {
            $registryTable = $connection->quoteSingleIdentifier($schema) . '.' . FunctionRegistry::TABLE;
            if ($target->hasTable($registryTable)) {
                throw new RuntimeException('A mapped table conflicts with the trigger function registry: ' . $registryTable);
            }
            $functions = [...$functions, ...$registry->plan($schema, $function, $source, $fromEmptySchema)];
        }

        return ['functions' => $functions, 'up' => array_values($additions), 'down' => array_values($removals)];
    }

    /** @return array<string, mixed>|false */
    private function readTrigger(string $schema, string $table, string $trigger): array|false
    {
        return $this->em->getConnection()->fetchAssociative(<<<'SQL'
            SELECT t.tgtype, t.tgenabled, f.proname AS function_name, fn.nspname AS function_schema,
                   pg_catalog.pg_get_triggerdef(t.oid) AS definition,
                   CASE WHEN NOT t.tgisinternal AND t.tgenabled = 'O' AND t.tgnargs = 0 AND t.tgconstraint = 0
                        AND t.tgqual IS NULL AND t.tgattr = ''::pg_catalog.int2vector AND t.tgparentid = 0
                        AND t.tgoldtable IS NULL AND t.tgnewtable IS NULL AND f.pronargs = 0
                        THEN 1 ELSE 0 END AS supported
            FROM pg_catalog.pg_trigger t
            JOIN pg_catalog.pg_class c ON c.oid = t.tgrelid
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_catalog.pg_proc f ON f.oid = t.tgfoid
            JOIN pg_catalog.pg_namespace fn ON fn.oid = f.pronamespace
            WHERE n.nspname = ? AND c.relname = ? AND t.tgname = ?
            SQL, [$schema, $table, $trigger]);
    }
}
