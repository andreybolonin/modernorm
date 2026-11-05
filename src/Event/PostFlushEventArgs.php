<?php

declare(strict_types=1);

namespace ModernORM\Event;

use ModernORM\EntityManagerInterface;
use Doctrine\Persistence\Event\ManagerEventArgs;

/**
 * Provides event arguments for the postFlush event.
 *
 * @link        www.doctrine-project.org
 *
 * @extends ManagerEventArgs<EntityManagerInterface>
 */
class PostFlushEventArgs extends ManagerEventArgs
{
}
