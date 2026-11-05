<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use ModernORM\Statistics;

/** @internal No additional SQL strings, parameter values or result rows are retained. */
final class StatisticsStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly Statistics $statistics)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $measurement = $this->statistics->start();
        if ($measurement === null) {
            return parent::execute();
        }
        $failed = true;
        try {
            $result = parent::execute();
            $failed = false;
            return $result;
        } finally {
            $this->statistics->recordSql($measurement, $failed);
        }
    }
}
