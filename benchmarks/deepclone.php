<?php

declare(strict_types=1);

namespace ModernORMDeepcloneBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Mapping\PropertyAccessors\ReadonlyAccessor;
use ReflectionClass;
use RuntimeException;

enum Status: string
{
    case Active = 'active';
}

#[ORM\MappedSuperclass]
class ParentRecord
{
    #[ORM\Column] private string $inherited;
}

#[ORM\Embeddable]
class Address
{
    #[ORM\Column] public string $city;
}

#[ORM\Entity]
class Record extends ParentRecord
{
    public static int $hooks = 0;
    public string $untouched;
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] private string $name;
    #[ORM\Column] protected int $quantity;
    #[ORM\Column] public bool $enabled;
    #[ORM\Column(nullable: true)] public ?string $description;
    #[ORM\Column(type: 'json')] public array $payload;
    #[ORM\Column(enumType: Status::class)] public Status $status;
    #[ORM\Column(type: 'datetime_immutable')] public DateTimeImmutable $created;
    #[ORM\Column] public string $hooked {
        set {
            ++self::$hooks;
            $this->hooked = $value;
        }
    }
    public function __construct()
    {
        throw new RuntimeException('Constructor must not run.');
    }
}

#[ORM\Entity]
class ComplexRecord
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $created;
    #[ORM\Embedded(class: Address::class)] public Address $address;
}

