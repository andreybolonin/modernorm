<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Closure;
use ModernORM\UnitOfWork;

/**
 * @internal Stores the coordinator's entity state and owns managed-registration transitions.
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
        $oid = spl_object_id($entity);
        $this->identifiers[$oid] = $id;
        $this->states[$oid] = UnitOfWork::STATE_MANAGED;
        $this->snapshots[$oid] = $data;

        // Keep metadata/identity validation at its original point, after recording state.
        ($this->publishIdentity)($entity);
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
}
