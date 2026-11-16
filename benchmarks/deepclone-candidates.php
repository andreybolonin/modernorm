<?php

declare(strict_types=1);

namespace ModernORMDeepcloneCandidates;

require dirname(__DIR__) . '/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Mapping\PropertyAccessors\ReadonlyAccessor;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use TypeError;

class ConstructorTrap
{
    public function __construct()
    {
        throw new RuntimeException('Constructor must not run.');
    }
}

#[ORM\Entity]
class Flat extends ConstructorTrap
{
    public static int $hooks = 0;
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] private string $name;
    #[ORM\Column] protected int $quantity;
    #[ORM\Column] public bool $enabled;
    #[ORM\Column(nullable: true)] public ?string $description;
    #[ORM\Column(type: 'json')] public array $payload;
    #[ORM\Column(type: 'datetime_immutable')] public DateTimeImmutable $created;
    #[ORM\Column] public string $hooked {
        set {
            ++self::$hooks;
            $this->hooked = $value;
        }
    }
}

#[ORM\Embeddable]
class TinyAddress extends ConstructorTrap
{
    #[ORM\Column] public string $city;
}

#[ORM\Embeddable]
class WideAddress extends ConstructorTrap
{
    #[ORM\Column] private string $street;
    #[ORM\Column] public string $city;
    #[ORM\Column] protected string $country;
    #[ORM\Column] public string $zip;
    #[ORM\Column] public string $region;
    #[ORM\Column] public string $district;
    #[ORM\Column] public string $building;
    #[ORM\Column] public string $unit;
}

#[ORM\Entity]
class EmbeddedOne extends ConstructorTrap
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Embedded(class: TinyAddress::class)] public TinyAddress $address;
}

#[ORM\Entity]
class EmbeddedEight extends ConstructorTrap
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Embedded(class: WideAddress::class)] public WideAddress $address;
}

#[ORM\Entity]
class ReadonlyOne extends ConstructorTrap
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $created;
}

#[ORM\Entity]
class ReadonlySix extends ConstructorTrap
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $created;
    #[ORM\Column] public readonly string $label;
    #[ORM\Column] public readonly int $quantity;
    #[ORM\Column] public readonly bool $enabled;
    #[ORM\Column(type: 'json')] public readonly array $payload;
    #[ORM\Column(nullable: true)] public readonly ?string $description;
}

/** Compile only the fixed benchmark mapping; this is not a general ORM adapter. */
function plan(ClassMetadata $metadata): array
{
    $keys = $readonly = $flatKeys = $types = [];
    foreach ($metadata->fieldMappings as $field => $_) {
        $property = $metadata->propertyAccessors[$field]->getUnderlyingReflector();
        $type = $property->getType();
        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException('These fixed benchmark fixtures require named property types.');
        }
        $key = $property->isPrivate() ? "\0" . $property->getDeclaringClass()->name . "\0" . $property->name
            : ($property->isProtected() ? "\0*\0" . $property->name : $property->name);
        $keys[$field] = [str_contains($field, '.') ? explode('.', $field, 2)[0] : '', $key];
        $flatKeys[$field] = $key;
        $types[$field] = [$type->getName(), $type->allowsNull()];
        if ($metadata->propertyAccessors[$field] instanceof ReadonlyAccessor) {
            $readonly[$field] = $property;
        }
    }
    $embedded = [];
    foreach ($metadata->embeddedClasses as $field => $mapping) {
        $embedded[$field] = new ReflectionClass($mapping->class);
    }
    return ['keys' => $keys, 'readonly' => $readonly, 'embedded' => $embedded, 'flatKeys' => $flatKeys, 'types' => $types];
}

function baseline(ClassMetadata $metadata, object $entity, array $data): array
{
    $snapshot = [];
    foreach (EntityHydrator::hydrateScalars($metadata, $entity, $data) as $field => $value) {
        $snapshot[$field] = $value;
    }
    return $snapshot;
}

