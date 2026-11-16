<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Exception\EntityIdentityCollisionException;
use ModernORM\Internal\UnitOfWork\EntityStateManager;
use ModernORM\Internal\UnitOfWork\IdentityMap;
use ModernORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class EntityStateManagerTest extends TestCase
{
    private EntityStateManager $states;
    private IdentityMap $map;
    protected function setUp(): void
    {
        $this->map = new IdentityMap();
        $this->states = new EntityStateManager(function (object $entity): bool {
            $oid = spl_object_id($entity);
            self::assertSame(UnitOfWork::STATE_MANAGED, $this->states->states[$oid]);
            self::assertArrayHasKey($oid, $this->states->snapshots);
            return $this->map->add($entity::class, UnitOfWork::getIdHashByIdentifier($this->states->identifiers[$oid]), $entity);
        });
    }
    public function testManagedRegistrationPublishesConsistentStateAndReplacesSnapshot(): void
    {
        $entity = new stdClass();
        $data = ['id' => '01', 'name' => 'first', 'nested' => ['value' => 1]];
        $this->states->registerManaged($entity, ['id' => '01'], $data);
        $oid = spl_object_id($entity);
        self::assertSame(['id' => '01'], $this->states->identifiers[$oid]);
        self::assertSame($entity, $this->map->get(stdClass::class, '01'));
        $this->states->snapshots[$oid]['nested']['value'] = 2;
        self::assertSame(1, $data['nested']['value']);
        $this->states->registerManaged($entity, ['id' => '01'], ['replacement' => true]);
        self::assertSame(['replacement' => true], $this->states->snapshots[$oid]);
        self::assertSame(1, $this->map->size());
    }
    public function testPostInsertRegistrationMergesIdWithoutAssigningEntityProperties(): void
    {
        $entity = new stdClass();
        $oid = spl_object_id($entity);
        $this->states->snapshots[$oid] = ['name' => 'pending', 'marker' => ['keep']];
        $this->states->states[$oid] = UnitOfWork::STATE_NEW;
        $this->states->registerPostInsertId($entity, 'id', 0);
        self::assertSame(['id' => 0], $this->states->identifiers[$oid]);
        self::assertSame(['name' => 'pending', 'marker' => ['keep'], 'id' => 0], $this->states->snapshots[$oid]);
        self::assertSame($entity, $this->map->get(stdClass::class, '0'));
        self::assertFalse(property_exists($entity, 'id'));
    }
    public function testCollisionKeepsCanonicalObjectAndPreviouslyRecordedState(): void
    {
        $first = new stdClass();
        $copy = new stdClass();
        $this->states->registerManaged($first, ['id' => 7], ['name' => 'first']);
        try {
            $this->states->registerManaged($copy, ['id' => 7], ['name' => 'copy']);
            self::fail('A different object at the same identity must fail.');
        } catch (EntityIdentityCollisionException) {
            self::assertSame($first, $this->map->get(stdClass::class, '7'));
            self::assertFalse($this->map->contains(stdClass::class, '7', $copy));
            self::assertSame(['id' => 7], $this->states->identifiers[spl_object_id($copy)]);
            self::assertSame(['name' => 'copy'], $this->states->snapshots[spl_object_id($copy)]);
            self::assertSame(UnitOfWork::STATE_MANAGED, $this->states->states[spl_object_id($copy)]);
        }
    }
    public static function transitions(): array
    {
        return ['managed registration' => [false], 'post-insert ID' => [true]];
    }
    #[DataProvider('transitions')]
    public function testPublicationFailurePreservesMutationOrder(bool $postInsert): void
    {
        $failure = new RuntimeException('identity validation failed');
        $states = new EntityStateManager(static function () use ($failure): bool {
            throw $failure;
        });
        $entity = new stdClass();
        $oid = spl_object_id($entity);
        $states->snapshots[$oid] = ['marker' => 'keep'];
        try {
            if ($postInsert) {
                $states->registerPostInsertId($entity, 'id', 9);
            } else {
                $states->registerManaged($entity, ['id' => 9], ['id' => 9]);
            }
            self::fail('Publication failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
            self::assertSame(['id' => 9], $states->identifiers[$oid]);
            self::assertSame(UnitOfWork::STATE_MANAGED, $states->states[$oid]);
            self::assertSame($postInsert ? ['marker' => 'keep', 'id' => 9] : ['id' => 9], $states->snapshots[$oid]);
        }
    }
    public function testRegistriesDoNotShareMaps(): void
    {
        $other = new EntityStateManager(static fn (object $entity): bool => true);
        $this->states->registerManaged(new stdClass(), ['id' => 1], ['id' => 1]);
        self::assertSame([], $other->identifiers);
        self::assertSame([], $other->states);
        self::assertSame([], $other->snapshots);
    }

    public static function cleanupKinds(): array
    {
        return ['successful DELETE' => [false], 'detach' => [true]];
    }

    #[DataProvider('cleanupKinds')]
    public function testCleanupIsIdempotentAndIsolatedFromIdentityAndOtherEntities(bool $detach): void
    {
        $entity = (object) ['id' => 7];
        $other = new stdClass();
        $this->states->registerManaged($entity, ['id' => 7], ['name' => 'removed']);
        $this->states->registerManaged($other, ['id' => 8], ['name' => 'retained']);
        $oid = spl_object_id($entity);
        $snapshot = $this->states->snapshots[$oid];
        for ($i = 0; $i < 2; ++$i) {
            if ($detach) {
                $this->states->forgetAfterDetach($oid);
            } else {
                $this->states->forgetAfterDeletion($oid);
            }
            self::assertArrayNotHasKey($oid, $this->states->identifiers);
            self::assertArrayNotHasKey($oid, $this->states->states);
            self::assertArrayNotHasKey($oid, $this->states->snapshots);
        }
        self::assertSame(['name' => 'removed'], $snapshot);
        self::assertSame(['id' => 8], $this->states->identifiers[spl_object_id($other)]);
        self::assertSame(['name' => 'retained'], $this->states->snapshots[spl_object_id($other)]);
        self::assertSame(UnitOfWork::STATE_MANAGED, $this->states->states[spl_object_id($other)]);
        self::assertSame($entity, $this->map->get(stdClass::class, '7'));
        self::assertSame(7, $entity->id);
    }

    #[DataProvider('cleanupKinds')]
    public function testSnapshotReleasePreservesEachTransitionsObservationOrder(bool $detach): void
    {
        $entity = new stdClass();
        $this->states->registerManaged($entity, ['id' => 7], []);
        $oid = spl_object_id($entity);
        $observed = [];
        $this->states->snapshots[$oid] = ['probe' => new class ($this->states, $oid, static function (bool $hasIdentifier, bool $hasState) use (&$observed): void {
            $observed[] = [$hasIdentifier, $hasState];
        }) {
            public function __construct(private EntityStateManager $states, private int $oid, private \Closure $observe)
            {
            }
            public function __destruct()
            {
                ($this->observe)(isset($this->states->identifiers[$this->oid]), isset($this->states->states[$this->oid]));
            }
        }];
        if ($detach) {
            $this->states->forgetAfterDetach($oid);
        } else {
            $this->states->forgetAfterDeletion($oid);
        }
        self::assertSame([[false, ! $detach]], $observed);
    }

}
