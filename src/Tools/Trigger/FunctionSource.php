<?php

declare(strict_types=1);

namespace ModernORM\Tools\Trigger;

use RuntimeException;

/** @internal */
final readonly class FunctionSource
{
    private function __construct(public string $sql, public string $hash)
    {
    }

    public static function read(?string $root, string $file, string $schema, string $function, string $context): self
    {
        if ($root === null || str_starts_with($file, '/') || str_contains($file, '\\') || str_contains($file, ':')) {
            throw new RuntimeException($context . ' requires a relative functionSql path and a configured trigger SQL root.');
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . $file);
        if ($path === false || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException($context . ' cannot read a SQL file inside the trigger SQL root: ' . $file);
        }
        $sql = @file_get_contents($path);
        if ($sql === false || str_contains($sql, "\0") || ! self::declaresFunction($sql, $schema, $function)) {
            throw new RuntimeException($context . ' requires one schema-qualified CREATE OR REPLACE FUNCTION ' . $schema . '.' . $function . '() RETURNS trigger LANGUAGE plpgsql with a dollar-quoted body: ' . $file);
        }

        return new self($sql, hash('sha256', $sql));
    }

    private static function declaresFunction(string $sql, string $schema, string $function): bool
    {
        $identifier = '(?:"(?:[^"]|"")+"|[a-zA-Z_\x80-\xff][a-zA-Z_0-9$\x80-\xff]*)';
        $header = '/\A\s*CREATE\s+OR\s+REPLACE\s+FUNCTION\s+(' . $identifier . ')\s*\.\s*(' . $identifier
            . ')\s*\(\s*\)\s+RETURNS\s+trigger\s+LANGUAGE\s+plpgsql\s+AS\s+(\$(?:[a-zA-Z_][a-zA-Z_0-9]*)?\$)/i';
        if (preg_match($header, $sql, $matches) !== 1
            || self::identifier($matches[1]) !== $schema || self::identifier($matches[2]) !== $function) {
            return false;
        }
        $end = strpos($sql, $matches[3], strlen($matches[0]));

        return $end !== false && preg_match('/\A\s*;?\s*\z/', substr($sql, $end + strlen($matches[3]))) === 1;
    }

    private static function identifier(string $token): string
    {
        return $token[0] === '"' ? str_replace('""', '"', substr($token, 1, -1)) : strtolower($token);
    }
}
