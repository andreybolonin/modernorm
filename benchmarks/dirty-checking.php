<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Local diagnostic only. No production API or native-speed claim.
declare(strict_types=1);

namespace ModernORMDirtyBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\UnitOfWork;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Internal\UnitOfWork\ChangeSetComputer;

$width = (int) ($argv[1] ?? 5);
$count = (int) ($argv[2] ?? 100000);
$rounds = (int) ($argv[3] ?? 5);
if (!in_array($width, [5,20,50], true)) {
    throw new \RuntimeException('Unsupported width');
}
$source = 'namespace ModernORMDirtyBenchmark; use ModernORM\\Mapping as ORM; #[ORM\\Entity] class Record { #[ORM\\Id, ORM\\Column, ORM\\GeneratedValue] public ?int $id = null;';
for ($f = 0;$f < $width;++$f) {
    $type = ['int','string','bool','float','?string'][$f % 5];
    $source .= '#[ORM\\Column(nullable: '.($type === '?string' ? 'true' : 'false').')] public '.$type.' $f'.$f.';';
}
eval($source.'}');

final class DiagnosticUow extends UnitOfWork
{
    public string $mode = 'current';
    public \Closure $runPlan;
    public int $fallbacks = 0;
    public function computeChangeSet(ClassMetadata $class, object $entity): void
    {
        if ($this->mode === 'current') {
            parent::computeChangeSet($class, $entity);
            return;
        }
        if (($this->mode === 'guarded' || $this->mode === 'fallback') && ($entity::class !== Record::class || $this->isUninitializedObject($entity) || $this->mode === 'fallback')) {
            ++$this->fallbacks;
            parent::computeChangeSet($class, $entity);
            return;
        }
        ($this->runPlan)($entity, $this->mode);
    }
}
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$db = DriverManager::getConnection(['driver' => 'pdo_pgsql','serverVersion' => '18.7','platform' => new PostgreSQLPlatform()]);
$em = new EntityManager($db, $config);
$metadata = $em->getClassMetadata(Record::class);
$uow = new DiagnosticUow($em);
$accessors = $metadata->propertyAccessors;
unset($accessors['id']);
$baseline = [];
$changed = [];
$second = [];
foreach ($accessors as $field => $accessor) {
    $i = (int)substr($field, 1);
    $baseline[$field] = [0,'before',false,0.25,null][$i % 5];
    $changed[$field] = [1,'after',true,1.25,'value'][$i % 5];
    $second[$field] = [2,'again',false,2.25,null][$i % 5];
}
$planStart = hrtime(true);
foreach ($accessors as $field => $accessor) {
    $p = $accessor->getUnderlyingReflector();
    if ($p->hasHooks() || $p->isReadOnly() || !$p->getType() instanceof \ReflectionNamedType || !in_array($p->getType()->getName(), ['int','float','string','bool'], true)) {
        throw new \RuntimeException('Ineligible metadata');
    }
}
if ($metadata->associationMappings || $metadata->embeddedClasses || !$metadata->isChangeTrackingDeferredImplicit()) {
    throw new \RuntimeException('Ineligible metadata');
}
$planMs = (hrtime(true) - $planStart) / 1e6;
$entities = [];
for ($i = 0;$i < $count;++$i) {
    $e = new Record();
    $e->id = $i + 1;
    foreach ($accessors as $field => $a) {
        $a->setValue($e, $baseline[$field]);
    }
    $entities[] = $e;
    $snapshot = $baseline;
    $snapshot['f0'] = 0;
    $uow->registerManaged($e, ['id' => $i + 1], $snapshot);
}
$state = (new \ReflectionProperty(UnitOfWork::class, 'entityStateManager'))->getValue($uow);
$queues = (new \ReflectionProperty(UnitOfWork::class, 'flushQueues'))->getValue($uow);
// Support both layouts when comparing the extraction against an older UnitOfWork.
$changeOwner = $uow;
$changeScope = UnitOfWork::class;
if ((new \ReflectionClass(UnitOfWork::class))->hasProperty('changeTrackingCoordinator')) {
    $changeOwner = (new \ReflectionProperty(UnitOfWork::class, 'changeTrackingCoordinator'))->getValue($uow);
    $changeScope = $changeOwner::class;
}
$changeProp = new \ReflectionProperty($changeScope, 'entityChangeSets');
$known = [];
$uow->runPlan = \Closure::bind(function (object $entity, string $mode) use ($accessors, &$known): void {
    $oid = spl_object_id($entity);
    if ($mode === 'floor') {
        $result = $known[$oid] ?? null;
        if ($result === null) {
            return;
        }
        [$changes,$actual] = $result;
    } else {
        $original = $this->entityStateManager->snapshots[$oid];
        $changes = [];
        $actual = null;
        foreach ($accessors as $field => $a) {
            $value = $a->getValue($entity);
            if ($original[$field] !== $value) {
                $changes[$field] = [$original[$field],$value];
                $actual ??= $original;
                $actual[$field] = $value;
            }
        }
        if (!$changes) {
            return;
        }
    }
    $this->entityChangeSets[$oid] = $changes;
    $this->entityStateManager->snapshots[$oid] = $actual;
    $this->flushQueues->addUpdate($entity);
}, $changeOwner, $changeScope);
function measured(\Closure $fn): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $start = hrtime(true);
    $fn();
    return ['ms' => (hrtime(true) - $start) / 1e6,'peak_bytes' => memory_get_peak_usage() - $before,'retained_bytes' => memory_get_usage() - $before];
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}
$output = ['php' => PHP_VERSION,'width' => $width,'entities' => $count,'rounds' => $rounds,'plan_ms' => $planMs,'baseline_bytes' => memory_get_usage(),'cases' => []];
foreach ([0,1,10,100] as $percent) {
    foreach (($percent === 0 ? ['one'] : ['one','all']) as $mutation) {
        $n = intdiv($count * $percent, 100);
        $target = $baseline;
        foreach ($changed as $field => $value) {
            if ($mutation === 'all' || $field === 'f0') {
                $target[$field] = $value;
            }
        }
        $expected = ChangeSetComputer::compare($metadata, $baseline, $target);
        $samples = [];
        for ($round = -1;$round < $rounds;++$round) {
            // Rotating order; allocation, GC, resets and mutation stay outside timing.
            $modes = ['current','fused','guarded','floor','fallback'];
            $shift = ($round + 1) % 5;
            $modes = array_merge(array_slice($modes, $shift), array_slice($modes, 0, $shift));
            foreach ($modes as $mode) {
                $changeProp->setValue($changeOwner, []);
                $queues->clear();
                $known = [];
                foreach ($entities as $i => $e) {
                    $oid = spl_object_id($e);
                    $snapshot = $baseline;
                    $snapshot['f0'] = 0;
                    $state->snapshots[$oid] = $snapshot;
                    $values = $i < $n ? $target : $baseline;
                    foreach ($accessors as $field => $a) {
                        $a->setValue($e, $values[$field]);
                    }
                    if ($mode === 'floor' && $i < $n) {
                        $known[$oid] = [$expected,$target];
                    }
                }
                $uow->mode = $mode;
                $timing = measured(fn () => $uow->computeChangeSets());
                if ($round >= 0) {
                    $samples[$mode][] = $timing;
                }
                check(count($uow->getScheduledEntityUpdates()) === $n, 'Update queue mismatch');
                if ($round >= 0) {
                    continue;
                } // Full equivalence/repeated-call checks in the warm-up.
                $changes = $changeProp->getValue($changeOwner);
                foreach ($entities as $i => $e) {
                    $oid = spl_object_id($e);
                    check(($changes[$oid] ?? []) === ($i < $n ? $expected : []), 'First changeset mismatch');
                    check($state->snapshots[$oid] === ($i < $n ? $target : $baseline), 'First snapshot mismatch');
                }
                unset($changes);
                // Repeated computation: fresh mutation, retained queues and old changesets.
                // Floor gets a new oracle outside timing; it is never an optimizer.
                $known = [];
                foreach ($entities as $i => $e) {
                    $e->f0 = 2;
                    $original = $i < $n ? $target : $baseline;
                    $actual = $original;
                    $actual['f0'] = 2;
                    if ($mode === 'floor') {
                        $known[spl_object_id($e)] = [ChangeSetComputer::compare($metadata, $original, $actual),$actual];
                    }
                }
                $uow->computeChangeSets();
                check(count($uow->getScheduledEntityUpdates()) === $count, 'Repeat queue mismatch');
                $changes = $changeProp->getValue($changeOwner);
                foreach ($entities as $i => $e) {
                    $original = $i < $n ? $target : $baseline;
                    $actual = $original;
                    $actual['f0'] = 2;
                    check($changes[spl_object_id($e)] === ['f0' => [$original['f0'],2]], 'Repeat changeset mismatch');
                    check($state->snapshots[spl_object_id($e)] === $actual, 'Repeat snapshot mismatch');
                }
                // A third unchanged computation must retain existing pending changes.
                $uow->computeChangeSets();
                check($changeProp->getValue($changeOwner) === $changes, 'Unchanged recomputation lost pending changes');
                unset($changes);
            }
        }
        // Separate stage diagnostics: actual collection matches the production loop;
        // comparison input allocation stays outside its timer. Not additive timings.
        $changeProp->setValue($changeOwner, []);
        $queues->clear();
        $known = [];
        $originals = [];
        foreach ($entities as $e) {
            $snapshot = $baseline;
            $snapshot['f0'] = 0;
            $state->snapshots[spl_object_id($e)] = $snapshot;
            $originals[] = $snapshot;
        }
        foreach ($entities as $i => $e) {
            foreach ($accessors as $field => $a) {
                $a->setValue($e, ($i < $n ? $target : $baseline)[$field]);
            }
        }
        $stages = [];
        for ($r = -1;$r < $rounds;++$r) {
            $actuals = [];
            $extract = measured(function () use ($metadata, $entities, &$actuals): void {
                foreach ($entities as $e) {
                    $actual = [];
                    foreach ($metadata->propertyAccessors as $name => $a) {
                        $value = $a->getValue($e);
                        if ($metadata->isCollectionValuedAssociation($name) && $value !== null) {
                            throw new \RuntimeException('Unexpected collection');
                        }
                        if ((!$metadata->isIdentifier($name) || !$metadata->isIdGeneratorIdentity()) && $name !== $metadata->versionField) {
                            $actual[$name] = $value;
                        }
                    }
                    $actuals[] = $actual;
                }
            });
            $checksum = 0;
            $compare = measured(function () use ($metadata, $originals, &$actuals, &$checksum): void {
                foreach ($actuals as $i => $actual) {
                    $checksum += count(ChangeSetComputer::compare($metadata, $originals[$i], $actual));
                }
            });
            check($checksum === $n * count($expected), 'Comparison checksum');
            unset($actuals);
            if ($r >= 0) {
                $stages['extract'][] = $extract;
                $stages['compare'][] = $compare;
            }
        }
        unset($originals);
        $output['cases'][] = ['percent' => $percent,'mutation' => $mutation,'samples' => $samples,'stages' => $stages,'verified' => true];
        file_put_contents('/tmp/modernorm-dirty-progress-'.$width.'.json', json_encode($output, JSON_THROW_ON_ERROR));
        fwrite(STDERR, "width=$width percent=$percent mutation=$mutation verified\n");
    }
}
check(!$db->isConnected(), 'Unexpected database connection');
$output['fallbacks'] = $uow->fallbacks;
echo json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
