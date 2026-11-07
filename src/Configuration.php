<?php

declare(strict_types=1);

namespace ModernORM;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use ModernORM\Cache\CacheConfiguration;
use ModernORM\Exception\InvalidEntityRepository;
use ModernORM\Internal\Hydration\AbstractHydrator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ClassMetadataFactory;
use ModernORM\Mapping\DefaultEntityListenerResolver;
use ModernORM\Mapping\DefaultNamingStrategy;
use ModernORM\Mapping\DefaultQuoteStrategy;
use ModernORM\Mapping\EntityListenerResolver;
use ModernORM\Mapping\NamingStrategy;
use ModernORM\Mapping\QuoteStrategy;
use ModernORM\Mapping\TypedFieldMapper;
use ModernORM\Repository\DefaultRepositoryFactory;
use ModernORM\Repository\RepositoryFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use ModernORM\Mapping\Driver\AttributeDriver;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;

use function class_exists;
use function is_a;
use function method_exists;

/**
 * Configuration container for all configuration options of Doctrine.
 * It combines all configuration options from DBAL & ORM.
 *
 * Internal note: When adding a new configuration option just write a getter/setter pair.
 */
class Configuration extends \Doctrine\DBAL\Configuration
{
    /** @var mixed[] */
    protected array $attributes = [];

    /** @phpstan-var array<class-string<AbstractPlatform>, ClassMetadata::GENERATOR_TYPE_*> */
    private $identityGenerationPreferences = [];

    /** @phpstan-param array<class-string<AbstractPlatform>, ClassMetadata::GENERATOR_TYPE_*> $value */
    public function setIdentityGenerationPreferences(array $value): void
    {
        $this->identityGenerationPreferences = $value;
    }

    /** @phpstan-return array<class-string<AbstractPlatform>, ClassMetadata::GENERATOR_TYPE_*> $value */
    public function getIdentityGenerationPreferences(): array
    {
        return $this->identityGenerationPreferences;
    }

    public function getUseDbalEditorApi(): bool
    {
        return $this->attributes['use_dbal_editor_api'] ?? false;
    }

    /**
     * @internal
     */
    public function setUseDbalEditorApi(bool $useDbalEditorApi): void
    {
        /** @phpstan-ignore function.alreadyNarrowedType (This API is not always available) */
        if ($useDbalEditorApi && ! method_exists(Schema::class, 'edit')) {
            throw new LogicException('Using the DBAL editor API requires doctrine/dbal 4.5 or higher.');
        }

        $this->attributes['use_dbal_editor_api'] = $useDbalEditorApi;
    }

    /**
     * Sets the cache driver implementation that is used for metadata caching.
     *
     * @todo Force parameter to be a Closure to ensure lazy evaluation
     *       (as soon as a metadata cache is in effect, the driver never needs to initialize).
     */
    public function setMetadataDriverImpl(MappingDriver $driverImpl): void
    {
        if (! $driverImpl instanceof AttributeDriver) {
            throw new \InvalidArgumentException('ModernORM supports Attributes mapping only; use ModernORM\\Mapping\\Driver\\AttributeDriver.');
        }
        $this->attributes['metadataDriverImpl'] = $driverImpl;
    }

    /**
     * Sets the entity alias map.
     *
     * @phpstan-param array<string, string> $entityNamespaces
     */
    public function setEntityNamespaces(array $entityNamespaces): void
    {
        $this->attributes['entityNamespaces'] = $entityNamespaces;
    }

    /**
     * Retrieves the list of registered entity namespace aliases.
     *
     * @phpstan-return array<string, string>
     */
    public function getEntityNamespaces(): array
    {
        return $this->attributes['entityNamespaces'];
    }

    /**
     * Gets the cache driver implementation that is used for the mapping metadata.
     */
    public function getMetadataDriverImpl(): AttributeDriver|null
    {
        return $this->attributes['metadataDriverImpl'] ?? null;
    }

    /**
     * Gets the cache driver implementation that is used for the SQL entity result-plan cache.
     */
    public function getQueryCache(): CacheItemPoolInterface|null
    {
        return $this->attributes['queryCache'] ?? null;
    }

    /**
     * Sets the cache driver implementation that is used for the SQL entity result-plan cache.
     */
    public function setQueryCache(CacheItemPoolInterface $cache): void
    {
        $this->attributes['queryCache'] = $cache;
    }

    public function getHydrationCache(): CacheItemPoolInterface|null
    {
        return $this->attributes['hydrationCache'] ?? null;
    }

    public function setHydrationCache(CacheItemPoolInterface $cache): void
    {
        $this->attributes['hydrationCache'] = $cache;
    }

