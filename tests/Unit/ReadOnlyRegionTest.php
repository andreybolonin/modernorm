<?php

declare(strict_types=1);
namespace ModernORM\Tests\Unit;

use ModernORM\Cache\EntityCacheEntry;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\Region\ReadOnlyRegion;
use ModernORM\Exception\CacheException;
use ModernORM\Tests\Support\RecordingCachePool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

require_once dirname(__DIR__) . '/Support/CacheFixtures.php';

final class ReadOnlyRegionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('lostGenerationKinds')]
    public function testLostOrInvalidGenerationCannotResurrectRetainedPayload(string $kind): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter(storeSerialized: true));
        $region = new ReadOnlyRegion('loss', $pool);
        $key = new EntityCacheKey('Country', ['id' => 1]);
        $oldTicket = $region->ticket($key);
        $generationKeys = $pool->writes;
        $region->putAt($oldTicket, new EntityCacheEntry('Country', ['id' => 1, 'name' => 'old']));
        foreach ($kind === 'all' ? $generationKeys : [$generationKeys[$kind === 'region' ? 0 : 1]] as $physicalKey) {
            $pool->deleteItem($physicalKey);
        }
        self::assertNull((new ReadOnlyRegion('loss', $pool))->get($key));
        // A SELECT that began before metadata loss can still publish its old ticket.
        $region->putAt($oldTicket, new EntityCacheEntry('Country', ['id' => 1, 'name' => 'late']));
        self::assertNull($region->get($key));
        self::assertTrue($pool->hasItem($oldTicket));
        $region->put($key, new EntityCacheEntry('Country', ['id' => 1, 'name' => 'fresh']));
        self::assertSame('fresh', $region->get($key)->data['name']);
    }
    public static function lostGenerationKinds(): iterable
    {
        yield 'region metadata lost' => ['region'];
        yield 'entry metadata lost' => ['entry'];
        yield 'all metadata lost, payload retained' => ['all'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedGenerations')]
    public function testMalformedGenerationIsReplacedWithoutReusingPayload(mixed $malformed): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter(storeSerialized: true));
        $region = new ReadOnlyRegion('malformed', $pool);
        $key = new EntityCacheKey('Country', ['id' => 1]);
        $ticket = $region->ticket($key);
        $entryKey = $pool->writes[1];
        $region->putAt($ticket, new EntityCacheEntry('Country', ['id' => 1]));
        $pool->save($pool->getItem($entryKey)->set($malformed));
        self::assertNull($region->get($key));
        self::assertNotSame($ticket, $region->ticket($key));
        $region->put($key, new EntityCacheEntry('Country', ['id' => 1, 'name' => 'old']));
        $region->evict($key);
        $pool->save($pool->getItem($entryKey)->set($malformed));
        self::assertNull($region->get($key));
    }
    public static function malformedGenerations(): iterable
    {
        yield 'array' => [['invalid']];
        yield 'empty string' => [''];
        yield 'non-token string' => ['invalid'];
        yield 'wrong length' => [str_repeat('a', 31)];
    }

    public function testSingleEvictionMakesInFlightPublicationUnreachable(): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter(storeSerialized: true));
        $first = new ReadOnlyRegion('shared', $pool);
        $second = new ReadOnlyRegion('shared', $pool);
        $key = new EntityCacheKey('Country', ['id' => 1]);
        $ticket = $first->ticket($key);
        $second->evict($key);
        $first->putAt($ticket, new EntityCacheEntry('Country', ['id' => 1, 'name' => 'stale']));
        self::assertNull($first->get($key));
        self::assertNull($second->get($key));
        $second->put($key, new EntityCacheEntry('Country', ['id' => 1, 'name' => 'fresh']));
        self::assertSame('fresh', $first->get($key)->data['name']);
    }
    public function testRegionEvictionIsScopedAndInvalidatesOldFillTickets(): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter(storeSerialized: true));
        $first = new ReadOnlyRegion('a', $pool); $other = new ReadOnlyRegion('b', $pool);
        $key = new EntityCacheKey('Country', ['id' => 1]);
        $ticket = $first->ticket($key);
        $other->put($key, new EntityCacheEntry('Country', ['id' => 1]));
        $pool->save($pool->getItem('unrelated')->set('keep'));
        $first->evictAll();
        $first->putAt($ticket, new EntityCacheEntry('Country', ['id' => 1]));
        self::assertFalse($first->contains($key));
        self::assertTrue($other->contains($key));
        self::assertSame('keep', $pool->getItem('unrelated')->get());
    }
    public function testKeysNormalizeScalarTypesWithoutLosingIdentifierBoundaries(): void
    {
        self::assertSame((new EntityCacheKey('Country', ['id' => 1]))->hash, (new EntityCacheKey('Country', ['id' => '1']))->hash);
        self::assertNotSame((new EntityCacheKey('Country', ['id' => '01']))->hash, (new EntityCacheKey('Country', ['id' => '1']))->hash);
        self::assertNotSame((new EntityCacheKey('A_', ['id' => 'b']))->hash, (new EntityCacheKey('A', ['id' => '_b']))->hash);
        self::assertNotSame((new EntityCacheKey('A', ['id' => 'a:b']))->hash, (new EntityCacheKey('A', ['id' => 'a/b']))->hash);
    }
    public function testPhysicalKeysFitThePsr6GuaranteedLength(): void
    {
        $pool = new class extends ArrayAdapter {
            public function getItem(mixed $key): \Symfony\Component\Cache\CacheItem
            {
                if (strlen($key) > 64) { throw new \InvalidArgumentException('Provider only supports 64-character keys.'); }
                return parent::getItem($key);
            }
        };
        $region = new ReadOnlyRegion(str_repeat('long-region', 40), $pool);
        $key = new EntityCacheKey(str_repeat('LongClass', 40), ['id' => str_repeat('value', 40)]);
        $region->put($key, new EntityCacheEntry('Country', ['id' => 1]));
        self::assertTrue($region->contains($key));
        $region->evict($key);
        self::assertFalse($region->contains($key));
        $region->evictAll();
    }
    public function testFailedGenerationWriteIsReported(): void
    {
        $pool = new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool { return false; }
        };
        $this->expectException(CacheException::class);
        (new ReadOnlyRegion('test', $pool))->evictAll();
    }
}
