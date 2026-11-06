<?php

declare(strict_types=1);

namespace ModernORM\Cache;

use Doctrine\DBAL\Types\Type;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Query;
use ModernORM\UnitOfWork;

final class ReadOnlyEntityHydrator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }
    public function signature(ClassMetadata $metadata): string
    {
        $mapping = [$metadata->name, $metadata->table, $metadata->fieldMappings, $metadata->identifier];
        if ($metadata->softDeleteField !== null) {
            $mapping[] = ['softDelete' => $metadata->softDeleteField];
        }
        return hash('sha256', serialize($mapping));
    }
    public function fromDatabaseRow(ClassMetadata $metadata, array $data): ReadOnlyEntityCacheEntry
    {
        foreach ($data as $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new \LogicException('READ_ONLY cache requires scalar database driver values.');
            }
        }
        return new ReadOnlyEntityCacheEntry($metadata->name, $data, $this->signature($metadata));
    }
    /** Validate and convert without creating entities or invoking PostLoad. */
    public function getData(ClassMetadata $metadata, EntityCacheKey $key, CacheEntry $entry): ?array
    {
        if (! $entry instanceof ReadOnlyEntityCacheEntry || $entry->class !== $metadata->name
            || $entry->mappingSignature !== $this->signature($metadata)
            || array_diff_key($metadata->fieldMappings, $entry->data) !== []
            || array_diff_key($entry->data, $metadata->fieldMappings) !== []) {
            return null;
        }
        foreach ($entry->data as $value) {
            if ($value !== null && ! is_scalar($value)) {
                return null;
            }
        }
        $data = [];
        $platform = $this->em->getConnection()->getDatabasePlatform();
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $data[$field] = Type::getType($mapping->type)->convertToPHPValue($entry->data[$field], $platform);
        }
        if ($metadata->softDeleteField !== null && $data[$metadata->softDeleteField] !== null) {
            return null;
        }
        $field = $metadata->getSingleIdentifierFieldName();
        if (UnitOfWork::getIdHashByIdentifier([$data[$field]]) !== UnitOfWork::getIdHashByIdentifier($key->identifier)) {
            return null;
        }
        return $data;
    }
    public function loadCacheEntry(ClassMetadata $metadata, EntityCacheKey $key, CacheEntry $entry, ?object $entity = null): ?object
    {
        $statistics = $this->em->getStatistics();
        $measurement = $statistics?->startHydration(self::class);
        $failed = true;
        try {
            $data = $this->getData($metadata, $key, $entry);
            if ($data === null) {
                $failed = false;
                return null;
            }
            if ($measurement !== null) {
                ++$measurement->rows;
            }
            $hints = [];
            $uow = $this->em->getUnitOfWork();
            if ($entity !== null) {
                // Detached ghosts keep their ordinary single-loader behavior after
                // clear/detach. Hydrate the supplied object, not a replacement instance.
                if ($uow->tryGetById($key->identifier, $metadata->name) !== $entity) {
                    $uow->registerManaged($entity, $key->identifier, []);
                }
                $hints[Query::HINT_REFRESH] = true;
                $hints[Query::HINT_REFRESH_ENTITY] = $entity;
            }
            $result = $uow->createEntity($metadata->name, $data, $hints);
            $uow->hydrationComplete();
            $failed = false;
            return $result;
        } finally {
            if ($measurement !== null) {
                $statistics->recordHydration($measurement, $failed, !$failed);
            }
        }
    }
}
