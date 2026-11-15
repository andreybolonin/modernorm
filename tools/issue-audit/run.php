<?php

declare(strict_types=1);

namespace ModernORM\IssueAudit;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Fixtures.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use ModernORM\EntityManager;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMappingBuilder;
use ModernORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new \UnexpectedValueException($message);
    }
}

$cases = [];
$cases['metadata_mapping_after_cache'] = [[12783, 11957], static function (EntityManager $em): void {
    $metadata = unserialize(serialize($em->getClassMetadata(Owner::class)));
    $metadata->wakeupReflection(new RuntimeReflectionService());
    $metadata->mapField(['fieldName' => 'extra', 'type' => 'string']);
}];
$cases['metadata_typed_mapping_after_cache'] = [[12783], static function (EntityManager $em): void {
    $metadata = unserialize(serialize($em->getClassMetadata(Owner::class)));
    $metadata->wakeupReflection(new RuntimeReflectionService());
    $metadata->mapField(['fieldName' => 'extra']);
}];
$cases['hooked_generated_identifier_delete'] = [[12077], static function (EntityManager $em): void {
    $entity = new HookedId();
    $em->persist($entity);
    $em->flush();
    $em->remove($entity);
    $em->flush();
}];
$cases['readonly_datetime_refresh'] = [[9505], static function (EntityManager $em): void {
    $entity = new ReadonlyDate();
    $em->persist($entity);
    $em->flush();
    $em->refresh($entity);
}];
// Historical probe name: root refresh remains supported after cascade refresh removal.
$cases['cascade_refresh_new_child'] = [[7169, 10499], static function (EntityManager $em): void {
    $owner = new Owner();
    $em->persist($owner);
    $em->flush();
    $child = new Child();
    $child->owner = $owner;
    $owner->children->add($child);
    $em->refresh($owner);
    check($owner->children->isEmpty(), 'Refresh retained a new child absent from the database.');
}];
$cases['indexed_collection_reparent_before_initialization'] = [[6589, 6339, 8324, 7932, 11093, 11572, 9427], static function (EntityManager $em): void {
    $old = new Owner();
    $new = new Owner();
    $child = new Child();
    $child->owner = $old;
    $old->children->add($child);
    $em->persist($old);
    $em->persist($new);
    $em->flush();
    [$oldId, $newId, $childId] = [$old->id, $new->id, $child->id];
    $em->clear();
    $child = $em->find(Child::class, $childId);
    $old = $em->find(Owner::class, $oldId);
    $new = $em->find(Owner::class, $newId);
    $child->owner = $new;
    $new->children->add($child);
    $old->children->removeElement($child);
    check($child->owner === $new, 'Collection initialization overwrote the locally changed owning FK.');
    $em->flush();
    $em->clear();
    check($em->find(Child::class, $childId)->owner->id === $newId, 'Reparenting was not persisted.');
}];
$cases['fetch_join_child_reparenting'] = [[7812, 6339], static function (EntityManager $em): void {
    $old = new Owner();
    $new = new Owner();
    $child = new Child();
    $child->owner = $old;
    $old->children->add($child);
    $em->persist($old);
    $em->persist($new);
    $em->flush();
    [$oldId, $newId, $childId] = [$old->id, $new->id, $child->id];
    $em->clear();
    $quote = $em->getConfiguration()->getQuoteStrategy();
    $table = $quote->getTableName($em->getClassMetadata(Child::class), $em->getConnection()->getDatabasePlatform());
    $owner = $em->createQueryBuilder()->fromEntity(Owner::class, 'o')->addJoinedEntity(Child::class, 'c', 'o', 'children')
        ->leftJoin('o', $table, 'c', 'c.owner_id = o.id')->where('o.id = :id')->setParameter('id', $oldId)->getQuery()->getSingleResult();
    $child = $owner->children->first();
    $child->owner = $em->find(Owner::class, $newId);
    $em->flush();
    $em->clear();
    check($em->find(Child::class, $childId)->owner->id === $newId, 'Fetch-joined child reparenting was not persisted.');
}];
$cases['to_one_local_change_survives_old_reference_load'] = [[8282, 8436], static function (EntityManager $em): void {
    $document = new Document();
    $document->file = new File();
    $em->persist($document);
    $em->flush();
    $id = $document->id;
    $em->clear();
    $document = $em->find(Document::class, $id);
    $old = $document->file;
    $document->file = $new = new File();
    $em->initializeObject($old);
    check($document->file === $new, 'Loading the old inverse OneToOne restored its former owner.');
}];
$cases['lazy_owner_orphan_removal'] = [[12352], static function (EntityManager $em): void {
    $document = new Document();
    $document->file = new File();
    $em->persist($document);
    $em->flush();
    [$id, $fileId] = [$document->id, $document->file->id];
    $em->clear();
    $document = $em->getReference(Document::class, $id);
    $document->file = null;
    $em->flush();
    $em->clear();
    check($em->find(File::class, $fileId) === null, 'Clearing a lazy owner relation did not remove the orphan.');
}];
$cases['cascade_remove_callback_entity'] = [[7806, 6079], static function (EntityManager $em): void {
    File::$removed = [];
    $first = new Document();
    $first->file = new File();
    $first->file->name = 'first';
    $second = new Document();
    $second->file = new File();
    $second->file->name = 'second';
    $em->persist($first);
    $em->persist($second);
    $em->flush();
    $id = $first->id;
    $em->clear();
    $em->remove($em->find(Document::class, $id));
    $em->flush();
    check(File::$removed === ['first'], 'Removal callback received the wrong or additional file.');
}];
$cases['many_to_many_repository_criteria'] = [[3752, 5770, 6173], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add($tag = new Tag());
    $em->persist($owner);
    $em->flush();
    $rows = $em->getRepository(Owner::class)->findBy(['tags' => $tag]);
    check(count($rows) === 1 && $rows[0] === $owner, 'Repository criteria failed to resolve ManyToMany membership.');
}];
$cases['many_to_many_clear_delete_diff'] = [[4173, 2272], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add(new Tag());
    $em->persist($owner);
    $em->flush();
    $owner->tags->clear();
    $em->flush();
    check($em->association($owner, 'tags')->count() === 0, 'Clearing a collection did not remove database links.');
}];
$cases['native_result_mapping_quoted_select'] = [[3927], static function (EntityManager $em): void {
    $row = new QuotedRow();
    $row->id = 1;
    $em->persist($row);
    $em->flush();
    $builder = new ResultSetMappingBuilder($em);
    $builder->addRootEntityFromClassMetadata(QuotedRow::class, 'q');
    $quote = $em->getConfiguration()->getQuoteStrategy();
    $table = $quote->getTableName($em->getClassMetadata(QuotedRow::class), $em->getConnection()->getDatabasePlatform());
    $result = $em->createNativeQuery('SELECT ' . $builder->generateSelectClause() . ' FROM ' . $table . ' q', $builder)->getSingleResult();
    check($result === $row, 'Quoted native select failed to preserve identity.');
}];
$cases['reserved_table_schema_round_trip'] = [[11898], static function (EntityManager $em): void {
    $em->getConnection()->getConfiguration()->setSchemaAssetsFilter(static fn (): bool => false);
    check((new SchemaTool($em))->getUpdateSchemaSql([$em->getClassMetadata(QuotedRow::class)]) === [], 'Reserved schema-qualified table produced a repeat diff.');
}];
$cases['mapped_superclass_sequence_name'] = [[12390], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(SequenceRow::class);
    check(str_ends_with($metadata->sequenceGeneratorDefinition['sequenceName'], 'issue_explicit_sequence'), 'Inherited sequence name was lost: ' . $metadata->sequenceGeneratorDefinition['sequenceName']);
    $row = new SequenceRow();
    $em->persist($row);
    $em->flush();
    check($row->id !== null, 'Inherited explicit sequence did not generate an ID.');
}];

