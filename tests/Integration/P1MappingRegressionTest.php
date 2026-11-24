<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TypeRegistry;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMappingBuilder;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\MappedSuperclass]
class P1ExplicitSequenceBase
{
    #[ORM\Id, ORM\Column(name: '`RecordID`', type: 'integer'), ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: '`InheritedSeq`', allocationSize: 3, initialValue: 11)]
    public ?int $id = null;
}

#[ORM\MappedSuperclass]
class P1SequenceMiddle extends P1ExplicitSequenceBase
{
}

#[ORM\Entity, ORM\Table(name: 'sequence_first')]
final class P1SequenceFirst extends P1SequenceMiddle
{
}

#[ORM\Entity, ORM\Table(name: 'sequence_second')]
final class P1SequenceSecond extends P1ExplicitSequenceBase
{
}

#[ORM\Entity, ORM\Table(name: 'sequence_override')]
final class P1SequenceOverride extends P1ExplicitSequenceBase
{
}

#[ORM\MappedSuperclass]
class P1DefaultSequenceBase
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'SEQUENCE')]
    public ?int $id = null;
}

#[ORM\Entity, ORM\Table(name: 'default_first')]
final class P1DefaultSequenceFirst extends P1DefaultSequenceBase
{
}

#[ORM\Entity, ORM\Table(name: 'default_second')]
final class P1DefaultSequenceSecond extends P1DefaultSequenceBase
{
}

#[ORM\Entity, ORM\Table(name: 'ansi_records')]
final class P1AnsiRecord
{
    #[ORM\Id, ORM\Column(name: 'record_key', type: 'string')]
    public string $id = 'key';
    #[ORM\Column(type: 'string')]
    public string $name = 'initial';
}

final class P1UpperType extends StringType
{
    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return 'UPPER(' . $sqlExpr . ')';
    }
}

final class P1AllQuoteStrategy extends ORM\DefaultQuoteStrategy
{
    public function getColumnName(string $fieldName, ORM\ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $platform->quoteSingleIdentifier($class->getColumnName($fieldName));
    }
}

#[ORM\Entity, ORM\Table(name: 'native_parents')]
final class P1NativeParent
{
    #[ORM\Id, ORM\Column(name: '`ParentID`', type: 'integer')]
    public int $id = 7;
    #[ORM\Column(name: '`Parent Label`', type: 'string')]
    public string $label = 'parent';
}

#[ORM\Entity, ORM\Table(name: 'native_records')]
final class P1NativeRecord
{
    #[ORM\Id, ORM\Column(name: '`RecordID`', type: 'integer')]
    public int $id = 11;
    #[ORM\Column(name: '`Select Value`', type: 'p1_upper')]
    public string $value = 'mixed';
    #[ORM\Column(name: '`QuotedText`', type: 'string')]
    public string $quoted = 'quoted';
    #[ORM\Column(type: 'string')]
    public string $plain = 'plain';
    #[ORM\ManyToOne(targetEntity: P1NativeParent::class)]
    #[ORM\JoinColumn(name: '`OwnerRef`', referencedColumnName: '`ParentID`')]
    public ?P1NativeParent $parent = null;
}

final class P1MappingRegressionTest extends TestCase
{
    private EntityManager $em;
    private string $schema;

