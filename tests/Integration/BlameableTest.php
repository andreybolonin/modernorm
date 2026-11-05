<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use ModernORM\Behavior\ActorProviderInterface;
use ModernORM\Behavior\BlameableInterface;
use ModernORM\Behavior\BlameableListener;
use ModernORM\Behavior\BlameableTrait;
use ModernORM\Behavior\TimestampableTrait;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_blameable_actors')]
final class BlameableActor
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name)
    {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_blameable_records'), ORM\HasLifecycleCallbacks]
#[ORM\Blameable(actorClass: BlameableActor::class)]
final class BlameableRecord implements BlameableInterface
{
    use BlameableTrait;
    use TimestampableTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'original';


}

final class TestActorProvider implements ActorProviderInterface
{
    public ?object $actor = null;

    public function getActor(): ?object
    {
        return $this->actor;
    }
}

final class BlameableTest extends TestCase
{
    private EntityManager $em;
    private TestActorProvider $provider;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $this->provider = new TestActorProvider();
        $this->em->getEventManager()->addEventListener([Events::prePersist, Events::preUpdate], new BlameableListener($this->provider));
        $metadata = [$this->em->getClassMetadata(BlameableActor::class), $this->em->getClassMetadata(BlameableRecord::class)];
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_blameable_records, modernorm_test_blameable_actors');
        $connection->close();
    }

    private function actor(string $name): BlameableActor
    {
        $actor = new BlameableActor($name);
        $this->em->persist($actor);
        $this->em->flush();

        return $actor;
    }

    public function testInsertAndUpdatePersistActorAssociations(): void
    {
        $creator = $this->actor('creator');
        $editor = $this->actor('editor');
        $this->provider->actor = $creator;
        $record = new BlameableRecord();
        $this->em->persist($record);
        $this->em->flush();
        self::assertSame($creator, $record->getCreatedBy());
        self::assertSame($creator, $record->getUpdatedBy());
        self::assertNotNull($record->getCreatedAt());
        $this->provider->actor = $editor;
        $this->em->flush();
        self::assertSame($creator, $record->getUpdatedBy());
        $record->name = 'changed';
        $this->em->flush();
        self::assertSame($creator, $record->getCreatedBy());
        self::assertSame($editor, $record->getUpdatedBy());
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(BlameableRecord::class, $id);
        self::assertSame($creator->id, $loaded->getCreatedBy()->id);
        self::assertSame($editor->id, $loaded->getUpdatedBy()->id);
        self::assertSame('changed', $loaded->name);
    }

    public function testAnonymousWritesPreserveExistingActors(): void
    {
        $record = new BlameableRecord();
        $this->em->persist($record);
        $this->em->flush();
        self::assertNull($record->getCreatedBy());
        self::assertNull($record->getUpdatedBy());
        $creator = $this->actor('creator');
        $this->provider->actor = $creator;
        $record->name = 'authenticated update';
        $this->em->flush();
        self::assertNull($record->getCreatedBy());
        self::assertSame($creator, $record->getUpdatedBy());
        $this->provider->actor = null;
        $record->name = 'anonymous update';
        $this->em->flush();
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(BlameableRecord::class, $id);
        self::assertNull($loaded->getCreatedBy());
        self::assertSame($creator->id, $loaded->getUpdatedBy()->id);
        self::assertSame('anonymous update', $loaded->name);
    }

    public function testImportedActorsArePreservedOnInsert(): void
    {
        $imported = $this->actor('imported');
        $current = $this->actor('current');
        $this->provider->actor = $current;
        $record = (new BlameableRecord())->setCreatedBy($imported)->setUpdatedBy($imported);
        $this->em->persist($record);
        $this->em->flush();
        $id = $record->id;
        $this->em->clear();
        $loaded = $this->em->find(BlameableRecord::class, $id);
        self::assertSame($imported->id, $loaded->getCreatedBy()->id);
        self::assertSame($imported->id, $loaded->getUpdatedBy()->id);
    }

    public function testDetachedActorIsRejectedOnInsert(): void
    {
        $actor = $this->actor('detached');
        $this->em->clear();
        $this->provider->actor = $actor;
        $this->expectException(InvalidArgumentException::class);
        $this->em->persist(new BlameableRecord());
    }

    public function testDetachedActorIsRejectedOnUpdate(): void
    {
        $record = new BlameableRecord();
        $this->em->persist($record);
        $this->em->flush();
        $actor = $this->actor('detached');
        $this->em->detach($actor);
        $this->provider->actor = $actor;
        $record->name = 'changed';
        $this->expectException(InvalidArgumentException::class);
        $this->em->flush();
    }
}
