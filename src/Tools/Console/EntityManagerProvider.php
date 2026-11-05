<?php

declare(strict_types=1);

namespace ModernORM\Tools\Console;

use ModernORM\EntityManagerInterface;

interface EntityManagerProvider
{
    public function getDefaultManager(): EntityManagerInterface;

    public function getManager(string $name): EntityManagerInterface;
}
