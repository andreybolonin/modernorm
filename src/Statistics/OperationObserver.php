<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

/** Optional synchronous export boundary. Core stores no event history. */
interface OperationObserver
{
    public function completed(OperationEvent $event): void;
}
