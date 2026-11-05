# Changelog

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