$cases['metadata_association_override_after_cache'] = [[12783, 11957], static function (EntityManager $em): void {
    $metadata = unserialize(serialize($em->getClassMetadata(Child::class)));
    $metadata->wakeupReflection(new RuntimeReflectionService());
    $metadata->setAssociationOverride('owner', ['joinColumns' => [['name' => 'new_owner_id', 'referencedColumnName' => 'id']]]);
}];
$cases['single_inverse_join_column_override'] = [[11390], static function (EntityManager $em): void {
    $override = new \ModernORM\Mapping\AssociationOverride('tags', inverseJoinColumns: new \ModernORM\Mapping\InverseJoinColumn());
    check(count($override->inverseJoinColumns) === 1, 'Singular inverse join column was not normalized.');
}];
$cases['inferred_version_type'] = [[11110], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(InferredVersion::class);
    check($metadata->isVersioned && $metadata->getTypeOfField('version') === 'integer', 'Inferred integer version is not mapped.');
}];
$cases['invalid_index_by_validation'] = [[11608], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Owner::class);
    $metadata->associationMappings['children']->indexBy = 'not_a_field';
    $errors = (new \ModernORM\Tools\SchemaValidator($em))->validateClass($metadata);
    check($errors !== [], 'Invalid indexBy passes mapping validation.');
}];
$cases['invalid_index_columns'] = [[11223], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Owner::class);
    $metadata->table['indexes']['bad_index'] = ['fields' => ['not_a_field']];
    try {
        (new SchemaTool($em))->getCreateSchemaSql([$metadata]);
    } catch (\Throwable $error) {
        check(str_contains($error->getMessage(), 'bad_index'), 'Unexpected failure while validating the index: ' . $error->getMessage());
        return;
    }
    throw new \UnexpectedValueException('Invalid index field was not rejected with a mapping error.');
}];
$cases['conflicting_column_association_attributes'] = [[7513, 5978], static function (EntityManager $em): void {
    try {
        $em->getClassMetadata(ConflictingMapping::class);
    } catch (\ModernORM\Exception\MappingException) {
        return;
    }
    throw new \UnexpectedValueException('Column plus ManyToOne attributes silently discard the association.');
}];
$cases['invalid_nested_identifier'] = [[11236, 8866], static function (EntityManager $em): void {
    try {
        $em->find(Owner::class, ['id' => [1]]);
    } catch (\ModernORM\Exception\ORMInvalidArgumentException|\InvalidArgumentException) {
        return;
    }
    throw new \UnexpectedValueException('Nested identifier array was not rejected.');
}];
$cases['refresh_discards_change_without_phantom_update'] = [[9201], static function (EntityManager $em): void {
    $owner = new Owner();
    $em->persist($owner);
    $em->flush();
    $owner->name = 'local';
    $em->refresh($owner);
    check($owner->name === 'owner', 'Refresh did not restore the stored value.');
    $em->getUnitOfWork()->computeChangeSets();
    check(! $em->getUnitOfWork()->isScheduledForUpdate($owner), 'Refresh left a phantom update.');
}];
$cases['refresh_does_not_cascade_without_mapping'] = [[6031], static function (EntityManager $em): void {
    $document = new Document();
    $document->file = new File();
    $em->persist($document);
    $em->flush();
    $document->file->name = 'local';
    $em->refresh($document);
    check($document->file->name === 'local', 'Refreshing parent silently refreshed a non-cascade child.');
}];
$cases['many_to_many_deleted_target_second_flush'] = [[9124], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add($tag = new Tag());
    $em->persist($owner);
    $em->flush();
    $em->remove($tag);
    $em->flush();
    $em->flush();
    check($em->association($owner, 'tags')->count() === 0, 'Deleted target still has join links.');
}];
$cases['many_to_many_dirty_collection_refetch'] = [[10003], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add(new Tag());
    $em->persist($owner);
    $em->flush();
    $owner->tags->add($new = new Tag());
    $new->name = 'new';
    $metadata = $em->getClassMetadata(Tag::class);
    $quote = $em->getConfiguration()->getQuoteStrategy();
    $table = $quote->getTableName($metadata, $em->getConnection()->getDatabasePlatform());
    $link = $quote->getJoinTableName($em->getClassMetadata(Owner::class)->associationMappings['tags'], $em->getClassMetadata(Owner::class), $em->getConnection()->getDatabasePlatform());
    $em->createQueryBuilder()->fromEntity(Owner::class, 'o')->addJoinedEntity(Tag::class, 't', 'o', 'tags')
        ->leftJoin('o', $link, 'l', 'l.owner_id = o.id')->leftJoin('o', $table, 't', 't.id = l.tag_id')
        ->where('o.id = :id')->setParameter('id', $owner->id)->getQuery()->getResult();
    $em->flush();
    check($em->association($owner, 'tags')->count() === 2, 'Refetch lost or corrupted an unpersisted target.');
}];
$cases['query_lock_preserves_managed_local_value'] = [[6650, 3689], static function (EntityManager $em): void {
    $owner = new Owner();
    $em->persist($owner);
    $em->flush();
    $owner->name = 'local';
    $em->getConnection()->beginTransaction();
    try {
        $row = $em->createQueryBuilder()->fromEntity(Owner::class, 'o')->where('o.id = :id')->setParameter('id', $owner->id)->forUpdate()->getQuery()->getSingleResult();
        check($row->name === 'owner', 'A locked query preserves the identity-map local value instead of implicitly refreshing.');
    } finally {
        $em->getConnection()->rollBack();
    }
}];
$cases['repeated_find_lock_local_change'] = [[12260], static function (EntityManager $em): void {
    $owner = new Owner();
    $em->persist($owner);
    $em->flush();
    $em->getConnection()->beginTransaction();
    try {
        $owner = $em->find(Owner::class, $owner->id, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $owner->name = 'local';
        $again = $em->find(Owner::class, $owner->id, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        check($again->name === 'local', 'A repeated find with a pessimistic lock refreshes away local changes.');
    } finally {
        $em->getConnection()->rollBack();
    }
}];
$cases['rsm_unknown_alias_validation'] = [[7243], static function (EntityManager $em): void {
    $mapping = new \ModernORM\Query\ResultSetMapping();
    $mapping->addEntityResult(Owner::class, 'o');
    set_error_handler(static function (int $severity, string $message): never {
        throw new \ErrorException($message, 0, $severity);
    });
    try {
        $mapping->addFieldResult('missing', 'id', 'id');
    } catch (\InvalidArgumentException|\LogicException) {
        return;
    } finally {
        restore_error_handler();
    }
    throw new \UnexpectedValueException('RSM accepts an unregistered entity alias.');
}];
$cases['transaction_decorator_callback'] = [[7161], static function (EntityManager $em): void {
    $decorator = new class ($em) extends \ModernORM\Decorator\EntityManagerDecorator {
    };
    $received = $decorator->wrapInTransaction(static fn ($manager) => $manager);
    check($received === $decorator, 'Transaction callback receives the wrapped manager instead of the decorator.');
}];
$cases['native_query_joined_columns_first'] = [[2633], static function (EntityManager $em): void {
    $owner = new Owner();
    $child = new Child();
    $child->owner = $owner;
    $owner->children->add($child);
    $em->persist($owner);
    $em->flush();
    $ownerId = $owner->id;
    $em->clear();
    $quote = $em->getConfiguration()->getQuoteStrategy();
    $platform = $em->getConnection()->getDatabasePlatform();
    $owners = $quote->getTableName($em->getClassMetadata(Owner::class), $platform);
    $children = $quote->getTableName($em->getClassMetadata(Child::class), $platform);
    $mapping = new \ModernORM\Query\ResultSetMapping();
    $mapping->addEntityResult(Child::class, 'c')->addJoinedEntityResult(Owner::class, 'o', 'c', 'owner');
    $mapping->addFieldResult('o', 'owner_id', 'id')->addFieldResult('o', 'owner_name', 'name');
    $mapping->addFieldResult('c', 'child_id', 'id')->addFieldResult('c', 'child_name', 'name');
    $mapping->addMetaResult('c', 'owner_fk', 'owner_id', false, 'integer');
    $row = $em->createNativeQuery('SELECT o.id AS owner_id, o.name AS owner_name, c.id AS child_id, c.name AS child_name, c.owner_id AS owner_fk FROM ' . $children . ' c JOIN ' . $owners . ' o ON o.id = c.owner_id', $mapping)->getSingleResult();
    check($row->owner->id === $ownerId, 'Joined columns preceding root columns corrupted the relation.');
}];

$cases['custom_value_object_scalar_criteria'] = [[9183], static function (EntityManager $em): void {
    $row = new CustomRow();
    $em->persist($row);
    $em->flush();
    $em->clear();
    $rows = $em->getRepository(CustomRow::class)->findBy(['token' => new Token('UPPER')]);
    check(count($rows) === 1, 'Scalar custom-type criteria failed to use its DBAL converter.');
}];
$cases['custom_value_object_array_criteria'] = [[11405, 11712], static function (EntityManager $em): void {
    $row = new CustomRow();
    $em->persist($row);
    $em->flush();
    $em->clear();
    $rows = $em->getRepository(CustomRow::class)->findBy(['token' => [new Token('UPPER')]]);
    check(count($rows) === 1, 'Custom-type IN criteria did not convert array elements through their DBAL type.');
}];
$cases['quoted_custom_type_sql_conversion'] = [[8191, 7460], static function (EntityManager $em): void {
    $em->getConnection()->getConfiguration()->setTypeProvider(new \Doctrine\DBAL\Types\TypeRegistry(['issue_token' => new SqlTokenType(), 'issue_enum' => new StateType()]));
    $row = new CustomRow();
    $em->persist($row);
    $em->flush();
    $em->clear();
    $rows = $em->getRepository(CustomRow::class)->findBy(['token' => new Token('UPPER')]);
    check(count($rows) === 1, 'Quoted custom-type write/query SQL conversion disagrees (INSERT may skip convertToDatabaseValueSQL).');
}];
$cases['datetime_array_repository_criteria'] = [[6934], static function (EntityManager $em): void {
    $row = new CustomRow();
    $em->persist($row);
    $em->flush();
    $em->clear();
    $rows = $em->getRepository(CustomRow::class)->findBy(['date' => [new \DateTimeImmutable('2025-01-02 03:04:05')]]);
    check(count($rows) === 1, 'DateTime array criteria failed to convert elements.');
}];
$cases['ansi_quote_identifier_column_names'] = [[10014], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(CustomRow::class);
    $names = (new \ModernORM\Mapping\AnsiQuoteStrategy())->getIdentifierColumnNames($metadata, $em->getConnection()->getDatabasePlatform());
    check($names === ['row_id'], 'AnsiQuoteStrategy returns PHP identifier fields instead of mapped column names.');
}];
$cases['generated_field_local_change_tracking'] = [[12017], static function (EntityManager $em): void {
    $row = new GeneratedRow();
    $em->persist($row);
    $em->flush();
    $row->value = 'local generated-field edit';
    $em->getUnitOfWork()->computeChangeSets();
    check(! $em->getUnitOfWork()->isScheduledForUpdate($row), 'Non-updatable generated field schedules an entity update.');
}];
$cases['many_to_many_pre_update_old_new_values'] = [[7426], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add(new Tag());
    $em->persist($owner);
    $em->flush();
    $events = new class () {
        public bool $called = false;
        public function preUpdate(\ModernORM\Event\PreUpdateEventArgs $args): void
        {
            $this->called = true;
            if ($args->hasChangedField('tags')) {
                $args->getOldValue('tags');
                $args->getNewValue('tags');
            }
        }
    };
    $em->getEventManager()->addEventListener([\ModernORM\Events::preUpdate], $events);
    $owner->tags->clear();
    $em->flush();
    check($events->called, 'Collection-only clear does not dispatch the owner entity preUpdate event.');
}];