    public function getMetadataCache(): CacheItemPoolInterface|null
    {
        return $this->attributes['metadataCache'] ?? null;
    }

    public function setMetadataCache(CacheItemPoolInterface $cache): void
    {
        $this->attributes['metadataCache'] = $cache;
    }

    /**
     * Sets a TypedFieldMapper for php typed fields to DBAL types auto-completion.
     */
    public function setTypedFieldMapper(TypedFieldMapper|null $typedFieldMapper): void
    {
        $this->attributes['typedFieldMapper'] = $typedFieldMapper;
    }

    /**
     * Gets a TypedFieldMapper for php typed fields to DBAL types auto-completion.
     */
    public function getTypedFieldMapper(): TypedFieldMapper|null
    {
        return $this->attributes['typedFieldMapper'] ?? null;
    }

    /**
     * Sets the custom hydrator modes in one pass.
     *
     * @param array<string, class-string<AbstractHydrator>> $modes An array of ($modeName => $hydrator).
     */
    public function setCustomHydrationModes(array $modes): void
    {
        $this->attributes['customHydrationModes'] = [];

        foreach ($modes as $modeName => $hydrator) {
            $this->addCustomHydrationMode($modeName, $hydrator);
        }
    }

    /**
     * Gets the hydrator class for the given hydration mode name.
     *
     * @return class-string<AbstractHydrator>|null
     */
    public function getCustomHydrationMode(string $modeName): string|null
    {
        return $this->attributes['customHydrationModes'][$modeName] ?? null;
    }

    /**
     * Adds a custom hydration mode.
     *
     * @param class-string<AbstractHydrator> $hydrator
     */
    public function addCustomHydrationMode(string $modeName, string $hydrator): void
    {
        $this->attributes['customHydrationModes'][$modeName] = $hydrator;
    }

    /**
     * Sets a class metadata factory.
     *
     * @param class-string $cmfName
     */
    public function setClassMetadataFactoryName(string $cmfName): void
    {
        $this->attributes['classMetadataFactoryName'] = $cmfName;
    }

    /** @return class-string */
    public function getClassMetadataFactoryName(): string
    {
        if (! isset($this->attributes['classMetadataFactoryName'])) {
            $this->attributes['classMetadataFactoryName'] = ClassMetadataFactory::class;
        }

        return $this->attributes['classMetadataFactoryName'];
    }

    /**
     * Sets default repository class.
     *
     * @param class-string<EntityRepository> $className
     *
     * @throws InvalidEntityRepository If $classname is not an ObjectRepository.
     */
    public function setDefaultRepositoryClassName(string $className): void
    {
        if (! class_exists($className) || ! is_a($className, EntityRepository::class, true)) {
            throw InvalidEntityRepository::fromClassName($className);
        }

        $this->attributes['defaultRepositoryClassName'] = $className;
    }

    /**
     * Get default repository class.
     *
     * @return class-string<EntityRepository>
     */
    public function getDefaultRepositoryClassName(): string
    {
        return $this->attributes['defaultRepositoryClassName'] ?? EntityRepository::class;
    }

    /**
     * Sets naming strategy.
     */
    public function setNamingStrategy(NamingStrategy $namingStrategy): void
    {
        $this->attributes['namingStrategy'] = $namingStrategy;
    }

    /**
     * Gets naming strategy..
     */
    public function getNamingStrategy(): NamingStrategy
    {
        if (! isset($this->attributes['namingStrategy'])) {
            $this->attributes['namingStrategy'] = new DefaultNamingStrategy();
        }

        return $this->attributes['namingStrategy'];
    }

    /**
     * Sets quote strategy.
     */
    public function setQuoteStrategy(QuoteStrategy $quoteStrategy): void
    {
        $this->attributes['quoteStrategy'] = $quoteStrategy;
    }

    /**
     * Gets quote strategy.
     */
    public function getQuoteStrategy(): QuoteStrategy
    {
        if (! isset($this->attributes['quoteStrategy'])) {
            $this->attributes['quoteStrategy'] = new DefaultQuoteStrategy();
        }

        return $this->attributes['quoteStrategy'];
    }

    /**
     * Set the entity listener resolver.
     */
    public function setEntityListenerResolver(EntityListenerResolver $resolver): void
    {
        $this->attributes['entityListenerResolver'] = $resolver;
    }

    /**
     * Get the entity listener resolver.
     */
    public function getEntityListenerResolver(): EntityListenerResolver
    {
        if (! isset($this->attributes['entityListenerResolver'])) {
            $this->attributes['entityListenerResolver'] = new DefaultEntityListenerResolver();
        }

        return $this->attributes['entityListenerResolver'];
    }

