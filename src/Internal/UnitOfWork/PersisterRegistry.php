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

use ModernORM\Cache\Persister\CachedPersister;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Persisters\Collection\CollectionPersister;
use ModernORM\Persisters\Collection\ManyToManyPersister;
use ModernORM\Persisters\Collection\OneToManyPersister;
use ModernORM\Persisters\Entity\BasicEntityPersister;
use ModernORM\Persisters\Entity\EntityPersister;

/** @internal Creates and retains persisters for the lifetime of a UnitOfWork. */
final class PersisterRegistry
{
    /** @var array<class-string, EntityPersister> */
    private array $persisters = [];

    /** @var array<int, CollectionPersister> */
    private array $collectionPersisters = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly bool $hasCache,
    ) {
    }

    /** @param class-string $entityName */
    public function getEntityPersister(string $entityName): EntityPersister
    {
        if (isset($this->persisters[$entityName])) {
            return $this->persisters[$entityName];
        }

        $class = $this->em->getClassMetadata($entityName);

        $class->assertSupportedMapping();
        $persister = new BasicEntityPersister($this->em, $class);

        if ($this->hasCache && $class->cache !== null) {
            $persister = $this->em->getCache()->wrapPersister($persister, $class);
        }

        $this->persisters[$entityName] = $persister;

        return $this->persisters[$entityName];
    }

    public function getCollectionPersister(AssociationMapping $association): CollectionPersister
    {
        $role = $association->type();

        if (isset($this->collectionPersisters[$role])) {
            return $this->collectionPersisters[$role];
        }

        $persister = $association->type() === ClassMetadata::ONE_TO_MANY
            ? new OneToManyPersister($this->em)
            : new ManyToManyPersister($this->em);

        $this->collectionPersisters[$role] = $persister;

        return $this->collectionPersisters[$role];
    }

    public function forEachCachedPersister(callable $callback): void
    {
        if (! $this->hasCache) {
            return;
        }

        foreach ($this->persisters as $persister) {
            if ($persister instanceof CachedPersister) {
                $callback($persister);
            }
        }
    }


    public function afterTransactionComplete(): void
    {
        $failure = null;
        $this->forEachCachedPersister(static function (CachedPersister $persister) use (&$failure): void {
            try {
                $persister->afterTransactionComplete();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function afterTransactionRolledBack(): void
    {
        $this->forEachCachedPersister(static function (CachedPersister $persister): void {
            $persister->afterTransactionRolledBack();
        });
    }

    public function afterTransactionCommitFailed(\Throwable $commitFailure): void
    {
        $affected = false;
        $cacheFailure = null;
        $this->forEachCachedPersister(static function (CachedPersister $persister) use (&$affected, &$cacheFailure): void {
            try {
                $affected = $persister->invalidatePendingWrites() || $affected;
            } catch (\Throwable $error) {
                $affected = true;
                $cacheFailure ??= $error;
            }
        });
        $this->afterTransactionRolledBack();
        if ($affected) {
            throw new \ModernORM\Exception\CacheSynchronizationException($commitFailure, null, $cacheFailure);
        }
    }
}
