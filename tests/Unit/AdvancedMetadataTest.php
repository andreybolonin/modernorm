<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tests\Integration\{ContactCard, Translation, TranslationLink};
use PHPUnit\Framework\TestCase;

// New ModernORM tests. No upstream source is copied in this file.
require_once dirname(__DIR__) . '/Integration/AdvancedMappingTest.php';

final class AdvancedMetadataTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
    }



    public function testEmbeddedFieldsAndLifecycleMetadata(): void
    {
        $metadata = $this->em->getClassMetadata(ContactCard::class);
        self::assertSame('address_city', $metadata->getColumnName('address.city'));
        self::assertSame(['id'], $metadata->identifier);
        self::assertSame(['beforePersist'], $metadata->lifecycleCallbacks[Events::prePersist]);
        self::assertSame(['beforeUpdate'], $metadata->lifecycleCallbacks[Events::preUpdate]);
    }

    public function testMappedSuperclassDoesNotCreatePolymorphicHierarchy(): void
    {
        $metadata = $this->em->getClassMetadata(ContactCard::class);
        self::assertSame(ContactCard::class, $metadata->rootEntityName);
        self::assertSame([], $metadata->parentClasses);
        self::assertSame([], $metadata->subClasses);
        self::assertSame(['id'], $metadata->identifier);
        self::assertFalse($this->em->getConnection()->isConnected());
    }
}
