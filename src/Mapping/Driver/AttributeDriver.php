<?php

declare(strict_types=1);

namespace ModernORM\Mapping\Driver;

use ModernORM\Events;
use ModernORM\Behavior\BlameableInterface;
use ModernORM\Mapping;
use ModernORM\Mapping\Builder\EntityListenerBuilder;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Exception\MappingException;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\Mapping\Driver\ClassLocator;
use Doctrine\Persistence\Mapping\Driver\ColocatedMappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

use function assert;
use function class_exists;
use function constant;
use function defined;
use function sprintf;

class AttributeDriver implements MappingDriver
{
    use ColocatedMappingDriver;
    use ReflectionBasedDriver;

    private const ENTITY_ATTRIBUTE_CLASSES = [
        Mapping\Entity::class => 1,
        Mapping\MappedSuperclass::class => 2,
    ];

    private readonly AttributeReader $reader;

    /**
     * @param string[]|ClassLocator $paths                     a ClassLocator, or an array of directories.
     * @param true                  $reportFieldsWhereDeclared no-op, to be removed in 4.0
     */
    public function __construct(array|ClassLocator $paths, bool $reportFieldsWhereDeclared = true)
    {
        if (! $reportFieldsWhereDeclared) {
            throw new InvalidArgumentException(sprintf(
                'The $reportFieldsWhereDeclared argument is no longer supported, make sure to omit it when calling %s.',
                __METHOD__,
            ));
        }

        $this->reader = new AttributeReader();

        if ($paths instanceof ClassLocator) {
            $this->classLocator = $paths;
        } else {
            $this->addPaths($paths);
        }
    }

