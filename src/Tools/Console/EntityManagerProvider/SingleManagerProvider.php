<?php

declare(strict_types=1);

namespace ModernORM\Tools\Console\EntityManagerProvider;

use ModernORM\Exception\UnknownManagerException;
use ModernORM\EntityManagerInterface;
use ModernORM\Tools\Console\EntityManagerProvider;

final class SingleManagerProvider implements EntityManagerProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $defaultManagerName = 'default',
    ) {
    }

    public function getDefaultManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function getManager(string $name): EntityManagerInterface
    {
        if ($name !== $this->defaultManagerName) {
            throw UnknownManagerException::unknownManager($name, [$this->defaultManagerName]);
        }

        return $this->entityManager;
    }
}
