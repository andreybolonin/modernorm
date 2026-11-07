<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Exception\EntityIdentityCollisionException;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_identity_records')]
final class ManagedIdentityRecord
{
    public function __construct(
        #[ORM\Id, ORM\Column] public string $code,
        #[ORM\Column] public string $name = 'original',
    ) {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_identity_holders')]
final class ManagedIdentityHolder
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\ManyToOne(targetEntity: ManagedIdentityRecord::class, batchSize: 2)]
        #[ORM\JoinColumn(name: 'record_code', referencedColumnName: 'code')]
        public ManagedIdentityRecord $record,
    ) {
    }
}

final class IdentityMapTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $schema = new SchemaTool($this->em);
        $metadata = array_map($this->em->getClassMetadata(...), [ManagedIdentityRecord::class, ManagedIdentityHolder::class]);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_identity_holders, modernorm_test_identity_records');
        $connection->close();
    }

    private function seed(): ManagedIdentityRecord
    {
        $record = new ManagedIdentityRecord('01');
        $this->em->persist($record);
        $this->em->flush();
        return $record;
    }

    private function rejectedCopy(ManagedIdentityRecord $record): ManagedIdentityRecord
    {
        $copy = new ManagedIdentityRecord($record->code, 'copy');
        try {
            $this->em->getUnitOfWork()->registerManaged($copy, ['code' => $copy->code], ['code' => $copy->code, 'name' => $copy->name]);
            self::fail('Expected an identity collision.');
        } catch (EntityIdentityCollisionException) {
        }
        return $copy;
    }

    public function testRejectedCopyIsNotAMemberOfTheIdentityMap(): void
    {
        $record = $this->seed();
        $copy = $this->rejectedCopy($record);
        self::assertFalse($this->em->getUnitOfWork()->isInIdentityMap($copy));
        self::assertTrue($this->em->getUnitOfWork()->isInIdentityMap($record));
        self::assertSame($record, $this->em->find(ManagedIdentityRecord::class, '01'));
    }

    public function testRemovingRejectedCopyCannotEvictTheRegisteredInstance(): void
    {
        $record = $this->seed();
        $copy = $this->rejectedCopy($record);
        self::assertFalse($this->em->getUnitOfWork()->removeFromIdentityMap($copy));
        self::assertSame($record, $this->em->find(ManagedIdentityRecord::class, '01'));
        self::assertSame(1, $this->em->getUnitOfWork()->size());
    }

    public function testDetachingRejectedCopyPreservesRegisteredInstance(): void
    {
        $record = $this->seed();
        $copy = $this->rejectedCopy($record);
        $this->em->detach($copy);
        self::assertSame($record, $this->em->find(ManagedIdentityRecord::class, '01'));
        $record->name = 'retained';
        $this->em->flush();
        self::assertSame('retained', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_identity_records WHERE code = ?', ['01']));
    }

    public function testReferenceFindQueryAndAssociationShareTheSameInstance(): void
    {
        $record = $this->seed();
        $this->em->persist(new ManagedIdentityHolder(1, $record));
        $this->em->persist(new ManagedIdentityHolder(2, $record));
        $this->em->flush();
        $this->em->clear();
        $reference = $this->em->getReference(ManagedIdentityRecord::class, '01');
        self::assertTrue($this->em->isUninitializedObject($reference));
        self::assertSame($reference, $this->em->find(ManagedIdentityRecord::class, '01'));
        $queried = $this->em->createQueryBuilder()->fromEntity(ManagedIdentityRecord::class, 'r')->where('r.code = :code')
            ->setParameter('code', '01')->getQuery()->getSingleResult();
        self::assertSame($reference, $queried);
        self::assertSame('original', $reference->name);
        self::assertSame($reference, $this->em->find(ManagedIdentityHolder::class, 1)->record);
        self::assertSame($reference, $this->em->find(ManagedIdentityHolder::class, 2)->record);
    }

    public function testStringKeysSnapshotIsolationAndClearPreservePublicContract(): void
    {
        $first = $this->seed();
        $second = new ManagedIdentityRecord('1');
        $this->em->persist($second);
        $this->em->flush();
        $uow = $this->em->getUnitOfWork();
        self::assertSame(2, $uow->size());
        self::assertSame($first, $uow->tryGetById(['code' => '01'], ManagedIdentityRecord::class));
        self::assertSame($second, $uow->tryGetByIdHash(1, ManagedIdentityRecord::class));
        self::assertFalse($uow->tryGetByIdHash('missing', ManagedIdentityRecord::class));
        self::assertFalse($uow->tryGetById(['code' => 'missing'], ManagedIdentityRecord::class));
        $snapshot = $uow->getIdentityMap();
        unset($snapshot[ManagedIdentityRecord::class]['01']);
        self::assertSame($first, $uow->getByIdHash('01', ManagedIdentityRecord::class));
        $uow->markReadOnly($first);
        self::assertTrue($uow->isReadOnly($first));
        self::assertTrue($uow->removeFromIdentityMap($first));
        self::assertFalse($uow->isReadOnly($first));
        self::assertFalse($uow->removeFromIdentityMap($first));
        self::assertSame(1, $uow->size());
        $this->em->clear();
        self::assertSame([], $uow->getIdentityMap());
        self::assertSame(0, $uow->size());
        self::assertNotSame($first, $this->em->find(ManagedIdentityRecord::class, '01'));
    }
}
