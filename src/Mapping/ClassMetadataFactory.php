<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use ModernORM\Exception\MappingException;
use Doctrine\Common\EventDispatcher;
use Doctrine\DBAL\Platforms;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use ModernORM\EntityManagerInterface;
use ModernORM\Event\LoadClassMetadataEventArgs;
use ModernORM\Event\OnClassMetadataNotFoundEventArgs;
use ModernORM\Events;
use ModernORM\Exception\ORMException;
use ModernORM\Id\AssignedGenerator;
use ModernORM\Id\BigIntegerIdentityGenerator;
use ModernORM\Id\IdentityGenerator;
use ModernORM\Id\SequenceGenerator;
use ModernORM\Exception\InvalidCustomGenerator;
use ModernORM\Exception\UnknownGeneratorType;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\ReflectionService;
use ReflectionException;

use function assert;
use function class_exists;
use function is_a;
use function is_int;

use const PHP_VERSION_ID;

/**
 * The ClassMetadataFactory is used to create ClassMetadata objects that contain all the
 * metadata mapping information of a class which describes how a class should be mapped
 * to a relational database.
 *
 * @extends AbstractClassMetadataFactory<ClassMetadata>
 */
class ClassMetadataFactory extends AbstractClassMetadataFactory
{
    protected string $cacheSalt = '__MODERNORM_ENTITY_CACHE_WRITE_POLICY_V9__';

    public function getMetadataFor(string $className): ClassMetadata
    {
        if (! $this->initialized) {
            $this->initialize();
        }
        $metadata = parent::getMetadataFor($className);
        $metadata->assertSupportedMapping();
        return $metadata;
    }

    private EntityManagerInterface|null $em       = null;
    private AbstractPlatform|null $targetPlatform = null;
    private MappingDriver|null $driver            = null;
    private EventDispatcher|null $eventDispatcher = null;

    /** @var mixed[] */
    private array $embeddablesActiveNesting = [];

    private function determineIdGeneratorStrategy(AbstractPlatform $platform): int
    {
        foreach ($this->em->getConfiguration()->getIdentityGenerationPreferences() as $platformFamily => $strategy) {
            if (is_a($platform, $platformFamily)) {
                return $strategy;
            }
        }

        return ClassMetadata::GENERATOR_TYPE_IDENTITY;
    }

    public function setEntityManager(EntityManagerInterface $em): void
    {

        $this->em = $em;
    }

    /**
     * @param A $maybeOwningSide
     *
     * @return (A is ManyToManyAssociationMapping ? ManyToManyOwningSideMapping : (
     *     A is OneToOneAssociationMapping ? OneToOneOwningSideMapping : (
     *     A is OneToManyAssociationMapping ? ManyToOneAssociationMapping : (
     *     A is ManyToOneAssociationMapping ? ManyToOneAssociationMapping :
     *     ManyToManyOwningSideMapping|OneToOneOwningSideMapping|ManyToOneAssociationMapping
     * ))))
     *
     * @template A of AssociationMapping
     */
    final public function getOwningSide(AssociationMapping $maybeOwningSide): OwningSideMapping
    {
        if ($maybeOwningSide instanceof OwningSideMapping) {
            assert($maybeOwningSide instanceof ManyToManyOwningSideMapping ||
                $maybeOwningSide instanceof OneToOneOwningSideMapping ||
                $maybeOwningSide instanceof ManyToOneAssociationMapping);

            return $maybeOwningSide;
        }

        assert($maybeOwningSide instanceof InverseSideMapping);

        $owningSide = $this->getMetadataFor($maybeOwningSide->targetEntity)
            ->associationMappings[$maybeOwningSide->mappedBy];

        assert($owningSide instanceof ManyToManyOwningSideMapping ||
            $owningSide instanceof OneToOneOwningSideMapping ||
            $owningSide instanceof ManyToOneAssociationMapping);

        return $owningSide;
    }

    protected function initialize(): void
    {
        $this->driver          = $this->em->getConfiguration()->getMetadataDriverImpl();
        $this->eventDispatcher = $this->em->getEventManager();
        $this->initialized     = true;
    }

    protected function onNotFoundMetadata(string $className): ClassMetadata|null
    {
        $eventArgs = new OnClassMetadataNotFoundEventArgs($className, $this->em);

        $this->eventDispatcher->dispatchEvent(Events::onClassMetadataNotFound, $eventArgs);
        $classMetadata = $eventArgs->getFoundMetadata();
        assert($classMetadata instanceof ClassMetadata || $classMetadata === null);

        return $classMetadata;
    }

