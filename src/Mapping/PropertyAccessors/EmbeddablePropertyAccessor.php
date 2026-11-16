<?php

declare(strict_types=1);

namespace ModernORM\Mapping\PropertyAccessors;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/** @internal */
class EmbeddablePropertyAccessor implements PropertyAccessor
{
    /** @var ReflectionClass<object>|null */
    private ?ReflectionClass $embeddedReflection = null;

    public function __construct(
        private PropertyAccessor $parent,
        private PropertyAccessor $child,
        /** @var class-string */
        private string $embeddedClass,
    ) {
    }

    public function setValue(object $object, mixed $value): void
    {
        $embeddedObject = $this->parent->getValue($object);

        if ($embeddedObject === null) {
            $embeddedObject = $this->createEmbeddedObject($object);
        }

        $this->child->setValue($embeddedObject, $value);
    }

    /** Native batches only support ordinary writable scalar children and direct parents. */
    public function getBatchProperty(): ?ReflectionProperty
    {
        foreach ([$this->parent, $this->child] as $accessor) {
            if ($accessor::class !== RawValuePropertyAccessor::class && $accessor::class !== TypedNoDefaultPropertyAccessor::class) {
                return null;
            }
        }
        $property = $this->child->getUnderlyingReflector();
        $type = $property->getType();
        if ($property->isReadOnly() || $property->isVirtual() || ! $type instanceof ReflectionNamedType
            || ! $type->isBuiltin() || ! in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)
        ) {
            return null;
        }
        return $property;
    }

    /** Attach a missing embedded before child writes, preserving partial-failure behavior. */
    public function getOrCreateEmbeddedObject(object $object): object
    {
        return $this->parent->getValue($object) ?? $this->createEmbeddedObject($object);
    }

    private function createEmbeddedObject(object $object): object
    {
        $this->embeddedReflection ??= new ReflectionClass($this->embeddedClass);
        $embeddedObject = $this->embeddedReflection->newInstanceWithoutConstructor();
        $this->parent->setValue($object, $embeddedObject);
        return $embeddedObject;
    }

    public function getValue(object $object): mixed
    {
        $embeddedObject = $this->parent->getValue($object);

        if ($embeddedObject === null) {
            return null;
        }

        return $this->child->getValue($embeddedObject);
    }

    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->child->getUnderlyingReflector();
    }
}