    public function isTransient(string $className): bool
    {
        $classAttributes = $this->reader->getClassAttributes(new ReflectionClass($className));

        foreach ($classAttributes as $a) {
            $attr = $a instanceof RepeatableAttributeCollection ? $a[0] : $a;
            if (isset(self::ENTITY_ATTRIBUTE_CLASSES[$attr::class])) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param class-string<T>  $className
     * @param ClassMetadata<T> $metadata
     *
     * @template T of object
     */
    public function loadMetadataForClass(string $className, PersistenceClassMetadata $metadata): void
    {
        $reflectionClass = $metadata->getReflectionClass()
            // this happens when running attribute driver in combination with
            // static reflection services. This is not the nicest fix
            ?? new ReflectionClass($metadata->name);

        $classAttributes = $this->reader->getClassAttributes($reflectionClass);

        // Evaluate Entity attribute
        if (isset($classAttributes[Mapping\Entity::class])) {
            $entityAttribute = $classAttributes[Mapping\Entity::class];
            if ($entityAttribute->repositoryClass !== null) {
                $metadata->setCustomRepositoryClass($entityAttribute->repositoryClass);
            }

            if ($entityAttribute->readOnly) {
                $metadata->markReadOnly();
            }
        } elseif (isset($classAttributes[Mapping\MappedSuperclass::class])) {
            $mappedSuperclassAttribute = $classAttributes[Mapping\MappedSuperclass::class];

            $metadata->setCustomRepositoryClass($mappedSuperclassAttribute->repositoryClass);
            $metadata->isMappedSuperclass = true;
        } elseif (isset($classAttributes[Mapping\Embeddable::class])) {
            $metadata->isEmbeddedClass = true;
        } else {
            throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }

        if (isset($classAttributes[Mapping\Immutable::class])) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                throw new MappingException('Immutable must be applied to a concrete Entity: ' . $className);
            }
            $metadata->markReadOnly();
        }

        if (isset($classAttributes[Mapping\SoftDelete::class])) {
            $metadata->softDeleteField = 'deletedAt';
        }

        $primaryTable = [];

        if (isset($classAttributes[Mapping\Table::class])) {
            $tableAnnot             = $classAttributes[Mapping\Table::class];
            $primaryTable['name']   = $tableAnnot->name;
            $primaryTable['schema'] = $tableAnnot->schema;

            if ($tableAnnot->options) {
                $primaryTable['options'] = $tableAnnot->options;
            }
        }

        if (isset($classAttributes[Mapping\Index::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\Index::class);
            }

            foreach ($classAttributes[Mapping\Index::class] as $idx => $indexAnnot) {
                $index = [];

                if (! empty($indexAnnot->columns)) {
                    $index['columns'] = $indexAnnot->columns;
                }

                if (! empty($indexAnnot->fields)) {
                    $index['fields'] = $indexAnnot->fields;
                }

                if (
                    isset($index['columns'], $index['fields'])
                    || (
                        ! isset($index['columns'])
                        && ! isset($index['fields'])
                    )
                ) {
                    throw MappingException::invalidIndexConfiguration(
                        $className,
                        (string) ($indexAnnot->name ?? $idx),
                    );
                }

                if (! empty($indexAnnot->flags)) {
                    $index['flags'] = $indexAnnot->flags;
                }

                if (! empty($indexAnnot->options)) {
                    $index['options'] = $indexAnnot->options;
                }

                if (! empty($indexAnnot->name)) {
                    $primaryTable['indexes'][$indexAnnot->name] = $index;
                } else {
                    $primaryTable['indexes'][] = $index;
                }
            }
        }

        if (isset($classAttributes[Mapping\UniqueConstraint::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\UniqueConstraint::class);
            }

            foreach ($classAttributes[Mapping\UniqueConstraint::class] as $idx => $uniqueConstraintAnnot) {
                $uniqueConstraint = [];

                if (! empty($uniqueConstraintAnnot->columns)) {
                    $uniqueConstraint['columns'] = $uniqueConstraintAnnot->columns;
                }

                if (! empty($uniqueConstraintAnnot->fields)) {
                    $uniqueConstraint['fields'] = $uniqueConstraintAnnot->fields;
                }

                if (
                    isset($uniqueConstraint['columns'], $uniqueConstraint['fields'])
                    || (
                        ! isset($uniqueConstraint['columns'])
                        && ! isset($uniqueConstraint['fields'])
                    )
                ) {
                    throw MappingException::invalidUniqueConstraintConfiguration(
                        $className,
                        (string) ($uniqueConstraintAnnot->name ?? $idx),
                    );
                }

                if (! empty($uniqueConstraintAnnot->options)) {
                    $uniqueConstraint['options'] = $uniqueConstraintAnnot->options;
                }

                if (! empty($uniqueConstraintAnnot->name)) {
                    $primaryTable['uniqueConstraints'][$uniqueConstraintAnnot->name] = $uniqueConstraint;
                } else {
                    $primaryTable['uniqueConstraints'][] = $uniqueConstraint;
                }
            }
        }

        $metadata->setPrimaryTable($primaryTable);

        if (isset($classAttributes[Mapping\Cacheable::class])) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                throw new MappingException('Cacheable must be applied to a concrete Entity: ' . $className);
            }
            $metadata->enableCache($classAttributes[Mapping\Cacheable::class]->ttl, $classAttributes[Mapping\Cacheable::class]->writePolicy);
        }

        foreach ([Mapping\InheritanceType::class, Mapping\DiscriminatorColumn::class, Mapping\DiscriminatorMap::class] as $unsupported) {
            if (isset($classAttributes[$unsupported])) {
                throw MappingException::entityInheritanceNotSupported($metadata->name);
            }
        }

        // Evaluate DoctrineChangeTrackingPolicy attribute
        if (isset($classAttributes[Mapping\ChangeTrackingPolicy::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\ChangeTrackingPolicy::class);
            }

            $changeTrackingAttribute = $classAttributes[Mapping\ChangeTrackingPolicy::class];
            $metadata->setChangeTrackingPolicy(constant('ModernORM\Mapping\ClassMetadata::CHANGETRACKING_' . $changeTrackingAttribute->value));
        }

        foreach ($reflectionClass->getProperties() as $property) {
            if ($this->isRepeatedPropertyDeclaration($property, $metadata)) {
                continue;
            }

            $mapping              = [];
            $mapping['fieldName'] = $property->name;

            // Read repeatable JoinColumn attributes
            $joinColumns = [];

            $joinColumnAttributes = $this->reader->getPropertyAttributeCollection($property, Mapping\JoinColumn::class);

            foreach ($joinColumnAttributes as $joinColumnAttribute) {
                $joinColumns[] = $this->joinColumnToArray($joinColumnAttribute);
            }

            // Field can only be attributed with one of:
            // Column, OneToOne, OneToMany, ManyToOne, ManyToMany, Embedded
            $columnAttribute     = $this->reader->getPropertyAttribute($property, Mapping\Column::class);
            $oneToOneAttribute   = $this->reader->getPropertyAttribute($property, Mapping\OneToOne::class);
            $oneToManyAttribute  = $this->reader->getPropertyAttribute($property, Mapping\OneToMany::class);
            $manyToOneAttribute  = $this->reader->getPropertyAttribute($property, Mapping\ManyToOne::class);
            $manyToManyAttribute = $this->reader->getPropertyAttribute($property, Mapping\ManyToMany::class);
            $embeddedAttribute   = $this->reader->getPropertyAttribute($property, Mapping\Embedded::class);

            if ($columnAttribute !== null) {
                $mapping = $this->columnToArray($property->name, $columnAttribute);

                $idAttribute = $this->reader->getPropertyAttribute($property, Mapping\Id::class);
                if ($idAttribute !== null) {
                    $mapping['id']         = true;
                    $mapping['idPosition'] = $idAttribute->position;
                }

                $generatedValueAttribute = $this->reader->getPropertyAttribute($property, Mapping\GeneratedValue::class);

                if ($generatedValueAttribute !== null) {
                    $metadata->setIdGeneratorType(constant('ModernORM\Mapping\ClassMetadata::GENERATOR_TYPE_' . $generatedValueAttribute->strategy));
                }

                if ($this->reader->getPropertyAttribute($property, Mapping\Version::class)) {
                    $metadata->setVersionMapping($mapping);
                }

                $metadata->mapField($mapping);

                // Check for SequenceGenerator/TableGenerator definition
                $seqGeneratorAttribute    = $this->reader->getPropertyAttribute($property, Mapping\SequenceGenerator::class);
                $customGeneratorAttribute = $this->reader->getPropertyAttribute($property, Mapping\CustomIdGenerator::class);

                if ($seqGeneratorAttribute !== null) {
                    $metadata->setSequenceGeneratorDefinition(
                        [
                            'sequenceName' => $seqGeneratorAttribute->sequenceName,
                            'allocationSize' => $seqGeneratorAttribute->allocationSize,
                            'initialValue' => $seqGeneratorAttribute->initialValue,
                        ],
                    );
                } elseif ($customGeneratorAttribute !== null) {
                    $metadata->setCustomGeneratorDefinition(
                        [
                            'class' => $customGeneratorAttribute->class,
                        ],
                    );
                }
            } elseif ($oneToOneAttribute !== null) {
                if ($metadata->isEmbeddedClass) {
                    throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\OneToOne::class);
                }

                $idAttribute = $this->reader->getPropertyAttribute($property, Mapping\Id::class);
                if ($idAttribute !== null) {
                    $mapping['id']         = true;
                    $mapping['idPosition'] = $idAttribute->position;
                }

                $mapping['targetEntity']  = $oneToOneAttribute->targetEntity;
                $mapping['joinColumns']   = $joinColumns;
                $mapping['mappedBy']      = $oneToOneAttribute->mappedBy;
                $mapping['inversedBy']    = $oneToOneAttribute->inversedBy;
                $mapping['cascade']       = $oneToOneAttribute->cascade;
                $mapping['orphanRemoval'] = $oneToOneAttribute->orphanRemoval;
                $mapping['fetch']         = $this->getFetchMode($className, $oneToOneAttribute->fetch);
                $metadata->mapOneToOne($mapping);
            } elseif ($oneToManyAttribute !== null) {
                if ($metadata->isEmbeddedClass) {
                    throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\OneToMany::class);
                }

                $mapping['mappedBy']      = $oneToManyAttribute->mappedBy;
                $mapping['targetEntity']  = $oneToManyAttribute->targetEntity;
                $mapping['cascade']       = $oneToManyAttribute->cascade;
                $mapping['indexBy']       = $oneToManyAttribute->indexBy;
                $mapping['orphanRemoval'] = $oneToManyAttribute->orphanRemoval;
                $mapping['fetch']         = $this->getFetchMode($className, $oneToManyAttribute->fetch);
                $mapping['batchSize']     = $oneToManyAttribute->batchSize;

                $orderByAttribute = $this->reader->getPropertyAttribute($property, Mapping\OrderBy::class);

                if ($orderByAttribute !== null) {
                    $mapping['orderBy'] = $orderByAttribute->value;
                }

                $metadata->mapOneToMany($mapping);
            } elseif ($manyToOneAttribute !== null) {
                if ($metadata->isEmbeddedClass) {
                    throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\ManyToOne::class);
                }

                $idAttribute = $this->reader->getPropertyAttribute($property, Mapping\Id::class);

                if ($idAttribute !== null) {
                    $mapping['id']         = true;
                    $mapping['idPosition'] = $idAttribute->position;
                }

                $mapping['joinColumns']  = $joinColumns;
                $mapping['cascade']      = $manyToOneAttribute->cascade;
                $mapping['inversedBy']   = $manyToOneAttribute->inversedBy;
                $mapping['targetEntity'] = $manyToOneAttribute->targetEntity;
                $mapping['fetch']        = $this->getFetchMode($className, $manyToOneAttribute->fetch);
                $mapping['batchSize']    = $manyToOneAttribute->batchSize;
                $metadata->mapManyToOne($mapping);
            } elseif ($manyToManyAttribute !== null) {
                if ($metadata->isEmbeddedClass) {
                    throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\ManyToMany::class);
                }

                $joinTable          = [];
                $joinTableAttribute = $this->reader->getPropertyAttribute($property, Mapping\JoinTable::class);

                if ($joinTableAttribute !== null) {
                    $joinTable = [
                        'name' => $joinTableAttribute->name,
                        'schema' => $joinTableAttribute->schema,
                    ];

                    if ($joinTableAttribute->options) {
                        $joinTable['options'] = $joinTableAttribute->options;
                    }

                    if ($joinTableAttribute->foreignKeyName !== null) {
                        $joinTable['foreignKeyName'] = $joinTableAttribute->foreignKeyName;
                    }

                    if ($joinTableAttribute->inverseForeignKeyName !== null) {
                        $joinTable['inverseForeignKeyName'] = $joinTableAttribute->inverseForeignKeyName;
                    }

                    foreach ($joinTableAttribute->joinColumns as $joinColumn) {
                        $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumn);
                    }

                    foreach ($joinTableAttribute->inverseJoinColumns as $joinColumn) {
                        $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumn);
                    }
                }

                foreach ($this->reader->getPropertyAttributeCollection($property, Mapping\JoinColumn::class) as $joinColumn) {
                    $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumn);
                }

                foreach ($this->reader->getPropertyAttributeCollection($property, Mapping\InverseJoinColumn::class) as $joinColumn) {
                    $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumn);
                }

                $mapping['joinTable']     = $joinTable;
                $mapping['targetEntity']  = $manyToManyAttribute->targetEntity;
                $mapping['mappedBy']      = $manyToManyAttribute->mappedBy;
                $mapping['inversedBy']    = $manyToManyAttribute->inversedBy;
                $mapping['cascade']       = $manyToManyAttribute->cascade;
                $mapping['indexBy']       = $manyToManyAttribute->indexBy;
                $mapping['orphanRemoval'] = $manyToManyAttribute->orphanRemoval;
                $mapping['fetch']         = $this->getFetchMode($className, $manyToManyAttribute->fetch);
                $mapping['batchSize']     = $manyToManyAttribute->batchSize;

                $orderByAttribute = $this->reader->getPropertyAttribute($property, Mapping\OrderBy::class);

                if ($orderByAttribute !== null) {
                    $mapping['orderBy'] = $orderByAttribute->value;
                }

                $metadata->mapManyToMany($mapping);
            } elseif ($embeddedAttribute !== null) {
                $mapping['class']        = $embeddedAttribute->class;
                $mapping['columnPrefix'] = $embeddedAttribute->columnPrefix;

                $metadata->mapEmbedded($mapping);
            }
        }

        if (isset($classAttributes[Mapping\Blameable::class])) {
            $this->mapBlameable($metadata, $reflectionClass, $classAttributes[Mapping\Blameable::class]);
        }

        // Evaluate AssociationOverrides attribute
        if (isset($classAttributes[Mapping\AssociationOverrides::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\AssociationOverride::class);
            }

            $associationOverride = $classAttributes[Mapping\AssociationOverrides::class];

            foreach ($associationOverride->overrides as $associationOverride) {
                $override  = [];
                $fieldName = $associationOverride->name;

                // Read repeatable JoinColumn attributes
                if ($associationOverride->joinColumns) {
                    $joinColumns = [];

                    foreach ($associationOverride->joinColumns as $joinColumn) {
                        $joinColumns[] = $this->joinColumnToArray($joinColumn);
                    }

                    $override['joinColumns'] = $joinColumns;
                }

                if ($associationOverride->inverseJoinColumns) {
                    $joinColumns = [];

                    foreach ($associationOverride->inverseJoinColumns as $joinColumn) {
                        $joinColumns[] = $this->joinColumnToArray($joinColumn);
                    }

                    $override['inverseJoinColumns'] = $joinColumns;
                }

                // Check for JoinTable attributes
                if ($associationOverride->joinTable) {
                    $joinTableAnnot = $associationOverride->joinTable;
                    $joinTable      = [
                        'name'      => $joinTableAnnot->name,
                        'schema'    => $joinTableAnnot->schema,
                        'joinColumns' => $override['joinColumns'] ?? [],
                        'inverseJoinColumns' => $override['inverseJoinColumns'] ?? [],
                    ];

                    unset($override['joinColumns'], $override['inverseJoinColumns']);

                    $override['joinTable'] = $joinTable;
                }

                // Check for inversedBy
                if ($associationOverride->inversedBy) {
                    $override['inversedBy'] = $associationOverride->inversedBy;
                }

                // Check for `fetch`
                if ($associationOverride->fetch) {
                    $override['fetch'] = constant(ClassMetadata::class . '::FETCH_' . $associationOverride->fetch);
                }

                $metadata->setAssociationOverride($fieldName, $override);
            }
        }

        // Evaluate AttributeOverrides attribute
        if (isset($classAttributes[Mapping\AttributeOverrides::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\AttributeOverrides::class);
            }

            $attributeOverridesAnnot = $classAttributes[Mapping\AttributeOverrides::class];

            foreach ($attributeOverridesAnnot->overrides as $attributeOverride) {
                $mapping = $this->columnToArray($attributeOverride->name, $attributeOverride->column);

                $metadata->setAttributeOverride($attributeOverride->name, $mapping);
            }
        }

        // Evaluate EntityListeners attribute
        if (isset($classAttributes[Mapping\EntityListeners::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\EntityListeners::class);
            }

            $entityListenersAttribute = $classAttributes[Mapping\EntityListeners::class];

            foreach ($entityListenersAttribute->value as $item) {
                $listenerClassName = $metadata->fullyQualifiedClassName($item);

                if (! class_exists($listenerClassName)) {
                    throw MappingException::entityListenerClassNotFound($listenerClassName, $className);
                }

                $hasMapping    = false;
                $listenerClass = new ReflectionClass($listenerClassName);

                foreach ($listenerClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    // find method callbacks.
                    $callbacks  = $this->getMethodCallbacks($method);
                    $hasMapping = $hasMapping ?: ! empty($callbacks);

                    foreach ($callbacks as $value) {
                        $metadata->addEntityListener($value[1], $listenerClassName, $value[0]);
                    }
                }

                // Evaluate the listener using naming convention.
                if (! $hasMapping) {
                    EntityListenerBuilder::bindEntityListener($metadata, $listenerClassName);
                }
            }
        }

        // Evaluate #[HasLifecycleCallbacks] attribute
        if (isset($classAttributes[Mapping\HasLifecycleCallbacks::class])) {
            if ($metadata->isEmbeddedClass) {
                throw MappingException::invalidAttributeOnEmbeddable($metadata->name, Mapping\HasLifecycleCallbacks::class);
            }

            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($this->getMethodCallbacks($method) as $value) {
                    $metadata->addLifecycleCallback($value[0], $value[1]);
                }
            }
        }
    }

    /**
     * Attempts to resolve the fetch mode.
     *
     * @param class-string $className The class name.
     * @param string       $fetchMode The fetch mode.
     *
     * @return ClassMetadata::FETCH_* The fetch mode as defined in ClassMetadata.
     *
     * @throws MappingException If the fetch mode is not valid.
     */
    private function getFetchMode(string $className, string $fetchMode): int
    {
        if (! defined('ModernORM\Mapping\ClassMetadata::FETCH_' . $fetchMode)) {
            throw MappingException::invalidFetchMode($className, $fetchMode);
        }

        return constant('ModernORM\Mapping\ClassMetadata::FETCH_' . $fetchMode);
    }

    /**
     * Attempts to resolve the generated mode.
     *
     * @throws MappingException If the fetch mode is not valid.
     */
    private function getGeneratedMode(string $generatedMode): int
    {
        if (! defined('ModernORM\Mapping\ClassMetadata::GENERATED_' . $generatedMode)) {
            throw MappingException::invalidGeneratedMode($generatedMode);
        }

        return constant('ModernORM\Mapping\ClassMetadata::GENERATED_' . $generatedMode);
    }

    /**
     * Parses the given method.
     *
     * @return list<array{string, string}>
     * @phpstan-return list<array{string, (Events::*)}>
     */
    private function getMethodCallbacks(ReflectionMethod $method): array
    {
        $callbacks  = [];
        $attributes = $this->reader->getMethodAttributes($method);

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Mapping\PrePersist) {
                $callbacks[] = [$method->name, Events::prePersist];
            }

            if ($attribute instanceof Mapping\PostPersist) {
                $callbacks[] = [$method->name, Events::postPersist];
            }

            if ($attribute instanceof Mapping\PreUpdate) {
                $callbacks[] = [$method->name, Events::preUpdate];
            }

            if ($attribute instanceof Mapping\PostUpdate) {
                $callbacks[] = [$method->name, Events::postUpdate];
            }

            if ($attribute instanceof Mapping\PreRemove) {
                $callbacks[] = [$method->name, Events::preRemove];
            }

            if ($attribute instanceof Mapping\PostRemove) {
                $callbacks[] = [$method->name, Events::postRemove];
            }

            if ($attribute instanceof Mapping\PostLoad) {
                $callbacks[] = [$method->name, Events::postLoad];
            }

            if ($attribute instanceof Mapping\PreFlush) {
                $callbacks[] = [$method->name, Events::preFlush];
            }
        }

        return $callbacks;
    }

    /**
     * Parse the given JoinColumn as array
     *
     * @return mixed[]
     * @phpstan-return array{
     *                   name: string|null,
     *                   unique: bool,
     *                   nullable: bool,
     *                   onDelete: mixed,
     *                   columnDefinition: string|null,
     *                   referencedColumnName: string,
     *                   foreignKeyName: string|null,
     *                   options?: array<string, mixed>
     *               }
     */
    private function joinColumnToArray(Mapping\JoinColumn|Mapping\InverseJoinColumn $joinColumn): array
    {
        $mapping = [
            'name' => $joinColumn->name,
            'deferrable' => $joinColumn->deferrable,
            'unique' => $joinColumn->unique,
            'nullable' => $joinColumn->nullable,
            'onDelete' => $joinColumn->onDelete,
            'columnDefinition' => $joinColumn->columnDefinition,
            'referencedColumnName' => $joinColumn->referencedColumnName,
            'foreignKeyName' => $joinColumn->foreignKeyName,
        ];

        if ($joinColumn->options) {
            $mapping['options'] = $joinColumn->options;
        }

        return $mapping;
    }

    /**
     * @param ClassMetadata<T> $metadata
     * @param ReflectionClass<covariant T> $reflectionClass
     * @template T of object
     */
    private function mapBlameable(ClassMetadata $metadata, ReflectionClass $reflectionClass, Mapping\Blameable $attribute): void
    {
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
            throw new MappingException('Blameable must be applied to a concrete Entity: ' . $metadata->name);
        }
        if (! $reflectionClass->implementsInterface(BlameableInterface::class)) {
            throw new MappingException('Blameable requires BlameableInterface: ' . $metadata->name);
        }
        if (! class_exists($attribute->actorClass)) {
            throw new MappingException('Blameable actorClass must be a concrete Entity: ' . $attribute->actorClass);
        }
        $actor = new ReflectionClass($attribute->actorClass);
        $actorAttributes = $this->reader->getClassAttributes($actor);
        if ($actor->isAbstract() || ! isset($actorAttributes[Mapping\Entity::class])) {
            throw new MappingException('Blameable actorClass must be a concrete Entity: ' . $attribute->actorClass);
        }

        foreach (['createdBy', 'updatedBy'] as $field) {
            if (! $reflectionClass->hasProperty($field)) {
                throw new MappingException('Blameable requires an instance property: ' . $metadata->name . '::' . $field);
            }
            $property = $reflectionClass->getProperty($field);
            if ($property->isStatic() || $property->isReadOnly()) {
                throw new MappingException('Blameable requires a writable instance property: ' . $metadata->name . '::' . $field);
            }
            if (isset($metadata->fieldMappings[$field]) || isset($metadata->embeddedClasses[$field])) {
                throw new MappingException('Blameable property must be a ManyToOne association: ' . $metadata->name . '::' . $field);
            }
            if (isset($metadata->associationMappings[$field])) {
                $mapping = $metadata->associationMappings[$field];
                if (! $mapping instanceof Mapping\ManyToOneAssociationMapping || $mapping->targetEntity !== $actor->getName()) {
                    throw new MappingException('Blameable association must be ManyToOne targeting actorClass: ' . $metadata->name . '::' . $field);
                }
                continue;
            }
            $metadata->mapManyToOne([
                'fieldName' => $field,
                'targetEntity' => $actor->getName(),
                'joinColumns' => [['nullable' => true, 'onDelete' => 'SET NULL']],
            ]);
        }
    }

    /**
     * Parse the given Column as array
     *
     * @return mixed[]
     * @phpstan-return array{
     *                   fieldName: string,
     *                   type: mixed,
     *                   scale: int,
     *                   length: int,
     *                   unique: bool,
     *                   nullable: bool,
     *                   index: bool,
     *                   precision: int,
     *                   enumType?: class-string,
     *                   options?: mixed[],
     *                   columnName?: string,
     *                   columnDefinition?: string
     *               }
     */
    private function columnToArray(string $fieldName, Mapping\Column $column): array
    {
        $mapping = [
            'fieldName' => $fieldName,
            'type'      => $column->type,
            'scale'     => $column->scale,
            'length'    => $column->length,
            'unique'    => $column->unique,
            'nullable'  => $column->nullable,
            'index'     => $column->index,
            'precision' => $column->precision,
        ];

        if ($column->options) {
            $mapping['options'] = $column->options;
        }

        if (isset($column->name)) {
            $mapping['columnName'] = $column->name;
        }

        if (isset($column->columnDefinition)) {
            $mapping['columnDefinition'] = $column->columnDefinition;
        }

        if ($column->updatable === false) {
            $mapping['notUpdatable'] = true;
        }

        if ($column->insertable === false) {
            $mapping['notInsertable'] = true;
        }

        if ($column->generated !== null) {
            $mapping['generated'] = $this->getGeneratedMode($column->generated);
        }

        if ($column->enumType) {
            $mapping['enumType'] = $column->enumType;
        }

        return $mapping;
    }
}
