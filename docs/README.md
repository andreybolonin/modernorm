# Documentation

Start with the [project README](../README.md) for key features, installation and the Doctrine comparison. Full [recorded benchmark results](../benchmarks/results/README.md) and [methodology](../benchmarks/README.md) are maintained separately.

## Design and supported behavior

| Topic | Document |
| --- | --- |
| Architecture and component boundaries | [Architecture](architecture.md) |
| Tested scenarios and exclusions | [Compatibility matrix](compatibility.md) |
| Inherited persistence bugs, fixes and limitations | [Persistence audit](persistence-audit.md) |
| Attributes, identifiers and inheritance boundary | [Mapping scope](mapping-scope.md) |
| Queries, hydration, joins and pagination | [SQL query API](sql-query-api.md) |
| Automatic creation and update dates | [Timestampable trait](timestampable.md) |
| Creation and update actor attribution | [Blameable behavior](blameable.md) |
| Logical deletion, visibility and restoration | [SoftDelete policy](soft-delete.md) |
| Immutable entities | [Immutable contract](immutable-entities.md) |
| ManyToOne batching and shared settings | [Batch fetching](batch-fetching.md) |
| OneToMany loading | [Collection batching](collection-batch-fetching.md) |
| ManyToMany loading | [ManyToMany batching](many-to-many-batch-fetching.md) |
| PostgreSQL bulk writes and context policies | [Bulk API](bulk-api.md) |
| Entity L2 configuration, writes and recovery | [L2 cache contract](read-only-l2-cache.md) |
| L2 supported/excluded scenarios | [L2 scenario matrix](l2-scope-matrix.md) |
| Generation audit and Redis process checks | [Redis verification](l2-redis-verification.md) |
| SQL/flush/batch and operation measurement | [Statistics API](statistics.md) |
| Bulk, hydration and UnitOfWork scopes | [Operation events](operation-events.md) |
| Batch aggregates and observer events | [Batch statistics](batch-statistics.md) |

## Release and participation

- [Beta contract and release gates](beta-release.md)
- [Local verification evidence](beta-verification.md)
- [Roadmap](../roadmap.md)
- [Contributing](../CONTRIBUTING.md)
- [Security reporting](../SECURITY.md)
- [Changelog](../CHANGELOG.md)
- [MIT license](../LICENSE) and [Doctrine provenance](../tools/upstream.json)

Historical design drafts and superseded local audits have been removed. Current contracts and the roadmap distinguish implemented behavior from proposals. ModernORMBundle maintains its Symfony, migration and fixture integration documentation in its own package.