$cases['embedded_metadata_nullable'] = [[7133], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(EmbeddedRow::class);
    if ($metadata->hasField('address')) {
        $metadata->isNullable('address');
    }
}];
$cases['mapped_superclass_namespace_chain'] = [[12372], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Third\ConcreteRow::class);
    check($metadata->hasField('id') && $metadata->hasField('name'), 'Cross-namespace mapped-superclass chain lost fields.');
}];
$cases['deleted_row_refresh'] = [[5750], static function (EntityManager $em): void {
    $owner = new Owner();
    $em->persist($owner);
    $em->flush();
    $table = $em->getConfiguration()->getQuoteStrategy()->getTableName($em->getClassMetadata(Owner::class), $em->getConnection()->getDatabasePlatform());
    $em->getConnection()->executeStatement('DELETE FROM ' . $table . ' WHERE id = ?', [$owner->id]);
    try {
        $em->refresh($owner);
    } catch (\ModernORM\Exception\EntityNotFoundException) {
        return;
    }
    throw new \UnexpectedValueException('Refreshing a deleted row silently leaves the object managed.');
}];
$cases['scheduled_delete_preserves_identity'] = [[11480], static function (EntityManager $em): void {
    $owner = new Owner();
    $em->persist($owner);
    $em->flush();
    $em->remove($owner);
    $row = $em->createQueryBuilder()->fromEntity(Owner::class, 'o')->where('o.id = :id')->setParameter('id', $owner->id)->getQuery()->getSingleResult();
    check($row !== $owner, 'A scheduled-delete row is hydrated into the same identity-map instance.');
}];
$cases['generated_pending_orphan_collection_replacement'] = [[8133], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Owner::class);
    $metadata->associationMappings['children']->orphanRemoval = true;
    $owner = new Owner();
    $old = new Child();
    $old->owner = $owner;
    $owner->children->add($old);
    $em->persist($owner);
    $new = new Child();
    $new->name = 'replacement';
    $new->owner = $owner;
    $owner->children = new \Doctrine\Common\Collections\ArrayCollection([$new]);
    $em->flush();
    check($old->id === null, 'Discarded new orphan was still inserted before removal.');
    check($em->association($owner, 'children')->count() === 1, 'New collection replacement has extra rows.');
}];
$cases['metadata_asset_filter_create_contract'] = [[9625], static function (EntityManager $em): void {
    $em->getConnection()->getConfiguration()->setSchemaAssetsFilter(static fn (): bool => false);
    $sql = (new SchemaTool($em))->getCreateSchemaSql([$em->getClassMetadata(Owner::class)]);
    check(! str_contains(implode('\n', $sql), 'CREATE TABLE'), 'Explicit mapped CREATE SQL is not constrained by the introspection asset filter.');
}];
$cases['many_to_many_event_values_with_entity_change'] = [[4076], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add(new Tag());
    $em->persist($owner);
    $em->flush();
    $events = new class () {
        public bool $called = false;
        public function preUpdate(\ModernORM\Event\PreUpdateEventArgs $args): void
        {
            $this->called = true;
            if ($args->hasChangedField('tags')) {
                check($args->getOldValue('tags') instanceof \Doctrine\Common\Collections\Collection, 'ManyToMany old event value is not the previous collection.');
                check($args->getNewValue('tags') instanceof \Doctrine\Common\Collections\Collection, 'ManyToMany new event value is not the replacement collection.');
            }
        }
    };
    $em->getEventManager()->addEventListener([\ModernORM\Events::preUpdate], $events);
    $owner->name = 'changed';
    $owner->tags = new \Doctrine\Common\Collections\ArrayCollection();
    $em->flush();
    check($events->called, 'Entity scalar update did not dispatch preUpdate.');
}];

