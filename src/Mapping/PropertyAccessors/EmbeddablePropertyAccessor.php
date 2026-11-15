<?php

declare(strict_types=1);

namespace ModernORM\Mapping\PropertyAccessors;

use ReflectionClass;
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
            $this->embeddedReflection ??= new ReflectionClass($this->embeddedClass);
            $embeddedObject = $this->embeddedReflection->newInstanceWithoutConstructor();

            $this->parent->setValue($object, $embeddedObject);
        }

        $this->child->setValue($embeddedObject, $value);
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
