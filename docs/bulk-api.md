# PostgreSQL-native bulk API

**Implemented:** multi-row INSERT, PostgreSQL ON CONFLICT (UPSERT/DO NOTHING), UPDATE and DELETE, with optional RETURNING and explicit `BulkPolicy::BYPASS` / `BulkPolicy::CLEAR`. `SYNC`, COPY, UPDATE FROM, automatic chunking and graph reconciliation remain future work. Direct DBAL SQL retains its existing behavior and does not automatically use this API's invalidation protocol.

## Usage

```php
use ModernORM\Bulk\BulkPolicy;

$result = $em->bulk()->insert(Product::class, [
    ['sku' => 'A', 'price' => '12.50'],
    ['sku' => 'B', 'price' => '15.00'],
], BulkPolicy::CLEAR, returning: ['id', 'sku']);

$result = $em->bulk()->upsert(Product::class, [
    ['sku' => 'A', 'price' => '13.00'],
], conflictFields: ['sku'], updateFields: ['price'],
   policy: BulkPolicy::BYPASS, returning: ['id', 'price']);

$result = $em->bulk()->update(Product::class, ['price' => '14.00'],
    'sku = :sku', BulkPolicy::CLEAR, parameters: ['sku' => 'A'],
    returning: ['id', 'price']);

$result = $em->bulk()->delete(Product::class, 'sku = :sku',
    BulkPolicy::CLEAR, parameters: ['sku' => 'B'], returning: ['id']);
```

Operation signatures:

```php
insert($entity, $rows, $policy, $returning = [], $affectedEntities = []);
upsert($entity, $rows, $conflictFields, $updateFields, $policy, $returning = [], $affectedEntities = []);
update($entity, $values, $where, $policy, $parameters = [], $types = [], $returning = [], $affectedEntities = []);
delete($entity, $where, $policy, $parameters = [], $types = [], $returning = [], $affectedEntities = []);
```

`BulkExecutor` is available through EntityManager and its decorator. Values, conflict/update fields and RETURNING fields use **mapped scalar field names**, including flattened embeddables. Mapped DBAL types convert input values; backed enum values are supported. Table/schema/column quoting follows the configured quote strategy. Association/FK properties are outside this first structured API; direct DBAL SQL remains available.

Predicates use trusted **SQL column names**, not entity property names. Use named parameters without leading colons; `types` accepts ordinary DBAL types and array parameter types. `__morm_bulk_` is reserved internally. Predicates/SQL structure must come from application code, never untrusted input. Empty predicates are rejected; use explicit `TRUE` to target all rows.

INSERT requires a nonempty list of nonempty rows with identical field sets; field order may differ. Omitted columns use database defaults. UPSERT requires explicit conflict fields and a matching database unique constraint. Empty updateFields means `DO NOTHING`; otherwise only the listed incoming fields are assigned through `EXCLUDED`. One call executes one DML statement, without implicit chunking. INSERT exceeding 65,535 bound values is rejected before SQL; applications split larger inputs and choose transaction boundaries explicitly.

`BulkResult` exposes:

- `affectedRows`: affected rows, including inserted/updated rows in UPSERT; conflicts skipped by DO NOTHING are excluded.
- `returnedRows`: raw associative DBAL rows, keyed by requested field names. No ORM hydration/type conversion on output, PostLoad callbacks or managed entities are created. Without RETURNING this is an empty list.
- `databaseCommitted`: true after an API-owned commit; false means the caller-owned transaction is still pending. No managed entities are synchronized in either supported mode.

## UnitOfWork policies

Every bulk call must explicitly select a policy. There is no implicit flush in any mode; the application decides whether to call flush first.

| Policy | Behavior after successful execution | Intended use |
| --- | --- | --- |
| `BYPASS` | Leave managed objects, snapshots, pending changes, and lazy queues unchanged. | SQL-oriented workflows where the caller manages consistency. |
| `CLEAR` | Clear the entire EntityManager, detaching managed objects and clearing pending operations and lazy queues. | Imports, ETL, and operations followed by a fresh persistence context. |

These policies govern UnitOfWork, not database permissions, transaction isolation, or L2 invalidation. They are also independent of `Immutable` and the READ_ONLY cache strategy.

### BYPASS

- SQL does not refresh or detach entities and does not discard pending changes.
- Existing managed entities and initialized collections may become stale. A later flush can write their pending changes over bulk results.
- An Identity Map hit can return stale data without querying the database. Reloading requires explicit refresh/clear or another EntityManager.
- Existing lazy queues are not reset. Batching remains ordinary lazy loading, not a reconciliation mechanism.

### CLEAR

- Clear only after successful execution; a failed SQL operation must not itself clear UnitOfWork.
- Clear the whole EntityManager, not only selected entity classes or returned IDs.
- Discard pending ORM inserts, updates, removals, collection work, and queued lazy loads. There is no automatic flush to preserve them.
- A successful operation affecting zero rows still clears the EntityManager: selecting CLEAR is an explicit lifecycle decision.
- PHP variables retain their objects and local values, but those objects are detached. Clear does not reload or reverse their state.

## SQL, callbacks, and locking