$cases['custom_enum_type_criteria'] = [[10432], static function (EntityManager $em): void {
    $row = new EnumRow();
    $em->persist($row);
    $em->flush();
    $rows = $em->getRepository(EnumRow::class)->findBy(['state' => CustomState::Active]);
    check(count($rows) === 1, 'Custom enum DBAL criteria did not retain its enum value.');
}];
$cases['eager_to_one_null_survives_refetch'] = [[8205], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Child::class);
    $metadata->associationMappings['owner']->fetch = \ModernORM\Mapping\ClassMetadata::FETCH_EAGER;
    $owner = new Owner();
    $child = new Child();
    $child->owner = $owner;
    $owner->children->add($child);
    $em->persist($owner);
    $em->flush();
    $id = $child->id;
    $em->clear();
    $child = $em->find(Child::class, $id);
    $child->owner = null;
    $again = $em->getRepository(Child::class)->findOneBy(['id' => $id]);
    check($again->owner === null, 'Eager refetch restored a locally nulled relation.');
}];
$cases['bidi_one_to_one_delete_second_flush'] = [[11580], static function (EntityManager $em): void {
    $mapping = $em->getClassMetadata(Document::class)->associationMappings['file'];
    $mapping->cascade = [];
    $mapping->orphanRemoval = false;
    $document = new Document();
    $document->file = $file = new File();
    $em->persist($file);
    $em->persist($document);
    $em->flush();
    $id = $document->id;
    $em->clear();
    $document = $em->find(Document::class, $id);
    $em->initializeObject($document->file);
    $em->remove($document);
    $em->flush();
    $em->flush();
}];
$cases['collection_add_remove_diff_same_size'] = [[7365], static function (EntityManager $em): void {
    $owner = new Owner();
    $owner->tags->add($old = new Tag());
    $em->persist($owner);
    $em->flush();
    $events = new class () {
        public int $insertions = 0;
        public int $deletions = 0;
        public function onFlush(\ModernORM\Event\OnFlushEventArgs $args): void
        {
            foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledCollectionUpdates() as $collection) {
                $this->insertions += count($collection->getInsertDiff());
                $this->deletions += count($collection->getDeleteDiff());
            }
        }
    };
    $em->getEventManager()->addEventListener([\ModernORM\Events::onFlush], $events);
    $owner->tags->removeElement($old);
    $owner->tags->add(new Tag());
    $em->flush();
    check($events->insertions === 1 && $events->deletions === 1, 'Equal-size replacement lost collection insert/delete diffs.');
}];
$cases['post_flush_persist_survives_next_flush'] = [[5732], static function (EntityManager $em): void {
    $late = new Owner();
    $late->name = 'late';
    $events = new class ($late) {
        private bool $done = false;
        public function __construct(private readonly Owner $late)
        {
        }
        public function postFlush(\ModernORM\Event\PostFlushEventArgs $args): void
        {
            if (! $this->done) {
                $this->done = true;
                $args->getObjectManager()->persist($this->late);
            }
        }
    };
    $em->getEventManager()->addEventListener([\ModernORM\Events::postFlush], $events);
    $em->persist(new Owner());
    $em->flush();
    $em->flush();
    check($late->id !== null, 'Entity persisted from postFlush was dropped instead of inserted by the next flush.');
}];
$cases['redundant_fk_index_with_covering_index'] = [[6783], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Child::class);
    $metadata->table['indexes']['covering_owner_name'] = ['columns' => ['owner_id', 'name']];
    $schema = (new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(Owner::class), $metadata, $em->getClassMetadata(Tag::class)]);
    foreach ($schema->getTables() as $table) {
        if ($table->getObjectName()->getUnqualifiedName()->getValue() !== 'children') {
            continue;
        }
        foreach ($table->getIndexes() as $index) {
            check($index->getColumns() !== ['owner_id'], 'Redundant single-FK index remains beside a covering compound index.');
        }
    }
}];
$cases['one_to_one_unique_transfer_before_delete'] = [[5685], static function (EntityManager $em): void {
    $mapping = $em->getClassMetadata(Document::class)->associationMappings['file'];
    $mapping->cascade = ['persist'];
    $mapping->orphanRemoval = false;
    $first = new Document();
    $first->file = new File();
    $second = new Document();
    $em->persist($first);
    $em->persist($second);
    $em->flush();
    $second->file = $first->file;
    $first->file = null;
    $em->remove($first);
    $em->flush();
}];
$cases['nested_array_hydration_shared_target_row_order'] = [[9185], static function (EntityManager $em): void {
    $owner = new Owner();
    $first = new Child();
    $second = new Child();
    $second->name = 'second';
    foreach ([$first, $second] as $child) {
        $child->owner = $owner;
        $owner->children->add($child);
    }
    $em->persist($owner);
    $em->flush();
    $firstId = $first->id;
    $secondId = $second->id;
    $em->clear();
    $quote = $em->getConfiguration()->getQuoteStrategy();
    $platform = $em->getConnection()->getDatabasePlatform();
    $children = $quote->getTableName($em->getClassMetadata(Child::class), $platform);
    $owners = $quote->getTableName($em->getClassMetadata(Owner::class), $platform);
    $mapping = new \ModernORM\Query\ResultSetMapping();
    $mapping->addEntityResult(Child::class, 'r')->addJoinedEntityResult(Owner::class, 'o', 'r', 'owner')->addJoinedEntityResult(Child::class, 'c', 'o', 'children');
    foreach ([['r', 'rid', 'id'], ['r', 'rname', 'name'], ['o', 'oid', 'id'], ['o', 'oname', 'name'], ['c', 'cid', 'id'], ['c', 'cname', 'name']] as [$alias, $column, $field]) {
        $mapping->addFieldResult($alias, $column, $field);
    }
    $sql = 'SELECT r.id AS rid, r.name AS rname, o.id AS oid, o.name AS oname, c.id AS cid, c.name AS cname FROM (VALUES (' . $firstId . ',' . $firstId . ',1),(' . $firstId . ',' . $secondId . ',2),(' . $secondId . ',' . $secondId . ',3),(' . $secondId . ',' . $firstId . ',4)) v(rid,cid,position) JOIN ' . $children . ' r ON r.id = v.rid JOIN ' . $owners . ' o ON o.id = r.owner_id JOIN ' . $children . ' c ON c.id = v.cid ORDER BY v.position';
    $rows = $em->createNativeQuery($sql, $mapping)->getArrayResult();
    check(count($rows) === 2, 'Nested array hydration lost a root.');
    foreach ($rows as $row) {
        $ids = array_column($row['owner']['children'], 'id');
        sort($ids);
        check($ids === [$firstId, $secondId], 'Nested array hydration reused shared-target pointers across different row orders: ' . json_encode($rows, JSON_THROW_ON_ERROR));
    }
}];

