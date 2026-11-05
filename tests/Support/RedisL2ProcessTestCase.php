<?php

declare(strict_types=1);

namespace ModernORM\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;

require_once __DIR__.'/RedisL2Worker.php';

abstract class RedisL2ProcessTestCase extends TestCase
{
    protected Connection $db;
    protected string $schema;
    protected string $namespace;
    protected array $workers = [];

    protected function setUp(): void
    {
        if (!getenv('MODERNORM_TEST_REDIS_DSN')) {
            self::markTestSkipped('Set MODERNORM_TEST_REDIS_DSN for Redis process tests.');
        }
        if (!extension_loaded('redis') || !function_exists('proc_open')) {
            self::fail('Redis process tests require ext-redis and proc_open.');
        }
        $this->schema = 'modernorm_redis_'.bin2hex(random_bytes(6));
        $this->namespace = $this->schema;
        $this->db = \ModernORM\Tests\Support\redisL2Connection();
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('CREATE TABLE '.$this->schema.'.record (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL, version INTEGER DEFAULT 1 NOT NULL)');
        $this->db->executeStatement('INSERT INTO '.$this->schema.'.record VALUES (1, ?, 1)', ['original']);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $pipes, $stderr]) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            fclose($stderr);
        }
        $this->workers = [];
        if (isset($this->db, $this->schema)) {
            try {
                // clear() applies only to this test's random adapter namespace, never FLUSHDB.
                (new RedisAdapter(RedisAdapter::createConnection(getenv('MODERNORM_TEST_REDIS_DSN')), $this->namespace))->clear();
            } finally {
                try {
                    $this->db->executeStatement('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE');
                } finally {
                    $this->db->close();
                }
            }
        }
    }

    protected function worker(?string $namespace = null, int $ttl = 60): int
    {
        $stderr = tmpfile();
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/RedisL2Worker.php', json_encode([$this->schema, $namespace ?? $this->namespace, $ttl], JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr],
            $pipes
        );
        self::assertIsResource($process);
        $id = count($this->workers);
        $this->workers[$id] = [$process, $pipes, $stderr];
        self::assertSame(['ready' => true], $this->receive($id));
        return $id;
    }

    protected function send(int $worker, array $request): void
    {
        $line = json_encode($request, JSON_THROW_ON_ERROR)."\n";
        self::assertSame(strlen($line), fwrite($this->workers[$worker][1][0], $line));
        fflush($this->workers[$worker][1][0]);
    }

    protected function receive(int $worker): array
    {
        $read = [$this->workers[$worker][1][1]];
        $write = $except = [];
        if (stream_select($read, $write, $except, 15) !== 1 || ($line = fgets($read[0])) === false) {
            rewind($this->workers[$worker][2]);
            self::fail('Redis worker timeout/exit: '.stream_get_contents($this->workers[$worker][2]));
        }
        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function request(int $worker, array $request): array
    {
        $this->send($worker, $request);
        $response = $this->receive($worker);
        self::assertArrayNotHasKey('error', $response, json_encode($response));
        return $response;
    }
}
