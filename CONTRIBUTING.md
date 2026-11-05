# Contributing to ModernORM

ModernORM is an independent PostgreSQL-first ORM. It originated from Doctrine ORM, but we do not maintain an upstream synchronization process or promise to port Doctrine fixes. Changes come through community issues and pull requests and are reviewed against ModernORM's own supported contract.

Use PHP 8.4 or 8.5 and a dedicated PostgreSQL 17 database. Install Composer dependencies, then run:

```sh
composer install
PGHOST=127.0.0.1 PGUSER=postgres PGPASSWORD=postgres PGDATABASE=modernorm_test composer test
composer validate --strict
```

Tests create/drop test tables and isolated schemas. Never use an application or production database. Existing GitHub Actions jobs document the Linux verification environment.

For a bug fix, include a reproducible failing case and an appropriate regression test. Test behavior rather than reproducing an implementation. Describe effects on Identity Map, UnitOfWork, transaction outcomes and L2 invalidation when applicable. Run the affected tests first, then the required release checks. Keep unrelated formatting and refactors out of the PR.

Changes to the beta API require an explanation of compatibility and documentation updates. PostgreSQL-only, attributes-only mapping, scalar identifiers and SQL-first queries are intentional constraints; discuss proposals that expand supported scenarios before implementing them.

A public issue or PR has no guaranteed response time. Contributors and maintainers may propose fixes independently of Doctrine; referencing an upstream issue is useful context, not a commitment to adopt its patch. Preserve existing MIT copyright and license notices for copied third-party code and identify its source.

Report security-sensitive findings privately as described in [SECURITY.md](SECURITY.md). The local release-candidate and benchmark commands are documented in [the beta release checklist](docs/beta-release.md).
