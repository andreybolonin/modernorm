<?php

declare(strict_types=1);

namespace ModernORM\Event;

use ModernORM\EntityManagerInterface;
use Doctrine\Persistence\Event\ManagerEventArgs;

/**
 * Provides event arguments for the preFlush event.
 *
 * @link        www.doctrine-project.org
 *
 * @extends ManagerEventArgs<EntityManagerInterface>
 */
class OnFlushEventArgs extends ManagerEventArgs
{
}
