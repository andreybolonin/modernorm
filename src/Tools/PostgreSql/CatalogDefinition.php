<?php

declare(strict_types=1);

namespace ModernORM\Tools\PostgreSql;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** @internal Records the correspondence between declaration text and PostgreSQL's stored definition at apply time. */
final class CatalogDefinition
{
    private const MARKER = 'modernorm:definition:v1:';
    private const PATTERN = '/(?:\A|\n)' . self::MARKER . '([a-f0-9]{64}):([a-f0-9]{64})\z/';

    /** @param array<string,mixed> $actual */
    public static function equivalent(string $desired, string $deparsed, array $actual): bool
    {
        $comment = $actual['object_comment'] ?? null;
        if (is_string($comment) && preg_match(self::PATTERN, $comment, $marker) === 1) {
            if ($marker[2] !== $actual['fingerprint']) {
                return false;
            }
            return $marker[1] === self::sourceHash($desired);
        }

        return self::withoutOuterParentheses($desired) === self::withoutOuterParentheses($deparsed);
    }

    /** @param list<string> $arguments */
    public static function stamp(Connection $connection, string $object, string $source, string $lookup, array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $argument) {
            $placeholder = strpos($lookup, '?');
            if ($placeholder === false) {
                throw new RuntimeException('Invalid PostgreSQL catalog lookup.');
            }
            // Substitute into a fixed catalog query, before interpolating any developer SQL.
            $parts[] = substr($lookup, 0, $placeholder) . $connection->quote($argument);
            $lookup = substr($lookup, $placeholder + 1);
        }
        $lookup = implode('', $parts) . $lookup;
        $marker = $connection->quote(self::MARKER . self::sourceHash($source) . ':');
        $statement = $connection->quote('COMMENT ON ' . $object . ' IS ');
        $body = "DECLARE item record;\nBEGIN\nSELECT * INTO STRICT item FROM ($lookup) AS definition;\n"
            . "EXECUTE $statement || pg_catalog.quote_literal(pg_catalog.concat_ws(pg_catalog.chr(10), nullif(item.object_comment, ''), $marker || item.fingerprint));\nEND";
        $tag = '$modernorm_definition$';
        for ($suffix = 1; str_contains($body, $tag); ++$suffix) {
            $tag = '$modernorm_definition_' . $suffix . '$';
        }

        return 'DO ' . $tag . $body . $tag;
    }

    private static function sourceHash(string $source): string
    {
        return hash('sha256', self::withoutOuterParentheses($source));
    }

    private static function withoutOuterParentheses(string $sql): string
    {
        $sql = trim($sql);
        while (str_starts_with($sql, '(') && str_ends_with($sql, ')')) {
            // Recognize only unambiguous outer grouping. Complex quoting falls back to exact text.
            if (preg_match('/\$|\\\\|--|\/\*/', $sql) === 1
                || preg_match_all(<<<'REGEX'
                    /"(?:[^"]|"")*"|'(?:[^']|'')*'|[(),"']/
                    REGEX, $sql, $tokens) === false) {
                break;
            }
            $depth = 0;
            $last = count($tokens[0]) - 1;
            foreach ($tokens[0] as $index => $token) {
                if ($token === '"' || $token === "'") {
                    return $sql;
                }
                if ($token === ',' && $depth === 1) {
                    return $sql;
                }
                if ($token === '(') {
                    ++$depth;
                } elseif ($token === ')' && --$depth === 0 && $index !== $last) {
                    return $sql;
                }
            }
            if ($depth !== 0) {
                break;
            }
            $sql = trim(substr($sql, 1, -1));
        }

        return $sql;
    }
}
