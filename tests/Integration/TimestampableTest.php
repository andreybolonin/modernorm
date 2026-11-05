<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use ModernORM\Behavior\TimestampableTrait;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_timestamps'), ORM\HasLifecycleCallbacks]
final class TimestampedRecord
{
    use TimestampableTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'original';
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_immutable_timestamps'), ORM\HasLifecycleCallbacks, ORM\Immutable]
final class ImmutableTimestampedRecord
{
    use TimestampableTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'original';
}

final class TimestampableTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = [$this->em->getClassMetadata(TimestampedRecord::class), $this->em->getClassMetadata(ImmutableTimestampedRecord::class)];
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_timestamps, modernorm_test_immutable_timestamps');
        $this->em->getConnection()->close();
    }

    public function testInsertInitializesBothDatesAndPersistsThem(): void
    {
        $record = new TimestampedRecord();
        self::assertNull($record->getCreatedAt());
        self::assertNull($record->getUpdatedAt());
        $this->em->persist($record);
        $this->em->flush();
        self::assertInstanceOf(DateTimeImmutable::class, $record->getCreatedAt());
        self::assertSame($record->getCreatedAt(), $record->getUpdatedAt());
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(TimestampedRecord::class, $id);
        self::assertSame($record->getCreatedAt()->getTimestamp(), $loaded->getCreatedAt()->getTimestamp());
        self::assertSame($record->getUpdatedAt()->getTimestamp(), $loaded->getUpdatedAt()->getTimestamp());
    }

    public function testInsertPreservesImportedDates(): void
    {
        $created = new DateTimeImmutable('2020-01-02 03:04:05');
        $updated = new DateTimeImmutable('2021-02-03 04:05:06');
        $record = (new TimestampedRecord())->setCreatedAt($created)->setUpdatedAt($updated);
        $this->em->persist($record);
        $this->em->flush();
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(TimestampedRecord::class, $id);
        self::assertEquals($created, $loaded->getCreatedAt());
        self::assertSame($updated->getTimestamp(), $loaded->getUpdatedAt()->getTimestamp());
    }

    public function testUpdateChangesOnlyUpdatedAtAndNoOpFlushPreservesDates(): void
    {
        $past = new DateTimeImmutable('2020-01-02 03:04:05');
        $record = (new TimestampedRecord())->setCreatedAt($past)->setUpdatedAt($past);
        $this->em->persist($record);
        $this->em->flush();
        $this->em->flush();
        self::assertSame($past, $record->getUpdatedAt());
        $record->name = 'changed';
        $this->em->flush();
        self::assertSame($past, $record->getCreatedAt());
        self::assertGreaterThan($past, $record->getUpdatedAt());
        $updated = $record->getUpdatedAt();
        $this->em->flush();
        self::assertSame($updated, $record->getUpdatedAt());
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(TimestampedRecord::class, $id);
        self::assertEquals($past, $loaded->getCreatedAt());
        self::assertSame($updated->getTimestamp(), $loaded->getUpdatedAt()->getTimestamp());
        self::assertSame('changed', $loaded->name);
    }

    public function testImmutableEntityInitializesDatesButDoesNotUpdateThem(): void
    {
        $record = new ImmutableTimestampedRecord();
        $this->em->persist($record);
        $this->em->flush();
        $updated = $record->getUpdatedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $updated);
        $record->name = 'ignored';
        $this->em->flush();
        self::assertSame($updated, $record->getUpdatedAt());
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(ImmutableTimestampedRecord::class, $id);
        self::assertSame('original', $loaded->name);
        self::assertSame($updated->getTimestamp(), $loaded->getUpdatedAt()->getTimestamp());
    }
}
