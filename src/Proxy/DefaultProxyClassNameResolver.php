<?php

declare(strict_types=1);

namespace ModernORM\Proxy;

use Doctrine\Persistence\Mapping\ProxyClassNameResolver;

/** Native lazy objects retain the entity class name. */
final class DefaultProxyClassNameResolver implements ProxyClassNameResolver
{
    public function resolveClassName(string $className): string
    {
        return $className;
    }

    /** @return class-string */
    public static function getClass(object $object): string
    {
        return $object::class;
    }
}
