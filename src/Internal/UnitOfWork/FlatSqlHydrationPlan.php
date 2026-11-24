<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\FieldMapping;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\RawValuePropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor;
use ReflectionNamedType;

/**
 * @internal One query-local plan for normalized, reference-free scalar SQL rows.
 * Built-in SQL hydrators copy each converted column into a fresh entity-data array.
 * Arbitrary arrays, existing entities and special mappings retain EntityHydrator.
 */
final readonly class FlatSqlHydrationPlan
{
    public const HINT = 'modernorm.internal.flat_sql_hydration_plan';

    /**
     * @param array<string, PropertyAccessor> $accessors
     * @param array<string, FieldMapping> $mappings
     * @param array<string, true> $required
     */
    private function __construct(
        private ClassMetadata $metadata,
        private array $accessors,
        private array $mappings,
        private array $required,
    ) {
    }

    public static function available(): bool
    {
        static $available = extension_loaded('deepclone')
            && version_compare(phpversion('deepclone'), '0.8.13', '>=')
            && function_exists('deepclone_hydrate')
            && defined('DEEPCLONE_HYDRATE_NO_LAZY_INIT');
        return $available;
    }

    /** @param list<string> $fields */
    public static function compile(ClassMetadata $metadata, array $fields): ?self
    {
        if (! self::available() || $metadata->embeddedClasses !== [] || $metadata->associationMappings !== []
            || $metadata->reflClass === null || $metadata->reflClass->getParentClass() !== false
            || count($fields) !== count($metadata->fieldMappings) || count(array_unique($fields)) !== count($fields)
        ) {
            return null;
        }
        $required = [];
        foreach ($fields as $field) {
            if (! isset($metadata->fieldMappings[$field])) {
                return null;
            }
            $accessor = $metadata->propertyAccessors[$field];
            if ($accessor::class !== RawValuePropertyAccessor::class && $accessor::class !== TypedNoDefaultPropertyAccessor::class) {
                return null;
            }
            $property = $accessor->getUnderlyingReflector();
            $type = $property->getType();
            if ($property->getName() !== $field || $property->isReadOnly() || $property->isVirtual()
                || ! $type instanceof ReflectionNamedType || ! $type->isBuiltin()
                || ! in_array($type->getName(), ['int', 'float', 'string', 'bool'], true)
            ) {
                return null;
            }
            if (! $type->allowsNull()) {
                $required[$field] = true;
            }
        }
        return new self($metadata, $metadata->propertyAccessors, $metadata->fieldMappings, $required);
    }

    /**
     * Called only for a new entity after its normal UnitOfWork registration.
     * The SQL producer guarantees the compiled field shape and reference-free elements.
     * @param array<string, mixed> $data
     */
    public function tryHydrate(ClassMetadata $metadata, object $entity, array $data): bool
    {
        if ($metadata !== $this->metadata || $entity::class !== $metadata->name
            || $metadata->propertyAccessors !== $this->accessors || $metadata->fieldMappings !== $this->mappings
            || count($data) !== count($this->mappings) || $metadata->reflClass->isUninitializedLazyObject($entity)
        ) {
            return false;
        }
        foreach ($data as $field => $value) {
            // Preserve null-to-uninitialized/hooked-field behavior and user-defined coercions.
            if (is_object($value) || ($value === null && isset($this->required[$field]))) {
                return false;
            }
        }
        // Own private/protected property names are accepted without mangling; no row rekeying.
        \deepclone_hydrate($entity, $data, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
        return true;
    }
}
