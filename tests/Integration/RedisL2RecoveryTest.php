<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use ModernORM\Exception\CacheException;
use ModernORM\Exception\CacheSynchronizationException;
use ModernORM\Tests\Support\RedisL2DisposableTestCase;

require_once dirname(__DIR__).'/Support/RedisL2DisposableTestCase.php';

final class RedisL2RecoveryTest extends RedisL2DisposableTestCase
{
    public function testReadDuringRealOutageFailsAndEmptyRestartRefillsFromSql(): void
    {
        $reader = $this->worker();
        $this->request($reader, ['op' => 'find']);
        self::assertSame(0, $this->request($reader, ['op' => 'find'])['sql']);
        $this->server->stop();
        $this->send($reader, ['op' => 'find']);
        $failure = $this->receive($reader);
        self::assertSame(CacheException::class, $failure['error']);
        $this->db->executeStatement('UPDATE '.$this->schema.'.record SET name = ?, version = 2', ['during-outage']);
        $this->server->start();
        // Explicit recovery creates a fresh client/manager; no hidden SQL write retry.
        $recovered = $this->worker();
        $fresh = $this->request($recovered, ['op' => 'find']);
        self::assertSame('during-outage', $fresh['name']);
        self::assertSame(1, $fresh['sql']);
        self::assertSame(0, $this->request($recovered, ['op' => 'find'])['sql']);
    }

    public function testRealOutageBeforeUpdateRollsBackWithoutWriting(): void
    {
        $writer = $this->worker();
        $this->request($writer, ['op' => 'find']);
        $this->server->stop();
        $this->send($writer, ['op' => 'update', 'name' => 'rejected']);
        $failure = $this->receive($writer);
        self::assertSame(CacheException::class, $failure['error']);
        self::assertFalse($failure['open']);
        self::assertSame('original', $this->db->fetchOne('SELECT name FROM '.$this->schema.'.record WHERE id = 1'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT version FROM '.$this->schema.'.record WHERE id = 1'));
    }

    public function testUnresponsiveServerUsesConfiguredReadTimeoutAndCanBeRecovered(): void
    {
        $reader = $this->worker();
        $this->request($reader, ['op' => 'find']);
        $this->server->pause();
        $this->send($reader, ['op' => 'find']);
        // Redis retains its TCP sockets but stops answering. The worker uses finite read timeouts.
        self::assertSame(CacheException::class, $this->receive($reader)['error']);
        $this->server->resume();
        $recovered = $this->worker();
        self::assertSame('original', $this->request($recovered, ['op' => 'find'])['name']);
    }

    public function testLostPostCommitInvalidationAndSnapshotRestoreRequireNewNamespace(): void
    {
        $reader = $this->worker();
        $writer = $this->worker();
        $this->request($reader, ['op' => 'find']);
        $this->send($writer, ['op' => 'update', 'name' => 'committed', 'pause' => true]);
        self::assertSame(['event' => 'paused'], $this->receive($writer));
        self::assertSame('original', $this->request($reader, ['op' => 'find'])['name']);
        $this->server->snapshot();
        $this->server->stop();
        $this->send($writer, ['continue' => true]);
        $failure = $this->receive($writer);
        self::assertSame(CacheSynchronizationException::class, $failure['error']);
        self::assertTrue($failure['committed']);
        self::assertFalse($failure['open']);
        self::assertSame('committed', $this->db->fetchOne('SELECT name FROM '.$this->schema.'.record WHERE id = 1'));
        $this->server->start();
        $restored = $this->worker();
        $stale = $this->request($restored, ['op' => 'find']);
        self::assertSame('original', $stale['name']);
        self::assertSame(0, $stale['sql']);
        $recovered = $this->worker($this->namespace.'_recovered');
        $fresh = $this->request($recovered, ['op' => 'find']);
        self::assertSame('committed', $fresh['name']);
        self::assertSame(2, $fresh['version']);
        self::assertSame(1, $fresh['sql']);
        self::assertSame(0, $this->request($recovered, ['op' => 'find'])['sql']);
    }

    public function testRestoringAnOlderSnapshotAfterSuccessfulUpdateAlsoRequiresNewNamespace(): void
    {
        $reader = $this->worker();
        $writer = $this->worker();
        $this->request($reader, ['op' => 'find']);
        $this->server->snapshot();
        $this->request($writer, ['op' => 'update', 'name' => 'committed']);
        self::assertSame('committed', $this->request($reader, ['op' => 'find'])['name']);
        $this->server->stop();
        $this->server->start();
        $restored = $this->worker();
        self::assertSame('original', $this->request($restored, ['op' => 'find'])['name']);
        $fresh = $this->worker($this->namespace.'_recovered');
        self::assertSame('committed', $this->request($fresh, ['op' => 'find'])['name']);
    }

    public function testWriterDeathAfterPhysicalCommitLeavesCacheRequiringRecovery(): void
    {
        $reader = $this->worker();
        $writer = $this->worker();
        $this->request($reader, ['op' => 'find']);
        $this->send($writer, ['op' => 'update', 'name' => 'committed', 'pause' => true, 'pause_after_commit' => true]);
        self::assertSame(['event' => 'paused'], $this->receive($writer));
        self::assertSame('original', $this->request($reader, ['op' => 'find'])['name']);
        $this->send($writer, ['continue' => true]);
        self::assertSame(['event' => 'paused'], $this->receive($writer));
        self::assertSame('committed', $this->db->fetchOne('SELECT name FROM '.$this->schema.'.record WHERE id = 1'));
        self::assertTrue(proc_terminate($this->workers[$writer][0], 9));
        $stale = $this->request($reader, ['op' => 'find']);
        self::assertSame('original', $stale['name']);
        self::assertSame(0, $stale['sql']);
        $this->request($reader, ['op' => 'evict']);
        $fresh = $this->request($reader, ['op' => 'find']);
        self::assertSame('committed', $fresh['name']);
        self::assertSame(2, $fresh['version']);
        self::assertSame(0, $this->request($reader, ['op' => 'find'])['sql']);
    }
}
