<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping\Driver\AttributeDriver;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/Repository/NoteRepository.php';
require_once __DIR__ . '/Entity/Note.php';

// DBAL connection options remain unchanged. No Symfony dependencies are needed.
$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
    'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
    'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
]);
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity']));

return new EntityManager($connection, $config);
