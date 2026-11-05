# Changelog

## 0.1.0-beta.2 — 2026-11-05

Harden the bounded entity L2 contract and add Redis verification and performance evidence. The package remains beta.

- Validate generation tokens as canonical random-token strings; malformed metadata is replaced instead of reusing deterministic stale addresses.
- Add independent Redis/PHP process checks for stale fills, cold-generation races, optimistic writers, rollback and ORM/bulk invalidation.
- Exercise actual Redis socket loss, unresponsiveness, restart/RDB rollback and PHP writer death after physical COMMIT, with explicit recovery outcomes.
- Verify payload/generation TTL and real maxmemory eviction; record a 60-second one-writer/four-reader workload with sampled heap and Redis key retention.
- Add reproducible bulk and L2 benchmarks with SQL/cache counters, raw samples and documented measurement boundaries. Local batched SQL can outperform warm Redis L2; fewer SELECTs are not a blanket speed claim.
- Extend PHP/dependency CI with pinned Redis 8.10.3, disposable-server checks, short endurance and measurement artifacts; update the clean-consumer candidate version.

Existing valid cache generations and public APIs remain compatible. No collection/query cache, outbox, external cached-write transaction support or automatic failover is added. The commit/invalidation crash window, coordinated snapshot recovery and generation-metadata retention remain explicit limits; see [Redis verification](docs/l2-redis-verification.md).

## 0.1.0-beta.1 — 2026-11-05

First public beta. The supported contract is bounded; this is not a production-stable release.

- PostgreSQL-only persistence with PHP 8.4 native lazy objects, attributes, scalar entity identifiers, Identity Map and UnitOfWork.
- SQL-first QueryBuilder/entity hydration; DQL, inheritance mappings, composite entity IDs and ORM Filters are excluded.
- Lazy ManyToOne, OneToMany and ManyToMany batch fetching with association-level batch sizes.
- Independent Immutable entity semantics and entity-by-ID PSR-6 L2 with READ_ONLY or UPDATE/invalidate write policies.
- PostgreSQL bulk BYPASS/CLEAR operations and connection-scoped SQL/flush/batch Statistics with optional completion observers.
- Separate ModernORMBundle integration for Symfony, Profiler, migrations and fixtures.
- Release-archive/clean-consumer checks, dependency-matrix CI and reproducible local measurements.

See [beta scope and release gates](docs/beta-release.md) for supported scenarios and pending publication checks. There is no automatic Doctrine upstream synchronization policy.

Beta preparation corrections: require DBAL 4.3+ for the CTE QueryBuilder API, explicitly require psr/log 3 for development test loggers, and exclude local IDE/vendor/environment files from Composer archives.
