<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use ModernORM\Cache\EntityCacheEntry;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\Region\ReadOnlyRegion;
use ModernORM\Tests\Support\RecordingCachePool;
use ModernORM\Tests\Support\RedisL2DisposableTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\RedisAdapter;

require_once dirname(__DIR__).'/Support/RedisL2DisposableTestCase.php';
require_once dirname(__DIR__).'/Support/CacheFixtures.php';

final class RedisL2EnduranceTest extends RedisL2DisposableTestCase
{
    private function client(): \Redis
    {
        $client = new \Redis();
        $client->connect('127.0.0.1', $this->server->port, 1);
        $client->setOption(\Redis::OPT_READ_TIMEOUT, 1);
        return $client;
    }

    private function until(\Closure $condition, float $seconds = 3): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) { return; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Redis expiry/eviction condition did not occur within the bounded deadline.');
    }

    private function physicalKey(\Redis $client, string $logical): string
    {
        $cursor = null;
        do {
            foreach ($client->scan($cursor) ?: [] as $key) {
                if (str_ends_with($key, $logical)) { return $key; }
            }
        } while ($cursor !== 0);
        self::fail('Fixture key was not found in the disposable Redis instance.');
    }

    private function fixture(\Redis $client, int $ttl, int $poolLifetime = 0): array
    {
        $pool = new RecordingCachePool(new RedisAdapter($client, $this->namespace, $poolLifetime));
        $region = new ReadOnlyRegion('expiry', $pool, $ttl);
        $key = new EntityCacheKey('Record', ['id' => 1]);
        $ticket = $region->ticket($key);
        $metadata = array_map(fn (string $logical): string => $this->physicalKey($client, $logical), $pool->writes);
        $region->putAt($ticket, new EntityCacheEntry('Record', ['name' => 'old', 'padding' => str_repeat('x', 8192)]));
        return [$region, $key, $ticket, $this->physicalKey($client, $ticket), $metadata];
    }

    private function persistentKeys(\Redis $client): int
    {
        $cursor = null; $keys = [];
        do {
            foreach ($client->scan($cursor) ?: [] as $key) {
                if ($client->pTtl($key) === -1) { $keys[$key] = true; }
            }
        } while ($cursor !== 0);
        return count($keys);
    }

    public function testPayloadExpiresWhileGenerationMetadataRemains(): void
    {
        $client = $this->client();
        try {
            [$region, $key, , $payload, $metadata] = $this->fixture($client, 1);
            self::assertGreaterThan(0, $client->pTtl($payload));
            self::assertLessThanOrEqual(1000, $client->pTtl($payload));
            foreach ($metadata as $physical) { self::assertSame(-1, $client->pTtl($physical)); }
            $this->until(static fn (): bool => !$client->exists($payload));
            self::assertNull($region->get($key));
            foreach ($metadata as $physical) { self::assertSame(1, $client->exists($physical)); }
            $region->put($key, new EntityCacheEntry('Record', ['name' => 'fresh']));
            self::assertSame('fresh', $region->get($key)->data['name']);
        } finally { $client->close(); }
    }

    public function testZeroTtlPreservesPayloadButEvictionStillInvalidatesItsTicket(): void
    {
        $client = $this->client();
        try {
            [$region, $key, $ticket, $payload] = $this->fixture($client, 0);
            self::assertSame(-1, $client->pTtl($payload));
            $region->evict($key);
            $region->putAt($ticket, new EntityCacheEntry('Record', ['name' => 'late']));
            self::assertNull($region->get($key));
            self::assertSame(1, $client->exists($payload));
        } finally { $client->close(); }
    }

    public function testExpiringGenerationMetadataCannotReviveLongerLivedPayload(): void
    {
        $client = $this->client();
        try {
            [$region, $key, $ticket, $payload, $metadata] = $this->fixture($client, 60, 1);
            foreach ($metadata as $physical) { self::assertGreaterThan(0, $client->pTtl($physical)); }
            $this->until(static fn (): bool => !$client->exists($metadata[0]) && !$client->exists($metadata[1]));
            self::assertSame(1, $client->exists($payload));
            self::assertNull($region->get($key));
            $region->putAt($ticket, new EntityCacheEntry('Record', ['name' => 'late']));
            self::assertNull($region->get($key));
        } finally { $client->close(); }
    }

    #[DataProvider('evictionPolicies')]
    public function testActualMemoryPressureCausesSafeMisses(string $policy): void
    {
        $client = $this->client();
        try {
            [$region, $key, $ticket, $payload, $metadata] = $this->fixture($client, 60);
            $client->config('SET', 'maxmemory-policy', $policy);
            $client->config('SET', 'maxmemory-samples', '10');
            // Redis LRU has second-level clock granularity; establish an observable age gap.
            $this->until(static fn (): bool => $client->object('IDLETIME', $metadata[0]) >= 1);
            $used = (int) $client->info('memory')['used_memory'];
            $before = (int) $client->info('stats')['evicted_keys'];
            $client->config('SET', 'maxmemory', (string) ($used + 262144));
            $observed = false;
            for ($i = 0; $i < 256; ++$i) {
                if ($policy === 'allkeys-lru') { $client->get($payload); }
                $client->setEx('pressure_'.$i, 30, str_repeat('p', 65536));
                $lostMetadata = !$client->exists($metadata[0]) || !$client->exists($metadata[1]);
                $payloadExists = (bool) $client->exists($payload);
                if (($policy === 'volatile-lru' && !$payloadExists) || ($policy === 'allkeys-lru' && $lostMetadata && $payloadExists)) {
                    $observed = true; break;
                }
            }
            self::assertTrue($observed, 'Did not observe the intended real Redis eviction.');
            self::assertGreaterThan($before, (int) $client->info('stats')['evicted_keys']);
            $client->config('SET', 'maxmemory', '0');
            if ($policy === 'volatile-lru') {
                foreach ($metadata as $physical) { self::assertSame(1, $client->exists($physical)); }
            } else {
                self::assertSame(1, $client->exists($payload));
                // The old value is physically present, but a new random generation makes it unreachable.
                self::assertNull($region->get($key));
                $region->putAt($ticket, new EntityCacheEntry('Record', ['name' => 'late']));
            }
            self::assertNull($region->get($key));
            $region->put($key, new EntityCacheEntry('Record', ['name' => 'fresh']));
            self::assertSame('fresh', $region->get($key)->data['name']);
        } finally { $client->config('SET', 'maxmemory', '0'); $client->close(); }
    }

    public static function evictionPolicies(): iterable
    {
        yield 'payload evicted, persistent generations retained' => ['volatile-lru'];
        yield 'generation evicted, hot payload retained' => ['allkeys-lru'];
    }

    private function cycle(int $number, int $writer, array $readers): void
    {
        $this->send($writer, ['op' => 'update', 'name' => 'value_'.$number]);
        foreach ($readers as $reader) { $this->send($reader, ['op' => 'find']); }
        $written = $this->receive($writer);
        self::assertSame($number + 1, $written['version'] ?? null, json_encode($written));
        foreach ($readers as $reader) {
            $inFlight = $this->receive($reader);
            self::assertContains($inFlight['version'] ?? null, [$number, $number + 1], json_encode($inFlight));
            $expected = $inFlight['version'] === $number + 1 ? 'value_'.$number : ($number === 1 ? 'original' : 'value_'.($number - 1));
            self::assertSame($expected, $inFlight['name']);
        }
        // These reads start after commit/invalidation acknowledgement, unlike the in-flight group.
        foreach ($readers as $reader) { $this->send($reader, ['op' => 'find']); }
        foreach ($readers as $reader) {
            $fresh = $this->receive($reader);
            self::assertSame($number + 1, $fresh['version'] ?? null, json_encode($fresh));
            self::assertSame('value_'.$number, $fresh['name']);
        }
    }

    public function testConcurrentWorkersRemainFreshWithStableMetadataAndExpiringPayloads(): void
    {
        $seconds = getenv('MODERNORM_L2_SOAK_SECONDS') ?: '3';
        if (!ctype_digit($seconds) || (int) $seconds < 1 || (int) $seconds > 600) {
            self::fail('MODERNORM_L2_SOAK_SECONDS must be from 1 to 600.');
        }
        $client = $this->client();
        try {
            $writer = $this->worker(ttl: 1);
            $readers = [$this->worker(ttl: 1), $this->worker(ttl: 1), $this->worker(ttl: 1), $this->worker(ttl: 1)];
            $all = [$writer, ...$readers];
            for ($i = 1; $i <= 20; ++$i) { $this->cycle($i, $writer, $readers); }
            // Concurrent first initialization can leave orphan entry-generation keys.
            $baselineGenerations = $this->persistentKeys($client);
            self::assertGreaterThanOrEqual(2, $baselineGenerations);
            $sample = function (float $elapsed, int $cycles) use ($all, $client): array {
                $workers = [];
                foreach ($all as $worker) { $workers[] = $this->request($worker, ['op' => 'metrics']); }
                return ['elapsed_seconds' => $elapsed, 'cycles' => $cycles, 'redis_keys' => $client->dbSize(),
                    'persistent_generation_keys' => $this->persistentKeys($client),
                    'redis_used_memory_bytes' => (int) $client->info('memory')['used_memory'], 'workers' => $workers];
            };
            $samples = [$sample(0, 0)];
            $start = hrtime(true); $nextSample = 1; $nextProgress = 5; $cycles = 0;
            do {
                $this->cycle(21 + $cycles, $writer, $readers); ++$cycles;
                $elapsed = (hrtime(true) - $start) / 1e9;
                if ($elapsed >= $nextSample) { $samples[] = $sample($elapsed, $cycles); $nextSample = $elapsed + 1; }
                if ($elapsed >= $nextProgress && getenv('MODERNORM_L2_SOAK_REPORT')) {
                    fwrite(STDERR, sprintf("L2 soak: %.1fs, %d committed updates, four readers\n", $elapsed, $cycles));
                    $nextProgress = $elapsed + 5;
                }
            } while ($elapsed < (int) $seconds);
            $samples[] = $sample($elapsed, $cycles);
            foreach ($samples as $point) { self::assertSame($baselineGenerations, $point['persistent_generation_keys']); }
            foreach ($all as $index => $worker) {
                $heaps = array_map(static fn (array $point): int => $point['workers'][$index]['heap_bytes'], $samples);
                self::assertLessThan(4194304, max($heaps) - min($heaps), 'Worker retained heap grew by 4 MiB after warm-up.');
                foreach ($samples as $point) { self::assertSame(1, $point['workers'][$index]['managed_entities']); }
            }
            // Stop filling, then let the one-second payload TTL reclaim all old addresses.
            try { $this->until(static fn (): bool => $client->dbSize() === $baselineGenerations); }
            catch (\Throwable $error) {
                $keys = []; $cursor = null;
                $diagnostics = new RedisAdapter($client, $this->namespace);
                do { foreach ($client->scan($cursor) ?: [] as $key) {
                    $keys[$key] = ['ttl' => $client->pTtl($key), 'type' => get_debug_type($diagnostics->getItem(substr($key, -64))->get())];
                } } while ($cursor !== 0);
                throw new \RuntimeException('Remaining Redis key TTLs: '.json_encode($keys), 0, $error);
            }
            $remaining = $client->dbSize();
            $report = ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'redis' => $client->info('server')['redis_version'],
                'symfony_cache' => \Composer\InstalledVersions::getPrettyVersion('symfony/cache'), 'ext_redis' => phpversion('redis'),
                'duration_seconds' => $elapsed, 'committed_updates' => $cycles, 'warmup_updates' => 20, 'reader_processes' => 4,
                'payload_ttl_seconds' => 1, 'remaining_keys_after_quiescence' => $remaining, 'samples' => $samples,
                'baseline_generation_keys' => $baselineGenerations,
                'note' => 'One writer and four independent readers. In-flight reads may return the preceding committed version; reads started after writer acknowledgement must return the new version. PHP retained heap after GC is sampled, not RSS. Initial generation races may leave orphan metadata; persistent key count must stay at its post-warm-up baseline, and all payload keys must expire after quiescence. Fixed single-ID workload; not a bound for growing entity/ID/class cardinality or repeated class eviction.'];
            if ($path = getenv('MODERNORM_L2_SOAK_REPORT')) {
                if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n") === false) {
                    throw new \RuntimeException('Cannot write L2 soak report.');
                }
            }
        } finally { $client->close(); }
    }
}
