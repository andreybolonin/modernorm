<?php

declare(strict_types=1);

namespace ModernORM\Id;

use ModernORM\EntityManagerInterface;
use ModernORM\Exception\EntityMissingAssignedId;

/**
 * Special generator for application-assigned identifiers (doesn't really generate anything).
 */
class AssignedGenerator extends AbstractIdGenerator
{
    /**
     * Returns the identifier assigned to the given entity.
     *
     * {@inheritDoc}
     *
     * @throws EntityMissingAssignedId
     */
    public function generateId(EntityManagerInterface $em, object|null $entity): array
    {
        $class = $em->getClassMetadata($entity::class);
        $field = $class->getSingleIdentifierFieldName();
        $value = $class->getFieldValue($entity, $field);
        if ($value === null) {
            throw EntityMissingAssignedId::forField($entity, $field);
        }

        return [$field => $value];
    }
}
