<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use ModernORM\Exception\CacheSynchronizationException;
use ModernORM\Exception\OptimisticLockException;

require_once dirname(__DIR__).'/Support/RedisL2ProcessTestCase.php';

final class RedisL2ConcurrencyTest extends \ModernORM\Tests\Support\RedisL2ProcessTestCase
{
    public function testPrecommitReaderFillIsInvalidatedAfterWriterCommit(): void
    {
        $reader = $this->worker(); $writer = $this->worker();
        self::assertSame(1, $this->request($reader, ['op' => 'find'])['sql']);
        self::assertSame(0, $this->request($reader, ['op' => 'find'])['sql']);
        $this->send($writer, ['op' => 'update', 'name' => 'committed', 'pause' => true]);
        self::assertSame(['event' => 'paused'], $this->receive($writer));
        $old = $this->request($reader, ['op' => 'find']);
        self::assertSame('original', $old['name']); self::assertSame(1, $old['sql']);
        $this->send($writer, ['continue' => true]);
        self::assertSame(2, $this->receive($writer)['version']);
        $fresh = $this->request($reader, ['op' => 'find']);
        self::assertSame('committed', $fresh['name']); self::assertSame(1, $fresh['sql']);
        self::assertSame(0, $this->request($reader, ['op' => 'find'])['sql']);
    }

    public function testRollbackDeleteReinsertAndBulkInvalidateAcrossProcesses(): void
    {
        $reader = $this->worker(); $writer = $this->worker();
        $this->request($reader, ['op' => 'find']);
        self::assertSame('private', $this->request($writer, ['op' => 'rollback'])['name']);
        $unchanged = $this->request($reader, ['op' => 'find']);
        self::assertSame('original', $unchanged['name']); self::assertSame(0, $unchanged['sql']);
        $this->request($writer, ['op' => 'delete']);
        self::assertNull($this->request($reader, ['op' => 'find'])['name']);
        $this->request($writer, ['op' => 'insert', 'name' => 'reinserted']);
        self::assertSame('reinserted', $this->request($reader, ['op' => 'find'])['name']);
        self::assertSame(1, $this->request($writer, ['op' => 'bulk_update', 'name' => 'bulk'])['affected']);
        self::assertSame('bulk', $this->request($reader, ['op' => 'find'])['name']);
        self::assertSame(0, $this->request($reader, ['op' => 'find'])['sql']);
        self::assertSame(1, $this->request($writer, ['op' => 'bulk_delete'])['affected']);
        self::assertNull($this->request($reader, ['op' => 'find'])['name']);
    }

    public function testTwoWritersPreserveOptimisticVersionChecks(): void
    {
        $first = $this->worker(); $second = $this->worker();
        $this->request($first, ['op' => 'find']); $this->request($second, ['op' => 'find']);
        $this->request($first, ['op' => 'update', 'name' => 'winner']);
        $this->send($second, ['op' => 'update', 'name' => 'stale']);
        $error = $this->receive($second);
        self::assertSame(OptimisticLockException::class, $error['error']); self::assertFalse($error['open']);
        $fresh = $this->request($first, ['op' => 'find']);
        self::assertSame('winner', $fresh['name']); self::assertSame(2, $fresh['version']);
    }

    public function testPostCommitCacheFailureRequiresExplicitRecoveryWithoutReplayingSql(): void
    {
        $reader = $this->worker(); $writer = $this->worker();
        $this->request($reader, ['op' => 'find']);
        $this->send($writer, ['op' => 'update', 'name' => 'committed', 'pause' => true, 'fail_after_commit' => true]);
        self::assertSame(['event' => 'paused'], $this->receive($writer));
        self::assertSame('original', $this->request($reader, ['op' => 'find'])['name']);
        $this->send($writer, ['continue' => true]);
        $error = $this->receive($writer);
        self::assertSame(CacheSynchronizationException::class, $error['error']);
        self::assertTrue($error['committed']); self::assertFalse($error['open']);
        self::assertSame('committed', $this->db->fetchOne('SELECT name FROM '.$this->schema.'.record WHERE id = 1'));
        // The retained precommit fill demonstrates why reopening Redis alone is not recovery.
        self::assertSame('original', $this->request($reader, ['op' => 'find'])['name']);
        $this->request($reader, ['op' => 'evict']);
        $fresh = $this->request($reader, ['op' => 'find']);
        self::assertSame('committed', $fresh['name']); self::assertSame(2, $fresh['version']);
    }

