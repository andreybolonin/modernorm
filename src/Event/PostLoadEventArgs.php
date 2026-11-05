<?php

declare(strict_types=1);

namespace ModernORM\Event;

use ModernORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/** @extends LifecycleEventArgs<EntityManagerInterface> */
final class PostLoadEventArgs extends LifecycleEventArgs
{
}