/** Same argument preparation for split/fused creation, without embedded chunk overhead. */
function flatCandidate(ClassMetadata $metadata, array $data, array $keys, bool $combined): array
{
    $vars = [];
    foreach ($data as $field => $value) {
        if (isset($keys[$field])) {
            $vars[$keys[$field]] = $value;
        }
    }
    if ($combined) {
        $entity = \deepclone_hydrate($metadata->name, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
    } else {
        $entity = $metadata->newInstance();
        \deepclone_hydrate($entity, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
    }
    return [$entity, []];
}

/** Fixed-fixture guarded readonly prototype with the coordinator's generator contract. */
function guardedReadonly(ClassMetadata $metadata, object $entity, array $data, array $plan): \Generator
{
    foreach ($plan['readonly'] as $property) {
        if ($property->isInitialized($entity)) {
            yield from EntityHydrator::hydrateScalars($metadata, $entity, $data);
            return;
        }
    }
    $vars = [];
    foreach ($data as $field => $value) {
        if (! isset($plan['flatKeys'][$field])) {
            continue;
        }
        [$type, $nullable] = $plan['types'][$field];
        if ($value === null ? ! $nullable : (get_debug_type($value) !== $type && ! (is_object($value) && $value instanceof $type))) {
            yield from EntityHydrator::hydrateScalars($metadata, $entity, $data);
            return;
        }
        $vars[$plan['flatKeys'][$field]] = $value;
    }
    \deepclone_hydrate($entity, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
    foreach ($plan['readonly'] as $field => $_) {
        if (array_key_exists($field, $data)) {
            yield $field => $metadata->propertyAccessors[$field]->getValue($entity);
        }
    }
}

/** Prototype: checks/plumbing are timed; general mapping/type eligibility is not implemented. */
function candidate(ClassMetadata $metadata, ?object $entity, array $data, array $plan, bool $combined): array
{
    if ($plan['embedded'] === [] && $plan['readonly'] === []) {
        return flatCandidate($metadata, $data, $plan['flatKeys'], $combined);
    }
    if ($entity !== null) {
        foreach ($plan['readonly'] as $property) {
            if ($property->isInitialized($entity)) {
                return [$entity, baseline($metadata, $entity, $data)];
            }
        }
    }
    // Keep contiguous input groups separate: no reordering across embedded/root fields.
    $chunks = [];
    $last = -1;
    foreach ($data as $field => $value) {
        if (! isset($plan['keys'][$field])) {
            continue;
        }
        [$group, $key] = $plan['keys'][$field];
        if ($last < 0 || $chunks[$last]['group'] !== $group) {
            $chunks[] = ['group' => $group, 'vars' => []];
            ++$last;
        }
        $chunks[$last]['vars'][$key] = $value;
    }
    if ($combined) {
        // Only the flat construction scenario uses this variant.
        $entity = \deepclone_hydrate($metadata->name, $chunks[0]['vars'], DEEPCLONE_HYDRATE_NO_LAZY_INIT);
    } else {
        $entity ??= $metadata->newInstance();
        foreach ($chunks as $chunk) {
            $target = $entity;
            if ($chunk['group'] !== '') {
                $accessor = $metadata->propertyAccessors[$chunk['group']];
                $target = $accessor->getValue($entity);
                if ($target === null) {
                    $target = $plan['embedded'][$chunk['group']]->newInstanceWithoutConstructor();
                    // Attach before assigning children, as the ordinary accessor does.
                    $accessor->setValue($entity, $target);
                }
            }
            \deepclone_hydrate($target, $chunk['vars'], DEEPCLONE_HYDRATE_NO_LAZY_INIT);
        }
    }
    $snapshot = [];
    foreach ($plan['readonly'] as $field => $_) {
        if (array_key_exists($field, $data)) {
            $snapshot[$field] = $metadata->propertyAccessors[$field]->getValue($entity);
        }
    }
    return [$entity, $snapshot];
}

$mode = $argv[1] ?? 'orm';
if (! in_array($mode, ['orm', 'candidate', 'guarded'], true) || ! extension_loaded('deepclone')) {
    throw new RuntimeException('Load deepclone, then use: deepclone-candidates.php [orm|candidate|guarded]');
}
$rows = max(1, (int) (getenv('MODERNORM_BENCH_ROWS') ?: 20000));
$rounds = max(3, (int) (getenv('MODERNORM_BENCH_ROUNDS') ?: 9));
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
$date = new DateTimeImmutable('2026-01-01');
$flat = ['id' => 42, 'name' => 'Alice', 'quantity' => 7, 'enabled' => true, 'description' => null,
    'payload' => ['tags' => ['a', 'b']], 'created' => $date, 'hooked' => 'raw'];
$tiny = ['id' => 42, 'name' => 'Alice', 'address.city' => 'Berlin'];
$wide = ['id' => 42, 'name' => 'Alice'];
foreach (['street', 'city', 'country', 'zip', 'region', 'district', 'building', 'unit'] as $field) {
    $wide['address.' . $field] = $field . '-value';
}
$readonlyOne = ['id' => 42, 'name' => 'Alice', 'created' => $date];
$readonlySix = $readonlyOne + ['label' => 'label', 'quantity' => 7, 'enabled' => true,
    'payload' => ['tags' => ['a', 'b']], 'description' => null];
$cases = [
    'create-flat-combined' => [Flat::class, $flat, false],
    'create-flat-split' => [Flat::class, $flat, false],
    'embedded-1-new' => [EmbeddedOne::class, $tiny, false],
    'embedded-8-new' => [EmbeddedEight::class, $wide, false],
    'embedded-8-existing' => [EmbeddedEight::class, $wide, true],
    'readonly-1-new' => [ReadonlyOne::class, $readonlyOne, false],
    'readonly-6-new' => [ReadonlySix::class, $readonlySix, false],
    'readonly-6-existing-control' => [ReadonlySix::class, $readonlySix, true],
];
$results = [];
foreach ($cases as $case => [$class, $data, $existing]) {
    if ($mode === 'guarded' && ! str_starts_with($case, 'readonly-')) {
        continue;
    }
    $metadata = $em->getClassMetadata($class);
    $plan = plan($metadata);
    $data['unmapped'] = 'ignored';
    $values = $data;
    if ($existing && $plan['readonly'] !== []) {
        $values['created'] = new DateTimeImmutable('2026-01-01');
    }
    $times = $peaks = $retained = [];
    for ($round = -2; $round < $rounds; ++$round) {
        $entities = $embeddedBefore = [];
        if ($existing) {
            for ($i = 0; $i < $rows; ++$i) {
                $entity = $metadata->newInstance();
                baseline($metadata, $entity, $data);
                $entities[] = $entity;
                if ($plan['embedded'] !== []) {
                    $embeddedBefore[] = $entity->address;
                }
            }
        }
        unset($entity);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);
        for ($i = 0; $i < $rows; ++$i) {
            $entity = $existing ? $entities[$i] : null;
            if ($mode === 'guarded') {
                $entity ??= $metadata->newInstance();
                $snapshot = [];
                foreach (guardedReadonly($metadata, $entity, $values, $plan) as $field => $value) {
                    $snapshot[$field] = $value;
                }
            } elseif ($mode === 'candidate') {
                [$entity, $snapshot] = candidate($metadata, $entity, $values, $plan, $case === 'create-flat-combined');
            } else {
                $entity ??= $metadata->newInstance();
                $snapshot = baseline($metadata, $entity, $values);
            }
            if (! $existing) {
                $entities[] = $entity;
            }
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $peak = memory_get_peak_usage() - $before;
        $heap = memory_get_usage() - $before;
        foreach ($entities as $index => $entity) {
            foreach ($data as $field => $expected) {
                if ($field === 'unmapped') {
                    continue;
                }
                $actual = $metadata->propertyAccessors[$field]->getValue($entity);
                if ($actual !== $expected) {
                    throw new RuntimeException("Wrong value or identity: $case/$field");
                }
            }
            if (isset($embeddedBefore[$index]) && $entity->address !== $embeddedBefore[$index]) {
                throw new RuntimeException('Existing embedded identity was replaced.');
            }
            if (array_key_exists('unmapped', (array) $entity) || Flat::$hooks !== 0) {
                throw new RuntimeException('Unmapped field or hook was executed.');
            }
        }
        foreach ($plan['readonly'] as $field => $_) {
            if ($snapshot[$field] !== $data[$field]) {
                throw new RuntimeException('Wrong readonly snapshot correction.');
            }
        }
        if ($round >= 0) {
            $times[] = $elapsed;
            $peaks[] = $peak;
            $retained[] = $heap;
        }
        unset($entities, $entity, $snapshot, $embeddedBefore);
    }
    $median = static function (array $values) {
        sort($values);
        return $values[intdiv(count($values), 2)];
    };
    $results[$case] = ['median_ms' => $median($times), 'median_peak_delta_bytes' => $median($peaks),
        'median_retained_delta_bytes' => $median($retained), 'samples_ms' => $times,
        'peak_delta_samples_bytes' => $peaks];
}

// Characterize important adoption limits separately from successful-assignment timings.
$readonlyMetadata = $em->getClassMetadata(ReadonlySix::class);
$badReadonly = ['created' => $date, 'quantity' => []];
$baselineSnapshot = [];
$entity = $readonlyMetadata->newInstance();
try {
    foreach (EntityHydrator::hydrateScalars($readonlyMetadata, $entity, $badReadonly) as $field => $value) {
        $baselineSnapshot[$field] = $value;
    }
    throw new RuntimeException('Invalid readonly type was accepted.');
} catch (TypeError) {
}
$nativeSnapshot = [];
$entity = $readonlyMetadata->newInstance();
try {
    [$entity, $nativeSnapshot] = candidate($readonlyMetadata, $entity, $badReadonly, plan($readonlyMetadata), false);
    throw new RuntimeException('Invalid native readonly type was accepted.');
} catch (TypeError) {
}
if ($baselineSnapshot !== ['created' => $date] || $nativeSnapshot !== []) {
    throw new RuntimeException('Unexpected readonly error-order probe result.');
}
$guardedSnapshot = [];
$entity = $readonlyMetadata->newInstance();
try {
    foreach (guardedReadonly($readonlyMetadata, $entity, $badReadonly, plan($readonlyMetadata)) as $field => $value) {
        $guardedSnapshot[$field] = $value;
    }
    throw new RuntimeException('Invalid guarded readonly type was accepted.');
} catch (TypeError) {
}
if ($guardedSnapshot !== $baselineSnapshot) {
    throw new RuntimeException('Guarded readonly fallback lost an earlier correction.');
}
$embeddedMetadata = $em->getClassMetadata(EmbeddedEight::class);
$badEmbedded = ['id' => 42, 'address.city' => 'before error', 'name' => [], 'address.unit' => 'must not write'];
$states = [];
foreach (['orm', 'candidate'] as $probeMode) {
    $entity = $embeddedMetadata->newInstance();
    try {
        if ($probeMode === 'orm') {
            baseline($embeddedMetadata, $entity, $badEmbedded);
        } else {
            candidate($embeddedMetadata, $entity, $badEmbedded, plan($embeddedMetadata), false);
        }
        throw new RuntimeException('Invalid embedded interleaved type was accepted.');
    } catch (TypeError) {
    }
    $states[$probeMode] = [];
    foreach (array_keys($badEmbedded) as $field) {
        $states[$probeMode][$field] = $embeddedMetadata->propertyAccessors[$field]->getValue($entity);
    }
}
if ($states['orm'] !== $states['candidate'] || $states['orm']['address.city'] !== 'before error' || $states['orm']['address.unit'] !== null) {
    throw new RuntimeException('Embedded interleaved assignment/error order changed.');
}
$flatMetadata = $em->getClassMetadata(Flat::class);
$badFlat = $flat;
$badFlat['quantity'] = [];
try {
    $em->getUnitOfWork()->createEntity(Flat::class, $badFlat);
    throw new RuntimeException('Invalid ORM type was accepted.');
} catch (TypeError) {
}
$registeredOnFailure = $em->getUnitOfWork()->tryGetById(['id' => 42], Flat::class) !== false;
$returnedObject = null;
try {
    [$returnedObject] = candidate($flatMetadata, null, $badFlat, plan($flatMetadata), true);
    throw new RuntimeException('Invalid combined type was accepted.');
} catch (TypeError) {
}
if (! $registeredOnFailure || $returnedObject !== null) {
    throw new RuntimeException('Unexpected registration-order probe result.');
}
echo json_encode(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m'),
    'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
    'deepclone' => phpversion('deepclone'), 'mode' => $mode, 'rows' => $rows, 'rounds' => $rounds,
    'scope' => 'Creation and scalar/embedded assignment only; no SQL or lifecycle events',
    'compatibility_probes' => ['orm_registers_entity_before_failed_assignment' => $registeredOnFailure,
        'combined_returns_entity_on_failed_assignment' => $returnedObject !== null,
        'readonly_snapshot_before_later_failure_preserved_by_candidate' => $baselineSnapshot === $nativeSnapshot,
        'readonly_snapshot_before_later_failure_preserved_by_guarded' => $baselineSnapshot === $guardedSnapshot,
        'embedded_interleaved_partial_failure_preserved' => $states['orm'] === $states['candidate']],
    'measurements' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
