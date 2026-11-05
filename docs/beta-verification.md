# Local beta verification — 2026-11-05

This is evidence for a local candidate, **not a published beta or a production-stable release**. No commit, Git tag, push or Packagist publication was performed.

| Check | Environment | Result |
| --- | --- | --- |
| Locked dependencies | PHP 8.4.27, PostgreSQL 17.11, macOS | 418 tests / 2170 assertions |
| Locked dependencies | PHP 8.5.12, PostgreSQL 17.11, macOS | 418 tests / 2170 assertions |
| Lowest allowed dependencies | PHP 8.4.27, PostgreSQL 17.11, macOS | 418 tests / 2170 assertions |
| Latest allowed dependencies | PHP 8.4.27, PostgreSQL 17.11, macOS | 418 tests / 2170 assertions |
| Lowest runtime without dev packages | PHP 8.4.27 | Native lazy objects, Statistics and persistence example passed |
| ZIP consumer without path/symlink or dev packages | PHP 8.4.27 and 8.5.12 | CRUD, Identity Map, 5 references in 3 lazy SELECTs, SQL rollback and recovery passed |
| ZIP consumer with optional Symfony Cache | PHP 8.4.27 and 8.5.12 | L2 hit without SQL, UPDATE invalidation and rollback/recovery passed |
| Composer validation / workflow YAML | Local tools | Passed; 6 Linux CI combinations configured |

## Corrections discovered by the release checks

- `doctrine/dbal` is now `^4.3`: the previously allowed 4.0.0 has no QueryBuilder `with()` and failed the CTE hydration test.
- `psr/log:^3` is an explicit development dependency, matching the typed test loggers. The runtime package does not require PSR Log merely for these tests.
- Composer archive exclusions remove IDE, vendor, Git/cache and environment files. Before this correction, a local `.idea` directory entered the ZIP despite Git ignore rules.
- The release checker compares packaged source hashes and composer.json against the working source and requires the MIT/provenance files.

The tested lowest runtime set included DBAL **4.3.0**, Persistence **4.0.0**, Collections **3.1.0**, and Instantiator **2.0.0**. Lowest resolution is subject to Composer's security blocking; it means the lowest installable allowed set at this verification time.

## Artifact evidence

- Candidate version used by the isolated Composer package repository: `0.1.0-beta.1`.
- Verified PHP source files: 281.
- ZIP SHA-256: `7db2c4f5a057ac229c276b2a8bc40bc6291881f51bc72e15f37621c97bfc0445`.
- The report identifies the tested local ZIP, not a Git tag. Rerun the same checks for the final published commit/artifact.

## Measurements

For 100 roots with 100 distinct lazy targets, batch size 1 used **101 SELECTs** including the root query; batch size 32 used **5 SELECTs**. Both ended with 200 managed entities and the same result checksum. The recorded wall times were 11.679 ms and 3.035 ms, respectively. The order is fixed (size 1 then 32), so warm-up/cache effects differ; latency/memory observations do not prove a production speedup or reduced asymptotic Identity Map size.

Statistics SELECT-1 microbenchmark (200 queries per round, 7 rounds, including fetch): median disabled **6.260 ms**, counters **6.335 ms**, empty observer **6.334 ms**. This is a local diagnostic with no timing thresholds and no Doctrine comparison. Scripts emit JSON with retained/peak PHP memory and environment information.

## Pending external release gates

Linux GitHub Actions has **not run**: the local Docker daemon is unavailable and publication/push is not authorized. Private security reporting, the final commit/tag review and a real Packagist consumer installation also remain pending. These are explicit release gates in [the beta contract](beta-release.md).

The maintenance model is independent community issues/PRs; there is no automatic/scheduled porting of Doctrine fixes. Existing provenance and license notices remain.
