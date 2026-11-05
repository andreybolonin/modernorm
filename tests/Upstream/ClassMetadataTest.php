<?php

declare(strict_types=1);

namespace ModernORM\Tests\Upstream;

use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Exception\MappingException;
use PHPUnit\Framework\TestCase;

final class MetadataEntity
{
    public string $status;
    public string $username;
    public string $name;
}

final class ClassMetadataTest extends TestCase
{
    public function testFieldIsNullable(): void
    {
        $cm = new ClassMetadata(MetadataEntity::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // Explicit Nullable
        $cm->mapField(['fieldName' => 'status', 'nullable' => true, 'type' => 'string', 'length' => 50]);
        self::assertTrue($cm->isNullable('status'));

        // Explicit Not Nullable
        $cm->mapField(['fieldName' => 'username', 'nullable' => false, 'type' => 'string', 'length' => 50]);
        self::assertFalse($cm->isNullable('username'));

        // Implicit Not Nullable
        $cm->mapField(['fieldName' => 'name', 'type' => 'string', 'length' => 50]);
        self::assertFalse($cm->isNullable('name'), 'By default a field should not be nullable.');
    }

    public function testSetInvalidVersionMappingThrowsException(): void
    {
        $field              = [];
        $field['fieldName'] = 'foo';
        $field['type']      = 'string';

        $cm = new ClassMetadata(MetadataEntity::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $cm->setVersionMapping($field);
    }

    public function testGetSingleIdentifierFieldNameMultipleIdentifierEntityThrowsException(): void
    {
        $cm = new ClassMetadata(MetadataEntity::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->isIdentifierComposite = true;

        $this->expectException(MappingException::class);
        $cm->getSingleIdentifierFieldName();
    }

    public function testGetSingleIdentifierFieldNameNoIdEntityThrowsException(): void
    {
        $cm = new ClassMetadata(MetadataEntity::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $cm->getSingleIdentifierFieldName();
    }

    public function testSetMultipleIdentifierSetsComposite(): void
    {
        $cm = new ClassMetadata(MetadataEntity::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'name']);
        $cm->mapField(['fieldName' => 'username']);

        $cm->setIdentifier(['name', 'username']);
        self::assertTrue($cm->isIdentifierComposite);
    }
}
