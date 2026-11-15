<?php

declare(strict_types=1);

namespace ModernORM\Mapping\PropertyAccessors;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use ReflectionProperty;

use function sprintf;

/** @internal */
class ReadonlyAccessor implements PropertyAccessor
{
    public function __construct(private PropertyAccessor $parent, private ReflectionProperty $reflectionProperty)
    {
        if (! $this->reflectionProperty->isReadOnly()) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s must be readonly property',
                $this->reflectionProperty->getDeclaringClass()->getName(),
                $this->reflectionProperty->getName(),
            ));
        }
    }

    public function setValue(object $object, mixed $value): void
    {
        /* For lazy properties, skip the isInitialized() check
           because it would trigger the initialization of the whole object. */
        if (
            $this->reflectionProperty->isLazy($object)
            || ! $this->reflectionProperty->isInitialized($object)
        ) {
            $this->parent->setValue($object, $value);

            return;
        }

        $current = $this->parent->getValue($object);
        if ($current instanceof DateTimeInterface && $value instanceof DateTimeInterface && $current == $value) {
            return;
        }

        if ($current !== $value) {
            throw new LogicException(sprintf(
                'Attempting to change readonly property %s::$%s.',
                $this->reflectionProperty->getDeclaringClass()->getName(),
                $this->reflectionProperty->getName(),
            ));
        }
    }

    public function getValue(object $object): mixed
    {
        return $this->parent->getValue($object);
    }

    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->reflectionProperty;
    }
}