- Bulk SQL does not invoke entity persist/update/remove callbacks, cascade traversal, or per-entity validation through UnitOfWork.
- CLEAR still uses the normal clear lifecycle, including onClear. That is distinct from per-row entity callbacks.
- Database constraints and triggers retain their database-defined behavior. Trigger side effects outside the declared write set are not automatically synchronized.
- Optimistic version guards and version increments must be requested explicitly. No mode automatically adds optimistic locking to arbitrary SQL.
- Values are bound parameters and use declared DBAL types. SQL structure and identifiers come from trusted application code.
- Results must distinguish affected SQL row count from returned rows and from the number of managed objects synchronized. These quantities can differ.

Direct SQL can modify tables represented by Immutable entities because that marker governs ORM dirty checking. SYNC remains a separate proposal in the [roadmap](../roadmap.md), not an available policy.

## Transactions, errors, and rollback

An operation's database outcome and its in-memory/cache outcome are separate.

| Situation | Required behavior |
| --- | --- |
| Invalid arguments or unsupported write set | Fail before DML without flushing, clearing or changing managed state. |
| SQL failure before commit | Roll back an owned transaction; do not apply CLEAR. Follow normal DBAL recovery requirements for an outer transaction. |
| Successful owned transaction | Commit before applying the selected in-memory policy. |
| Successful SQL inside a caller-owned transaction | SQL success is not commit. Uncached BYPASS/CLEAR operations may participate. |
| Outer rollback after CLEAR | The database may revert; the cleared EntityManager and detached PHP objects are not restored. |
| CLEAR or cache failure after commit | Report the post-write failure and committed outcome. Do not imply rollback or automatically retry the SQL. Discard an unsafe persistence context. |
| Ambiguous commit outcome | Report uncertainty; do not assume either success or rollback, and do not retry automatically. |

CLEAR inside an outer transaction takes effect after successful SQL, before the caller's eventual commit or rollback. The caller is responsible for the transaction outcome; rollback does not restore detached objects.

## L2 invalidation

BYPASS does not opt out of L2 invalidation. Clearing UnitOfWork alone is not cache eviction; both supported policies use the same invalidation protocol.

The implemented invalidation policy is independent of BYPASS/CLEAR:

- Identify affected cached entity classes independently of the UnitOfWork mode. Collection/query-result L2 is not part of this API.
- Use actual affected IDs where sufficient; otherwise require a declared write set and broader region eviction.
- Coordinate eviction with commit. Evicting only before commit allows another process to repopulate old values; eviction after commit still has a visibility window.
- Make the outcome of eviction failures after commit explicit. An SQL retry is not a cache-recovery strategy.
- Reject unsupported L2/write-set combinations before writing; do not silently leave shared cache entries stale.

The target entity plus optional `affectedEntities: [OtherEntity::class, ...]` form the declared write set. The executor resolves and validates every declared class before writing, and deduplicates cached classes. It uses the entity cache facade's `evictAll(Entity::class)` before DML and after a successful owned commit, including zero-row successes. Internal generation tickets make precommit fills and delayed fills on the previous generation unreachable. Unrelated classes and other entries in a shared pool are preserved. Regions are not part of the public API.

Applications **must declare additional mapped entities written by database triggers/cascades** in affectedEntities. They are not discovered by parsing SQL or inspecting the database. Dependencies outside that declaration and external writers need application coordination. The API does not promise automatic dependency discovery or atomic database/cache consistency.

If any declared class uses a cached region, only an API-owned top-level transaction is supported. Existing outer transactions and disabled auto-commit are rejected before DML or CLEAR. Unsupported L2 strategies/graphs are rejected during region validation. An L2-enabled manager may participate in an outer transaction when the declared write set contains only uncached entities.

For uncached writes with auto-commit disabled, the API ensures a transaction is active and leaves commit/rollback to the caller. With no outer transaction and auto-commit enabled, it starts and commits its own transaction.

SQL/cache failure before commit rolls back an owned transaction and does not CLEAR. A failure inside a caller transaction leaves its recovery to the caller. Commit failures raise `ModernORM\Exception\BulkOperationException` with `databaseCommitted === null`, disconnect the uncertain connection and close the EntityManager; no SQL retry is attempted. A known successful commit followed by cache invalidation or CLEAR failure raises the same exception with `databaseCommitted === true` and closes the EntityManager. A CLEAR failure inside an outer transaction reports null because its eventual outcome is unknown. Remaining transaction/database state must be inspected before retrying.

The existing [entity L2 contract](read-only-l2-cache.md), including its visibility windows and external-writer limitations, still applies. Bulk invalidation supports both READ_ONLY and INVALIDATE entity write policies; those policies govern ordinary ORM UPDATE, not direct bulk SQL.

## Coverage and future scope

`BulkOperationsTest` covers INSERT/UPSERT/DO NOTHING, raw RETURNING/counts, mapped types/quoting, dirty snapshots/pending work, both lazy queues, zero-row CLEAR/onClear, rollback, outer transaction policies, explicit version guards, Immutable writes, trigger write sets, class isolation, late fills and pre/post-commit failures.

SYNC, COPY, UPDATE FROM and chunking are not implemented. They require separate state, transaction and partial-outcome contracts; see the [roadmap](../roadmap.md).
