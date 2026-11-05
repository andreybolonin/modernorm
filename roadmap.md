# Roadmap

ModernORM develops independently as a PostgreSQL-first ORM for PHP 8.4+. The current architecture is documented in [architecture](docs/architecture.md); the supported boundary is in [compatibility](docs/compatibility.md).

## Implemented scope

- PostgreSQL persistence, Identity Map, UnitOfWork, snapshot/explicit tracking and native lazy objects.
- Attributes, scalar entity IDs, embeddables, MappedSuperclass, associations and documented lifecycle/locking behavior.
- SQL entity/scalar/mixed hydration, explicit joins and root-ID pagination; no DQL.
- Lazy ManyToOne, OneToMany and ManyToMany batching with attribute-level sizes.
- Independent Immutable semantics, metadata/query-plan PSR-6 caches, entity L2 with READ_ONLY and INVALIDATE policies.
- PostgreSQL bulk INSERT/UPSERT/UPDATE/DELETE with RETURNING and explicit BYPASS/CLEAR policies.
- Connection-scoped Statistics and immutable completion events.
- Separate ModernORMBundle: Symfony services/reset, Profiler/SQL history, optional migration and fixture adapters.

## First beta

The [beta checklist](docs/beta-release.md) is authoritative. Local dependency and runtime evidence is recorded in [beta verification](docs/beta-verification.md).

Remaining external release gates include Linux CI for the final commit, a private security-reporting channel, review of the final repository/archive contents, and a real public Composer installation. Publishing/tagging is a separate step. New large ORM features are not prerequisites for this bounded beta.

## Candidate follow-up work

| Area | Next investigation | Required boundary |
| --- | --- | --- |
| Bulk SYNC | Restricted scalar UPDATE with candidate IDs, dirty-state preflight and RETURNING reconciliation | Preserve object identity and snapshots; define commit/rollback outcomes before exposing an API |
| Bulk imports | COPY, UPDATE FROM and chunked operations | Explicit transaction boundaries, partial outcomes and affected-entity declarations |
| L2 infrastructure | Redis and multi-process/failure measurements | Verify generation metadata, eviction, late fills and recovery; PSR-6 compatibility alone is insufficient |
| Observability | Entity/collection/DML counters and hydration measurements | Stable definitions, bounded labels/state and measured overhead |
| Exporters | Prometheus/Grafana and OpenTelemetry adapters | Optional dependencies, worker reset and an explicit tracing-context model |
| Diagnostics | PostgreSQL EXPLAIN/plan inspection and performance advice | Opt-in execution, data handling and overhead limits |
| Fetch planning | Entity graphs/fetch plans and N+1 diagnostics | Specify hydration/completeness semantics and interaction with batching |
| Low-overhead persistence | Stateless workflows or session-level read-only modes | Explicit lifecycle/Identity Map behavior; do not weaken ordinary persistence contracts |
| Isolation | Tenant-aware contexts and replica support | Consistent SQL, Identity Map, cache keys and invalidation rules |
| Domain integrations | Transactional outbox, temporal data and soft delete | Separate optional contracts for delivery, history and visibility; no implicit core policy |

These are proposals, not release promises. Collection/query-result L2 and distributed cache strategies are outside the current contract. Further reduction of internal machinery should follow supported-scenario decisions rather than a target line count.

## Maintenance

Community issues and PRs are reviewed against ModernORM's own contract. There is no automatic or scheduled transfer of Doctrine fixes. Preserve third-party attribution, provide focused regression coverage and document compatibility changes. See [CONTRIBUTING](CONTRIBUTING.md) and [SECURITY](SECURITY.md).
