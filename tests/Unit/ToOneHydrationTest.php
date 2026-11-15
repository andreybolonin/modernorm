<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Internal\UnitOfWork\ToOneHydrationMode;
use ModernORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

enum ToOneStringKey: string
{
    case Key = 'key';
}
enum ToOneIntegerKey: int
{
    case Key = 7;
}
class ToOnePlanRecord
{
}

final class ToOneHydrationTest extends TestCase
{
    private function target(): ORM\ClassMetadata
    {
        $target = new ORM\ClassMetadata(ToOnePlanRecord::class);
        $target->mapField(['fieldName' => 'key', 'columnName' => 'record_key', 'type' => 'string', 'id' => true]);
        return $target;
    }
    private function association(): ORM\ToOneOwningSideMapping
    {
        $source = new ORM\ClassMetadata(ToOnePlanRecord::class);
        $source->mapManyToOne(['fieldName' => 'target', 'targetEntity' => ToOnePlanRecord::class,
            'joinColumns' => [['name' => 'target_ref', 'referencedColumnName' => 'record_key']]]);
        $association = $source->associationMappings['target'];
        self::assertInstanceOf(ORM\ToOneOwningSideMapping::class, $association);
        return $association;
    }
    public static function identifiers(): iterable
    {
        yield 'missing FK' => [[], []];
        yield 'null FK' => [['target_ref' => null], []];
        yield 'zero remains an ID' => [['target_ref' => 0], ['key' => 0]];
        yield 'empty string remains an ID' => [['target_ref' => ''], ['key' => '']];
        yield 'physical FK column' => [['target_ref' => 'abc', 'target' => 'wrong'], ['key' => 'abc']];
        yield 'string enum' => [['target_ref' => ToOneStringKey::Key], ['key' => 'key']];
        yield 'integer enum' => [['target_ref' => ToOneIntegerKey::Key], ['key' => 7]];
    }
    #[DataProvider('identifiers')]
    public function testIdentifierExtractionDoesNotMutateInputs(array $data, array $expected): void
    {
        $association = $this->association();
        $target = $this->target();
        $before = serialize([$association, $target, $data]);
        self::assertSame($expected, EntityHydrator::toOneIdentifier($association, $target, $data));
        self::assertSame($before, serialize([$association, $target, $data]));
    }
    public static function loadingModes(): iterable
    {
        $lazy = ORM\ClassMetadata::FETCH_LAZY;
        $eager = ORM\ClassMetadata::FETCH_EAGER;
        yield 'NULL before all other decisions' => [[], true, true, $eager, true, true, ToOneHydrationMode::NULL_REFERENCE];
        yield 'SoftDelete requires visible lookup' => [['key' => 'x'], true, false, $lazy, null, false, ToOneHydrationMode::SOFT_DELETE_LOOKUP];
        yield 'SoftDelete bypasses raw managed reuse' => [['key' => 'x'], true, true, $eager, true, true, ToOneHydrationMode::SOFT_DELETE_LOOKUP];
        yield 'reuse lazy managed target' => [['key' => 'x'], false, true, $lazy, true, true, ToOneHydrationMode::MANAGED];
        yield 'reuse initialized eager target' => [['key' => 'x'], false, true, $eager, true, false, ToOneHydrationMode::MANAGED];
        yield 'existing eager proxy without hint' => [['key' => 'x'], false, true, $eager, null, true, ToOneHydrationMode::MANAGED];
        yield 'existing eager proxy with enabled hint' => [['key' => 'x'], false, true, $eager, true, true, ToOneHydrationMode::MANAGED_DEFERRED];
        yield 'existing eager proxy with disabled hint retains presence behavior' => [['key' => 'x'], false, true, $eager, false, true, ToOneHydrationMode::MANAGED_DEFERRED];
        yield 'existing eager proxy with zero hint retains presence behavior' => [['key' => 'x'], false, true, $eager, 0, true, ToOneHydrationMode::MANAGED_DEFERRED];
        yield 'new lazy reference' => [['key' => 'x'], false, false, $lazy, null, false, ToOneHydrationMode::REFERENCE];
        yield 'deferred hint does not change LAZY' => [['key' => 'x'], false, false, $lazy, true, false, ToOneHydrationMode::REFERENCE];
        yield 'new eager target without hint' => [['key' => 'x'], false, false, $eager, null, false, ToOneHydrationMode::EAGER_LOOKUP];
        yield 'new eager target with disabled hint' => [['key' => 'x'], false, false, $eager, false, false, ToOneHydrationMode::EAGER_LOOKUP];
        yield 'new eager target with zero hint' => [['key' => 'x'], false, false, $eager, 0, false, ToOneHydrationMode::EAGER_LOOKUP];
        yield 'new eager target with enabled hint' => [['key' => 'x'], false, false, $eager, true, false, ToOneHydrationMode::REFERENCE_DEFERRED];
        yield 'new eager target with truthy hint' => [['key' => 'x'], false, false, $eager, 'enabled', false, ToOneHydrationMode::REFERENCE_DEFERRED];
    }
    #[DataProvider('loadingModes')]
    public function testLoadingDecisionsUseOnlySuppliedFacts(array $id, bool $softDelete, bool $managed, int $fetchMode, mixed $hint, bool $uninitialized, ToOneHydrationMode $expected): void
    {
        $target = $this->target();
        $target->softDeleteField = $softDelete ? 'deletedAt' : null;
        $entity = $managed ? new stdClass() : null;
        $before = serialize([$target, $id, $entity]);
        self::assertSame($expected, EntityHydrator::planToOneLoad($target, $id, $entity, $fetchMode, $hint, $uninitialized));
        self::assertSame($before, serialize([$target, $id, $entity]));
    }
}
