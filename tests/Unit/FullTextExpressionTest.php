<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\Connection;
use ModernORM\Tools\FullText\Expression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FullTextExpressionTest extends TestCase
{
    private function reader(): Expression
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params): string {
            return match ($params[0]) {
                'pg_catalog.russian', 'russian' => '123',
                'pg_catalog.simple', 'simple' => '456',
                default => throw new \LogicException('Unexpected test configuration.'),
            };
        });
        return new Expression($connection);
    }

    public function testCatalogFormattingAndConfigAliasesCompareWithoutDdl(): void
    {
        $reader = $this->reader();
        $expected = $reader->read("pg_catalog.setweight(pg_catalog.to_tsvector('pg_catalog.russian'::pg_catalog.regconfig, COALESCE(\"ProductName\"::pg_catalog.text, ''::pg_catalog.text)), 'A'::pg_catalog.\"char\") || pg_catalog.setweight(pg_catalog.to_tsvector('pg_catalog.russian'::pg_catalog.regconfig, COALESCE(description::pg_catalog.text, ''::pg_catalog.text)), 'B'::pg_catalog.\"char\")");
        $actual = $reader->read("(setweight(to_tsvector('russian'::regconfig, COALESCE(\"ProductName\", ''::text)), 'A'::\"char\") || setweight(to_tsvector('russian'::regconfig, COALESCE((description)::text, ''::text)), 'B'::\"char\"))");
        self::assertSame($expected, $actual);
        self::assertSame(['ProductName', 'description'], $reader->columns($actual));
        self::assertNotSame($actual, $reader->read("setweight(to_tsvector('simple'::regconfig, COALESCE(\"ProductName\", ''::text)), 'A'::\"char\")"));
    }

    public function testQuotedIdentifiersAndLiteralsDoNotLoseTheirMeaning(): void
    {
        $reader = $this->reader();
        self::assertNotSame($reader->read('"Upper"'), $reader->read('Upper'));
        self::assertSame(['column', 'a"b'], $reader->read('"a""b"'));
        self::assertSame(['literal', "a'b"], $reader->read("'a''b'"));
        self::assertNotSame($reader->read("f('A')"), $reader->read("f('B')"));
        self::assertNotSame($reader->read('f(a, b)'), $reader->read('f(b, a)'));
        self::assertNotSame($reader->read('a || (b || c)'), $reader->read('(a || b) || c'));
        self::assertNotSame($reader->read('other.to_tsvector(a)'), $reader->read('pg_catalog.to_tsvector(a)'));
    }

    public static function unsupportedExpressions(): iterable
    {
        yield 'trailing command' => ['f(a); DROP TABLE products'];
        yield 'unclosed expression' => ['f(a'];
        yield 'unsupported operator' => ['a + b'];
        yield 'subquery' => ['(SELECT a FROM products)'];
        yield 'comment' => ['a /* comment */'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsupportedExpressions')]
    public function testUnsupportedExpressionsFailExplicitly(string $sql): void
    {
        $this->expectException(\RuntimeException::class);
        $this->reader()->read($sql);
    }
}
