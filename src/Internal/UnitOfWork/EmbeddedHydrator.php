<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Generator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\PropertyAccessors\EmbeddablePropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\ReadonlyAccessor;
use ReflectionClass;
use ReflectionReference;
use WeakMap;

/**
 * @internal Batches contiguous writable embedded fields without reordering other assignments.
 * @phpstan-type BatchField array{group: string, key: string, required: bool, accessor: EmbeddablePropertyAccessor, reflection: ReflectionClass<object>}
 */
final class EmbeddedHydrator
{
    // Eight fields were measured to benefit; one-field batches were slower than accessors.
    public const MIN_BATCH_SIZE = 8;

    /** @return array<string, BatchField> */
    public static function plan(ClassMetadata $metadata): array
    {
        /** @var WeakMap<ClassMetadata, array{accessors: array<string, PropertyAccessor>, fields: array<string, BatchField>}> $plans */
        static $plans = new WeakMap();
        $cached = $plans[$metadata] ?? null;
        if ($cached !== null && $cached['accessors'] === $metadata->propertyAccessors) {
            return $cached['fields'];
        }
        $fields = $counts = $reflections = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $accessor = $metadata->propertyAccessors[$field];
            if ($accessor::class !== EmbeddablePropertyAccessor::class || $mapping->declaredField === null) {
                continue;
            }
            $group = $mapping->declaredField;
            $property = $accessor->getBatchProperty();
            if ($property === null) {
                continue;
            }
            $reflections[$group] ??= new ReflectionClass($metadata->embeddedClasses[$group]->class);
            $fields[$field] = ['group' => $group,
                'key' => $property->isPrivate() ? "\0" . $property->getDeclaringClass()->getName() . "\0" . $property->getName()
                    : ($property->isProtected() ? "\0*\0" . $property->getName() : $property->getName()),
                'required' => ! $property->getType()->allowsNull(), 'accessor' => $accessor, 'reflection' => $reflections[$group]];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        foreach ($fields as $field => $entry) {
            if ($counts[$entry['group']] < self::MIN_BATCH_SIZE) {
                unset($fields[$field]);
            }
        }
        $plans[$metadata] = ['accessors' => $metadata->propertyAccessors, 'fields' => $fields];
        return $fields;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, BatchField> $plan
     * @return Generator<string, mixed>
     */
    public static function hydrate(ClassMetadata $metadata, object $entity, array $data, array $plan): Generator
    {
        $pending = $vars = [];
        $group = null;
        foreach ($data as $field => $value) {
            if (! isset($metadata->fieldMappings[$field])) {
                continue;
            }
            $entry = $plan[$field] ?? null;
            // Objects may execute user code during scalar coercion; null may unset a field.
            $batchable = $entry !== null && ! is_object($value) && ! ($value === null && $entry['required'])
                && ReflectionReference::fromArrayElement($data, $field) === null;
            if ($pending !== [] && (! $batchable || $group !== $entry['group'])) {
                self::flush($entity, $pending, $vars, $plan);
                $pending = $vars = [];
            }
            if ($batchable) {
                $pending[$field] = $value;
                $vars[$entry['key']] = $value;
                $group = $entry['group'];
                continue;
            }
            $accessor = $metadata->propertyAccessors[$field];
            // Flushing earlier fields may have changed an input reference to a child property.
            $value = $data[$field];
            $accessor->setValue($entity, $value);
            if ($accessor instanceof ReadonlyAccessor) {
                yield $field => $accessor->getValue($entity);
            }
        }
        if ($pending !== []) {
            self::flush($entity, $pending, $vars, $plan);
        }
    }

    /**
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $vars
     * @param array<string, BatchField> $plan
     */
    private static function flush(object $entity, array $pending, array $vars, array $plan): void
    {
        if (count($pending) >= self::MIN_BATCH_SIZE) {
            $first = $plan[array_key_first($pending)];
            $target = $first['accessor']->getOrCreateEmbeddedObject($entity);
            if ($target::class === $first['reflection']->getName() && ! $first['reflection']->isUninitializedLazyObject($target)) {
                \deepclone_hydrate($target, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
                return;
            }
        }
        foreach ($pending as $field => $value) {
            $plan[$field]['accessor']->setValue($entity, $value);
        }
    }
}
