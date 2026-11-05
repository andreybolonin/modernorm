<?php

declare(strict_types=1);

namespace ModernORM\Tests\Support;

require_once __DIR__.'/RedisL2ProcessTestCase.php';
require_once __DIR__.'/RedisTestServer.php';

abstract class RedisL2DisposableTestCase extends RedisL2ProcessTestCase
{
    protected ?RedisTestServer $server = null;
    private string|false $previousDsn = false;

    protected function setUp(): void
    {
        $binary = getenv('MODERNORM_TEST_REDIS_SERVER');
        if (!$binary) {
            self::markTestSkipped('Set MODERNORM_TEST_REDIS_SERVER to run disposable Redis recovery tests.');
        }
        if (!extension_loaded('redis')) {
            self::fail('Redis recovery tests require ext-redis.');
        }
        if (!defined('SIGSTOP') || !defined('SIGCONT')) {
            self::fail('Redis recovery tests require POSIX signal constants (ext-pcntl).');
        }
        $this->previousDsn = getenv('MODERNORM_TEST_REDIS_DSN');
        $this->server = new RedisTestServer($binary);
        $this->server->start();
        putenv('MODERNORM_TEST_REDIS_DSN='.$this->server->dsn());
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->server === null) {
            return;
        }
        try {
            // Parent cleanup uses Redis, including when an assertion failed during an outage.
            $this->server->startIfStopped();
            parent::tearDown();
        } finally {
            $this->server->cleanup();
            putenv($this->previousDsn === false ? 'MODERNORM_TEST_REDIS_DSN' : 'MODERNORM_TEST_REDIS_DSN='.$this->previousDsn);
        }
    }
}
