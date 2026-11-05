<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Exception\MappingException;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_mapped_categories')]
final class MappedCategory
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\Column] public string $name = 'category',
    ) {
    }
}

#[ORM\MappedSuperclass, ORM\Table(name: 'modernorm_test_unwanted_superclass'), ORM\HasLifecycleCallbacks]
abstract class MappedBase
{
    #[ORM\Version, ORM\Column] public int $version;
    #[ORM\Column] public string $name = 'initial';
    public array $events = [];

    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\ManyToOne(targetEntity: MappedCategory::class, cascade: ['persist'])]
        #[ORM\JoinColumn(nullable: false)] public MappedCategory $category,
    ) {
    }

    #[ORM\PrePersist]
    public function beforePersist(): void
    {
        $this->events[] = 'base-prePersist';
    }

    #[ORM\PostLoad]
    public function afterLoad(): void
    {
        $this->events[] = 'base-postLoad';
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_mapped_documents')]
final class MappedDocument extends MappedBase
{
    #[ORM\Column] public string $title = 'document';
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_mapped_invoices')]
final class MappedInvoice extends MappedBase
{
    #[ORM\Column] public bool $paid = false;
}

abstract class PlainPhpBase
{
    #[ORM\Column] public string $description = 'inherited PHP property';
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_plain_php_child')]
final class PlainPhpChild extends PlainPhpBase
{
    public function __construct(#[ORM\Id, ORM\Column] public int $id)
    {
    }
}

final class MappedSuperclassTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [
            MappedCategory::class, MappedBase::class, MappedDocument::class, MappedInvoice::class, PlainPhpChild::class,
        ]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_mapped_documents, modernorm_test_mapped_invoices, modernorm_test_mapped_categories, modernorm_test_plain_php_child, modernorm_test_unwanted_superclass CASCADE');
        $this->em->getConnection()->close();
    }

    private function seed(): array
    {
        $category = new MappedCategory(10);
        $document = new MappedDocument(1, $category);
        $invoice = new MappedInvoice(1, $category);
        $this->em->persist($document);
        $this->em->persist($invoice);
        $this->em->flush();
        return [$document, $invoice];
    }

    public function testSuperclassFlattensIntoIndependentEntitiesAndKeepsCallbacks(): void
    {
        [$document, $invoice] = $this->seed();
        self::assertSame(['base-prePersist'], $document->events);
        self::assertSame(['base-prePersist'], $invoice->events);
        self::assertSame(1, $document->version);
        self::assertSame(1, $invoice->version);
        self::assertNull($this->em->getConnection()->fetchOne("SELECT to_regclass('modernorm_test_unwanted_superclass')"));
        $this->em->clear();
        $loadedDocument = $this->em->find(MappedDocument::class, 1);
        $loadedInvoice = $this->em->find(MappedInvoice::class, 1);
        self::assertNotSame($loadedDocument, $loadedInvoice);
        self::assertSame('document', $loadedDocument->title);
        self::assertFalse($loadedInvoice->paid);
        self::assertSame($loadedDocument->category, $loadedInvoice->category);
        self::assertSame(['base-postLoad'], $loadedDocument->events);
        self::assertSame(['base-postLoad'], $loadedInvoice->events);
        foreach ([MappedDocument::class, MappedInvoice::class] as $class) {
            $metadata = $this->em->getClassMetadata($class);
            self::assertSame($class, $metadata->rootEntityName);
            self::assertSame([], $metadata->parentClasses);
            self::assertSame([], $metadata->subClasses);
        }
    }

    public function testInheritedVersionAndFieldsUpdateWithoutTouchingOtherTable(): void
    {
        [$document] = $this->seed();
        $document->name = 'updated';
        $this->em->flush();
        self::assertSame(2, $document->version);
        $this->em->clear();
        $document = $this->em->find(MappedDocument::class, 1);
        self::assertSame('updated', $document->name);
        self::assertSame(2, $document->version);
        self::assertSame('initial', $this->em->find(MappedInvoice::class, 1)->name);
        self::assertSame(1, $this->em->find(MappedInvoice::class, 1)->version);
        $this->em->remove($document);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(MappedDocument::class, 1));
        self::assertNotNull($this->em->find(MappedInvoice::class, 1));
    }

    public function testNativeLazyAndSqlJoinHydrateSuperclassAssociation(): void
    {
        $this->seed();
        $this->em->clear();
        $reference = $this->em->getReference(MappedDocument::class, 1);
        self::assertTrue($this->em->isUninitializedObject($reference));
        self::assertSame(1, $reference->id);
        self::assertTrue($this->em->isUninitializedObject($reference));
        self::assertSame('document', $reference->title);
        $builder = $this->em->createQueryBuilder()->fromEntity(MappedDocument::class, 'd')
            ->innerJoin('d', 'modernorm_test_mapped_categories', 'c', 'c.id = d.category_id')
            ->addJoinedEntity(MappedCategory::class, 'c', 'd', 'category');
        $loaded = $builder->where($builder->column('d', 'name') . ' = :name')->setParameter('name', 'initial')->getQuery()->getSingleResult();
        self::assertSame($reference, $loaded);
        self::assertSame('category', $loaded->category->name);
        self::assertSame($loaded->category, $this->em->find(MappedCategory::class, 10));
    }

    public function testPlainPhpInheritanceDoesNotRequireEntityInheritanceMapping(): void
    {
        $child = new PlainPhpChild(42);
        $this->em->persist($child);
        $this->em->flush();
        $this->em->clear();
        $loaded = $this->em->createQueryBuilder()->fromEntity(PlainPhpChild::class, 'c')->getQuery()->getSingleResult();
        self::assertSame('inherited PHP property', $loaded->description);
        self::assertSame(42, $loaded->id);
        $loaded->description = 'changed';
        $this->em->flush();
        $this->em->clear();
        self::assertSame('changed', $this->em->find(PlainPhpChild::class, 42)->description);
    }

    public function testSuperclassCannotBeUsedAsPolymorphicQueryRoot(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('concrete Entity');
        $this->em->createQueryBuilder()->fromEntity(MappedBase::class, 'base');
    }
}
