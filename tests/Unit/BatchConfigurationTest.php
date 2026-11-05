<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BatchConfigurationTest extends TestCase
{
    public function testBatchFetchingIsOptIn(): void
    {
        $configuration = new Configuration();
        self::assertSame(1, $configuration->getLazyFetchBatchSize());
        $configuration->setLazyFetchBatchSize(32);
        self::assertSame(32, $configuration->getLazyFetchBatchSize());
    }

    public static function invalidSizes(): array { return [[0], [-1], [65536]]; }

    #[DataProvider('invalidSizes')]
    public function testBatchSizeRespectsParameterLimit(int $size): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Configuration())->setLazyFetchBatchSize($size);
    }
}
