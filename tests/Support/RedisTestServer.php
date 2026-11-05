<?php

declare(strict_types=1);

namespace ModernORM\Tests\Support;

/** A disposable standalone Redis process; never controls a pre-existing service. */
final class RedisTestServer
{
    public readonly string $directory;
    public readonly int $port;
    private mixed $process = null;
    private bool $paused = false;

    public function __construct(private readonly string $binary)
    {
        $this->directory = sys_get_temp_dir().'/modernorm_redis_server_'.bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) { throw new \RuntimeException('Cannot create Redis test directory.'); }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        if ($socket === false) { throw new \RuntimeException('Cannot allocate test port: '.$error); }
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
    }

    public function dsn(): string { return 'redis://127.0.0.1:'.$this->port; }

    public function startIfStopped(): void
    {
        if (!is_resource($this->process)) { $this->start(); }
        $this->resume();
    }

    public function pause(): void
    {
        if (!is_resource($this->process) || !proc_terminate($this->process, SIGSTOP)) {
            throw new \RuntimeException('Cannot pause disposable Redis.');
        }
        $this->paused = true;
    }

    public function resume(): void
    {
        if ($this->paused) {
            if (!proc_terminate($this->process, SIGCONT)) { throw new \RuntimeException('Cannot resume disposable Redis.'); }
            $this->paused = false;
        }
    }

    public function start(): void
    {
        if (is_resource($this->process)) { throw new \LogicException('Test Redis is already started.'); }
        $this->process = proc_open([
            $this->binary, '--bind', '127.0.0.1', '--port', (string) $this->port,
            '--save', '', '--appendonly', 'no', '--daemonize', 'no', '--dir', $this->directory,
            '--dbfilename', 'dump.rdb', '--logfile', $this->directory.'/redis.log',
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory.'/stdout.log', 'a'], 2 => ['file', $this->directory.'/stderr.log', 'a']], $pipes);
        if (!is_resource($this->process)) { throw new \RuntimeException('Cannot start test Redis.'); }
        $deadline = microtime(true) + 5;
        do {
            if (!proc_get_status($this->process)['running']) { break; }
            try {
                $client = new \Redis();
                if (@$client->connect('127.0.0.1', $this->port, 0.1) && $client->ping()) {
                    $client->close(); return;
                }
            } catch (\RedisException) { /* Wait only during bounded process startup. */ }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Test Redis failed to start: '.@file_get_contents($this->directory.'/redis.log'));
    }

    public function snapshot(): void
    {
        $client = new \Redis();
        $client->connect('127.0.0.1', $this->port, 1);
        try {
            if (!$client->save()) { throw new \RuntimeException('Cannot save test Redis snapshot.'); }
        } finally { $client->close(); }
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) { return; }
        // SIGKILL deliberately drops active sockets and prevents an implicit shutdown save.
        if (proc_get_status($this->process)['running']) { proc_terminate($this->process, 9); }
        proc_close($this->process);
        $this->process = null;
        $this->paused = false;
    }

    public function cleanup(): void
    {
        $this->stop();
        foreach (glob($this->directory.'/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }
}
