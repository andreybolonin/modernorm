<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Behavior;

use ModernORM\Cache\CacheWritePolicy;
use ModernORM\EntityManagerInterface;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping;
use ModernORM\Mapping\ClassMetadata;
use ReflectionClass;

/** @internal Bounded built-in policy, not a general-purpose ORM filter API. */
final class SoftDeletePolicy
{
    public static function validate(ClassMetadata $class): void
    {
        if ($class->softDeleteField !== null) {
            $field = $class->fieldMappings[$class->softDeleteField] ?? null;
            if ($class->isReadOnly || $class->isMappedSuperclass || $class->isEmbeddedClass) {
                throw new MappingException('SoftDelete requires a mutable concrete Entity: ' . $class->name);
            }
            if ($field === null || $field->type !== 'datetime_immutable' || ! $field->nullable
                || ! empty($field->notUpdatable) || ! empty($field->notInsertable) || ! empty($field->generated)
                || $class->getReflectionClass()->getProperty($class->softDeleteField)->isReadOnly()
                || $class->isIdentifier($class->softDeleteField) || $class->versionField === $class->softDeleteField) {
                throw new MappingException('SoftDelete requires a nullable, writable datetime_immutable deletedAt field: ' . $class->name);
            }
            if ($class->cache !== null && $class->cache['writePolicy'] !== CacheWritePolicy::INVALIDATE) {
                throw new MappingException('SoftDelete L2 caching requires CacheWritePolicy::INVALIDATE: ' . $class->name);
            }
        }
        foreach ($class->associationMappings as $association) {
            $target = new ReflectionClass($association->targetEntity);
            $softTarget = $target->getAttributes(Mapping\SoftDelete::class) !== [];
            if (($class->softDeleteField !== null || $softTarget)
                && ($association->isCascadeRemove() || $association->orphanRemoval)) {
                throw new MappingException('SoftDelete does not support cascade remove or orphanRemoval: ' . $class->name . '::' . $association->fieldName);
            }
            if (! $softTarget) {
                continue;
            }
            if ($association->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
                throw new MappingException('SoftDelete targets do not support EXTRA_LAZY associations: ' . $class->name . '::' . $association->fieldName);
            }
            if ($association->isToOne()) {
                if ($association->isToOneOwningSide()) {
                    foreach ($association->joinColumns as $column) {
                        if ($column->nullable === false) {
                            throw new MappingException('SoftDelete targets require nullable to-one associations: ' . $class->name . '::' . $association->fieldName);
                        }
                    }
                }
                $type = $class->getReflectionClass()->getProperty($association->fieldName)->getType();
                if ($type !== null && ! $type->allowsNull()) {
                    throw new MappingException('SoftDelete targets require nullable PHP properties: ' . $class->name . '::' . $association->fieldName);
                }
                // A lazy object cannot turn into null when its initializer discovers a tombstone.
                $association->fetch = ClassMetadata::FETCH_EAGER;
                if ($association->isToOneOwningSide()) {
                    $class->softDeleteToOneAssociations[] = $association->fieldName;
                }
            }
        }
    }

    public static function activeCondition(EntityManagerInterface $em, ClassMetadata $class, string $alias = ''): ?string
    {
        return self::condition($em, $class, $alias, false);
    }

    public static function deletedCondition(EntityManagerInterface $em, ClassMetadata $class, string $alias = ''): ?string
    {
        return self::condition($em, $class, $alias, true);
    }

    private static function condition(EntityManagerInterface $em, ClassMetadata $class, string $alias, bool $deleted): ?string
    {
        if ($class->softDeleteField === null) {
            return null;
        }
        $column = $em->getConfiguration()->getQuoteStrategy()->getColumnName($class->softDeleteField, $class, $em->getConnection()->getDatabasePlatform());

        return ($alias === '' ? '' : $alias . '.') . $column . ($deleted ? ' IS NOT NULL' : ' IS NULL');
    }
}
