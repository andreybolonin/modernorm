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

namespace ModernORM\Internal\UnitOfWork;

use WeakMap;

use function array_flip;
use function array_intersect_key;

/** @internal Tracks loaded scalar fields without retaining objects or reading their properties. */
final class PartialLoadTracker
{
    /** @var WeakMap<object, list<string>> */
    private WeakMap $fields;

    public function __construct()
    {
        $this->fields = new WeakMap();
    }

    /** @param list<string> $loadedFields */
    public function mark(object $entity, array $loadedFields): void
    {
        $this->fields[$entity] = $loadedFields;
    }

    /** Empty means a tracked reference without loaded scalar fields; null means untracked. @return list<string>|null */
    public function loadedFields(object $entity): ?array
    {
        return $this->fields[$entity] ?? null;
    }

    /**
     * Select known scalar values from a supplied snapshot, retaining loaded nulls.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function loadedSnapshot(object $entity, array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip($this->loadedFields($entity) ?? []));
    }

    public function forget(object $entity): void
    {
        unset($this->fields[$entity]);
    }

    public function clear(): void
    {
        $this->fields = new WeakMap();
    }
}