$cases['custom_type_collection_index_domain_value'] = [[10152], static function (EntityManager $em): void {
    $owner = new Owner();
    $row = new CustomRow();
    $row->owner = $owner;
    $owner->customRows->add($row);
    $em->persist($owner);
    $em->flush();
    $id = $owner->id;
    $em->clear();
    $owner = $em->find(Owner::class, $id);
    check(array_keys($owner->customRows->toArray()) === ['UPPER'], 'Custom-type indexBy uses database encoding rather than the PHP domain representation.');
}];
$cases['l2_embedded_noop_change_tracking'] = [[7204], static function (EntityManager $em): void {
    $row = new EmbeddedRow();
    $em->persist($row);
    $em->flush();
    $em->clear();
    $em->find(EmbeddedRow::class, 1);
    check($em->getCache()->contains(EmbeddedRow::class, 1), 'Probe did not warm the entity cache.');
    $em->clear();
    $row = $em->find(EmbeddedRow::class, 1);
    $em->getUnitOfWork()->computeChangeSets();
    check(! $em->getUnitOfWork()->isScheduledForUpdate($row), 'Warm L2 embedded hydration creates a phantom update.');
}];
$cases['foreign_key_replacement_ddl_statements'] = [[7202], static function (EntityManager $em): void {
    $metadata = $em->getClassMetadata(Child::class);
    $metadata->associationMappings['owner']->joinColumns[0]->onDelete = 'CASCADE';
    $em->getConnection()->getConfiguration()->setSchemaAssetsFilter(static fn (): bool => false);
    $sql = (new SchemaTool($em))->getSchemaMigrationSql([$em->getClassMetadata(Owner::class), $metadata, $em->getClassMetadata(Tag::class)])['up'];
    $fk = array_filter($sql, static fn ($statement) => str_contains($statement, 'CONSTRAINT'));
    check(count($fk) === 1, 'FK replacement emits separate DROP/ADD statements; apply the plan in a transaction.');
}];
$cases['cascade_delete_parent_with_featured_child_cycle'] = [[5665], static function (EntityManager $em): void {
    $parent = new CycleParent();
    $first = new CycleChild();
    $second = new CycleChild();
    foreach ([$first, $second] as $child) {
        $child->parent = $parent;
        $parent->children->add($child);
    }
    $parent->featured = $second;
    $em->persist($parent);
    $em->flush();
    [$parentId, $childId] = [$parent->id, $second->id];
    $em->remove($parent);
    $em->flush();
    $em->clear();
    check($em->find(CycleParent::class, $parentId) === null && $em->find(CycleChild::class, $childId) === null, 'Cascade failed to remove the nullable featured-child cycle.');
}];

