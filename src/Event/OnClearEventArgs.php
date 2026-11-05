<?php

declare(strict_types=1);

namespace ModernORM\Event;

use ModernORM\EntityManagerInterface;
use Doctrine\Persistence\Event\OnClearEventArgs as BaseOnClearEventArgs;

/**
 * Provides event arguments for the onClear event.
 *
 * @link        www.doctrine-project.org
 *
 * @extends BaseOnClearEventArgs<EntityManagerInterface>
 */
class OnClearEventArgs extends BaseOnClearEventArgs
{
}
