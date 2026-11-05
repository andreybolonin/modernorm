<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use ModernORM\Statistics;

/** Install in DBAL Configuration before DriverManager::getConnection(). */
final class Middleware implements DriverMiddleware
{
    public function __construct(private readonly Statistics $statistics)
    {
    }

    public function getStatistics(): Statistics
    {
        return $this->statistics;
    }

    public function wrap(Driver $driver): Driver
    {
        $this->statistics->bindDriver();
        return new StatisticsDriver($driver, $this->statistics);
    }
}
