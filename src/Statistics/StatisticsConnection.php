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
            return new StatisticsStatement(parent::prepare($sql), $this->statistics, DmlOperation::fromSql($sql));
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
        $operation = DmlOperation::fromSql($sql);
        $rows = null;
        $failed = true;
        try {
            $result = parent::query($sql);
            $failed = false;
            if ($operation !== null) {
                $rows = DmlDetails::rows($result);
            }
            return $result;
        } finally {
            $this->statistics->recordSql($measurement, $failed, $operation, $rows);
        }
    }

    public function exec(string $sql): int|string
    {
        $measurement = $this->statistics->start();
        if ($measurement === null) {
            return parent::exec($sql);
        }
        $operation = DmlOperation::fromSql($sql);
        $rows = null;
        $failed = true;
        try {
            $result = parent::exec($sql);
            $failed = false;
            if ($operation !== null) {
                $rows = DmlDetails::rows($result);
            }
            return $result;
        } finally {
            $this->statistics->recordSql($measurement, $failed, $operation, $rows);
        }
    }
}
