<?php

declare(strict_types=1);

namespace ModernORM\Tools\FullText;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** @internal A small expression reader, not a general PostgreSQL SQL normalizer. */
final class Expression
{
    private array $tokens = [];
    private int $position = 0;
    private array $configurations = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function read(string $sql): array
    {
        $this->tokens = [];
        $this->position = 0;
        $offset = 0;
        while ($offset < strlen($sql)) {
            if (! preg_match('/\G\s*(\x27(?:\x27\x27|[^\x27])*\x27|"(?:""|[^"])*"|[a-zA-Z_][a-zA-Z0-9_$]*|::|\|\||[(),.])/', $sql, $match, 0, $offset)) {
                if (trim(substr($sql, $offset)) === '') {
                    break;
                }
                throw new RuntimeException('Unsupported FullTextIndex generation expression. Use an explicit migration.');
            }
            $offset += strlen($match[0]);
            $token = $match[1];
            $this->tokens[] = match ($token[0]) {
                "'" => ['literal', str_replace("''", "'", substr($token, 1, -1))],
                '"' => ['name', str_replace('""', '"', substr($token, 1, -1))],
                default => preg_match('/^[a-zA-Z_]/', $token) ? ['name', strtolower($token)] : [$token, $token],
            };
        }
        $node = $this->concatenation();
        if ($this->position !== count($this->tokens)) {
            throw new RuntimeException('Unsupported FullTextIndex generation expression. Use an explicit migration.');
        }
        return $this->normalize($node);
    }

    /** @return list<string> */
    public function columns(array $node): array
    {
        if ($node[0] === 'column') {
            return [$node[1]];
        }
        $columns = [];
        foreach ($node as $child) {
            if (is_array($child)) {
                $columns = [...$columns, ...$this->columns($child)];
            }
        }
        return array_values(array_unique($columns));
    }

    private function concatenation(): array
    {
        $node = $this->primary();
        while ($this->accept('||')) {
            $node = ['concat', $node, $this->primary()];
        }
        return $node;
    }

    private function primary(): array
    {
        if ($this->accept('(')) {
            $node = $this->concatenation();
            $this->expect(')');
        } elseif (($this->tokens[$this->position][0] ?? null) === 'literal') {
            $node = $this->tokens[$this->position++];
        } else {
            $name = $this->name();
            if ($this->accept('(')) {
                $arguments = [];
                if (! $this->accept(')')) {
                    do {
                        $arguments[] = $this->concatenation();
                    } while ($this->accept(','));
                    $this->expect(')');
                }
                $node = ['function', $name, ...$arguments];
            } else {
                $node = ['column', $name];
            }
        }
        while ($this->accept('::')) {
            $node = ['cast', $node, $this->name()];
        }
        return $node;
    }

    private function name(): string
    {
        $name = $this->expect('name');
        if ($this->accept('.')) {
            $schema = $name;
            $name = $this->expect('name');
            if ($schema !== 'pg_catalog') {
                $name = $schema . '.' . $name;
            }
        }
        return $name;
    }

    /** @phpstan-impure Consumes a token from the mutable input cursor. */
    private function accept(string $kind): bool
    {
        if (($this->tokens[$this->position][0] ?? null) !== $kind) {
            return false;
        }
        ++$this->position;
        return true;
    }

    private function expect(string $kind): string
    {
        $token = $this->tokens[$this->position] ?? null;
        if (! $this->accept($kind)) {
            throw new RuntimeException('Unsupported FullTextIndex generation expression. Use an explicit migration.');
        }
        return $token[1];
    }

    private function normalize(array $node): array
    {
        foreach ($node as &$child) {
            if (is_array($child)) {
                $child = $this->normalize($child);
            }
        }
        unset($child);
        if ($node[0] === 'cast') {
            if ($node[2] === 'text' && in_array($node[1][0], ['column', 'literal'], true)) {
                return $node[1];
            }
            if ($node[2] === 'regconfig' && $node[1][0] === 'literal') {
                $name = $node[1][1];
                $oid = $this->configurations[$name] ??= $this->connection->fetchOne('SELECT CAST(? AS pg_catalog.regconfig)::oid::text', [$name]);
                return ['configuration', $oid];
            }
        }
        return $node;
    }
}
