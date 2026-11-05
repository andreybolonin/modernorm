<?php

declare(strict_types=1);

namespace ModernORM\Utility;

use BackedEnum;
use ModernORM\Mapping\ClassMetadata;

/** Normalizes the single scalar identifier for hydration and identity-map lookups. */
final class IdentifierFlattener
{
    public function flattenIdentifier(ClassMetadata $class, array $id): array
    {
        $class->assertSupportedIdentifier();
        $field = $class->getSingleIdentifierFieldName();
        $value = $id[$field] ?? null;
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if ($value !== null && ! is_scalar($value)) {
            throw new \InvalidArgumentException('An entity identifier must be scalar.');
        }

        return [$field => $value];
    }
}