    /**
     * Set the entity repository factory.
     */
    public function setRepositoryFactory(RepositoryFactory $repositoryFactory): void
    {
        $this->attributes['repositoryFactory'] = $repositoryFactory;
    }

    /**
     * Get the entity repository factory.
     */
    public function getRepositoryFactory(): RepositoryFactory
    {
        return $this->attributes['repositoryFactory'] ?? new DefaultRepositoryFactory();
    }

    public function isSecondLevelCacheEnabled(): bool
    {
        return $this->attributes['isSecondLevelCacheEnabled'] ?? false;
    }

    public function setSecondLevelCacheEnabled(bool $flag = true): void
    {
        $this->attributes['isSecondLevelCacheEnabled'] = $flag;
    }

    public function setSecondLevelCacheConfiguration(CacheConfiguration $cacheConfig): void
    {
        $this->attributes['secondLevelCacheConfiguration'] = $cacheConfig;
    }

    public function getSecondLevelCacheConfiguration(): CacheConfiguration|null
    {
        return $this->attributes['secondLevelCacheConfiguration'] ?? null;
    }

    /** Configure and enable entity L2 cache with an explicit PSR-6 pool. */
    public function setSecondLevelCache(CacheItemPoolInterface $pool, string $namespace = 'modernorm', ?\ModernORM\Cache\Logging\CacheLogger $logger = null): void
    {
        $this->setSecondLevelCacheConfiguration(new CacheConfiguration($pool, $namespace, $logger));
        $this->setSecondLevelCacheEnabled();
    }

    /**
     * Returns query hints, which will be applied to every query in application
     *
     * @phpstan-return array<string, mixed>
     */
    public function getDefaultQueryHints(): array
    {
        return $this->attributes['defaultQueryHints'] ?? [];
    }

    /**
     * Sets array of query hints, which will be applied to every query in application
     *
     * @phpstan-param array<string, mixed> $defaultQueryHints
     */
    public function setDefaultQueryHints(array $defaultQueryHints): void
    {
        $this->attributes['defaultQueryHints'] = $defaultQueryHints;
    }

    /**
     * Gets the value of a default query hint. If the hint name is not recognized, FALSE is returned.
     *
     * @return mixed The value of the hint or FALSE, if the hint name is not recognized.
     */
    public function getDefaultQueryHint(string $name): mixed
    {
        return $this->attributes['defaultQueryHints'][$name] ?? false;
    }

    /**
     * Sets a default query hint. If the hint name is not recognized, it is silently ignored.
     */
    public function setDefaultQueryHint(string $name, mixed $value): void
    {
        $this->attributes['defaultQueryHints'][$name] = $value;
    }

    /**
     * Gets a list of entity class names to be ignored by the SchemaTool
     *
     * @return list<class-string>
     */
    public function getSchemaIgnoreClasses(): array
    {
        return $this->attributes['schemaIgnoreClasses'] ?? [];
    }

    /**
     * Sets a list of entity class names to be ignored by the SchemaTool
     *
     * @param list<class-string> $schemaIgnoreClasses List of entity class names
     */
    public function setSchemaIgnoreClasses(array $schemaIgnoreClasses): void
    {
        $this->attributes['schemaIgnoreClasses'] = $schemaIgnoreClasses;
    }

    /** Opt-in lazy ManyToOne, OneToMany, and ManyToMany batching. One preserves single-row loading. */
    public function setLazyFetchBatchSize(int $batchSize): void
    {
        if ($batchSize < 1 || $batchSize > 65535) {
            throw new \InvalidArgumentException('Lazy fetch batch size must be between 1 and 65535.');
        }
        $this->attributes['lazyFetchBatchSize'] = $batchSize;
    }

    public function getLazyFetchBatchSize(): int
    {
        return $this->attributes['lazyFetchBatchSize'] ?? 1;
    }

    public function setEagerFetchBatchSize(int $batchSize = 100): void
    {
        $this->attributes['fetchModeSubselectBatchSize'] = $batchSize;
    }

    public function getEagerFetchBatchSize(): int
    {
        return $this->attributes['fetchModeSubselectBatchSize'] ?? 100;
    }

    public function setDefaultStringTypeSchemaLength(int $length): void
    {
        $this->attributes['defaultStringTypeSchemaLength'] = $length;
    }

    public function getDefaultStringTypeSchemaLength(): int
    {
        return $this->attributes['defaultStringTypeSchemaLength'] ?? 255;
    }
}