    protected function setUp(): void
    {
        $this->schema = 'p1_mapping_' . bin2hex(random_bytes(5));
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $connection->getConfiguration()->setTypeProvider(new TypeRegistry(['p1_upper' => new P1UpperType()]));
        $config = ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter(0, true));
        $config->setMetadataDriverImpl(new class ($this->schema) extends AttributeDriver {
            public function __construct(private readonly string $schema)
            {
                parent::__construct([]);
            }
            public function loadMetadataForClass(string $className, \Doctrine\Persistence\Mapping\ClassMetadata $metadata): void
            {
                parent::loadMetadataForClass($className, $metadata);
                assert($metadata instanceof ORM\ClassMetadata);
                $metadata->table['schema'] = $this->schema;
                if ($className === P1AnsiRecord::class) {
                    // AnsiQuoteStrategy expects an already-qualified ANSI table name.
                    $metadata->table['name'] = $this->schema . '.ansi_records';
                    unset($metadata->table['schema']);
                }
                if ($metadata->sequenceGeneratorDefinition !== null && ! str_starts_with($metadata->sequenceGeneratorDefinition['sequenceName'], $this->schema . '.')) {
                    $definition = $metadata->sequenceGeneratorDefinition;
                    $definition['sequenceName'] = $this->schema . '.' . $definition['sequenceName'];
                    $metadata->setSequenceGeneratorDefinition($definition);
                }
                if ($className === P1SequenceOverride::class) {
                    $metadata->setSequenceGeneratorDefinition(['sequenceName' => $this->schema . '.override_seq', 'allocationSize' => 1, 'initialValue' => 40]);
                }
            }
        });
        $this->em = new EntityManager($connection, $config);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $db = $this->em->getConnection();
            $db->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $db->close();
        }
    }

    public function testExplicitInheritedSequenceKeepsNameAllocationAndInitialValue(): void
    {
        $classes = array_map($this->em->getClassMetadata(...), [P1SequenceFirst::class, P1SequenceSecond::class, P1SequenceOverride::class]);
        foreach (array_slice($classes, 0, 2) as $metadata) {
            self::assertSame($this->schema . '.InheritedSeq', $metadata->sequenceGeneratorDefinition['sequenceName']);
            self::assertSame('3', $metadata->sequenceGeneratorDefinition['allocationSize']);
            self::assertSame('11', $metadata->sequenceGeneratorDefinition['initialValue']);
            self::assertTrue($metadata->sequenceGeneratorDefinition['quoted']);
        }
        self::assertSame($this->schema . '.override_seq', $classes[2]->sequenceGeneratorDefinition['sequenceName']);
        (new SchemaTool($this->em))->createSchema($classes);
        $entities = [new P1SequenceFirst(), new P1SequenceFirst(), new P1SequenceSecond(), new P1SequenceOverride()];
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        self::assertSame([11, 12, 14, 40], array_map(static fn ($entity) => $entity->id, $entities));
        self::assertSame([], (new SchemaTool($this->em))->getUpdateSchemaSql($classes));
        // A second manager restores actual serialized metadata, including its generator.
        $other = new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $restored = $other->getClassMetadata(P1SequenceFirst::class);
        self::assertSame($this->schema . '.InheritedSeq', $restored->sequenceGeneratorDefinition['sequenceName']);
        $fresh = new P1SequenceFirst();
        $other->persist($fresh);
        $other->flush();
        self::assertSame(17, $fresh->id);
    }

    public function testDefaultSequenceIsIndependentForEachConcreteChild(): void
    {
        $classes = array_map($this->em->getClassMetadata(...), [P1DefaultSequenceFirst::class, P1DefaultSequenceSecond::class]);
        self::assertNotSame($classes[0]->sequenceGeneratorDefinition['sequenceName'], $classes[1]->sequenceGeneratorDefinition['sequenceName']);
        (new SchemaTool($this->em))->createSchema($classes);
        $first = new P1DefaultSequenceFirst();
        $second = new P1DefaultSequenceSecond();
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();
        self::assertSame(1, $first->id);
        self::assertSame(1, $second->id);
    }

    public function testAnsiQuoteStrategyUsesPhysicalPrimaryKeyForUpdateAndDelete(): void
    {
        $this->em->getConfiguration()->setQuoteStrategy(new ORM\AnsiQuoteStrategy());
        $metadata = $this->em->getClassMetadata(P1AnsiRecord::class);
        (new SchemaTool($this->em))->createSchema([$metadata]);
        $record = new P1AnsiRecord();
        $this->em->persist($record);
        $this->em->flush();
        $this->em->clear();
        $record = $this->em->find(P1AnsiRecord::class, 'key');
        self::assertSame('initial', $record->name);
        $record->name = 'updated';
        $this->em->flush();
        self::assertSame('updated', $this->em->getConnection()->fetchOne('SELECT name FROM ' . $this->schema . '.ansi_records WHERE record_key = ?', ['key']));
        $this->em->remove($record);
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->schema . '.ansi_records'));
    }

    public static function nativeModes(): iterable
    {
        foreach ([ResultSetMappingBuilder::COLUMN_RENAMING_NONE, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT, ResultSetMappingBuilder::COLUMN_RENAMING_CUSTOM] as $mode) {
            foreach ([false, true] as $customQuote) {
                yield $mode . ($customQuote ? '-custom-quote' : '-default-quote') => [$mode, $customQuote];
            }
        }
    }

    /** @param ResultSetMappingBuilder::COLUMN_RENAMING_* $mode */
    #[DataProvider('nativeModes')]
    public function testNativeBuilderQuotesFieldsForeignKeysAndResultAliases(int $mode, bool $customQuote): void
    {
        if ($customQuote) {
            $this->em->getConfiguration()->setQuoteStrategy(new P1AllQuoteStrategy());
        }
        $classes = array_map($this->em->getClassMetadata(...), [P1NativeRecord::class, P1NativeParent::class]);
        (new SchemaTool($this->em))->createSchema($classes);
        $parent = new P1NativeParent();
        $record = new P1NativeRecord();
        $record->parent = $parent;
        $this->em->persist($parent);
        $this->em->persist($record);
        $this->em->flush();
        $this->em->clear();
        $mapping = new ResultSetMappingBuilder($this->em, $mode);
        $rootNames = $mode === ResultSetMappingBuilder::COLUMN_RENAMING_CUSTOM
            ? ['RecordID' => 'Primary Key', 'Select Value' => 'Value"Alias', 'QuotedText' => 'Quoted Alias', 'plain' => 'Plain Alias', 'OwnerRef' => 'Link.Alias'] : [];
        $parentNames = $mode === ResultSetMappingBuilder::COLUMN_RENAMING_CUSTOM ? ['ParentID' => 'Joined Key', 'Parent Label' => 'Joined"Label'] : [];
        $mapping->addRootEntityFromClassMetadata(P1NativeRecord::class, 'r', $rootNames);
        $mapping->addJoinedEntityFromClassMetadata(P1NativeParent::class, 'p', 'r', 'parent', $parentNames);
        $select = $mapping->generateSelectClause(['r' => 'actual_r', 'p' => 'actual_p']);
        self::assertStringContainsString('UPPER(actual_r."Select Value")', $select);
        self::assertStringContainsString('actual_r."OwnerRef"', $select);
        if ($customQuote) {
            self::assertStringContainsString('actual_r."plain"', $select);
        }
        $sql = 'SELECT ' . $select . ' FROM ' . $this->schema . '.native_records actual_r LEFT JOIN ' . $this->schema . '.native_parents actual_p ON actual_r."OwnerRef" = actual_p."ParentID"';
        $loaded = $this->em->createNativeQuery($sql, $mapping)->getSingleResult();
        self::assertSame(11, $loaded->id);
        self::assertSame('MIXED', $loaded->value);
        self::assertSame('quoted', $loaded->quoted);
        self::assertSame('parent', $loaded->parent->label);
        self::assertSame($loaded->parent, $this->em->find(P1NativeParent::class, 7));
    }
}
