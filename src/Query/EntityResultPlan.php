<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Query;

use ModernORM\EntityManagerInterface;
use ModernORM\Exception\MappingException;
use ModernORM\Utility\PersisterHelper;

/** Serializable projection of one concrete entity table; MappedSuperclass fields are flattened. */
final class EntityResultPlan
{
    public array $select = [];
    public array $columns = [];
    public array $fields = [];
    public array $meta = [];
    public string $table;

    public static function forEntity(EntityManagerInterface $em, string $class, string $alias): self
    {
        if (strlen($alias) > 40 || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $alias) || str_contains($alias, '__orm')) {
            throw new \InvalidArgumentException('Invalid or reserved SQL entity alias.');
        }
        $metadata = $em->getClassMetadata($class);
        $metadata->assertSupportedMapping();
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
            throw new MappingException('SQL entity results require a concrete Entity, not MappedSuperclass or Embeddable.');
        }
        $cache = $em->getConfiguration()->getQueryCache();
        $item = $cache?->getItem('sql_entity_v3_' . hash('sha256', $class . ':' . $alias));
        if ($item?->isHit() && $item->get() instanceof self) {
            return $item->get();
        }
        $plan = new self();
        $platform = $em->getConnection()->getDatabasePlatform();
        $quote = $em->getConfiguration()->getQuoteStrategy();
        $plan->table = $quote->getTableName($metadata, $platform);
        foreach ($metadata->fieldMappings as $field => $fieldMapping) {
            $expression = $alias . '.' . $quote->getColumnName($field, $metadata, $platform);
            $column = $alias . '__f' . count($plan->fields);
            $type = \Doctrine\DBAL\Types\Type::getType($fieldMapping->type);
            $plan->select[] = $type->convertToPHPValueSQL($expression, $platform) . ' AS ' . $column;
            $plan->fields[] = [$column, $field, $metadata->name, $fieldMapping->enumType];
            $plan->columns[$field] = $expression;
        }
        foreach ($metadata->associationMappings as $association) {
            if (! $association->isToOneOwningSide()) {
                continue;
            }
            foreach ($association->joinColumns as $joinColumn) {
                $column = $alias . '__m' . count($plan->meta);
                $plan->select[] = $alias . '.' . $quote->getJoinColumnName($joinColumn, $metadata, $platform) . ' AS ' . $column;
                $target = $em->getClassMetadata($association->targetEntity);
                $plan->meta[] = [$column, $joinColumn->name, (bool) ($association->id ?? false), PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $target, $em)];
            }
        }
        if ($item !== null) {
            $cache->save($item->set($plan));
        }
        return $plan;
    }

    public function apply(ResultSetMapping $mapping, string $class, string $alias, ?string $parent = null, ?string $relation = null): void
    {
        if ($parent === null) {
            $mapping->addEntityResult($class, $alias);
        } else {
            $mapping->addJoinedEntityResult($class, $alias, $parent, $relation);
        }
        foreach ($this->fields as [$column, $field, $owner, $enum]) {
            $mapping->addFieldResult($alias, $column, $field, $owner);
            if ($enum !== null) {
                $mapping->addEnumResult($column, $enum);
            }
        }
        foreach ($this->meta as [$column, $field, $identifier, $type]) {
            $mapping->addMetaResult($alias, $column, $field, $identifier, $type);
        }
    }
}
