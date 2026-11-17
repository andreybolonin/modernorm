<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\PropertyAccessors\RawValuePropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor;
use ReflectionNamedType;
use WeakMap;

/** @internal One read/compare pass for ordinary scalar entities; coordination remains in UnitOfWork. */
final readonly class ScalarChangeTrackingPlan
{
    /** @param array<string, string> $fields Field names to raw object-cast keys. */
    private function __construct(private array $fields)
    {
    }

    public static function forMetadata(ClassMetadata $metadata): ?self
    {
        /** @var WeakMap<ClassMetadata, array{array<mixed>, self|null}> $plans */
        static $plans = new WeakMap();
        $signature = [$metadata->propertyAccessors, $metadata->fieldMappings, $metadata->identifier,
            $metadata->generatorType, $metadata->versionField, $metadata->associationMappings,
            $metadata->embeddedClasses, $metadata->isReadOnly, $metadata->changeTrackingPolicy, $metadata->reflClass, $metadata->inheritanceType];
        $cached = $plans[$metadata] ?? null;
        if ($cached !== null && $cached[0] === $signature) {
            return $cached[1];
        }
        $plan = self::compile($metadata);
        $plans[$metadata] = [$signature, $plan];
        return $plan;
    }

    private static function compile(ClassMetadata $metadata): ?self
    {
        if (! $metadata->isInheritanceTypeNone() || $metadata->reflClass === null || $metadata->reflClass->getParentClass() !== false
            || $metadata->associationMappings !== [] || $metadata->embeddedClasses !== []
            || $metadata->isReadOnly || ! $metadata->isChangeTrackingDeferredImplicit()
        ) {
            return null;
        }
        $fields = [];
        foreach ($metadata->propertyAccessors as $field => $accessor) {
            if (! isset($metadata->fieldMappings[$field])
                || ($accessor::class !== RawValuePropertyAccessor::class && $accessor::class !== TypedNoDefaultPropertyAccessor::class)
            ) {
                return null;
            }
            if ($accessor instanceof TypedNoDefaultPropertyAccessor && ! $accessor->usesRawPropertyAccess()) {
                return null;
            }
            $property = $accessor->getUnderlyingReflector();
            $type = $property->getType();
            if ($property->getDeclaringClass()->getName() !== $metadata->name || $property->getName() !== $field || $property->hasHooks() || $property->isReadOnly()
                || ! $type instanceof ReflectionNamedType || ! $type->isBuiltin()
                || ! in_array($type->getName(), ['int', 'float', 'string', 'bool'], true)
            ) {
                return null;
            }
            if ((! $metadata->isIdentifier($field) || ! $metadata->isIdGeneratorIdentity()) && $field !== $metadata->versionField) {
                $fields[$field] = $property->isPrivate()
                    ? "\0" . $property->getDeclaringClass()->getName() . "\0" . $field
                    : ($property->isProtected() ? "\0*\0" . $field : $field);
            }
        }
        return new self($fields);
    }

    /**
     * null requests fallback; an empty result preserves any pending changeset.
     * Build actual data in metadata order, including missing-baseline/insert-only fields.
     * Hydrated snapshots can contain IDs/version/extra keys or use another field order;
     * copying the original array on first change would not preserve normal snapshot semantics.
     *
     * @param array<string, mixed> $original
     * @return array{}|array{array<string, array{mixed, mixed}>, array<string, mixed>}|null
     */
    public function compare(ClassMetadata $metadata, object $entity, array $original): ?array
    {
        if ($entity::class !== $metadata->name || $metadata->reflClass->isUninitializedLazyObject($entity)) {
            return null;
        }
        // The admitted accessors read these same raw keys. Cast once per entity,
        // without invoking hooks or binding snapshot values to property references.
        $values = (array) $entity;
        $actual = [];
        $changes = [];
        foreach ($this->fields as $field => $key) {
            $value = $values[$key] ?? null;
            $actual[$field] = $value;
            if (! array_key_exists($field, $original) || isset($metadata->fieldMappings[$field]->notUpdatable)) {
                continue;
            }
            $old = $original[$field];
            // Non-scalar baselines retain enum/custom comparison semantics in the compatibility path.
            if ($old !== null && ! is_scalar($old)) {
                return null;
            }
            if ($old !== $value) {
                $changes[$field] = [$old, $value];
            }
        }
        return $changes === [] ? [] : [$changes, $actual];
    }
}