// Benchmark-only adapter. Metadata planning is excluded, as with warmed accessors.
// Keep embedded/readonly accessors and their snapshot corrections, including input order.
function nativeHydrate($metadata, object $entity, array $data, array $plan): \Generator
{
    $vars = [];
    foreach ($data as $field => $value) {
        if (! isset($metadata->fieldMappings[$field])) {
            continue;
        }
        if ($plan[$field] !== null) {
            $vars[$plan[$field]] = $value;
            continue;
        }
        if ($vars !== []) {
            \deepclone_hydrate($entity, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
            $vars = [];
        }
        $accessor = $metadata->propertyAccessors[$field];
        $accessor->setValue($entity, $value);
        if ($accessor instanceof ReadonlyAccessor) {
            yield $field => $accessor->getValue($entity);
        }
    }
    if ($vars !== []) {
        \deepclone_hydrate($entity, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
    }
}

// Explicit accessor baseline: EntityHydrator itself now automatically uses deepclone.
function accessorHydrate($metadata, object $entity, array $data): \Generator
{
    foreach ($data as $field => $value) {
        if (! isset($metadata->fieldMappings[$field])) {
            continue;
        }
        $accessor = $metadata->propertyAccessors[$field];
        $accessor->setValue($entity, $value);
        if ($accessor instanceof ReadonlyAccessor) {
            yield $field => $accessor->getValue($entity);
        }
    }
}

$mode = $argv[1] ?? 'accessors';
if (! in_array($mode, ['accessors', 'deepclone', 'orm'], true)) {
    throw new RuntimeException('Usage: php benchmarks/deepclone.php [accessors|deepclone|orm]');
}
if ($mode === 'deepclone' && ! function_exists('deepclone_hydrate')) {
    throw new RuntimeException('Load deepclone with php -d extension=/path/to/deepclone.so');
}
$rows = max(1, (int) (getenv('MODERNORM_BENCH_ROWS') ?: 20000));
$rounds = max(3, (int) (getenv('MODERNORM_BENCH_ROUNDS') ?: 9));
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
$date = new DateTimeImmutable('2026-01-01');
$results = [];
foreach (['flat-new', 'flat-existing', 'flat-lazy', 'embedded-readonly-new', 'embedded-readonly-existing'] as $scenario) {
    $complex = str_starts_with($scenario, 'embedded');
    $metadata = $em->getClassMetadata($complex ? ComplexRecord::class : Record::class);
    $reflection = new ReflectionClass($metadata->name);
    $data = $complex
        ? ['id' => 42, 'name' => 'Alice', 'created' => $date, 'address.city' => 'Berlin']
        : ['id' => 42, 'inherited' => 'parent private', 'name' => 'Alice', 'quantity' => 7,
            'enabled' => true, 'description' => null, 'payload' => ['tags' => ['a', 'b']],
            'status' => 'active', 'created' => $date, 'hooked' => 'raw'];
    $data['unmapped'] = 'must be ignored';
    $plan = [];
    foreach ($metadata->fieldMappings as $field => $_) {
        $plan[$field] = null;
        if (str_contains($field, '.') || $metadata->propertyAccessors[$field] instanceof ReadonlyAccessor) {
            continue;
        }
        $declaring = $reflection;
        while (! $declaring->hasProperty($field)) {
            $declaring = $declaring->getParentClass();
        }
        $property = $declaring->getProperty($field);
        $plan[$field] = $property->isPrivate() ? "\0" . $property->getDeclaringClass()->name . "\0" . $field : $field;
    }
    $hydrate = match ($mode) {
        'deepclone' => static fn ($entity, $values) => nativeHydrate($metadata, $entity, $values, $plan),
        'orm' => static fn ($entity, $values) => EntityHydrator::hydrateScalars($metadata, $entity, $values),
        default => static fn ($entity, $values) => accessorHydrate($metadata, $entity, $values),
    };
    $times = $peaks = $retained = [];
    for ($round = -2; $round < $rounds; ++$round) {
        $entities = [];
        $existing = str_ends_with($scenario, 'existing');
        $lazy = str_ends_with($scenario, 'lazy');
        if ($existing || $lazy) {
            for ($i = 0; $i < $rows; ++$i) {
                $entity = $lazy ? $reflection->newLazyGhost(static function (): void {
                    throw new RuntimeException('Lazy initializer must not run.');
                }) : $metadata->newInstance();
                if ($existing) {
                    iterator_to_array(accessorHydrate($metadata, $entity, $data));
                }
                $entities[] = $entity;
            }
            unset($entity);
        }
        // A distinct equivalent readonly date must retain the original object.
        $values = $data;
        $values['created'] = new DateTimeImmutable('2026-01-01');
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);
        for ($i = 0; $i < $rows; ++$i) {
            $entity = ($existing || $lazy) ? $entities[$i] : $metadata->newInstance();
            foreach ($hydrate($entity, $values) as $field => $value) {
                // Same snapshot correction work as the coordinator.
                $correction = [$field => $value];
            }
            if (! $existing && ! $lazy) {
                $entities[] = $entity;
            }
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $peak = memory_get_peak_usage() - $before;
        $heap = memory_get_usage() - $before;
        foreach ($entities as $entity) {
            if ($lazy && ! $reflection->isUninitializedLazyObject($entity)) {
                throw new RuntimeException('Hydration initialized a lazy ghost.');
            }
            if ($lazy) {
                $reflection->markLazyObjectAsInitialized($entity);
            }
            foreach ($data as $field => $expected) {
                if ($field === 'unmapped') {
                    continue;
                }
                $actual = $metadata->propertyAccessors[$field]->getValue($entity);
                if ($actual != $expected || ($field === 'created' && $complex && $existing && $actual !== $date)) {
                    throw new RuntimeException("Invalid hydration: $scenario/$field");
                }
            }
            if (array_key_exists('unmapped', (array) $entity) || Record::$hooks !== 0) {
                throw new RuntimeException('Unmapped field or hook was executed.');
            }
        }
        if ($round >= 0) {
            $times[] = $elapsed;
            $peaks[] = $peak;
            $retained[] = $heap;
        }
        unset($entities, $entity, $correction);
    }
    $median = static function (array $values) {
        sort($values);
        return $values[intdiv(count($values), 2)];
    };
    $results[$scenario] = ['median_ms' => $median($times), 'median_peak_delta_bytes' => $median($peaks),
        'median_retained_delta_bytes' => $median($retained), 'samples_ms' => $times];
}
echo json_encode(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m'),
    'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
    'deepclone' => phpversion('deepclone'), 'mode' => $mode, 'rows' => $rows, 'rounds' => $rounds,
    'scope' => 'Scalar assignment only; no SQL, conversion, identity map, associations or flush',
    'measurements' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
