<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use ModernORM\Exception\MappingException;

/**
 * The "many" side of a many-to-one association mapping is always the owning side.
 */
final class ManyToOneAssociationMapping extends ToOneOwningSideMapping
{
    /** Null inherits Configuration; one disables lazy batching. */
    public int|null $batchSize = null;

    public static function fromMappingArray(array $mappingArray): static
    {
        $mapping = parent::fromMappingArray($mappingArray);
        $mapping->assertValidBatchSize();
        return $mapping;
    }

    public function assertValidBatchSize(): void
    {
        if ($this->batchSize !== null && ($this->batchSize < 1 || $this->batchSize > 65535)) {
            throw new MappingException('ManyToOne batchSize must be between 1 and 65535: ' . $this->sourceEntity . '::' . $this->fieldName);
        }
    }

    public function __sleep(): array
    {
        return [...parent::__sleep(), 'batchSize'];
    }
}
