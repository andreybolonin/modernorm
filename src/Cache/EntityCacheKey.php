<?php

declare(strict_types=1);

namespace ModernORM\Cache;

use function ksort;
use function strtolower;

/**
 * @internal
 * Deterministic identifier encoding with explicit class and field boundaries.
 */
final class EntityCacheKey
{
    /**
     * The entity identifier
     *
     * @var array<string, mixed>
     */
    public readonly array $identifier;
    public readonly string $hash;

    /**
     * @param class-string         $entityClass The mapped concrete entity class.
     * @param array<string, mixed> $identifier  The entity identifier
     */
    public function __construct(
        public readonly string $entityClass,
        array $identifier,
    ) {
        ksort($identifier);

        $this->identifier = $identifier;

        $values = array_map(static fn ($value): string => (string) ($value instanceof \BackedEnum ? $value->value : $value), $identifier);
        $this->hash = 'entity_v2_' . hash('sha256', serialize([strtolower($entityClass), $values]));
    }
}
