# Security policy

ModernORM 0.1 is a beta release line. No stable release or security maintenance window has been announced. Do not treat local test results as production certification.

Report vulnerabilities privately through [GitHub vulnerability reporting](https://github.com/andreybolonin/modernorm/security/advisories/new). Include the affected version/commit, a minimal reproducer, relevant PostgreSQL/PHP versions and the impact. Do not include live credentials or user data.

GitHub private vulnerability reporting is enabled for this repository. If it becomes unavailable, open an issue requesting a private contact route without publishing exploit details.

There is no promised response-time SLA. Fixes are reviewed through the project's own issue/PR process; ModernORM does not automatically inherit or port security patches from Doctrine. A Doctrine advisory alone does not establish whether ModernORM is affected: report a reproducer against ModernORM where possible.

Parameterized values do not make arbitrary SQL fragments safe. Applications are responsible for authorization, tenant predicates, identifier allowlists and external writers' cache invalidation. The supported L2 and transaction boundaries are documented in the [beta contract](docs/beta-release.md).
