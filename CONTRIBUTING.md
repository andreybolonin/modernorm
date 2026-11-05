# Contributing to ModernORM

ModernORM is an independent PostgreSQL-first ORM. It originated from Doctrine ORM, but we do not maintain an upstream synchronization process or promise to port Doctrine fixes. Changes come through community issues and pull requests and are reviewed against ModernORM's own supported contract.

Use PHP 8.4 or 8.5 and a dedicated PostgreSQL 17 database. Install Composer dependencies, then run:

```sh
composer install
PGHOST=127.0.0.1 PGUSER=postgres PGPASSWORD=postgres PGDATABASE=modernorm_test composer test
composer validate --strict
composer quality
```

Tests create/drop test tables and isolated schemas. Never use an application or production database. Existing GitHub Actions jobs document the Linux verification environment.

`composer analyse` runs PHPStan level 5 on `src`. `phpstan-baseline.neon` records 101 existing findings after removing stale suppressions and fixing cache-persister ownership, hydration hint types and collection-mapping narrowing. The check prevents new findings; a successful run does not mean the legacy type debt is zero. Reduce baseline entries as their underlying issues are fixed; do not regenerate it merely to make a failing change pass. PHPDoc types are not treated as certain because metadata validation deliberately checks runtime input and restored cache data.

`composer cs:check` checks PSR-12 across `src`, tests, benchmarks, examples, release tools and the formatter configuration. `composer cs:fix` applies the same rules locally with risky rules disabled. The separate PHP 8.4 quality CI job uses locked development tools and needs no database or Redis. Console is a development dependency so optional CLI sources can be analysed; it remains optional for package consumers.

For a bug fix, include a reproducible failing case and an appropriate regression test. Test behavior rather than reproducing an implementation. Describe effects on Identity Map, UnitOfWork, transaction outcomes and L2 invalidation when applicable. Run the affected tests first, then the required release checks. Keep unrelated formatting and refactors out of the PR.

Changes to the beta API require an explanation of compatibility and documentation updates. PostgreSQL-only, attributes-only mapping, scalar identifiers and SQL-first queries are intentional constraints; discuss proposals that expand supported scenarios before implementing them.

A public issue or PR has no guaranteed response time. Contributors and maintainers may propose fixes independently of Doctrine; referencing an upstream issue is useful context, not a commitment to adopt its patch. Preserve existing MIT copyright and license notices for copied third-party code and identify its source.

Report security-sensitive findings privately as described in [SECURITY.md](SECURITY.md). The local release-candidate and benchmark commands are documented in [the beta release checklist](docs/beta-release.md).
