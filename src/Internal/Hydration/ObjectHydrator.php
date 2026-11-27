<?php

declare(strict_types=1);

namespace ModernORM\Internal\Hydration;

use ModernORM\Exception\HydrationException;
use Doctrine\Common\Collections\ArrayCollection;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\RawValuePropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor;
use ModernORM\PersistentCollection;
use ModernORM\Query;
use ModernORM\UnitOfWork;
use ReflectionMethod;

use function array_fill_keys;
use function array_keys;
use function assert;
use function count;
use function is_array;
use function key;
use function spl_object_id;

/**
 * The ObjectHydrator constructs an object graph out of an SQL result set.
 *
 * Internal note: Highly performance-sensitive code.
 */
class ObjectHydrator extends AbstractHydrator
{
    /** @var mixed[] */
    private array $identifierMap = [];

    /** @var mixed[] */
    private array $idTemplate = [];

    private int $resultCounter = 0;

    /** @var mixed[] */
    private array $rootAliases = [];

    /** @var mixed[] */
    private array $initializedCollections = [];

    /** @var array<string, PersistentCollection> */
    private array $uninitializedCollections = [];

    /** @var mixed[] */
    private array $existingCollections = [];

    /** @var array<string, array<int, int|string>> Object id => actual collection key. */
    private array $initializedCollectionElements = [];

    /** @var array<string, array{string, string, string}> Leaf alias to parent alias, relation field and identifier path. */
    private array $siblingCollectionLeaves = [];

    /** @var array<string, array{ClassMetadata, string, AssociationMapping, PropertyAccessor, string}> */
    private array $joinPaths = [];

    /** @var array<string, string> One-to-many alias to its writable owning field. */
    private array $freshInverse = [];

    private bool $customGatherRowData = false;

