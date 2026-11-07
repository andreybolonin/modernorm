<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Type;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\ArrayHydrator;
use ModernORM\Internal\Hydration\ObjectHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query;
use ModernORM\Query\ResultSetMapping;
use PHPUnit\Framework\TestCase;

final class HydrationCountingJson extends JsonType
{
    public static int $conversions = 0;
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        ++self::$conversions;
        return parent::convertToPHPValue($value, $platform);
    }
}

enum HydrationStatus: string
{
    case Active = 'active';
}

#[ORM\Entity]
class HydrationParent
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'hydration_counting_json')]
    public array $payload;
    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;
    #[ORM\Column(enumType: HydrationStatus::class)]
    public HydrationStatus $status;
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: HydrationChild::class)]
    public Collection $children;
}

#[ORM\Entity]
class HydrationChild
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column]
    public string $name;
    #[ORM\ManyToOne(targetEntity: HydrationParent::class, inversedBy: 'children')]
    public HydrationParent $parent;
}

final class HydrationDto
{
    public function __construct(public HydrationParent $parent, public int $total)
    {
    }
}

final class ObjectHydrationTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        if (! Type::hasType('hydration_counting_json')) {
            Type::addType('hydration_counting_json', HydrationCountingJson::class);
        }
        HydrationCountingJson::$conversions = 0;
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
    }

    /** @param list<array<string, mixed>> $rows */
    private function statement(array $rows): Result
    {
        $statement = $this->createMock(Result::class);
        $statement->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        $statement->expects(self::once())->method('free');
        return $statement;
    }

    private function mapping(bool $joined = true, string $alias = 'p'): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(HydrationParent::class, $alias);
        foreach (['p_id' => 'id', 'payload' => 'payload', 'created' => 'createdAt', 'status' => 'status'] as $column => $field) {
            $rsm->addFieldResult($alias, $column, $field);
        }
        $rsm->addEnumResult('status', HydrationStatus::class);
        if ($joined) {
            $rsm->addJoinedEntityResult(HydrationChild::class, 'c', $alias, 'children');
            $rsm->addFieldResult('c', 'c_id', 'id');
            $rsm->addFieldResult('c', 'c_name', 'name');
            $rsm->addMetaResult('c', 'c_parent', 'parent_id', false, 'integer');
        }
        return $rsm;
    }

    private function row(?int $child = 10, int $parent = 1): array
    {
        return ['p_id' => (string) $parent, 'payload' => '{"ok":true}', 'created' => '2026-11-07 12:34:56', 'status' => 'active',
            'c_id' => $child === null ? null : (string) $child, 'c_name' => $child === null ? null : 'child ' . $child, 'c_parent' => $child === null ? null : (string) $parent];
    }

    public function testRepeatedParentConvertsOnceAndPostLoadSeesCompleteGraph(): void
    {
        $loaded = [];
        $listener = new class ($loaded) {
            public function __construct(public array &$loaded)
            {
            }
            public function postLoad(\ModernORM\Event\PostLoadEventArgs $event): void
            {
                $entity = $event->getObject();
                $this->loaded[] = $entity;
                if ($entity instanceof HydrationParent) {
                    TestCase::assertCount(2, $entity->children);
                }
            }
        };
        $this->em->getEventManager()->addEventListener(['postLoad'], $listener);
        $result = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row(), $this->row(11), $this->row()]), $this->mapping());
        self::assertCount(1, $result);
        self::assertSame(1, HydrationCountingJson::$conversions);
        $parent = $result[0];
        self::assertSame(['ok' => true], $parent->payload);
        self::assertInstanceOf(DateTimeImmutable::class, $parent->createdAt);
        self::assertSame(HydrationStatus::Active, $parent->status);
        self::assertCount(3, $loaded);
        self::assertFalse($parent->children->isDirty());
        self::assertCount(2, $parent->children->getSnapshot());
        foreach ($parent->children as $child) {
            self::assertSame($parent, $child->parent);
            self::assertSame($child, $this->em->find(HydrationChild::class, $child->id));
        }
        self::assertSame($parent, $this->em->find(HydrationParent::class, 1));
    }

    public function testFetchJoinInitializesAnExistingLazyReference(): void
    {
        $parent = $this->em->getReference(HydrationParent::class, 1);
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($parent));
        $result = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row(), $this->row(11)]), $this->mapping());
        self::assertSame($parent, $result[0]);
        self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($parent));
        self::assertSame(['ok' => true], $parent->payload);
        self::assertCount(2, $parent->children);
        self::assertSame(1, HydrationCountingJson::$conversions);
    }

    public function testToOneFetchJoinPreservesIdentityForSharedTarget(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(HydrationChild::class, 'c');
        $rsm->addFieldResult('c', 'c_id', 'id');
        $rsm->addFieldResult('c', 'c_name', 'name');
        $rsm->addMetaResult('c', 'c_parent', 'parent_id', false, 'integer');
        $rsm->addJoinedEntityResult(HydrationParent::class, 'p', 'c', 'parent');
        foreach (['p_id' => 'id', 'payload' => 'payload', 'created' => 'createdAt', 'status' => 'status'] as $column => $field) {
            $rsm->addFieldResult('p', $column, $field);
        }
        $rsm->addEnumResult('status', HydrationStatus::class);
        $children = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row(), $this->row(11)]), $rsm);
        self::assertCount(2, $children);
        self::assertSame($children[0]->parent, $children[1]->parent);
        self::assertSame(['ok' => true], $children[0]->parent->payload);
        // Each root reaches the to-one branch; Identity Map still returns the same target.
        self::assertSame(2, HydrationCountingJson::$conversions);
    }

    public function testSameCollectionJoinedTwiceKeepsCorrectElementPointers(): void
    {
        $rsm = $this->mapping();
        $rsm->addJoinedEntityResult(HydrationChild::class, 'other', 'p', 'children');
        $rsm->addFieldResult('other', 'other_id', 'id');
        $rsm->addFieldResult('other', 'other_name', 'name');
        $rsm->addMetaResult('other', 'other_parent', 'parent_id', false, 'integer');
        $rows = [];
        foreach ([10, 11] as $first) {
            foreach ([10, 11] as $second) {
                $rows[] = $this->row($first) + ['other_id' => (string) $second, 'other_name' => 'child ' . $second, 'other_parent' => '1'];
            }
        }
        $parent = (new ObjectHydrator($this->em))->hydrateAll($this->statement($rows), $rsm)[0];
        self::assertSame([10, 11], array_map(static fn ($child) => $child->id, $parent->children->toArray()));
        self::assertCount(2, $parent->children->getSnapshot());
    }

    public function testPreexistingPlainCollectionKeepsElementsAndAvoidsDuplicates(): void
    {
        $parent = new HydrationParent();
        $parent->id = 1;
        $child = new HydrationChild();
        $child->id = 10;
        $child->name = 'managed';
        $child->parent = $parent;
        $parent->children = new ArrayCollection([7 => $child]);
        $this->em->getUnitOfWork()->registerManaged($parent, ['id' => 1], []);
        $this->em->getUnitOfWork()->registerManaged($child, ['id' => 10], []);
        $result = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row(11), $this->row(), $this->row(11)]), $this->mapping());
        self::assertSame($parent, $result[0]);
        self::assertSame([7 => 10, 8 => 11], array_map(static fn ($item) => $item->id, $parent->children->toArray()));
        self::assertSame($child, $parent->children[7]);
        self::assertSame('managed', $child->name);
    }

    public function testInitializedCollectionIsPreservedAndRefreshReplacesItsContents(): void
    {
        $hydrator = new ObjectHydrator($this->em);
        $rsm = $this->mapping();
        $parent = $hydrator->hydrateAll($this->statement([$this->row()]), $rsm)[0];
        $parent->payload = ['local' => true];
        $again = $hydrator->hydrateAll($this->statement([$this->row(11)]), $rsm)[0];
        self::assertSame($parent, $again);
        self::assertSame(['local' => true], $parent->payload);
        self::assertSame([10], array_map(static fn ($item) => $item->id, $parent->children->toArray()));
        $hydrator->hydrateAll($this->statement([$this->row(11)]), $rsm, [Query::HINT_REFRESH => true]);
        self::assertSame(['ok' => true], $parent->payload);
        self::assertSame([11], array_map(static fn ($item) => $item->id, $parent->children->toArray()));
        self::assertFalse($parent->children->isDirty());
    }

    public function testIndexByAndEmptyLeftJoin(): void
    {
        $rsm = $this->mapping();
        $rsm->addIndexBy('p', 'id');
        $rsm->addIndexBy('c', 'name');
        $result = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row(), $this->row(), $this->row(null, 2)]), $rsm);
        self::assertSame([1, 2], array_keys($result));
        self::assertSame(['child 10'], array_keys($result[1]->children->toArray()));
        self::assertTrue($result[2]->children->isInitialized());
        self::assertCount(0, $result[2]->children);
    }

    public function testCustomGatherRowDataStillReceivesEagerEntityData(): void
    {
        $hydrator = new class ($this->em) extends ObjectHydrator {
            public int $calls = 0;
            protected function gatherRowData(array $data, array &$id, array &$nonemptyComponents): array
            {
                ++$this->calls;
                $result = parent::gatherRowData($data, $id, $nonemptyComponents);
                TestCase::assertSame(['ok' => true], $result['data']['p']['payload']);
                return $result;
            }
        };
        $result = $hydrator->hydrateAll($this->statement([$this->row(), $this->row(11)]), $this->mapping());
        self::assertCount(2, $result[0]->children);
        self::assertSame(2, $hydrator->calls);
        self::assertSame(2, HydrationCountingJson::$conversions);
    }

    public function testMixedScalarsAndNestedDtoKeepTheirConversions(): void
    {
        $rsm = $this->mapping(false);
        $rsm->addScalarResult('total', 'total', 'integer');
        $result = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row() + ['total' => '42']]), $rsm);
        self::assertInstanceOf(HydrationParent::class, $result[0][0]);
        self::assertSame(42, $result[0]['total']);
        $this->em->clear();
        $rsm = $this->mapping(false);
        $rsm->addScalarResult('total', 'total', 'integer');
        $rsm->newObjectMappings['total'] = ['className' => HydrationDto::class, 'objIndex' => 0, 'argIndex' => 1];
        $rsm->nestedEntities['p'] = ['ownerIndex' => 0, 'argIndex' => 0];
        $rsm->nestedNewObjectArguments[] = ['ownerIndex' => 0, 'argIndex' => 0, 'argAlias' => 'p'];
        $result = (new ObjectHydrator($this->em))->hydrateAll($this->statement([$this->row() + ['total' => '42']]), $rsm);
        self::assertInstanceOf(HydrationDto::class, $result[0]);
        self::assertSame(42, $result[0]->total);
        self::assertSame(['ok' => true], $result[0]->parent->payload);
    }

    public function testPlanAndResultIndexesResetAfterFailureAndAbandonedStream(): void
    {
        $hydrator = new ObjectHydrator($this->em);
        $bad = $this->row();
        $bad['payload'] = 'invalid json';
        try {
            $hydrator->hydrateAll($this->statement([$bad]), $this->mapping());
            self::fail('Expected JSON conversion failure.');
        } catch (\Doctrine\DBAL\Types\Exception\ValueNotConvertible) {
        }
        $stream = $hydrator->toIterable($this->statement([$this->row(null, 2), $this->row(null, 3)]), $this->mapping(false));
        $stream->rewind();
        self::assertSame(2, $stream->current()->id);
        unset($stream);
        $result = $hydrator->hydrateAll($this->statement([$this->row(null, 4)]), $this->mapping(false, 'different'));
        self::assertSame([0], array_keys($result));
        self::assertSame(4, $result[0]->id);
        self::assertSame([5, 6], array_map(static fn ($item) => $item->id, iterator_to_array($hydrator->toIterable(
            $this->statement([$this->row(null, 5), $this->row(null, 6)]),
            $this->mapping(false),
        ))));
    }

    public function testRepeatedIdentifierFieldsUseTheFirstNonNullValue(): void
    {
        $rsm = $this->mapping();
        $rsm->addFieldResult('p', 'second_id', 'id');
        $first = $this->row() + ['second_id' => '999'];
        $second = $this->row(11) + ['second_id' => '888'];
        $fallback = $this->row(11) + ['second_id' => '1'];
        $fallback['p_id'] = null;
        foreach ([ObjectHydrator::class, ArrayHydrator::class] as $class) {
            $this->em->clear();
            $result = (new $class($this->em))->hydrateAll($this->statement([$first, $second, $fallback]), $rsm);
            self::assertCount(1, $result);
            $id = $class === ObjectHydrator::class ? $result[0]->id : $result[0]['id'];
            self::assertSame(1, $id);
        }
    }

    public function testArrayHydrationPreservesRepeatedFieldsEnumsAndMetaValues(): void
    {
        $rsm = $this->mapping();
        $rsm->addFieldResult('p', 'payload_again', 'payload');
        $rows = [$this->row() + ['payload_again' => '{"ignored":true}'], $this->row(11) + ['payload_again' => null]];
        $result = (new ArrayHydrator($this->em))->hydrateAll($this->statement($rows), $rsm);
        self::assertSame(['ok' => true], $result[0]['payload']);
        self::assertSame(HydrationStatus::Active, $result[0]['status']);
        self::assertCount(2, $result[0]['children']);
        self::assertSame(2, HydrationCountingJson::$conversions);
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
    }
}
