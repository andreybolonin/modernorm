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
    public function __construct(Statement $statement, private readonly Statistics $statistics, private readonly ?DmlOperation $operation = null)
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
        $rows = null;
        try {
            $result = parent::execute();
            $failed = false;
            if ($this->operation !== null) {
                $rows = DmlDetails::rows($result);
            }
            return $result;
        } finally {
            $this->statistics->recordSql($measurement, $failed, $this->operation, $rows);
        }
    }
}
