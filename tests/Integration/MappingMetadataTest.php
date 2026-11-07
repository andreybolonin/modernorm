<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function getenv;
use function serialize;
use function unserialize;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_mapping_defaults')]
final class MappingDefaultsRecord
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Version, ORM\Column(type: 'integer', options: ['default' => 7])]
    public int $revision;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    public int $zero;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $disabled;

    #[ORM\Column(options: ['default' => ''])]
    public string $empty;

    #[ORM\Column(nullable: true, options: ['default' => null])]
    public string|null $optional = null;
}

#[ORM\Entity]
class ScalarSchemaTarget
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'scalar_owner_tenant_code', fields: ['tenant', 'code'])]
class ScalarOwnerWithCompositeConstraints
{
    #[ORM\Id, ORM\Column(name: 'owner_key')] public string $id;
    #[ORM\Column] public string $tenant;
    #[ORM\Column] public string $code;
    #[ORM\ManyToMany(targetEntity: ScalarSchemaTarget::class)]
    #[ORM\JoinTable(name: 'scalar_owner_targets', joinColumns: [new ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'owner_key')], inverseJoinColumns: [new ORM\JoinColumn(name: 'target_id')])]
    public \Doctrine\Common\Collections\Collection $targets;
}

final class MappingMetadataTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
            'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), $config);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
    }

    public function testScalarEntityIdsRetainCompositeUniqueAndJoinTablePrimaryKeys(): void
    {
        $em = $this->em;
        $owner = $em->getClassMetadata(ScalarOwnerWithCompositeConstraints::class);
        $target = $em->getClassMetadata(ScalarSchemaTarget::class);
        $schema = (new SchemaTool($em))->getSchemaFromMetadata([$owner, $target]);
        $table = $schema->getTable($owner->getTableName());
        self::assertSame(['owner_key'], $table->getPrimaryKey()->getColumns());
        self::assertSame(['tenant', 'code'], $table->getIndex('scalar_owner_tenant_code')->getColumns());
        $join = $schema->getTable('scalar_owner_targets');
        self::assertSame(['owner_id', 'target_id'], $join->getPrimaryKey()->getColumns());
        self::assertCount(2, $join->getForeignKeys());
        foreach ($join->getForeignKeys() as $foreignKey) {
            self::assertSame($foreignKey->getForeignTableName() === $owner->getTableName() ? ['owner_key'] : ['id'], $foreignKey->getForeignColumns());
        }
    }

    public function testColumnOverrideAndReverseLookupSurviveSerialization(): void
    {
        $metadata = $this->em->getClassMetadata(MappingDefaultsRecord::class);
        $metadata->setAttributeOverride('zero', ['columnName' => 'zero_value']);
        $restored = unserialize(serialize($metadata));
        self::assertInstanceOf(ClassMetadata::class, $restored);
        $restored->wakeupReflection(new RuntimeReflectionService());

        self::assertSame('zero_value', $restored->getColumnName('zero'));
        self::assertSame('zero', $restored->getFieldName('zero_value'));
        self::assertArrayNotHasKey('zero', $restored->fieldNames);
        self::assertSame('unknown', $restored->getColumnName('unknown'));
    }

    public function testSchemaDefaultsSurviveMetadataSerialization(): void
    {
        $metadata = unserialize(serialize($this->em->getClassMetadata(MappingDefaultsRecord::class)));
        $metadata->wakeupReflection(new RuntimeReflectionService());
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata([$metadata]);
        $table = $schema->getTable('modernorm_test_mapping_defaults');

        self::assertSame(7, $table->getColumn('revision')->getDefault());
        self::assertSame(0, $table->getColumn('zero')->getDefault());
        self::assertFalse($table->getColumn('disabled')->getDefault());
        self::assertSame('', $table->getColumn('empty')->getDefault());
        self::assertNull($table->getColumn('optional')->getDefault());
    }

    /** @return iterable<string, array{int|null}> */
    public static function explicitDefaults(): iterable
    {
        yield 'custom' => [7];
        yield 'zero' => [0];
        yield 'null' => [null];
    }

    #[DataProvider('explicitDefaults')]
    public function testVersionMappingPreservesExplicitDefault(int|null $default): void
    {
        $metadata = new ClassMetadata(MappingDefaultsRecord::class);
        $mapping = ['fieldName' => 'revision', 'type' => 'integer', 'options' => ['default' => $default]];
        $metadata->setVersionMapping($mapping);
        $metadata->mapField($mapping);

        self::assertSame($default, $metadata->getFieldMapping('revision')->options['default']);
    }

    public function testLegacyTopLevelDefaultIsRejectedRatherThanIgnored(): void
    {
        $metadata = $this->em->getClassMetadata(MappingDefaultsRecord::class);
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('options["default"]');
        $metadata->mapField(['fieldName' => 'discardedDefault', 'type' => 'integer', 'default' => 0]);
    }
}
