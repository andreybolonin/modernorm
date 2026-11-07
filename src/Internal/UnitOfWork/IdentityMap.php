<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use ModernORM\Exception\EntityIdentityCollisionException;

use function array_map;
use function array_sum;

/** @internal Maps normalized class/identifier keys to canonical objects without loading or state tracking. */
final class IdentityMap
{
    /** @var array<class-string, array<string, object>> */
    private array $entities = [];

    /** @param class-string $class */
    public function add(string $class, string $idHash, object $entity): bool
    {
        $existing = $this->get($class, $idHash);
        if ($existing !== null) {
            if ($existing !== $entity) {
                throw EntityIdentityCollisionException::create($existing, $entity, $idHash);
            }
            return false;
        }

        $this->entities[$class][$idHash] = $entity;
        return true;
    }

    /** @param class-string $class */
    public function get(string $class, string $idHash): ?object
    {
        return $this->entities[$class][$idHash] ?? null;
    }

    /** @param class-string $class */
    public function contains(string $class, string $idHash, object $entity): bool
    {
        return $this->get($class, $idHash) === $entity;
    }

    /** @param class-string $class */
    public function remove(string $class, string $idHash, object $entity): bool
    {
        if (! $this->contains($class, $idHash, $entity)) {
            return false;
        }
        // Keep empty class buckets, preserving the shape of UnitOfWork's public snapshot.
        unset($this->entities[$class][$idHash]);
        return true;
    }

    /** @return array<class-string, array<string, object>> */
    public function all(): array
    {
        return $this->entities;
    }

    public function size(): int
    {
        return array_sum(array_map('count', $this->entities));
    }

    public function clear(): void
    {
        $this->entities = [];
    }
}