    /**
     * {@inheritDoc}
     */
    protected function doLoadMetadata(
        ClassMetadataInterface $class,
        ClassMetadataInterface|null $parent,
        bool $rootEntityFound,
        array $nonSuperclassParents,
    ): void {
        if ($parent !== null && ! $parent->isMappedSuperclass) {
            throw MappingException::entityInheritanceNotSupported($class->name);
        }
        if ($parent) {
            $class->setIdGeneratorType($parent->generatorType);
            $this->addInheritedFields($class, $parent);
            $this->addInheritedRelations($class, $parent);
            $this->addInheritedEmbeddedClasses($class, $parent);
            $class->setIdentifier($parent->identifier, $parent->identifierPositions);
            $class->setVersioned($parent->isVersioned);
            $class->setVersionField($parent->versionField);
            $class->setLifecycleCallbacks($parent->lifecycleCallbacks);
            $class->setChangeTrackingPolicy($parent->changeTrackingPolicy);

            if (! empty($parent->customGeneratorDefinition)) {
                $class->setCustomGeneratorDefinition($parent->customGeneratorDefinition);
            }

            if ($parent->isMappedSuperclass) {
                $class->setCustomRepositoryClass($parent->customRepositoryClassName);
            }
        }

        // Invoke driver
        try {
            $this->driver->loadMetadataForClass($class->getName(), $class);
        } catch (ReflectionException $e) {
            throw MappingException::reflectionFailure($class->getName(), $e);
        }

        $this->completeIdGeneratorMapping($class);

        if (! $class->isMappedSuperclass) {
            foreach ($class->embeddedClasses as $property => $embeddableClass) {
                if (isset($embeddableClass->inherited)) {
                    continue;
                }

                if (isset($this->embeddablesActiveNesting[$embeddableClass->class])) {
                    throw MappingException::infiniteEmbeddableNesting($class->name, $property);
                }

                $this->embeddablesActiveNesting[$class->name] = true;

                $embeddableMetadata = $this->getMetadataFor($embeddableClass->class);

                if ($embeddableMetadata->isEmbeddedClass) {
                    $this->addNestedEmbeddedClasses($embeddableMetadata, $class, $property);
                }

                $identifier = $embeddableMetadata->getIdentifier();

                if (! empty($identifier)) {
                    $this->inheritIdGeneratorMapping($class, $embeddableMetadata);
                }

                $class->inlineEmbeddable($property, $embeddableMetadata);

                unset($this->embeddablesActiveNesting[$class->name]);
            }
        }

        if ($parent) {
            $this->addInheritedIndexes($class, $parent);

            if ($parent->cache) {
                $class->cache = $parent->cache;
            }

            if ($parent->containsForeignIdentifier) {
                $class->containsForeignIdentifier = true;
            }

            if ($parent->containsEnumIdentifier) {
                $class->containsEnumIdentifier = true;
            }

            if (! empty($parent->entityListeners) && empty($class->entityListeners)) {
                $class->entityListeners = $parent->entityListeners;
            }
        }

        $class->setParentClasses($nonSuperclassParents);

        $this->eventDispatcher->dispatchEvent(
            Events::loadClassMetadata,
            new LoadClassMetadataEventArgs($class, $this->em),
        );

        $this->validateRuntimeMetadata($class, $parent);
    }

    /**
     * Validate runtime metadata is correctly defined.
     *
     * @throws MappingException
     */
    protected function validateRuntimeMetadata(ClassMetadata $class, ClassMetadataInterface|null $parent): void
    {
        $class->assertSupportedMapping();
        if (! $class->reflClass) {
            // only validate if there is a reflection class instance
            return;
        }

        $class->validateIdentifier();
        $class->validateAssociations();
        $class->validateLifecycleCallbacks($this->getReflectionService());
    }

    protected function newClassMetadataInstance(string $className): ClassMetadata
    {
        return new ClassMetadata(
            $className,
            $this->em->getConfiguration()->getNamingStrategy(),
            $this->em->getConfiguration()->getTypedFieldMapper(),
        );
    }

    /** @param class-string $className */
    private function peekIfIsMappedSuperclass(string $className): bool
    {
        $reflService = $this->getReflectionService();
        $class       = $this->newClassMetadataInstance($className);
        $this->initializeReflection($class, $reflService);

        $this->getDriver()->loadMetadataForClass($className, $class);

        return $class->isMappedSuperclass;
    }

