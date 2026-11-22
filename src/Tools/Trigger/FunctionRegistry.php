<?php

declare(strict_types=1);

namespace ModernORM\Tools\Trigger;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** @internal Tracks applied source hashes and refuses implicit adoption of live functions. */
final readonly class FunctionRegistry
{
    public const TABLE = 'modernorm_trigger_functions';
    public const TABLE_MARKER = 'modernorm:trigger-function-registry:v1';
    private const FUNCTION_MARKER = 'modernorm:trigger-function:v1';

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function plan(string $schema, string $name, FunctionSource $source, bool $fromEmptySchema): array
    {
        $table = $this->qualified($schema, self::TABLE);
        $function = $this->qualified($schema, $name) . '()';
        $functionLiteral = $this->connection->quote($function);
        $nameLiteral = $this->connection->quote($name);
        $hashLiteral = $this->connection->quote($source->hash);
        $tableLiteral = $this->connection->quote($table);
        $tableMarker = $this->connection->quote(self::TABLE_MARKER);
        $functionMarker = $this->connection->quote(self::FUNCTION_MARKER);
        $tableOid = 'pg_catalog.to_regclass(' . $tableLiteral . ')';
        $ownedTable = "pg_catalog.obj_description($tableOid, 'pg_class') IS NOT DISTINCT FROM $tableMarker";
        $lookup = 'SELECT source_hash FROM ' . $table . ' WHERE function_name = ' . $nameLiteral;
        $oid = 'pg_catalog.to_regprocedure(' . $functionLiteral . ')';
        $compatible = "EXISTS (SELECT 1 FROM pg_catalog.pg_proc WHERE oid = $oid AND prokind = 'f' AND prorettype = 'pg_catalog.trigger'::pg_catalog.regtype AND pg_catalog.obj_description(oid, 'pg_proc') = $functionMarker)";
        $previous = false;
        if (! $fromEmptySchema) {
            if ($this->connection->fetchOne('SELECT pg_catalog.to_regclass(?) IS NOT NULL', [$table])) {
                if (! $this->connection->fetchOne('SELECT ' . $ownedTable)) {
                    throw new RuntimeException('An existing object conflicts with the trigger function registry: ' . $table);
                }
                $previous = $this->connection->fetchOne($lookup);
            }
            $exists = (bool) $this->connection->fetchOne('SELECT ' . $oid . ' IS NOT NULL');
            if (($previous === false && $exists) || ($previous !== false && ! $this->connection->fetchOne('SELECT ' . $compatible))) {
                throw new RuntimeException($function . ' conflicts with an external, missing or incompatible function. Resolve ownership in an explicit migration.');
            }
        }
        if ($previous === $source->hash) {
            return [];
        }

        $expected = $previous === false ? 'NULL' : $this->connection->quote((string) $previous);
        $unexpectedFunction = $previous === false ? "$oid IS NOT NULL" : "NOT $compatible";
        $guard = "BEGIN\nIF ($lookup) IS DISTINCT FROM $expected OR $unexpectedFunction THEN\n"
            . "RAISE EXCEPTION 'Trigger function state changed since migration generation: %', $functionLiteral;\nEND IF;\nEND";
        $bootstrap = "BEGIN\nIF $tableOid IS NULL THEN\n"
            . "CREATE TABLE $table (function_name text PRIMARY KEY, source_hash text NOT NULL);\n"
            . "COMMENT ON TABLE $table IS $tableMarker;\n"
            . "ELSIF NOT ($ownedTable) THEN\nRAISE EXCEPTION 'An existing object conflicts with the trigger function registry';\nEND IF;\nEND";

        return [
            'SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(' . $tableLiteral . ', 0))',
            $this->doStatement($bootstrap),
            $this->doStatement($guard),
            $source->sql,
            'COMMENT ON FUNCTION ' . $function . ' IS ' . $functionMarker,
            'INSERT INTO ' . $table . ' (function_name, source_hash) VALUES (' . $nameLiteral . ', ' . $hashLiteral
                . ') ON CONFLICT (function_name) DO UPDATE SET source_hash = EXCLUDED.source_hash',
        ];
    }

    public static function irreversibleSql(): string
    {
        return "DO \$modernorm\$ BEGIN RAISE EXCEPTION 'This migration changes a managed trigger function and requires an explicit rollback migration'; END \$modernorm\$";
    }

    private function qualified(string $schema, string $name): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return $platform->quoteSingleIdentifier($schema) . '.' . $platform->quoteSingleIdentifier($name);
    }

    private function doStatement(string $body): string
    {
        $tag = '$modernorm$';
        for ($suffix = 1; str_contains($body, $tag); ++$suffix) {
            $tag = '$modernorm_' . $suffix . '$';
        }

        return 'DO ' . $tag . $body . $tag;
    }
}
