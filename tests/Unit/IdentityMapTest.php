<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ArrayObject;
use ModernORM\Exception\EntityIdentityCollisionException;
use ModernORM\Internal\UnitOfWork\IdentityMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeakReference;

final class IdentityMapTest extends TestCase
{
    public function testRepeatedRegistrationIsIdempotentAndClassKeysAreIndependent(): void
    {
        $map = new IdentityMap();
        $first = new stdClass();
        $second = new ArrayObject();
        self::assertTrue($map->add(stdClass::class, '1', $first));
        self::assertFalse($map->add(stdClass::class, '1', $first));
        self::assertTrue($map->add(ArrayObject::class, '1', $second));
        self::assertSame($first, $map->get(stdClass::class, '1'));
        self::assertSame($second, $map->get(ArrayObject::class, '1'));
        self::assertSame(2, $map->size());
    }

    public function testCollisionCannotReplaceExistingObjectOrChangeSize(): void
    {
        $map = new IdentityMap();
        $first = new stdClass();
        $second = new stdClass();
        $map->add(stdClass::class, 'key', $first);
        try {
            $map->add(stdClass::class, 'key', $second);
            self::fail('Expected an identity collision.');
        } catch (EntityIdentityCollisionException $error) {
            self::assertStringContainsString('ID hash of "key"', $error->getMessage());
        }
        self::assertSame($first, $map->get(stdClass::class, 'key'));
        self::assertSame(1, $map->size());
        self::assertFalse($map->contains(stdClass::class, 'key', $second));
        self::assertFalse($map->remove(stdClass::class, 'key', $second));
        self::assertTrue($map->contains(stdClass::class, 'key', $first));
    }

    public static function normalizedKeys(): iterable
    {
        yield 'empty hash' => [''];
        yield 'zero' => ['0'];
        yield 'integer text' => ['1'];
        yield 'leading zero' => ['01'];
        yield 'negative integer text' => ['-1'];
        yield 'uuid' => ['787b87f3-5d99-4bbc-9423-4638179ea880'];
    }

    #[DataProvider('normalizedKeys')]
    public function testPreparedKeysAreStoredWithoutAdditionalNormalization(string $key): void
    {
        $map = new IdentityMap();
        $entity = new stdClass();
        self::assertTrue($map->add(stdClass::class, $key, $entity));
        self::assertSame($entity, $map->get(stdClass::class, $key));
        self::assertTrue($map->remove(stdClass::class, $key, $entity));
        self::assertSame(0, $map->size());
    }

    public function testLeadingZerosRemainDistinctAndSnapshotsCannotMutateTheMap(): void
    {
        $map = new IdentityMap();
        $padded = new stdClass();
        $numeric = new stdClass();
        $map->add(stdClass::class, '01', $padded);
        $map->add(stdClass::class, '1', $numeric);
        $snapshot = $map->all();
        unset($snapshot[stdClass::class]['01']);
        self::assertSame($padded, $map->get(stdClass::class, '01'));
        self::assertSame($numeric, $map->get(stdClass::class, '1'));
        self::assertSame(2, $map->size());
        $beforeRemoval = $map->all();
        $map->remove(stdClass::class, '01', $padded);
        self::assertSame($padded, $beforeRemoval[stdClass::class]['01']);
    }

    public function testRemovalPreservesEmptyClassBucketAndAllowsNewInstanceAtSameKey(): void
    {
        $map = new IdentityMap();
        $first = new stdClass();
        $second = new stdClass();
        $map->add(stdClass::class, 'key', $first);
        self::assertTrue($map->remove(stdClass::class, 'key', $first));
        self::assertFalse($map->remove(stdClass::class, 'key', $first));
        self::assertSame([stdClass::class => []], $map->all());
        self::assertTrue($map->add(stdClass::class, 'key', $second));
        self::assertFalse($map->remove(stdClass::class, 'key', $first));
        self::assertSame($second, $map->get(stdClass::class, 'key'));
    }

    public function testMissingLookupsDoNotCreateBuckets(): void
    {
        $map = new IdentityMap();
        $entity = new stdClass();
        self::assertNull($map->get(stdClass::class, 'missing'));
        self::assertFalse($map->contains(stdClass::class, 'missing', $entity));
        self::assertFalse($map->remove(stdClass::class, 'missing', $entity));
        self::assertSame([], $map->all());
        self::assertSame(0, $map->size());
    }

    public function testClearReleasesObjectsAndAllowsRegistrationInANewContext(): void
    {
        $map = new IdentityMap();
        $entity = new stdClass();
        $reference = WeakReference::create($entity);
        $map->add(stdClass::class, 'key', $entity);
        unset($entity);
        $map->clear();
        self::assertNull($reference->get());
        self::assertSame([], $map->all());
        self::assertSame(0, $map->size());
        $next = new stdClass();
        self::assertTrue($map->add(stdClass::class, 'key', $next));
        self::assertSame(1, $map->size());
    }
}
