<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use LogicException;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'hook_ids'), ORM\HasLifecycleCallbacks]
class P1HookIdentifier
{
    public static int $hookWrites = 0;
    public static array $removedIds = [];
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public int $id {
        get => $this->id;
        set {
            ++self::$hookWrites; $this->id = $value;
        }
    }
    #[ORM\PostRemove]
    public function removed(): void
    {
        self::$removedIds[] = $this->id;
    }
}

#[ORM\Entity, ORM\Table(name: 'nullable_ids')]
class P1NullableHookIdentifier
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null {
        get => $this->id;
        set => $this->id = $value;
    }
}

#[ORM\Entity, ORM\Table(name: 'plain_ids')]
class P1PlainIdentifier
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public int $id;
}

#[ORM\Entity, ORM\Table(name: 'readonly_dates'), ORM\HasLifecycleCallbacks]
class P1ReadonlyDates
{
    public static int $updates = 0;
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(type: 'datetime_immutable')]
    public readonly DateTimeImmutable $immutable;
    #[ORM\Column(type: 'datetime')]
    public readonly DateTime $mutable;
    #[ORM\Column]
    public string $name = 'initial';
    public function __construct()
    {
        $this->immutable = new DateTimeImmutable('2025-01-02 03:04:05');
        $this->mutable = new DateTime('2025-01-02 03:04:05');
    }
    #[ORM\PreUpdate]
    public function updating(): void
    {
        ++self::$updates;
    }
}

final class P1ReadonlyHookRegressionTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'readonly_hook_' . bin2hex(random_bytes(5));
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [P1HookIdentifier::class, P1NullableHookIdentifier::class, P1PlainIdentifier::class, P1ReadonlyDates::class]);
        foreach ($metadata as $class) {
            $class->table['schema'] = $this->schema;
        }
        (new SchemaTool($this->em))->createSchema($metadata);
        P1HookIdentifier::$hookWrites = 0;
        P1HookIdentifier::$removedIds = [];
        P1ReadonlyDates::$updates = 0;
    }
    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $db = $this->em->getConnection();
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $db->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $db->close();
        }
    }
    public static function deletedLoads(): iterable
    {
        yield 'persisted' => [false];
        yield 'lazy reference' => [true];
    }
    #[DataProvider('deletedLoads')]
    public function testDeleteHookedIdentifierRetainsIdAndCanBePersistedAgain(bool $lazy): void
    {
        $entity = new P1HookIdentifier();
        $this->em->persist($entity);
        $this->em->flush();
        $id = $entity->id;
        if ($lazy) {
            $this->em->clear();
            $entity = $this->em->getReference(P1HookIdentifier::class, $id);
        }
        $this->em->remove($entity);
        $this->em->flush();
        self::assertSame($id, $entity->id);
        self::assertSame([$id], P1HookIdentifier::$removedIds);
        self::assertSame(0, P1HookIdentifier::$hookWrites);
        self::assertFalse($this->em->contains($entity));
        self::assertNull($this->em->find(P1HookIdentifier::class, $id));
        $this->em->flush();
        $this->em->persist($entity);
        $this->em->flush();
        self::assertNotSame($id, $entity->id);
        $this->em->clear();
        self::assertNotNull($this->em->find(P1HookIdentifier::class, $entity->id));
    }
    public function testNullableHookedAndPlainIdentifiersStillReset(): void
    {
        $hooked = new P1NullableHookIdentifier();
        $plain = new P1PlainIdentifier();
        $this->em->persist($hooked);
        $this->em->persist($plain);
        $this->em->flush();
        $this->em->remove($hooked);
        $this->em->remove($plain);
        $this->em->flush();
        self::assertNull($hooked->id);
        self::assertFalse((new \ReflectionProperty($plain, 'id'))->isInitialized($plain));
        self::assertSame(0, $this->em->getRepository(P1NullableHookIdentifier::class)->count([]));
        self::assertSame(0, $this->em->getRepository(P1PlainIdentifier::class)->count([]));
    }
    public static function loads(): iterable
    {
        yield 'persisted' => ['persisted'];
        yield 'hydrated' => ['hydrated'];
        yield 'lazy' => ['lazy'];
    }
    #[DataProvider('loads')]
    public function testUnchangedReadonlyDatesRefreshWithoutPhantomUpdates(string $load): void
    {
        $entity = new P1ReadonlyDates();
        $this->em->persist($entity);
        $this->em->flush();
        $id = $entity->id;
        if ($load !== 'persisted') {
            $this->em->clear();
            $entity = $load === 'lazy' ? $this->em->getReference(P1ReadonlyDates::class, $id) : $this->em->find(P1ReadonlyDates::class, $id);
        }
        $immutable = $entity->immutable;
        $mutable = $entity->mutable;
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->schema . ".readonly_dates SET name = 'database'");
        $this->em->refresh($entity);
        $this->em->refresh($entity);
        self::assertSame('database', $entity->name);
        self::assertSame($immutable, $entity->immutable);
        self::assertSame($mutable, $entity->mutable);
        $snapshot = $this->em->getUnitOfWork()->getOriginalEntityData($entity);
        self::assertSame($immutable, $snapshot['immutable']);
        self::assertSame($mutable, $snapshot['mutable']);
        $this->em->flush();
        self::assertSame(0, P1ReadonlyDates::$updates);
    }
    public static function dateFields(): iterable
    {
        yield 'immutable' => ['immutable'];
        yield 'mutable' => ['mutable'];
    }
    #[DataProvider('dateFields')]
    public function testChangedReadonlyDateStillRejectsRefresh(string $field): void
    {
        $entity = new P1ReadonlyDates();
        $this->em->persist($entity);
        $this->em->flush();
        $before = $entity->$field;
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->schema . ".readonly_dates SET " . $field . " = '2025-01-03 03:04:05'");
        try {
            $this->em->refresh($entity);
            self::fail('Changed readonly date must reject refresh.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Attempting to change readonly property', $exception->getMessage());
            self::assertSame($before, $entity->$field);
        }
    }
}
