<?php

declare(strict_types=1);

namespace ModernORM\Proxy;

use ModernORM\EntityManagerInterface;
use ModernORM\Batch\LazyReferenceQueue;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Utility\IdentifierFlattener;
use ReflectionClass;

/** Creates PHP 8.4 native lazy objects; no generated proxy classes are used. */
class ProxyFactory
{
    private readonly LazyReferenceQueue $batchQueue;
    private readonly IdentifierFlattener $identifierFlattener;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->batchQueue = new LazyReferenceQueue($em);
        $this->identifierFlattener = new IdentifierFlattener();
    }

    /** @internal */
    public function getBatchQueue(): LazyReferenceQueue
    {
        return $this->batchQueue;
    }

    /** @param array<string, mixed> $identifier */
    public function getProxy(string $className, array $identifier): object
    {
        $classMetadata       = $this->em->getClassMetadata($className);
        $entityPersister     = $this->em->getUnitOfWork()->getEntityPersister($className);
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

        foreach ($identifier as $idField => $value) {
            $classMetadata->propertyAccessors[$idField]->setValue($proxy, $value);
        }

        return $proxy;
    }
}