    public function testCacheFailureBeforeWriteLeavesDatabaseUnchanged(): void
    {
        $reader = $this->worker(); $writer = $this->worker();
        $this->request($reader, ['op' => 'find']);
        $this->send($writer, ['op' => 'update', 'name' => 'rejected', 'fail_before_write' => true]);
        $error = $this->receive($writer);
        self::assertSame(\ModernORM\Exception\CacheException::class, $error['error']);
        self::assertFalse($error['open']);
        self::assertSame('original', $this->db->fetchOne('SELECT name FROM '.$this->schema.'.record WHERE id = 1'));
        self::assertSame('original', $this->request($reader, ['op' => 'find'])['name']);
    }

    public function testRepeatedUpdatesRemainFreshForLongLivedReader(): void
    {
        $reader = $this->worker(); $writer = $this->worker();
        $cycles = getenv('MODERNORM_L2_STRESS_CYCLES') ?: '25';
        if (!ctype_digit($cycles) || (int) $cycles < 1 || (int) $cycles > 10000) {
            self::fail('MODERNORM_L2_STRESS_CYCLES must be between 1 and 10000.');
        }
        for ($i = 1; $i <= (int) $cycles; ++$i) {
            $this->request($writer, ['op' => 'update', 'name' => 'value_'.$i]);
            $fresh = $this->request($reader, ['op' => 'find']);
            self::assertSame('value_'.$i, $fresh['name']);
            self::assertSame($i + 1, $fresh['version']);
            self::assertSame(1, $fresh['sql']);
            self::assertSame(0, $this->request($reader, ['op' => 'find'])['sql']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('metadataKinds')]
    public function testLostMetadataAndLatePublicationDoNotResurrectPayloads(int $index): void
    {
        $first = $this->worker(); $second = $this->worker();
        $ticket = $this->request($first, ['op' => 'ticket']);
        $keys = $ticket['metadata_keys'];
        self::assertCount(2, $keys);
        $this->request($first, ['op' => 'publish', 'ticket' => $ticket['ticket'], 'name' => 'old']);
        $this->request($second, ['op' => 'lose_metadata', 'keys' => [$keys[$index]]]);
        self::assertNull($this->request($second, ['op' => 'read_region'])['name']);
        $this->request($first, ['op' => 'publish', 'ticket' => $ticket['ticket'], 'name' => 'late']);
        self::assertNull($this->request($second, ['op' => 'read_region'])['name']);
        $fresh = $this->request($second, ['op' => 'ticket']);
        $this->request($second, ['op' => 'publish', 'ticket' => $fresh['ticket'], 'name' => 'fresh']);
        self::assertSame('fresh', $this->request($first, ['op' => 'read_region'])['name']);
    }

    public static function metadataKinds(): iterable
    {
        yield 'region' => [0];
        yield 'entry' => [1];
    }

    public function testConcurrentColdGenerationInitializationOnlyCausesSafeMisses(): void
    {
        $first = $this->worker(); $second = $this->worker();
        $this->send($first, ['op' => 'ticket', 'pause' => true]);
        self::assertSame(['event' => 'paused'], $this->receive($first));
        $old = $this->request($second, ['op' => 'ticket']);
        $this->request($second, ['op' => 'publish', 'ticket' => $old['ticket'], 'name' => 'old']);
        $this->request($second, ['op' => 'evict_region']);
        $this->send($first, ['continue' => true]);
        $fresh = $this->receive($first);
        self::assertArrayNotHasKey('error', $fresh);
        self::assertNull($this->request($first, ['op' => 'read_region'])['name']);
        $this->request($first, ['op' => 'publish', 'ticket' => $fresh['ticket'], 'name' => 'fresh']);
        self::assertSame('fresh', $this->request($second, ['op' => 'read_region'])['name']);
    }
}
