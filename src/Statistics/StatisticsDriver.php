<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use ModernORM\Statistics;
use SensitiveParameter;

/** @internal */
final class StatisticsDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly Statistics $statistics)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new StatisticsConnection(parent::connect($params), $this->statistics);
    }
}
