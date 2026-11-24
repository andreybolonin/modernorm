<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Closure;
use ModernORM\UnitOfWork;

/**
 * @internal Stores entity state and owns managed registration, identified-state decisions and per-entity cleanup transitions.
 * Remaining lifecycle/dirty-check operations access these maps directly during this extraction.
 */
final class EntityStateManager
{
    /** @var array<int, array<string, mixed>> */
    public array $identifiers = [];

    /** @var array<int, UnitOfWork::STATE_*> */
    public array $states = [];

    /**
     * Original row arrays retain PHP copy-on-write semantics.
     * @var array<int, array<string, mixed>>
     */
    public array $snapshots = [];

    /** @param Closure(object): bool $publishIdentity */
    public function __construct(private readonly Closure $publishIdentity)
    {
    }

    /**
     * @param array<string, mixed> $id
     * @param array<string, mixed> $data
     */
    public function registerManaged(object $entity, array $id, array $data): void
    {
        $this->recordManaged(spl_object_id($entity), $id, $data);

        // Keep metadata/identity validation at its original point, after recording state.
        ($this->publishIdentity)($entity);
    }

    /**
     * Record managed state before publishing a known identity.
     *
     * @param array<string, mixed> $id
     * @param array<string, mixed> $data
     */
    public function recordManaged(int $oid, array $id, array $data): void
    {
        $this->identifiers[$oid] = $id;
        $this->states[$oid] = UnitOfWork::STATE_MANAGED;
        $this->snapshots[$oid] = $data;
    }

    /** The coordinator has already converted and assigned the ID property. */
    public function registerPostInsertId(object $entity, string $field, mixed $value): void
    {
        $oid = spl_object_id($entity);
        $this->identifiers[$oid] = [$field => $value];
        $this->states[$oid] = UnitOfWork::STATE_MANAGED;
        $this->snapshots[$oid][$field] = $value;

        ($this->publishIdentity)($entity);
    }

    /**
     * Resolve an uncached entity with a non-empty identifier. Null means no version shortcut;
     * false preserves the historical falsy-version NEW decision (including zero).
     * The coordinator supplies Identity Map / database observation only if required.
     * NEW/DETACHED results must not be cached: object IDs can be reused and existence can change.
     *
     * @param Closure(): bool $identityOrDatabaseExists
     * @return UnitOfWork::STATE_NEW|UnitOfWork::STATE_DETACHED
     */
    public function resolveIdentifiedState(?bool $versionValueIsTruthy, bool $postInsert, Closure $identityOrDatabaseExists): int
    {
        if ($versionValueIsTruthy !== null) {
            return $versionValueIsTruthy ? UnitOfWork::STATE_DETACHED : UnitOfWork::STATE_NEW;
        }
        if ($postInsert) {
            return UnitOfWork::STATE_DETACHED;
        }
        return $identityOrDatabaseExists() ? UnitOfWork::STATE_DETACHED : UnitOfWork::STATE_NEW;
    }

    /** After successful DELETE: snapshot destructors still observe the old cached state. */
    public function forgetAfterDeletion(int $oid): void
    {
        unset($this->identifiers[$oid], $this->snapshots[$oid], $this->states[$oid]);
    }

    /** After detach queue/lazy cleanup: snapshot destructors observe no cached state. */
    public function forgetAfterDetach(int $oid): void
    {
        unset($this->identifiers[$oid], $this->states[$oid], $this->snapshots[$oid]);
    }
}
