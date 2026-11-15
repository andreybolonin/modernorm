<?php

declare(strict_types=1);

namespace ModernORM\Query\Expression;

use ModernORM\QueryBuilder;

/** JSONB expressions bind their values to the originating builder. Paths are immutable. */
final class JsonbExpression
{
    /** @internal Obtain instances through QueryBuilder::jsonb().
     *  @param list<string|int> $segments
     */
    public function __construct(
        private readonly QueryBuilder $builder,
        private readonly string $column,
        private readonly array $segments = [],
    ) {
    }

    /** Append literal object keys or array indexes; dots are not path separators.
     *  @param list<string|int> $segments
     */
    public function path(array $segments): self
    {
        if (! array_is_list($segments)) {
            throw new \InvalidArgumentException('JSONB paths must be lists of string keys or integer indexes.');
        }
        foreach ($segments as $segment) {
            if (! is_string($segment) && ! is_int($segment)) {
                throw new \InvalidArgumentException('JSONB path segments must be string keys or integer indexes.');
            }
        }
        return new self($this->builder, $this->column, [...$this->segments, ...$segments]);
    }

    /** JSONB-valued SQL. Missing paths/SQL NULL yield SQL NULL; explicit JSON null stays JSON null. */
    public function json(): string
    {
        return $this->segments === [] ? $this->column : '(' . $this->column . ' #> ' . $this->pathArray() . ')';
    }

    /** Text-valued SQL. JSON null, missing paths and SQL NULL all yield SQL NULL. */
    public function text(): string
    {
        return '(' . $this->column . ' #>> ' . $this->pathArray() . ')';
    }

    public function contains(mixed $value): string
    {
        $encoded = self::encode($value);
        return '(' . $this->json() . ' @> CAST(' . $this->bind($encoded) . ' AS pg_catalog.jsonb))';
    }

    /** Native top-level key/string-element existence, also true for keys whose value is JSON null. */
    public function exists(string $key): string
    {
        // The function avoids PDO interpreting the PostgreSQL ? operator as a placeholder.
        return 'pg_catalog.jsonb_exists(' . $this->json() . ', CAST(' . $this->bind($key) . ' AS pg_catalog.text))';
    }

    /** Type-preserving JSONB equality/inequality. PHP null means JSON null, not SQL NULL. */
    public function compare(string $operator, mixed $value): string
    {
        $operator = $this->operator($operator);
        if (! in_array($operator, ['=', '<>'], true)) {
            throw new \InvalidArgumentException('Ordered JSONB comparisons require compareText() or compareNumber().');
        }
        $encoded = self::encode($value);
        return '(' . $this->json() . ' ' . $operator . ' CAST(' . $this->bind($encoded) . ' AS pg_catalog.jsonb))';
    }

    /** Compare JSON strings only, using PostgreSQL text collation. Other JSON types do not match. */
    public function compareText(string $operator, string $value): string
    {
        $operator = $this->operator($operator);
        $json = $this->json();
        $text = "(CASE WHEN pg_catalog.jsonb_typeof(" . $json . ") = 'string' THEN (" . $json . ' #>> ARRAY[]::pg_catalog.text[]) END)';
        return '(' . $text . ' ' . $operator . ' CAST(' . $this->bind($value) . ' AS pg_catalog.text))';
    }

    /** Compare JSON numbers only. Decimal strings preserve precision and are not coerced to PHP floats. */
    public function compareNumber(string $operator, int|float|string $value): string
    {
        $operator = $this->operator($operator);
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new \InvalidArgumentException('JSONB numeric comparisons require finite numbers.');
            }
            $number = self::encode($value);
        } else {
            $number = (string) $value;
        }
        if (! preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $number)) {
            throw new \InvalidArgumentException('JSONB numeric comparisons require a number or a decimal string.');
        }
        $json = $this->json();
        $numeric = "(CASE WHEN pg_catalog.jsonb_typeof(" . $json . ") = 'number' THEN CAST(" . $json . ' AS pg_catalog.numeric) END)';
        return '(' . $numeric . ' ' . $operator . ' CAST(' . $this->bind($number) . ' AS pg_catalog.numeric))';
    }

    public function isJsonNull(): string
    {
        return $this->compare('=', null);
    }

    /** SQL NULL after JSONB extraction: an absent path or a SQL NULL document. */
    public function isSqlNull(): string
    {
        return '(' . $this->json() . ' IS NULL)';
    }

    /** Path does not resolve in a non-SQL-NULL document; explicit JSON null is present. */
    public function isMissing(): string
    {
        if ($this->segments === []) {
            throw new \InvalidArgumentException('isMissing() requires a JSONB path. Use isSqlNull() for the whole column.');
        }
        return '(' . $this->column . ' IS NOT NULL AND ' . $this->json() . ' IS NULL)';
    }

    private function pathArray(): string
    {
        if ($this->segments === []) {
            return 'ARRAY[]::pg_catalog.text[]';
        }
        $parameters = [];
        foreach ($this->segments as $segment) {
            $parameters[] = 'CAST(' . $this->bind((string) $segment) . ' AS pg_catalog.text)';
        }
        return 'ARRAY[' . implode(', ', $parameters) . ']';
    }

    private function bind(string $value): string
    {
        return $this->builder->bindJsonbParameter($value);
    }

    private function operator(string $operator): string
    {
        if (! in_array($operator, ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
            throw new \InvalidArgumentException('Unsupported JSONB comparison operator.');
        }
        return $operator === '!=' ? '<>' : $operator;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
