<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\PersistentCollection;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_audit_owners')]
class AuditOwner
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    /** @var Collection<int, AuditChild> */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: AuditChild::class, cascade: ['persist'], orphanRemoval: true, fetch: 'EXTRA_LAZY', indexBy: 'id')]
    public Collection $children;
    /** @var Collection<int, AuditTag> */
    #[ORM\ManyToMany(targetEntity: AuditTag::class, cascade: ['persist'], fetch: 'EXTRA_LAZY', indexBy: 'id')]
    #[ORM\JoinTable(name: 'modernorm_audit_owner_tags')]
    public Collection $tags;
    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_audit_children')]
class AuditChild
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: AuditOwner::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: false)]
    public AuditOwner $owner;
    public function __construct(AuditOwner $owner)
    {
        $this->owner = $owner;
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_audit_groups')]
class AuditGroup
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $code = 'member';
    /** @var Collection<int, AuditTag> */
    #[ORM\ManyToMany(targetEntity: AuditTag::class, inversedBy: 'groups', cascade: ['persist'], fetch: 'EXTRA_LAZY', indexBy: 'id')]
    #[ORM\JoinTable(name: 'modernorm_audit_group_tags')]
    public Collection $tags;
    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_audit_tags')]
class AuditTag
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column]
    public string $code = 'member';
    /** @var Collection<int, AuditGroup> */
    #[ORM\ManyToMany(targetEntity: AuditGroup::class, mappedBy: 'tags', fetch: 'EXTRA_LAZY', indexBy: 'id')]
    public Collection $groups;
    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_audit_records')]
class AuditRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(unique: true)]
    public string $code;
    #[ORM\Column]
    public string $note = 'initial';
    public function __construct(string $code)
    {
        $this->code = $code;
    }
}

