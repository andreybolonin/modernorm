<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use ModernORM\Exception\MappingException;

/** A PostgreSQL stored search vector and its GIN index, outside entity state. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class FullTextIndex implements MappingAttribute
{
    /** @param non-empty-array<string, 'A'|'B'|'C'|'D'> $fields Ordered PHP field names and weights. */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly string $configuration,
        public readonly string $column = 'search_vector',
    ) {
    }

    /** @internal Also validates metadata restored from a cache or changed programmatically. */
    public function validate(ClassMetadata $metadata): void
    {
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
            throw new MappingException('FullTextIndex requires a concrete Entity: ' . $metadata->name);
        }
        foreach ([$this->name, $this->column] as $name) {
            if (strlen($name) > 63 || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new MappingException('FullTextIndex names must be simple identifiers of at most 63 bytes.');
            }
        }
        if (! preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/D', $this->configuration)) {
            throw new MappingException('FullTextIndex configuration must be an explicit schema-qualified name, e.g. pg_catalog.russian.');
        }
        if ($this->fields === []) {
            throw new MappingException('FullTextIndex requires at least one text field.');
        }
        foreach ($this->fields as $field => $weight) {
            $mapping = $metadata->fieldMappings[$field] ?? null;
            if (! is_string($field) || $mapping === null || ! in_array($mapping->type, ['string', 'text'], true)
                || $mapping->enumType !== null || ! in_array($weight, ['A', 'B', 'C', 'D'], true)) {
                throw new MappingException('FullTextIndex fields must map string/text scalar properties with A/B/C/D weights: ' . $metadata->name);
            }
            if ($mapping->columnDefinition !== null) {
                throw new MappingException('FullTextIndex source fields cannot use a custom columnDefinition.');
            }
        }
        foreach ($metadata->fieldMappings as $mapping) {
            if (strcasecmp($this->column, $mapping->columnName) === 0) {
                throw new MappingException('FullTextIndex technical column collides with a mapped field: ' . $this->column);
            }
        }
        foreach ($metadata->associationMappings as $association) {
            if (! $association->isToOneOwningSide()) {
                continue;
            }
            foreach ($association->joinColumns as $joinColumn) {
                if (strcasecmp($this->column, $joinColumn->name) === 0) {
                    throw new MappingException('FullTextIndex technical column collides with a join column: ' . $this->column);
                }
            }
        }
        foreach (['indexes', 'uniqueConstraints'] as $kind) {
            foreach ($metadata->table[$kind] ?? [] as $name => $index) {
                if (is_string($name) && strcasecmp(trim($name, '`"'), $this->name) === 0) {
                    throw new MappingException('FullTextIndex name collides with a mapped index: ' . $this->name);
                }
                if (in_array($this->column, $index['columns'] ?? [], true)) {
                    throw new MappingException('FullTextIndex technical columns cannot be used by ordinary mapped indexes.');
                }
            }
        }
    }
}
