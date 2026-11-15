<?php

/* Cursor codec scenarios adapted from Doctrine ORM. Copyright (c) Doctrine Project.
 * MIT license: licenses/Doctrine-ORM-MIT.txt. */

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Tools\Pagination\Cursor;
use ModernORM\Tools\Pagination\Exception\InvalidCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testUrlSafeRoundTripPreservesDatabaseValuesAndDirection(): void
    {
        $values = ['id' => '18446744073709551615', 'date' => '2026-01-01 00:00:00.123456+03', 'amount' => '1234567890.123456',
            'flag' => false, 'ratio' => 1.0, 'name' => 'кириллица/bar+baz'];
        foreach ([true, false] as $next) {
            $cursor = new Cursor($values, $next);
            $encoded = $cursor->encodeToString();
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/D', $encoded);
            $decoded = Cursor::fromEncodedString($encoded);
            self::assertSame($values, $decoded->getParameters());
            self::assertSame($next, $decoded->isNext());
            self::assertSame(! $next, $decoded->isPrevious());
        }
    }

    public static function invalidValues(): iterable
    {
        yield 'empty' => [[]];
        yield 'null' => [['id' => null]];
        yield 'nested array' => [['id' => [1, 2]]];
        yield 'object' => [['id' => new \stdClass()]];
        yield 'numeric key' => [[1]];
        yield 'NaN' => [['id' => NAN]];
        yield 'infinity' => [['id' => INF]];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidProgrammaticPositionsAreRejected(array $values): void
    {
        $this->expectException(InvalidCursor::class);
        new Cursor($values);
    }

    public static function invalidPayloads(): iterable
    {
        yield 'nonstring' => [null];
        yield 'array' => [[]];
        yield 'base64' => ['not-valid!@#$'];
        yield 'empty token' => [''];
        foreach ([
            'not json', 'null', '[]', '"string"',
            '{"v":1,"next":"false","values":{"id":1}}',
            '{"v":2,"next":true,"values":{"id":1}}',
            '{"v":1,"values":{"id":1}}',
            '{"v":1,"next":true,"values":{"id":null}}',
            '{"v":1,"next":true,"values":{"id":[1,2]}}',
            '{"v":1,"next":true,"values":{}}',
            '{"v":1,"next":true,"values":{"id":1},"sql":"DROP TABLE records"}',
        ] as $json) {
            yield $json => [rtrim(strtr(base64_encode($json), '+/', '-_'), '=')];
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedEncodedPositionsAreRejected(mixed $value): void
    {
        $this->expectException(InvalidCursor::class);
        Cursor::fromEncodedString($value);
    }
}
