<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use ModernORM\Statistics;
use Throwable;

/** @internal Transaction driver API calls are deliberately not SQL execution counters. */
final class StatisticsConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly Statistics $statistics)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $measurement = $this->statistics->start();
        try {
            return new StatisticsStatement(parent::prepare($sql), $this->statistics);
        } catch (Throwable $exception) {
            // Successful prepare is not execution; failed prepare is one failed SQL attempt.
            if ($measurement !== null) {
                $this->statistics->recordSql($measurement, true);
            }
            throw $exception;
        }
    }

    public function query(string $sql): Result
    {
        $measurement = $this->statistics->start();
        if ($measurement === null) {
            return parent::query($sql);
        }
        $failed = true;
        try {
            $result = parent::query($sql);
            $failed = false;
            return $result;
        } finally {
            $this->statistics->recordSql($measurement, $failed);
        }
    }

    public function exec(string $sql): int|string
    {
        $measurement = $this->statistics->start();
        if ($measurement === null) {
            return parent::exec($sql);
        }
        $failed = true;
        try {
            $result = parent::exec($sql);
            $failed = false;
            return $result;
        } finally {
            $this->statistics->recordSql($measurement, $failed);
        }
    }
}
