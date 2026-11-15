<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Generator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\PropertyAccessors\ReadonlyAccessor;

/** @internal Assigns prepared scalar/embedded values without managing entity state or associations. */
final class EntityHydrator
{
    /**
     * Consume the returned generator to hydrate fields in input order.
     * Yield retained readonly values so the coordinator can update its snapshot immediately,
     * including when a later field assignment fails.
     *
     * @param array<string, mixed> $data
     * @return Generator<string, mixed>
     */
    public static function hydrateScalars(ClassMetadata $metadata, object $entity, array $data): Generator
    {
        foreach ($data as $field => $value) {
            if (! isset($metadata->fieldMappings[$field])) {
                continue;
            }
            $accessor = $metadata->propertyAccessors[$field];
            $accessor->setValue($entity, $value);
            if ($accessor instanceof ReadonlyAccessor) {
                yield $field => $accessor->getValue($entity);
            }
        }
    }
}