    /**
     * Puts the `inherited` and `declared` values into mapping information for fields, associations
     * and embedded classes.
     */
    private function addMappingInheritanceInformation(
        AssociationMapping|EmbeddedClassMapping|FieldMapping $mapping,
        ClassMetadata $parentClass,
    ): void {
        if (! isset($mapping->declared)) {
            $mapping->declared = $parentClass->name;
        }
    }

    /**
     * Adds inherited fields to the subclass mapping.
     */
    private function addInheritedFields(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        foreach ($parentClass->fieldMappings as $mapping) {
            $subClassMapping = clone $mapping;
            $this->addMappingInheritanceInformation($subClassMapping, $parentClass);
            $subClass->addInheritedFieldMapping($subClassMapping);
        }

        foreach ($parentClass->propertyAccessors as $name => $field) {
            $subClass->propertyAccessors[$name] = $field;
        }
    }

    /**
     * Adds inherited association mappings to the subclass mapping.
     *
     * @throws MappingException
     */
    private function addInheritedRelations(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        foreach ($parentClass->associationMappings as $field => $mapping) {
            $subClassMapping = clone $mapping;
            $this->addMappingInheritanceInformation($subClassMapping, $parentClass);
            // When the class inheriting the relation ($subClass) is the first entity class since the
            // relation has been defined in a mapped superclass (or in a chain
            // of mapped superclasses) above, then declare this current entity class as the source of
            // the relationship.
            // According to the definitions given in https://github.com/doctrine/orm/pull/10396/,
            // this is the case <=> ! isset($mapping['inherited']).
            if (! isset($subClassMapping->inherited)) {
                $subClassMapping->sourceEntity = $subClass->name;
            }

            $subClass->addInheritedAssociationMapping($subClassMapping);
        }
    }

    private function addInheritedEmbeddedClasses(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        foreach ($parentClass->embeddedClasses as $field => $embeddedClass) {
            $subClassMapping = clone $embeddedClass;
            $this->addMappingInheritanceInformation($subClassMapping, $parentClass);
            $subClass->embeddedClasses[$field] = $subClassMapping;
        }
    }

    /**
     * Adds nested embedded classes metadata to a parent class.
     *
     * @param ClassMetadata $subClass    Sub embedded class metadata to add nested embedded classes metadata from.
     * @param ClassMetadata $parentClass Parent class to add nested embedded classes metadata to.
     * @param string        $prefix      Embedded classes' prefix to use for nested embedded classes field names.
     */
    private function addNestedEmbeddedClasses(
        ClassMetadata $subClass,
        ClassMetadata $parentClass,
        string $prefix,
    ): void {
        foreach ($subClass->embeddedClasses as $property => $embeddableClass) {
            if (isset($embeddableClass->inherited)) {
                continue;
            }

            $embeddableMetadata = $this->getMetadataFor($embeddableClass->class);

            $parentClass->mapEmbedded(
                [
                    'fieldName' => $prefix . '.' . $property,
                    'class' => $embeddableMetadata->name,
                    'columnPrefix' => $embeddableClass->columnPrefix,
                    'declaredField' => $embeddableClass->declaredField
                            ? $prefix . '.' . $embeddableClass->declaredField
                            : $prefix,
                    'originalField' => $embeddableClass->originalField ?: $property,
                ],
            );
        }
    }