final class PersistenceAuditTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [AuditOwner::class, AuditChild::class, AuditGroup::class, AuditTag::class, AuditRecord::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $db = $this->em->getConnection();
        while ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $db->executeStatement('DROP TABLE IF EXISTS modernorm_audit_owner_tags, modernorm_audit_group_tags, modernorm_audit_children, modernorm_audit_owners, modernorm_audit_groups, modernorm_audit_tags, modernorm_audit_records CASCADE');
        $db->close();
    }

    /** @return array{AuditOwner, list<int>} */
    private function owner(): array
    {
        $owner = new AuditOwner();
        $owner->children->add(new AuditChild($owner));
        $owner->children->add(new AuditChild($owner));
        $this->em->persist($owner);
        $this->em->flush();
        $id = $owner->id;
        $ids = array_map(static fn (AuditChild $child): int => $child->id, $owner->children->getValues());
        $this->em->clear();
        return [$this->em->find(AuditOwner::class, $id), $ids];
    }

    public static function replacementCases(): iterable
    {
        yield 'uninitialized, keep all' => [false, 2];
        yield 'initialized, keep all' => [true, 2];
        yield 'uninitialized, keep one' => [false, 1];
        yield 'initialized, keep one' => [true, 1];
        yield 'uninitialized, keep none' => [false, 0];
        yield 'initialized, keep none' => [true, 0];
        yield 'read-only owner, keep all' => [false, 2, true];
        yield 'read-only owner, keep one' => [false, 1, true];
    }

    #[DataProvider('replacementCases')]
    public function testReplacementDeletesOnlyDiscardedChildren(bool $initialize, int $keep, bool $readOnly = false): void
    {
        [$owner, $ids] = $this->owner();
        $this->em->getClassMetadata(AuditOwner::class)->isReadOnly = $readOnly;
        if ($initialize) {
            $owner->children->toArray();
        }
        $kept = [];
        foreach (array_slice($ids, 0, $keep) as $id) {
            $child = $this->em->getReference(AuditChild::class, $id);
            self::assertInstanceOf(AuditChild::class, $child);
            $kept[] = $child;
        }
        $owner->children = new ArrayCollection($kept);
        $removed = [];
        $this->listen(Events::postRemove, static function ($args) use (&$removed): void {
            if ($args->getObject() instanceof AuditChild) {
                $removed[] = $args->getObject()->id;
            }
        });
        $this->em->flush();
        $this->em->flush();
        $this->em->clear();
        foreach ($ids as $offset => $id) {
            self::assertSame($offset < $keep, $this->em->find(AuditChild::class, $id) !== null);
        }
        self::assertCount(2 - $keep, $removed);
        self::assertCount($keep, $this->em->find(AuditOwner::class, $owner->id)->children);
    }

    public static function removals(): iterable
    {
        yield 'remove' => ['remove'];
        yield 'removeElement' => ['removeElement'];
    }

    #[DataProvider('removals')]
    public function testRemovingOneOccurrenceDoesNotOrphanRemainingMember(string $method): void
    {
        [$owner, $ids] = $this->owner();
        $child = $owner->children->get($ids[0]);
        $owner->children->toArray();
        $owner->children->add($child);
        if ($method === 'remove') {
            $owner->children->remove($ids[0]);
        } else {
            $owner->children->removeElement($child);
        }
        self::assertTrue($owner->children->contains($child));
        $this->em->flush();
        $this->em->clear();
        self::assertNotNull($this->em->find(AuditChild::class, $ids[0]));
    }

    public function testReplacingIndexedMemberOrphansOnlyTheDisplacedEntity(): void
    {
        [$owner, $ids] = $this->owner();
        $children = $owner->children->toArray();
        $owner->children->set($ids[0], $children[$ids[1]]);
        $owner->children->remove($ids[1]);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(AuditChild::class, $ids[0]));
        self::assertNotNull($this->em->find(AuditChild::class, $ids[1]));
    }

    public function testClearAndReaddPreservesKeptChildAndInsertsNewChild(): void
    {
        [$owner, $ids] = $this->owner();
        $kept = $owner->children->get($ids[0]);
        $owner->children->clear();
        $owner->children->add($kept);
        $new = new AuditChild($owner);
        $owner->children->add($new);
        $this->em->flush();
        $this->em->clear();
        self::assertNotNull($this->em->find(AuditChild::class, $ids[0]));
        self::assertNull($this->em->find(AuditChild::class, $ids[1]));
        self::assertNotNull($this->em->find(AuditChild::class, $new->id));
    }

    public function testReplacingCollectionWithKeptAndNewMembers(): void
    {
        [$owner, $ids] = $this->owner();
        $new = new AuditChild($owner);
        $kept = $this->em->getReference(AuditChild::class, $ids[0]);
        self::assertInstanceOf(AuditChild::class, $kept);
        $owner->children = new ArrayCollection([$kept, $new]);
        $this->em->flush();
        $this->em->clear();
        self::assertNotNull($this->em->find(AuditChild::class, $ids[0]));
        self::assertNull($this->em->find(AuditChild::class, $ids[1]));
        self::assertNotNull($this->em->find(AuditChild::class, $new->id));
    }

    public function testExtraLazyOneToManyLookupChecksOwnerEvenForManagedTarget(): void
    {
        [$first, $firstIds] = $this->owner();
        $firstId = $first->id;
        [$second, $secondIds] = $this->owner();
        $first = $this->em->find(AuditOwner::class, $firstId);
        $foreign = $this->em->find(AuditChild::class, $secondIds[0]);
        $this->assertUninitialized($first->children);
        self::assertNull($first->children->get($foreign->id));
        self::assertSame($firstIds[0], $first->children->get($firstIds[0])->id);
        self::assertNull($first->children->get(999999));
        $this->assertUninitialized($first->children);
    }

    public static function manyToManySides(): iterable
    {
        foreach (['owning', 'inverse', 'unidirectional'] as $side) {
            foreach (['id', 'code'] as $index) {
                yield $side . ' by ' . $index => [$side, $index];
            }
        }
    }

    #[DataProvider('manyToManySides')]
    public function testExtraLazyManyToManyLookupChecksJoinTable(string $side, string $index): void
    {
        $first = $side === 'unidirectional' ? new AuditOwner() : new AuditGroup();
        $second = $side === 'unidirectional' ? new AuditOwner() : new AuditGroup();
        $tag = new AuditTag();
        $foreign = new AuditTag();
        $foreign->code = 'foreign';
        if ($second instanceof AuditGroup) {
            $second->code = 'foreign';
        }
        $class = $side === 'inverse' ? AuditTag::class : $first::class;
        $field = $side === 'inverse' ? 'groups' : 'tags';
        $this->em->getClassMetadata($class)->associationMappings[$field]->indexBy = $index;
        $first->tags->add($tag);
        $second->tags->add($foreign);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        $ids = [$first->id, $second->id, $tag->id, $foreign->id];
        $this->em->clear();
        if ($side === 'inverse') {
            $target = $this->em->find(AuditGroup::class, $ids[1]);
            $collection = $this->em->find(AuditTag::class, $ids[2])->groups;
            $member = $ids[0];
        } else {
            $target = $this->em->find(AuditTag::class, $ids[3]);
            $collection = $this->em->find($first::class, $ids[0])->tags;
            $member = $ids[2];
        }
        $this->assertUninitialized($collection);
        self::assertNull($collection->get($index === 'id' ? $target->id : 'foreign'));
        self::assertSame($member, $collection->get($index === 'id' ? $member : 'member')->id);
        self::assertNull($collection->get(999999));
        $this->assertUninitialized($collection);
    }

    private function assertUninitialized(Collection $collection): void
    {
        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertFalse($collection->isInitialized());
    }

    private function record(string $code = 'same'): AuditRecord
    {
        $record = new AuditRecord($code);
        $this->em->persist($record);
        $this->em->flush();
        return $record;
    }

    public function testImmediateUniqueReplacementInOneFlushFailsAndRollsBack(): void
    {
        $old = $this->record();
        $this->em->remove($old);
        $replacement = new AuditRecord('same');
        $this->em->persist($replacement);
        try {
            $this->em->flush();
            self::fail('Expected INSERT before DELETE to violate immediate UNIQUE.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_audit_records WHERE id = ?', [$old->id]));
    }

    public function testTwoFlushesWithinTransactionReplaceUniqueValueAtomically(): void
    {
        $old = $this->record();
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $oldId = $old->id;
        $this->em->remove($old);
        $this->em->flush();
        $replacement = new AuditRecord('same');
        $this->em->persist($replacement);
        $this->em->flush();
        $db->commit();
        $this->em->clear();
        self::assertNull($this->em->find(AuditRecord::class, $oldId));
        self::assertNotNull($this->em->find(AuditRecord::class, $replacement->id));
    }

    public function testImmediateUniqueSwapInOneFlushIsDocumentedLimitation(): void
    {
        $first = $this->record('first');
        $second = $this->record('second');
        $first->code = 'second';
        $second->code = 'first';
        try {
            $this->em->flush();
            self::fail('Immediate UNIQUE cannot be swapped by sequential UPDATE statements.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($this->em->isOpen());
        self::assertSame(['first', 'second'], $this->em->getConnection()->fetchFirstColumn('SELECT code FROM modernorm_audit_records ORDER BY id'));
    }

    public function testReplacingInverseCollectionWithoutOrphanRemovalKeepsDatabaseLinks(): void
    {
        [$owner, $ids] = $this->owner();
        $this->em->getClassMetadata(AuditOwner::class)->associationMappings['children']->orphanRemoval = false;
        $owner->children = new ArrayCollection();
        $this->em->flush();
        $this->em->clear();
        self::assertCount(2, $this->em->find(AuditOwner::class, $owner->id)->children);
    }

    public function testDeferredUniqueConstraintSupportsOneFlushReplacement(): void
    {
        $db = $this->em->getConnection();
        $indexes = $db->createSchemaManager()->listTableIndexes('modernorm_audit_records');
        foreach ($indexes as $index) {
            if ($index->isUnique() && ! $index->isPrimary()) {
                $db->executeStatement('DROP INDEX ' . $db->quoteIdentifier($index->getName()));
            }
        }
        $db->executeStatement('ALTER TABLE modernorm_audit_records ADD CONSTRAINT audit_code_unique UNIQUE (code) DEFERRABLE INITIALLY DEFERRED');
        $old = $this->record();
        $replacement = new AuditRecord('same');
        $this->em->remove($old);
        $this->em->persist($replacement);
        $this->em->flush();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM modernorm_audit_records'));
    }

    public function testForeignKeyInsertAndDeleteOrder(): void
    {
        $owner = new AuditOwner();
        $child = new AuditChild($owner);
        $owner->children->add($child);
        $this->em->persist($owner);
        $this->em->flush();
        $childId = $child->id;
        $ownerId = $owner->id;
        $this->em->remove($owner);
        $this->em->flush();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT id FROM modernorm_audit_children WHERE id = ?', [$childId]) ?: null);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_audit_owners WHERE id = ?', [$ownerId]));
    }

    private function listen(string $event, \Closure $callback): void
    {
        $listener = new class ($callback) {
            public function __construct(private \Closure $callback)
            {
            }
            public function __call(string $method, array $arguments): void
            {
                ($this->callback)(...$arguments);
            }
        };
        $this->em->getEventManager()->addEventListener([$event], $listener);
    }

    public function testOnFlushRecomputedChangeAndSubsequentFlushAreStable(): void
    {
        $record = $this->record();
        $calls = 0;
        $this->listen(Events::onFlush, function ($args) use ($record, &$calls): void {
            if (++$calls === 1) {
                $record->note = 'from onFlush';
                $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata(AuditRecord::class), $record);
            }
        });
        $record->note = 'changed';
        $this->em->flush();
        $this->em->flush();
        $this->em->clear();
        self::assertSame('from onFlush', $this->em->find(AuditRecord::class, $record->id)->note);
    }

    public static function emptyOnFlushCases(): iterable
    {
        yield 'new entity' => [true];
        yield 'managed update' => [false];
    }

    #[DataProvider('emptyOnFlushCases')]
    public function testOnFlushCanScheduleWorkFromInitiallyEmptyQueue(bool $insert): void
    {
        $record = $insert ? new AuditRecord('added') : $this->record();
        $calls = 0;
        $this->listen(Events::onFlush, function () use ($record, $insert, &$calls): void {
            if (++$calls !== 1) {
                return;
            }
            if ($insert) {
                $this->em->persist($record);
                $this->em->getUnitOfWork()->computeChangeSet($this->em->getClassMetadata(AuditRecord::class), $record);
            } else {
                $record->note = 'scheduled';
                $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata(AuditRecord::class), $record);
            }
        });
        $this->em->flush();
        self::assertNotNull($record->id);
        self::assertSame($insert ? 'initial' : 'scheduled', $this->em->getConnection()->fetchOne('SELECT note FROM modernorm_audit_records WHERE id = ?', [$record->id]));
        $this->em->flush();
        self::assertSame(2, $calls);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_audit_records'));
    }

    public function testExternalRollbackRequiresClearingTheManagedState(): void
    {
        $record = $this->record();
        $id = $record->id;
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $record->note = 'rolled back';
        $this->em->flush();
        $db->rollBack();
        self::assertSame('initial', $db->fetchOne('SELECT note FROM modernorm_audit_records WHERE id = ?', [$id]));
        self::assertSame('rolled back', $record->note);
        self::assertSame('rolled back', $this->em->getUnitOfWork()->getOriginalEntityData($record)['note']);
        $this->em->clear();
        self::assertSame('initial', $this->em->find(AuditRecord::class, $id)->note);
    }

    public function testTransactionWrapperClosesContextAfterOuterRollback(): void
    {
        $record = $this->record();
        try {
            $this->em->wrapInTransaction(function () use ($record): void {
                $record->note = 'flushed before failure';
                $this->em->flush();
                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException $error) {
            self::assertSame('outer failure', $error->getMessage());
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame('initial', $this->em->getConnection()->fetchOne('SELECT note FROM modernorm_audit_records WHERE id = ?', [$record->id]));
    }

    public function testPostFlushFailureDoesNotReplayCommittedInsert(): void
    {
        $record = new AuditRecord('committed');
        $calls = 0;
        $this->listen(Events::postFlush, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('after commit');
            }
        });
        $this->em->persist($record);
        try {
            $this->em->flush();
            self::fail('Expected postFlush exception.');
        } catch (RuntimeException $error) {
            self::assertSame('after commit', $error->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($record));
        $id = $record->id;
        $this->em->flush();
        self::assertSame($id, $record->id);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_audit_records'));
    }

    public static function transferCases(): iterable
    {
        yield 'source discovered first' => [false];
        yield 'destination discovered first' => [true];
    }

    #[DataProvider('transferCases')]
    public function testExplicitTransferWithCollectionReplacementSurvivesRegardlessOfDiscoveryOrder(bool $destinationFirst): void
    {
        [$source, $ids] = $this->owner();
        $sourceId = $source->id;
        $destination = new AuditOwner();
        $this->em->persist($destination);
        $this->em->flush();
        $destinationId = $destination->id;
        $this->em->clear();
        if ($destinationFirst) {
            $destination = $this->em->find(AuditOwner::class, $destinationId);
        }
        $source = $this->em->find(AuditOwner::class, $sourceId);
        $destination = $this->em->find(AuditOwner::class, $destinationId);
        $child = $source->children->get($ids[0]);
        $destination->children = new ArrayCollection([$child]);
        $child->owner = $destination;
        $source->children = new ArrayCollection();
        $this->em->flush();
        $this->em->clear();
        $child = $this->em->find(AuditChild::class, $ids[0]);
        self::assertNotNull($child);
        self::assertSame($destinationId, $child->owner->id);
        self::assertNull($this->em->find(AuditChild::class, $ids[1]));
    }

    public function testPreUpdateFailureRollsBackAndClosesManager(): void
    {
        $record = $this->record();
        $this->listen(Events::preUpdate, static function (): void {
            throw new RuntimeException('callback failed');
        });
        $record->note = 'unsaved';
        try {
            $this->em->flush();
            self::fail('Expected callback exception.');
        } catch (RuntimeException $error) {
            self::assertSame('callback failed', $error->getMessage());
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame('initial', $this->em->getConnection()->fetchOne('SELECT note FROM modernorm_audit_records WHERE id = ?', [$record->id]));
        self::assertSame('unsaved', $record->note);
    }

    public function testNestedFlushIsRejectedBeforeItCanWrite(): void
    {
        $record = $this->record();
        $nestedCalls = 0;
        $this->listen(Events::preFlush, function () use (&$nestedCalls): void {
            if (++$nestedCalls === 1) {
                $this->em->flush();
            }
        });
        $record->note = 'pending';
        try {
            $this->em->flush();
            self::fail('Expected nested flush rejection.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('flush', $error->getMessage());
        }
        self::assertSame('initial', $this->em->getConnection()->fetchOne('SELECT note FROM modernorm_audit_records WHERE id = ?', [$record->id]));
        $this->em->flush();
        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT note FROM modernorm_audit_records WHERE id = ?', [$record->id]));
    }
}
