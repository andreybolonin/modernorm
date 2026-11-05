<?php

declare(strict_types=1);

namespace ModernORM\Proxy;

use ModernORM\EntityManagerInterface;
use ModernORM\Batch\LazyReferenceQueue;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\UnitOfWork;
use ModernORM\Utility\IdentifierFlattener;
use ReflectionClass;

/** Creates PHP 8.4 native lazy objects; no generated proxy classes are used. */
class ProxyFactory
{
    private readonly UnitOfWork $uow;
    private readonly LazyReferenceQueue $batchQueue;
    private readonly IdentifierFlattener $identifierFlattener;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->uow = $em->getUnitOfWork();
        $this->batchQueue = new LazyReferenceQueue($em);
        $this->identifierFlattener = new IdentifierFlattener();
    }

    /** @internal */
    public function getBatchQueue(): LazyReferenceQueue
    {
        return $this->batchQueue;
    }

    /** @param array<string, mixed> $entityIdentifier */
    public function getEmbeddableProxy(string $className, object $parentEntity, array $entityIdentifier): object
    {
        $classMetadata       = $this->em->getClassMetadata($className);
        $entityPersister     = $this->uow->getEntityPersister($parentEntity::class);
        $uow                 = $this->uow;

        $cb = static function (object $object) use (
            $entityIdentifier,
            $entityPersister,
            $classMetadata,
            $parentEntity,
            $uow,
        ): void {
            // If the parent entity was already fully initialized (e.g. because
            // another of its properties was accessed first), all embedded fields
            // were already written as raw values onto this ghost during that
            // hydration pass. PHP will materialize those raw values automatically
            // once the initializer returns, so no SELECT is needed.
            if (! $uow->isUninitializedObject($parentEntity)) {
                return;
            }

            $original = $entityPersister->loadById($entityIdentifier, $parentEntity);
            if ($original === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $parentEntity::class,
                    $entityIdentifier,
                );
            }
        };

        return $classMetadata->reflClass->newLazyGhost($cb, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
    }

    /** @param array<string, mixed> $identifier */
    public function getProxy(string $className, array $identifier, bool $assignIdentifiers = true): object
    {
        $classMetadata       = $this->em->getClassMetadata($className);
        $entityPersister     = $this->uow->getEntityPersister($className);
        $identifierFlattener = $this->identifierFlattener;
        $batchQueue          = $this->batchQueue;

        $proxy = $classMetadata->reflClass->newLazyGhost(static function (object $object) use (
            $identifier,
            $batchQueue,
            $entityPersister,
            $identifierFlattener,
            $classMetadata,
        ): void {
            if ($batchQueue->initialize($object, $classMetadata)) {
                return;
            }
            $batchQueue->remove($object);
            $original = $entityPersister->loadById($identifier, $object);
            if ($original === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $identifierFlattener->flattenIdentifier($classMetadata, $identifier),
                );
            }
        }, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);

        if ($assignIdentifiers) {
            foreach ($identifier as $idField => $value) {
                $classMetadata->propertyAccessors[$idField]->setValue($proxy, $value);
            }
        }

        return $proxy;
    }
}