    protected function prepare(): void
    {
        $this->prepareEntityMapping();
        $this->customGatherRowData = (new ReflectionMethod($this, 'gatherRowData'))->getDeclaringClass()->name !== AbstractHydrator::class;
        $rsm = $this->resultSetMapping();
        $cacheJoinPaths = $this::class === self::class && $this->uow::class === UnitOfWork::class
            && empty($this->hints[Query::HINT_INTERNAL_ITERATION]);
        $leafParents = $this::class === self::class && $this->uow::class === UnitOfWork::class
            && ! $rsm->isMixed && $rsm->indexByMap === [] && $rsm->nestedEntities === []
            && empty($this->hints[Query::HINT_INTERNAL_ITERATION]) && count($rsm->parentAliasMap) > 1
            ? array_fill_keys($rsm->parentAliasMap, true)
            : [];

        if (! isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD])) {
            $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] = true;
        }

        foreach ($this->resultSetMapping()->aliasMap as $resultAlias => $className) {
            $this->identifierMap[$resultAlias] = [];
            $this->idTemplate[$resultAlias]    = '';

            // Remember which associations are "fetch joined", so that we know where to inject
            // collection stubs or proxies and where not.
            if (! isset($this->resultSetMapping()->relationMap[$resultAlias])) {
                continue;
            }

            $parent = $this->resultSetMapping()->parentAliasMap[$resultAlias];

            if (! isset($this->resultSetMapping()->aliasMap[$parent])) {
                if (isset($this->resultSetMapping()->nestedEntities[$resultAlias])) {
                    continue;
                }

                throw HydrationException::parentObjectOfRelationNotFound($resultAlias, $parent);
            }

            $sourceClassName = $this->resultSetMapping()->aliasMap[$parent];
            $sourceClass     = $this->getClassMetadata($sourceClassName);
            $relationField   = $rsm->relationMap[$resultAlias];
            $assoc           = $sourceClass->associationMappings[$relationField];

            if ($cacheJoinPaths && isset($sourceClass->propertyAccessors[$relationField])) {
                $this->joinPaths[$resultAlias] = [
                    $sourceClass,
                    $relationField,
                    $assoc,
                    $sourceClass->propertyAccessors[$relationField],
                    $parent . '.' . $resultAlias,
                ];
            }

            if (isset($leafParents[$parent]) && ! isset($leafParents[$resultAlias])
                && ! isset($rsm->parentAliasMap[$parent]) && $assoc->isToMany()
            ) {
                $this->siblingCollectionLeaves[$resultAlias] = [$parent, $assoc->fieldName, $parent . '.' . $resultAlias];
            }

            if ($cacheJoinPaths && $assoc->isOneToMany() && $rsm->indexByMap === [] && ! $rsm->isMixed
                && ! isset($this->hints[Query::HINT_REFRESH]) && ! isset($this->hints[Query::HINT_REFRESH_ENTITY])
            ) {
                $child = $this->getClassMetadata($className);
                $accessor = $child->propertyAccessors[$assoc->mappedBy] ?? null;
                if ($child->isInheritanceTypeNone() && count($child->associationMappings) === 1
                    && ($accessor instanceof RawValuePropertyAccessor
                        || ($accessor instanceof TypedNoDefaultPropertyAccessor && $accessor->usesRawPropertyAccess()))
                ) {
                    $property = $accessor->getUnderlyingReflector();
                    if (! $property->hasHooks() && ! $property->isReadOnly() && ! $property->isVirtual()
                        && (! $property->hasDefaultValue() || $property->getDefaultValue() === null)
                        && $this->hasRawJoinedFields($child)
                    ) {
                        $this->freshInverse[$resultAlias] = $assoc->mappedBy;
                    }
                }
            }

            $this->hints['fetched'][$parent][$assoc->fieldName] = true;

            if ($assoc->isManyToMany()) {
                continue;
            }

            // Mark any non-collection opposite sides as fetched, too.
            if (! $assoc->isOwningSide()) {
                $this->hints['fetched'][$resultAlias][$assoc->mappedBy] = true;

                continue;
            }

            // handle fetch-joined owning side bi-directional one-to-one associations
            if ($assoc->inversedBy !== null) {
                $class        = $this->getClassMetadata($className);
                $inverseAssoc = $class->associationMappings[$assoc->inversedBy];

                if (! $inverseAssoc->isToOne()) {
                    continue;
                }

                $this->hints['fetched'][$resultAlias][$inverseAssoc->fieldName] = true;
            }
        }

        $leavesPerParent = [];
        foreach ($this->siblingCollectionLeaves as [$parent]) {
            $leavesPerParent[$parent] = ($leavesPerParent[$parent] ?? 0) + 1;
        }
        foreach ($this->siblingCollectionLeaves as $alias => [$parent]) {
            if ($leavesPerParent[$parent] < 2) {
                unset($this->siblingCollectionLeaves[$alias]);
            }
        }
    }

    private function hasRawJoinedFields(ClassMetadata $child): bool
    {
        foreach ($child->fieldMappings as $field => $mapping) {
            $accessor = $child->propertyAccessors[$field] ?? null;
            if (! ($accessor instanceof RawValuePropertyAccessor)
                && ! ($accessor instanceof TypedNoDefaultPropertyAccessor && $accessor->usesRawPropertyAccess())
            ) {
                return false;
            }
            if ($accessor->getUnderlyingReflector()->hasHooks()) {
                return false;
            }
        }

        return true;
    }

    protected function cleanup(): void
    {
        $eagerLoad = isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD]) && $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] === true;

        parent::cleanup();

        $this->identifierMap                 =
        $this->initializedCollections        =
        $this->uninitializedCollections      =
        $this->initializedCollectionElements =
        $this->existingCollections           = [];

        $this->rootAliases = $this->idTemplate = [];
        $this->siblingCollectionLeaves = $this->joinPaths = $this->freshInverse = [];
        $this->resultCounter = 0;

        if ($eagerLoad) {
            $this->uow->triggerEagerLoads();
        }

        $this->uow->hydrationComplete();
    }

    protected function cleanupAfterRowIteration(): void
    {
        $this->identifierMap                 =
        $this->initializedCollections        =
        $this->uninitializedCollections      =
        $this->initializedCollectionElements =
        $this->existingCollections           = [];
    }

    /**
     * {@inheritDoc}
     */
    protected function hydrateAllData(): array
    {
        $result = [];

        while ($row = $this->statement()->fetchAssociative()) {
            $this->hydrationRows();
            $this->hydrateRowData($row, $result);
        }

        // Take snapshots from all newly initialized collections
        foreach ($this->initializedCollections as $coll) {
            $coll->takeSnapshot();
        }

        foreach ($this->uninitializedCollections as $coll) {
            if (! $coll->isInitialized()) {
                $coll->setInitialized(true);
            }
        }

        return $result;
    }

    /**
     * Initializes a related collection.
     *
     * @param string $fieldName      The name of the field on the entity that holds the collection.
     * @param string $parentResultAlias Alias of the parent fetch joining this collection.
     */
    private function initRelatedCollection(
        object $entity,
        ClassMetadata $class,
        string $fieldName,
        string $parentResultAlias,
    ): PersistentCollection {
        $oid      = spl_object_id($entity);
        $relation = $class->associationMappings[$fieldName];
        $value    = $class->propertyAccessors[$fieldName]->getValue($entity);

        if ($value === null || is_array($value)) {
            $value = new ArrayCollection((array) $value);
        }

        if (! $value instanceof PersistentCollection) {
            assert($relation->isToMany());
            $value = new PersistentCollection(
                $this->em,
                $this->metadataCache[$relation->targetEntity],
                $value,
            );
            $value->setOwner($entity, $relation);

            $class->propertyAccessors[$fieldName]->setValue($entity, $value);
            $this->uow->setOriginalEntityProperty($oid, $fieldName, $value);

            $this->initializedCollections[$oid . $fieldName] = $value;
            $this->initializedCollectionElements[$oid . $fieldName] = [];
            foreach ($value->unwrap() as $key => $element) {
                $this->initializedCollectionElements[$oid . $fieldName][spl_object_id($element)] = $key;
            }
        } elseif (
            isset($this->hints[Query::HINT_REFRESH]) ||
            isset($this->hints['fetched'][$parentResultAlias][$fieldName]) &&
             ! $value->isInitialized()
        ) {
            // Is already PersistentCollection, but either REFRESH or FETCH-JOIN and UNINITIALIZED!
            $value->setDirty(false);
            $value->setInitialized(true);
            $value->unwrap()->clear();

            $this->initializedCollections[$oid . $fieldName] = $value;
            $this->initializedCollectionElements[$oid . $fieldName] = [];
        } else {
            // Is already PersistentCollection, and DON'T REFRESH or FETCH-JOIN!
            $this->existingCollections[$oid . $fieldName] = $value;
        }

        return $value;
    }

    /**
     * Gets an entity instance.
     *
     * @param string $resultAlias The result alias of the entity's class.
     * @phpstan-param array<string, mixed> $data     The instance data.
     *
     * @throws HydrationException
     */
    private function getEntity(array $data, string $resultAlias): object
    {
        $className = $this->resultSetMapping()->aliasMap[$resultAlias];

        if (isset($this->hints[Query::HINT_REFRESH_ENTITY], $this->rootAliases[$resultAlias])) {
            $this->registerManaged($this->metadataCache[$className], $this->hints[Query::HINT_REFRESH_ENTITY], $data);
        }

        $this->hints['fetchAlias'] = $resultAlias;

        if ($this->flatSqlPlanPending) {
            $this->compileFlatSqlPlan($this->metadataCache[$className], $data);
        }

        if ($this::class === self::class && $this->uow::class === UnitOfWork::class) {
            return $this->uow->createEntityWithMetadata($this->getClassMetadata($className), $data, $this->hints);
        }

        return $this->uow->createEntity($className, $data, $this->hints);
    }

    /**
     * @param class-string $className
     * @phpstan-param array<string, mixed> $data
     */
    private function getEntityFromIdentityMap(string $className, array $data): object|bool
    {
        // TODO: Abstract this code and UnitOfWork::createEntity() equivalent?
        $class = $this->metadataCache[$className];

        return $this->uow->tryGetByIdHash($data[$class->identifier[0]], $class->name);
    }

    /**
     * Hydrates a single row in an SQL result set.
     *
     * @internal
     * First, the data of the row is split into chunks where each chunk contains data
     * that belongs to a particular component/class. Afterwards, all these chunks
     * are processed, one after the other. For each chunk of class data only one of the
     * following code paths is executed:
     * Path A: The data chunk belongs to a joined/associated object and the association
     *         is collection-valued.
     * Path B: The data chunk belongs to a joined/associated object and the association
     *         is single-valued.
     * Path C: The data chunk belongs to a root result element/object that appears in the topmost
     *         level of the hydrated result. A typical example are the objects of the type
     *         specified by the FROM clause in a SQL query.
     *
     * @param mixed[] $row    The data of the row to process.
     * @param mixed[] $result The result array to fill.
     */
    protected function hydrateRowData(array $row, array &$result): void
    {
        $this->assertCompleteEntityRow($row);
        $rsm = $this->resultSetMapping();

        // Initialize
        $id                 = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        // Split the row data into chunks of class data.
        $rowData = $this->customGatherRowData
            ? $this->gatherRowData($row, $id, $nonemptyComponents)
            : $this->gatherMappedRow($row, $id, $nonemptyComponents, true);

        // reset result pointers for each data row
        $resultPointers = [];

        // Hydrate the data chunks
        foreach ($rowData['data'] as $resultAlias => $data) {
            if (isset($this->siblingCollectionLeaves[$resultAlias], $nonemptyComponents[$resultAlias])) {
                [$parentAlias, $field, $path] = $this->siblingCollectionLeaves[$resultAlias];
                $index = $this->identifierMap[$path][$id[$parentAlias]][$id[$resultAlias]] ?? null;
                if ($index !== null && isset($nonemptyComponents[$parentAlias], $resultPointers[$parentAlias])) {
                    $collection = $this->initializedCollections[spl_object_id($resultPointers[$parentAlias]) . $field] ?? null;
                    // No later alias reads this leaf pointer. Custom collection reads use the normal path.
                    if ($collection !== null && $collection->unwrap()::class === ArrayCollection::class && isset($collection[$index])) {
                        continue;
                    }
                }
            }

            $entityName = $rsm->aliasMap[$resultAlias];

            if (isset($rsm->parentAliasMap[$resultAlias])) {
                // It's a joined result

                $parentAlias = $rsm->parentAliasMap[$resultAlias];

                // We have a RIGHT JOIN result here. Doctrine cannot hydrate RIGHT JOIN Object-Graphs
                if (! isset($nonemptyComponents[$parentAlias])) {
                    // TODO: Add special case code where we hydrate the right join objects into identity map at least
                    continue;
                }

                if (isset($this->joinPaths[$resultAlias])) {
                    [$parentClass, $relationField, $relation, $reflField, $path] = $this->joinPaths[$resultAlias];
                } else {
                    $parentClass   = $this->metadataCache[$rsm->aliasMap[$parentAlias]];
                    $relationField = $rsm->relationMap[$resultAlias];
                    $relation      = $parentClass->associationMappings[$relationField];
                    $reflField     = $parentClass->propertyAccessors[$relationField];
                    $path          = $parentAlias . '.' . $resultAlias;
                }

                // Get a reference to the parent object to which the joined element belongs.
                if ($rsm->isMixed && isset($this->rootAliases[$parentAlias])) {
                    $objectClass  = $resultPointers[$parentAlias];
                    $parentObject = $objectClass[key($objectClass)];
                } elseif (isset($resultPointers[$parentAlias])) {
                    $parentObject = $resultPointers[$parentAlias];
                } else {
                    // Parent object of relation not found, mark as not-fetched again
                    if (isset($nonemptyComponents[$resultAlias])) {
                        $element = $this->getEntity($data ?? $this->gatherEntityRowData($row, $resultAlias), $resultAlias);

                        // Update result pointer and provide initial fetch data for parent
                        $resultPointers[$resultAlias]               = $element;
                        $rowData['data'][$parentAlias][$relationField] = $element;
                    } else {
                        $element = null;
                    }

                    // Mark as not-fetched again
                    unset($this->hints['fetched'][$parentAlias][$relationField]);
                    continue;
                }

                $oid = spl_object_id($parentObject);

                // Check the type of the relation (many or single-valued)
                if (! $relation->isToOne()) {
                    // PATH A: Collection-valued association
                    if (isset($nonemptyComponents[$resultAlias])) {
                        $collKey = $oid . $relationField;
                        if (isset($this->initializedCollections[$collKey])) {
                            $reflFieldValue = $this->initializedCollections[$collKey];
                        } elseif (! isset($this->existingCollections[$collKey])) {
                            $reflFieldValue = $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                        } else {
                            $reflFieldValue = $reflField->getValue($parentObject);
                        }

                        $indexExists  = isset($this->identifierMap[$path][$id[$parentAlias]][$id[$resultAlias]]);
                        $index        = $indexExists ? $this->identifierMap[$path][$id[$parentAlias]][$id[$resultAlias]] : false;
                        $indexIsValid = $index !== false ? isset($reflFieldValue[$index]) : false;

                        if (! $indexExists || ! $indexIsValid) {
                            if (isset($this->existingCollections[$collKey])) {
                                // Collection exists, only look for the element in the identity map.
                                $element = $this->getEntityFromIdentityMap($entityName, $data ?? $this->gatherEntityRowData($row, $resultAlias));
                                if ($element) {
                                    $resultPointers[$resultAlias] = $element;
                                } else {
                                    unset($resultPointers[$resultAlias]);
                                }
                            } else {
                                $linked = false;
                                if (isset($this->freshInverse[$resultAlias]) && $data === null
                                    && $reflFieldValue->unwrap()::class === ArrayCollection::class
                                ) {
                                    $this->hints['fetchAlias'] = $resultAlias;
                                    $element = $this->uow->createEntityWithMetadata(
                                        $this->metadataCache[$entityName],
                                        $this->gatherEntityRowData($row, $resultAlias),
                                        $this->hints,
                                        $parentObject,
                                        $this->freshInverse[$resultAlias],
                                        $linked,
                                    );
                                } else {
                                    $element = $this->getEntity($data ?? $this->gatherEntityRowData($row, $resultAlias), $resultAlias);
                                }

                                if (isset($rsm->indexByMap[$resultAlias])) {
                                    $indexValue = $row[$rsm->indexByMap[$resultAlias]];
                                    $reflFieldValue->hydrateSet($indexValue, $element);
                                    $this->initializedCollectionElements[$collKey][spl_object_id($element)] = $indexValue;
                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$resultAlias]] = $indexValue;
                                } else {
                                    $elementId = spl_object_id($element);
                                    if (! isset($this->initializedCollectionElements[$collKey][$elementId])) {
                                        if ($linked) {
                                            $reflFieldValue->unwrap()->add($element);
                                        } else {
                                            $reflFieldValue->hydrateAdd($element);
                                        }
                                        $reflFieldValue->last();
                                        $this->initializedCollectionElements[$collKey][$elementId] = $reflFieldValue->key();
                                    }

                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$resultAlias]] = $this->initializedCollectionElements[$collKey][$elementId];
                                }

                                // Update result pointer
                                $resultPointers[$resultAlias] = $element;
                            }
                        } else {
                            // Update result pointer
                            $resultPointers[$resultAlias] = $reflFieldValue[$index];
                        }
                    } elseif (! ($reflFieldValue = $reflField->getValue($parentObject))) {
                        $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                    } elseif ($reflFieldValue instanceof PersistentCollection && $reflFieldValue->isInitialized() === false && ! isset($this->uninitializedCollections[$oid . $relationField])) {
                        $this->uninitializedCollections[$oid . $relationField] = $reflFieldValue;
                    }
                } else {
                    // PATH B: Single-valued association
                    $reflFieldValue = $reflField->getValue($parentObject);

                    if (! $this->uow->isHydrationRefresh($parentObject, $this->hints) && $this->uow->isToOneAssociationChanged($parentObject, $relationField)) {
                        // Nested aliases follow the database row, while the parent's local link stays intact.
                        if (isset($nonemptyComponents[$resultAlias])) {
                            $resultPointers[$resultAlias] = $this->getEntity($data ?? $this->gatherEntityRowData($row, $resultAlias), $resultAlias);
                        } else {
                            unset($resultPointers[$resultAlias]);
                        }
                        continue;
                    }

                    if (! $reflFieldValue || isset($this->hints[Query::HINT_REFRESH]) || $this->uow->isUninitializedObject($reflFieldValue)) {
                        // we only need to take action if this value is null,
                        // we refresh the entity or its an uninitialized proxy.
                        if (isset($nonemptyComponents[$resultAlias])) {
                            $element = $this->getEntity($data ?? $this->gatherEntityRowData($row, $resultAlias), $resultAlias);
                            $reflField->setValue($parentObject, $element);
                            $this->uow->setOriginalEntityProperty($oid, $relationField, $element);
                            $targetClass = $this->metadataCache[$relation->targetEntity];

                            if ($relation->isOwningSide()) {
                                // TODO: Just check hints['fetched'] here?
                                // If there is an inverse mapping on the target class its bidirectional
                                if ($relation->inversedBy !== null) {
                                    $inverseAssoc = $targetClass->associationMappings[$relation->inversedBy];
                                    if ($inverseAssoc->isToOne()) {
                                        $this->uow->hydrateToOneAssociation($element, $inverseAssoc->fieldName, $parentObject, $this->uow->isHydrationRefresh($element, $this->hints));
                                    }
                                }
                            } else {
                                // For sure bidirectional, as there is no inverse side in unidirectional mappings
                                $this->uow->hydrateToOneAssociation($element, $relation->mappedBy, $parentObject, $this->uow->isHydrationRefresh($element, $this->hints));
                            }

                            // Update result pointer
                            $resultPointers[$resultAlias] = $element;
                        } else {
                            $this->uow->setOriginalEntityProperty($oid, $relationField, null);
                            $reflField->setValue($parentObject, null);
                        }
                        // else leave $reflFieldValue null for single-valued associations
                    } else {
                        // Update result pointer
                        $resultPointers[$resultAlias] = $reflFieldValue;
                    }
                }
            } else {
                // PATH C: Its a root result element
                $this->rootAliases[$resultAlias] = true; // Mark as root alias
                $entityKey                    = $rsm->entityMappings[$resultAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (! isset($nonemptyComponents[$resultAlias])) {
                    if ($rsm->isMixed) {
                        $result[] = [$entityKey => null];
                    } else {
                        $result[] = null;
                    }

                    $resultKey = $this->resultCounter;
                    ++$this->resultCounter;
                    continue;
                }

                // check for existing result from the iterations before
                if (! isset($this->identifierMap[$resultAlias][$id[$resultAlias]])) {
                    $element = $this->getEntity($data ?? $this->gatherEntityRowData($row, $resultAlias), $resultAlias);

                    if ($rsm->isMixed) {
                        $element = [$entityKey => $element];
                    }

                    if (isset($rsm->indexByMap[$resultAlias])) {
                        $resultKey = $row[$rsm->indexByMap[$resultAlias]];

                        if (isset($this->hints['collection'])) {
                            $this->hints['collection']->hydrateSet($resultKey, $element);
                        }

                        $result[$resultKey] = $element;
                    } else {
                        $resultKey = $this->resultCounter;
                        ++$this->resultCounter;

                        if (isset($this->hints['collection'])) {
                            $this->hints['collection']->hydrateAdd($element);
                        }

                        $result[] = $element;
                    }

                    $this->identifierMap[$resultAlias][$id[$resultAlias]] = $resultKey;

                    // Update result pointer
                    $resultPointers[$resultAlias] = $element;
                } else {
                    // Update result pointer
                    $index                           = $this->identifierMap[$resultAlias][$id[$resultAlias]];
                    $resultPointers[$resultAlias] = $result[$index];
                    $resultKey                       = $index;
                }
            }

            if (isset($this->hints[Query::HINT_INTERNAL_ITERATION]) && $this->hints[Query::HINT_INTERNAL_ITERATION]) {
                $this->uow->hydrationComplete();
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                $resultKey = isset($rsm->indexByMap['scalars'])
                    ? $row[$rsm->indexByMap['scalars']]
                    : $this->resultCounter - 1;
            }

            foreach ($rowData['scalars'] as $name => $value) {
                $result[$resultKey][$name] = $value;
            }
        }

        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (! isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }

            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $obj = $newObject['obj'];

                if ($scalarCount === 0 && count($rowData['newObjects']) === 1) {
                    $result[$resultKey] = $obj;

                    continue;
                }

                $result[$resultKey][$objIndex] = $obj;
            }
        }
    }

    /** @param mixed[] $data pre-hydrated SQL Result Row. */
    protected function hydrateNestedEntity(array $data, string $resultAlias): mixed
    {
        if (isset($this->resultSetMapping()->nestedEntities[$resultAlias])) {
            return $this->getEntity($data, $resultAlias);
        }

        return $data;
    }

    /**
     * When executed in a hydrate() loop we may have to clear internal state to
     * decrease memory consumption.
     */
    public function onClear(mixed $eventArgs): void
    {
        parent::onClear($eventArgs);

        $aliases = array_keys($this->identifierMap);

        $this->identifierMap = array_fill_keys($aliases, []);
    }
}
