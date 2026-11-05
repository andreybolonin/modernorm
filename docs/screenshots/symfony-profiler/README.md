# Symfony Profiler screenshots

Captured in Chrome from the actual ModernORMBundle Symfony Profiler using the local core with extended operation events. These are implementation screenshots, not interface mockups.

The demonstration runs against PostgreSQL temporary tables. It creates and loads orders with all three association batch kinds, exercises entity L2 hits and invalidation, and executes bulk INSERT (64 rows), UPDATE (64 rows), and DELETE (16 rows) with CLEAR. Deliberate uncached reference lookups exercise the Advisor's repeated-SQL rule. Repetition is a diagnostic hint, not proof of an N+1 problem.

Timings, cache hit rates and entity counts describe this demonstration request; they are not benchmark results. The PostgreSQL tab does not collect EXPLAIN plans or buffer statistics. The screenshots use the native Symfony light/dark themes. SQL bindings are not captured.

- `overview.png`, `overview-dark.png`: request dashboard and Performance Advisor.
- `queries.png`: SQL history and query diagnostics (visible portion).
- `unitofwork.png`: change-set, write and commit phases; scheduled work is not an executed DML count.
- `hydration.png`: hydration totals and hydrator groups.
- `batch.png`: ManyToOne, OneToMany and ManyToMany batch activity.
- `cache.png`: entity L2 counters.
- `bulk.png`: completed bulk calls, policies and outcomes.
- `postgresql.png`: available PostgreSQL context and explicit diagnostic limitations.

Extended hydration, bulk and UnitOfWork panels require a core version exposing those operation events. Older core versions display unavailable metrics explicitly.
