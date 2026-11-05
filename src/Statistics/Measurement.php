<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

/** @internal A monotonic timestamp tied to a collector generation. */
final readonly class Measurement
{
    public function __construct(public int $generation, public int $startedAt, public ?int $startedAtUnixNanoseconds = null) {}
}
