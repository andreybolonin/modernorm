<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\ScalarChangeTrackingPlan;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_scalar_tracking'), ORM\HasLifecycleCallbacks]
class ScalarTrackingEntity
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] private string $name = 'initial';
    #[ORM\Column(nullable: true)] protected ?string $note = null;
    #[ORM\Column(updatable: false)] public string $insertOnly = 'database';
    #[ORM\Column, ORM\Version] public int $version = 1;
    public int $updates = 0;
    public function rename(string $name): void
    {
        $this->name = $name;
    }
    #[ORM\PreUpdate]
    public function beforeUpdate(): void
    {
        $this->name .= '-callback';
        $this->note = 'callback-' . ($this->updates + 1);
        ++$this->updates;
    }
}

final class ScalarChangeTrackingPlanTest extends TestCase
{
    public function testSqlUpdatesPreserveVersionInsertOnlyAndPreUpdateRecomputation(): void
    {
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = $em->getClassMetadata(ScalarTrackingEntity::class);
        $schema = new SchemaTool($em);
        $schema->dropSchema([$metadata]);
        $schema->createSchema([$metadata]);
        try {
            self::assertNotNull(ScalarChangeTrackingPlan::forMetadata($metadata));
            $entity = new ScalarTrackingEntity();
            $em->persist($entity);
            $em->flush();
            $id = $entity->id;
            $em->clear();
            $entity = $em->find(ScalarTrackingEntity::class, $id);
            self::assertInstanceOf(ScalarTrackingEntity::class, $entity);
            foreach (['first', 'second'] as $index => $value) {
                $entity->rename($value);
                $entity->insertOnly = 'ignored';
                $em->flush();
                $row = $em->getConnection()->fetchAssociative('SELECT name, note, insertOnly AS fixed, version FROM modernorm_scalar_tracking WHERE id = ?', [$id]);
                self::assertSame($value . '-callback', $row['name']);
                self::assertSame('callback-' . ($index + 1), $row['note']);
                self::assertSame('database', $row['fixed']);
                self::assertSame($index + 2, $entity->version);
                $em->flush();
                self::assertSame($index + 1, $entity->updates);
            }
        } finally {
            $schema->dropSchema([$metadata]);
            $em->getConnection()->close();
        }
    }
}