    /**
     * Copy the table indices from the parent class superclass to the child class
     */
    private function addInheritedIndexes(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        if (! $parentClass->isMappedSuperclass) {
            return;
        }

        foreach (['uniqueConstraints', 'indexes'] as $indexType) {
            if (isset($parentClass->table[$indexType])) {
                foreach ($parentClass->table[$indexType] as $indexName => $index) {
                    if (is_int($indexName)) {
                        // Unnamed indices only have a positional key, so identify them by
                        // their columns: the inheriting table overrides such an index by
                        // declaring one on the same columns
                        if (! self::hasIndexOnSameColumns($subClass->table[$indexType] ?? [], $index)) {
                            $subClass->table[$indexType][] = $index;
                        }

                        continue;
                    }

                    if (isset($subClass->table[$indexType][$indexName])) {
                        continue; // Let the inheriting table override indices
                    }

                    $subClass->table[$indexType][$indexName] = $index;
                }
            }
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $indexes
     * @param array<string, mixed>                    $index
     */
    private static function hasIndexOnSameColumns(array $indexes, array $index): bool
    {
        foreach ($indexes as $existingIndex) {
            if (
                ($existingIndex['columns'] ?? null) === ($index['columns'] ?? null)
                && ($existingIndex['fields'] ?? null) === ($index['fields'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Completes the ID generator mapping. If "auto" is specified we choose the generator
     * most appropriate for the targeted database platform.
     *
     * @throws ORMException
     */
    private function completeIdGeneratorMapping(ClassMetadata $class): void
    {
        $idGenType = $class->generatorType;
        if ($idGenType === ClassMetadata::GENERATOR_TYPE_AUTO) {
            $class->setIdGeneratorType($this->determineIdGeneratorStrategy($this->getTargetPlatform()));
        }

        // Create & assign an appropriate ID generator instance
        switch ($class->generatorType) {
            case ClassMetadata::GENERATOR_TYPE_IDENTITY:
                $sequenceName = null;
                $fieldName    = $class->identifier ? $class->getSingleIdentifierFieldName() : null;
                $platform     = $this->getTargetPlatform();

                $generator = $fieldName && $class->fieldMappings[$fieldName]->type === 'bigint'
                    ? new BigIntegerIdentityGenerator()
                    : new IdentityGenerator();

                $class->setIdGenerator($generator);

                break;

            case ClassMetadata::GENERATOR_TYPE_SEQUENCE:
                // If there is no sequence definition yet, create a default definition
                $definition = $class->sequenceGeneratorDefinition;

                if (! $definition) {
                    $fieldName    = $class->getSingleIdentifierFieldName();
                    $sequenceName = $class->getSequenceName($this->getTargetPlatform());
                    $quoted       = isset($class->fieldMappings[$fieldName]->quoted) || isset($class->table['quoted']);

                    $definition = [
                        'sequenceName'      => $sequenceName,
                        'allocationSize'    => 1,
                        'initialValue'      => 1,
                    ];

                    if ($quoted) {
                        $definition['quoted'] = true;
                    }

                    $class->setSequenceGeneratorDefinition($definition);
                }

                $sequenceGenerator = new SequenceGenerator(
                    $this->em->getConfiguration()->getQuoteStrategy()->getSequenceName($definition, $class, $this->getTargetPlatform()),
                    (int) $definition['allocationSize'],
                );
                $class->setIdGenerator($sequenceGenerator);
                break;

            case ClassMetadata::GENERATOR_TYPE_NONE:
                $class->setIdGenerator(new AssignedGenerator());
                break;

            case ClassMetadata::GENERATOR_TYPE_CUSTOM:
                $definition = $class->customGeneratorDefinition;
                if ($definition === null) {
                    throw InvalidCustomGenerator::onClassNotConfigured();
                }

                if (! class_exists($definition['class'])) {
                    throw InvalidCustomGenerator::onMissingClass($definition);
                }

                $class->setIdGenerator(new $definition['class']());
                break;

            default:
                throw UnknownGeneratorType::create($class->generatorType);
        }
    }

    /**
     * Inherits the ID generator mapping from a parent class.
     */
    private function inheritIdGeneratorMapping(ClassMetadata $class, ClassMetadata $parent): void
    {
        if ($parent->isIdGeneratorSequence()) {
            $class->setSequenceGeneratorDefinition($parent->sequenceGeneratorDefinition);
        }

        if ($parent->generatorType) {
            $class->setIdGeneratorType($parent->generatorType);
        }

        if ($parent->idGenerator ?? null) {
            $class->setIdGenerator($parent->idGenerator);
        }
    }

    protected function wakeupReflection(ClassMetadataInterface $class, ReflectionService $reflService): void
    {
        $class->assertSupportedMapping();
        $class->wakeupReflection($reflService);

        if (PHP_VERSION_ID < 80400) {
            return;
        }

        foreach ($class->propertyAccessors as $propertyAccessor) {
            $property = $propertyAccessor->getUnderlyingReflector();

            if ($property->isVirtual()) {
                throw MappingException::mappingVirtualPropertyNotAllowed($class->name, $property->getName());
            }
        }
    }

    protected function initializeReflection(ClassMetadataInterface $class, ReflectionService $reflService): void
    {
        $class->initializeReflection($reflService);
    }

    protected function getDriver(): MappingDriver
    {
        assert($this->driver !== null);

        return $this->driver;
    }

    protected function isEntity(ClassMetadataInterface $class): bool
    {
        return ! $class->isMappedSuperclass;
    }

    private function getTargetPlatform(): Platforms\AbstractPlatform
    {
        if (! $this->targetPlatform) {
            $this->targetPlatform = $this->em->getConnection()->getDatabasePlatform();
        }

        return $this->targetPlatform;
    }
}
