# Lifecycle mutation and recovery contract

This is the tested boundary for ordinary entity writes. Bulk BYPASS, native SQL and caller-owned transactions have their own explicit contracts.

| Phase | Mutation contract |
| --- | --- |
| prePersist | Scalar preparation before initial persistence is reflected in the INSERT |
| preFlush | Ordinary scalar changes participate in subsequent change detection |
| onFlush | Change detection has already run; use computeChangeSet for a new insertion or recomputeSingleEntityChangeSet for an existing entity after mutation |
| preUpdate | Scalar property changes are recomputed before UPDATE; association graph changes are outside this tested mutation contract |
| preUpdate setNewValue | Changes the SQL changeset only; assign the property too when object and SQL values must agree, or refresh afterwards |
| postPersist / postUpdate / postRemove | Do not rely on newly scheduled work being incorporated into the executing write cycle |
| postFlush | Completed queues are cleaned; a direct scalar change on an already managed object can be detected by the next explicit flush. Scheduling new entities or explicit work here is outside the certified contract |

Nested flush is rejected before re-entering UnitOfWork. Lazy loading preserves already loaded local values and their original baseline; explicit refresh overwrites them. Partial native SQL hydration and full-object initialization retain a single Identity Map instance.

## Failures

| Failure boundary | Context / database state | Application action |
| --- | --- | --- |
| Before ORM transaction, such as the tested preFlush/onFlush exceptions | Manager remains open; explicitly scheduled work may remain pending. No write transaction was started by this flush | Correct the cause and decide whether to retry the pending work or clear/reload; an outer caller transaction remains the caller's responsibility |
| SQL or callback during writes | Failed flush closes/clears the manager and rolls back its transaction/savepoint; PHP property values and generated IDs are not rewound | Replace the manager/context; do not retry the closed one |
| postFlush after successful commit | Database work is already committed, manager remains open, completed queues/changesets are cleaned | Handle the callback failure without blindly replaying the completed business operation |
| Caller rolls back an outer transaction after a successful uncached flush | Database changes can roll back while PHP values/snapshots stay advanced | Clear/reload or replace the context before reuse |
| Unknown COMMIT result or committed cache failure | SQL may have committed; the cached-write failure/recovery contract identifies this separately | Follow explicit cache/commit recovery; do not infer rollback or replay SQL from the exception alone |

Cached writes require the documented ORM-owned transaction boundary; these rows do not promise arbitrary nested cached transactions. `wrapInTransaction` closes the context on failure. `clear()` discards pending ORM work and retained managed entities; it does not undo committed SQL.

For workers, clear the context between successful work units and replace it after write failures. Drop references to finished entity graphs and exceptions when no longer needed. The [worker stabilization test](stabilization-pass.md) checks object release and continued correctness; it is not a guarantee of bounded memory for every application cache or subscriber.

The [flush boundary audit](flush-boundary-audit.md) names the executable cases behind these statements. Combinations such as exceptions from onClear or scheduling other entities during post-write callbacks remain outside that audit.
