<?php

declare(strict_types=1);

namespace ModernORM\Event;

use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Event\LoadClassMetadataEventArgs as BaseLoadClassMetadataEventArgs;

/**
 * Class that holds event arguments for a loadMetadata event.
 *
 * @extends BaseLoadClassMetadataEventArgs<ClassMetadata<object>, EntityManagerInterface>
 */
class LoadClassMetadataEventArgs extends BaseLoadClassMetadataEventArgs
{
    /**
     * Retrieve associated EntityManager.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->getObjectManager();
    }
}
