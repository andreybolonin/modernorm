# ModernORM beta contract and release checklist

Release line: **0.1 beta**, beginning with **0.1.0-beta.1**. [Local verification results](beta-verification.md). This document records a bounded beta contract; it does not announce production-stable 1.0.

## Supported beta scope

- PHP 8.4/8.5; PostgreSQL 17 through pdo_pgsql. Other PostgreSQL/PHP versions are not part of this verification matrix.
- Attributes, one scalar entity PK, concrete entities/MappedSuperclass, the documented association and lifecycle contracts, Identity Map and UnitOfWork.
- SQL-first query/hydration API and explicit SQL pagination; lazy batching for ManyToOne/OneToMany/ManyToMany.
- Bulk BYPASS/CLEAR and connection-scoped Statistics. No bulk SYNC/COPY, DQL, inheritance mappings, composite entity IDs, Criteria SQL matching or ORM Filters.
- Entity L2 for flat entities, with READ_ONLY and INVALIDATE write policies, ORM-owned cached writes, and explicit failure outcomes. No collection/query-result L2 or caller-owned cached-write transactions.
- ArrayAdapter and FilesystemAdapter are the initial tested L2 adapters. Redis/APCu/distributed failover, loss of generation metadata, cross-host invalidation and performance certification are outside beta backend guarantees. An adapter implementing PSR-6 does not alone establish those guarantees.

## API and compatibility

The documented EntityManager/Configuration, mapping attributes, repositories, SQL query API, cache facade, Bulk API, Statistics/observer contracts and exception outcomes form the intended beta API. Internal persisters, hydrators, queue implementations, metadata serialization format and protected/private members are not extension contracts merely because inherited PHP classes expose them.

Beta patch fixes should preserve documented behavior. A necessary incompatible change requires a changelog entry, migration instructions and an explicit version decision before release. There is no 1.x compatibility promise during beta. Reject unsupported mappings/scenarios explicitly rather than silently emulating Doctrine behavior.

Clear/version metadata and query-plan pools when upgrading incompatible metadata/query structures. Coordinate L2 namespaces, external writers and worker restarts during deployment; running workers are not refreshed by replacing files. Never replay SQL blindly after an unknown commit or committed cache failure.

## Repeatable local release checks

```sh
composer validate --strict
PGUSER=your_role PGDATABASE=modernorm_test composer test
PGUSER=your_role PGDATABASE=modernorm_test php tools/release/check-consumer.php
PGUSER=your_role PGDATABASE=modernorm_test php benchmarks/statistics.php
PGUSER=your_role PGDATABASE=modernorm_test php benchmarks/batching.php
```

Use a dedicated test database. The consumer check builds a ZIP from the actual working files, rejects local-only content, compares packaged source hashes, and installs the candidate as a Composer dist package in an isolated application. It runs without dev dependencies, then adds optional Symfony Cache and verifies CRUD, Identity Map, lazy batching, L2 hit/update invalidation and recovery after a failed flush. It never uses a path repository or source symlink in the consumer. Output includes the artifact and SHA-256 manifest. This proves local distribution installation, not Packagist availability or a GitHub tag.

The CI dependency modes are locked, latest-allowed and lowest-allowed. A red lowest job must be fixed or the declared dependency range narrowed based on evidence; do not ignore it. Runtime and clean-consumer jobs are separate from the unit/integration suite.

Benchmarks report the environment, iteration count, wall time and PHP memory, with no timing pass/fail threshold and no claim of superiority over Doctrine. Compare runs on the same host/database after warm-up; do not interpret one noisy microbenchmark as production capacity.

## Release gates

- [x] Linux GitHub Actions passed all six PHP 8.4/8.5 × locked/latest/lowest jobs for the published candidate: [run](https://github.com/andreybolonin/modernorm/actions/runs/41999592523). Release tags must point to tested code.
- [ ] Archive and clean-consumer checks green for exactly the release candidate.
- [ ] Locked/latest/lowest dependency jobs green with no hidden integration skips.
- [ ] Review final commit/index contents, artifacts, licenses, screenshots and secrets. A mixed staged/unstaged worktree is not a release snapshot.
- [x] Enable a working private security reporting channel (GitHub private vulnerability reporting).
- [x] Core and bundle `v0.1.0-beta.1` are published on GitHub and Packagist; a clean Packagist-only consumer installed both with `^0.1@beta` and passed the Symfony production runtime smoke. The bundle uses the matching beta constraint.
- [ ] Record results and remaining limitations in the release notes.

Community issues/PRs are the maintenance model. There is no scheduled or automatic upstream porting of Doctrine fixes. Independent contributors may propose equivalent fixes, which are reviewed and tested against ModernORM.

Publication and tagging use reviewed commits with passing CI. Private reporting is enabled; public Composer availability is checked separately from GitHub publication.