$selected = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--case=')) {
        $selected[] = substr($argument, 7);
    } elseif ($argument !== '--fail-on-findings') {
        fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
        exit(2);
    }
}
if ($selected !== []) {
    $unknown = array_diff($selected, array_keys($cases));
    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown diagnostic case: ' . implode(', ', $unknown) . PHP_EOL);
        exit(2);
    }
    $cases = array_intersect_key($cases, array_fill_keys($selected, true));
}

$results = [];
foreach ($cases as $name => [$issues, $scenario]) {
    $schema = 'issue_audit_' . bin2hex(random_bytes(5));
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
        'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
        'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
    ]);
    $connection->getConfiguration()->setTypeProvider(new \Doctrine\DBAL\Types\TypeRegistry(['issue_token' => new TokenType(), 'issue_enum' => new StateType()]));
    $config = ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter());
    if ($name === 'l2_embedded_noop_change_tracking') {
        $config->setSecondLevelCache(new ArrayAdapter(), $schema);
    }
    $serverVersion = $connection->getServerVersion();
    $em = new EntityManager($connection, $config);
    $classes = array_map($em->getClassMetadata(...), [Owner::class, Child::class, Tag::class, HookedId::class, ReadonlyDate::class, Document::class, File::class, QuotedRow::class, SequenceRow::class, CustomRow::class, GeneratedRow::class, EmbeddedRow::class, EnumRow::class, CycleParent::class, CycleChild::class]);
    foreach ($classes as $class) {
        $class->table['schema'] = $schema;
    }
    if ($name === 'l2_embedded_noop_change_tracking') {
        $em->getClassMetadata(EmbeddedRow::class)->cache = ['ttl' => 60, 'writePolicy' => \ModernORM\Cache\CacheWritePolicy::INVALIDATE];
    }
    $ownerMetadata = $em->getClassMetadata(Owner::class);
    $ownerMetadata->associationMappings['tags']->joinTable->schema = $schema;
    // Qualify the inherited sequence for isolated schema creation; preserve its mapped basename.
    $sequence = $em->getClassMetadata(SequenceRow::class);
    $sequence->sequenceGeneratorDefinition['sequenceName'] = $schema . '.' . $sequence->sequenceGeneratorDefinition['sequenceName'];
    $sequence->idGenerator = new \ModernORM\Id\SequenceGenerator($sequence->sequenceGeneratorDefinition['sequenceName'], 1);
    try {
        (new SchemaTool($em))->createSchema($classes);
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Audit setup failed: ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
    try {
        $scenario($em);
        $results[] = ['case' => $name, 'issues' => $issues, 'result' => 'not-reproduced'];
    } catch (\Throwable $error) {
        $results[] = ['case' => $name, 'issues' => $issues, 'result' => 'probe-failed', 'exception' => $error::class, 'message' => preg_replace('/issue_audit_[a-f0-9]+|@[0-9]+/', '<isolated>', $error->getMessage()), 'trace' => array_map(static fn ($frame) => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'], array_slice($error->getTrace(), 0, 6))];
    } finally {
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        $connection->close();
    }
}
$json = json_encode(['php' => PHP_VERSION, 'postgresql' => $serverVersion, 'zend_assertions' => ini_get('zend.assertions'), 'cases' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
echo str_replace(dirname(__DIR__, 2), '<repo>', $json) . PHP_EOL;

// A completed diagnostic run is not a green regression suite: known findings are reported in JSON.
if (in_array('--fail-on-findings', $argv, true)) {
    exit(count(array_filter($results, static fn ($case) => $case['result'] === 'probe-failed')) > 0 ? 1 : 0);
}
