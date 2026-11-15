<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use ModernORM\Mapping\ClassMetadata;

use function array_key_exists;

/** @internal Compares prepared scalar/embedded snapshots without reading entities or changing ORM state. */
final class ChangeSetComputer
{
    /**
     * Compare an existing entity, excluding fields forbidden in UPDATE statements.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $actual
     * @return array<string, array{mixed, mixed}>
     */
    public static function compare(ClassMetadata $metadata, array $original, array $actual): array
    {
        $changes = self::compareSnapshots($metadata, $original, $actual);
        foreach ($changes as $field => $change) {
            if (isset($metadata->fieldMappings[$field]->notUpdatable)) {
                unset($changes[$field]);
            }
        }
        return $changes;
    }

    /**
     * Recompare a prepared INSERT, retaining insert-only fields. Missing baselines are skipped.
     * Field-level equality and enum normalization remain owned by FieldChangeComparator.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $actual
     * @return array<string, array{mixed, mixed}>
     */
    public static function compareForInsert(ClassMetadata $metadata, array $original, array $actual): array
    {
        return self::compareSnapshots($metadata, $original, $actual);
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $actual
     * @return array<string, array{mixed, mixed}>
     */
    private static function compareSnapshots(ClassMetadata $metadata, array $original, array $actual): array
    {
        $changes = [];
        foreach ($actual as $field => $value) {
            if (isset($metadata->associationMappings[$field]) || ! array_key_exists($field, $original)) {
                continue;
            }
            $change = FieldChangeComparator::compare($original[$field], $value, ! empty($metadata->fieldMappings[$field]->enumType));
            if ($change !== null) {
                $changes[$field] = $change;
            }
        }
        return $changes;
    }
}
