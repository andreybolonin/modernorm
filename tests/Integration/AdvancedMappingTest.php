<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use ModernORM\Mapping as ORM;
use ModernORM\EntityManager;
use ModernORM\Event\PreUpdateEventArgs;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_translations')]
final class Translation
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 16)] public string $code,
        #[ORM\Id, ORM\Column(length: 8)] public string $locale,
        #[ORM\Column] public string $text,
    ) {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_translation_links')]
final class TranslationLink
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Translation::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'translation_code', referencedColumnName: 'code', nullable: false)]
    #[ORM\JoinColumn(name: 'translation_locale', referencedColumnName: 'locale', nullable: false)]
    public Translation $translation;
}

#[ORM\Embeddable]
final class PostalAddress
{
    public function __construct(
        #[ORM\Column] public string $city = 'Moscow',
        #[ORM\Column(length: 16)] public string $zip = '101000',
    ) {
    }
}

#[ORM\MappedSuperclass]
abstract class IdentifiedEntity
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_contacts'), ORM\HasLifecycleCallbacks]
final class ContactCard extends IdentifiedEntity
{
    #[ORM\Column] public string $name = 'initial';
    #[ORM\Embedded(class: PostalAddress::class, columnPrefix: 'address_')]
    public PostalAddress $address;
    public array $events = [];
    public ?array $nameChange = null;

    public function __construct()
    {
        $this->address = new PostalAddress();
    }
    #[ORM\PrePersist]
    public function beforePersist(): void
    {
        $this->name = 'prepared';
        $this->events[] = 'prePersist';
    }
    #[ORM\PostPersist]
    public function afterPersist(): void
    {
        $this->events[] = 'postPersist';
    }
    #[ORM\PostLoad]
    public function afterLoad(): void
    {
        $this->events[] = 'postLoad';
    }
    #[ORM\PreUpdate]
    public function beforeUpdate(PreUpdateEventArgs $event): void
    {
        $this->events[] = 'preUpdate';
        if ($event->hasChangedField('name')) {
            $this->nameChange = [$event->getOldValue('name'), $event->getNewValue('name')];
        }
    }
    #[ORM\PostUpdate]
    public function afterUpdate(): void
    {
        $this->events[] = 'postUpdate';
    }
    #[ORM\PreRemove]
    public function beforeRemove(): void
    {
        $this->events[] = 'preRemove';
    }
    #[ORM\PostRemove]
    public function afterRemove(): void
    {
        $this->events[] = 'postRemove';
    }
}

final class AdvancedMappingTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [ContactCard::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }
    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_translation_links, modernorm_test_translations, modernorm_test_contacts CASCADE');
        $this->em->getConnection()->close();
    }


    public function testEmbeddableChangeTrackingAndSql(): void
    {
        $contact = new ContactCard();
        $this->em->persist($contact);
        $this->em->flush();
        $id = $contact->id;
        $this->em->clear();
        $contact = $this->em->find(ContactCard::class, $id);
        self::assertSame('Moscow', $contact->address->city);
        $contact->address->city = 'Kazan';
        $this->em->flush();
        $this->em->clear();
        $contact = $this->em->createQueryBuilder()->fromEntity(ContactCard::class, 'c')->where('c.address_city = :city')->getQuery()->setParameter('city', 'Kazan')->getSingleResult();
        self::assertSame($id, $contact->id);
        self::assertSame('Kazan', $contact->address->city);
        self::assertSame('101000', $contact->address->zip);
    }
    public function testLifecycleCallbacksAndChangeSet(): void
    {
        $contact = new ContactCard();
        $this->em->persist($contact);
        self::assertSame(['prePersist'], $contact->events);
        $this->em->flush();
        self::assertSame(['prePersist', 'postPersist'], $contact->events);
        $id = $contact->id;
        $this->em->clear();
        $contact = $this->em->find(ContactCard::class, $id);
        self::assertSame(['postLoad'], $contact->events);
        self::assertSame('prepared', $contact->name);
        $contact->name = 'updated';
        $this->em->flush();
        self::assertSame(['prepared', 'updated'], $contact->nameChange);
        self::assertSame(['postLoad', 'preUpdate', 'postUpdate'], $contact->events);
        $this->em->remove($contact);
        $this->em->flush();
        self::assertSame(['postLoad', 'preUpdate', 'postUpdate', 'preRemove', 'postRemove'], $contact->events);
        self::assertNull($this->em->find(ContactCard::class, $id));
    }
}
