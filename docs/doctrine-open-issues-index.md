# Open Doctrine ORM issue index — 2026-11-15

[Audit findings and methodology](doctrine-open-issues-audit.md) · [Full reasons and evidence](evidence/doctrine-open-issues-2026-11-15.json)

Every row is an open non-PR issue from the same paginated snapshot. Status is the ModernORM audit classification, not the upstream resolution status. A related passing test is not full issue certification.

| Issue | Title | Baseline audit classification | Follow-up |
| --- | --- | --- | --- |
| [#12784](https://github.com/doctrine/orm/issues/12784) | Detaching native lazy objects leaves stale partial-loading state | `covered-related` | — |
| [#12783](https://github.com/doctrine/orm/issues/12783) | ClassMetadata: namingStrategy and typedFieldMapper are not restored after metadata cache unserialization | `reproduced` | `fixed (e98c822)` |
| [#12774](https://github.com/doctrine/orm/issues/12774) | Dynamic properties | `not-bug-report` | — |
| [#12773](https://github.com/doctrine/orm/issues/12773) | RFC: Support window functions (OVER) in DQL | `not-bug-report` | — |
| [#12747](https://github.com/doctrine/orm/issues/12747) | Deprecate passing enumType to the DBAL layer | `not-bug-report` | — |
| [#12717](https://github.com/doctrine/orm/issues/12717) | LimitSubqueryWalker generates invalid SQL on MySQL for ORDER BY expressions | `not-applicable` | — |
| [#12672](https://github.com/doctrine/orm/issues/12672) | Lazy initialisation of a partial object marks loaded DateTime fields as changed | `covered-related` | — |
| [#12639](https://github.com/doctrine/orm/issues/12639) | Add an option for schema validation to allow many-to-one relations to be used as one-to-one relations | `not-bug-report` | — |
| [#12634](https://github.com/doctrine/orm/issues/12634) | Multi-table UPDATE/DELETE fails with DBAL 4 for string identifiers: temporary id table column loses its length | `not-applicable` | — |
| [#12604](https://github.com/doctrine/orm/issues/12604) | Make PropertyAccessorInterface public API | `not-bug-report` | — |
| [#12585](https://github.com/doctrine/orm/issues/12585) | Duplicate variable in select clause silently disables fetch joining | `not-applicable` | — |
| [#12555](https://github.com/doctrine/orm/issues/12555) | Improve partial lazy load efficiency by selecting only needed columns | `not-bug-report` | — |
| [#12549](https://github.com/doctrine/orm/issues/12549) | Unnamed Foreign Keys not longer has a shortened hashed name and run into error | `not-applicable` | — |
| [#12530](https://github.com/doctrine/orm/issues/12530) | indexBy collection hydrates null key when an unrelated SQLFilter is enabled (JOINED inheritance) | `not-applicable` | — |
| [#12495](https://github.com/doctrine/orm/issues/12495) | [Feature Request] Allow eager loading / hydration of associations when passing a root entity to DQL NEW operator | `not-applicable` | — |
| [#12457](https://github.com/doctrine/orm/issues/12457) | ORM 4 should not set IDs of removed entities to null anymore | `not-bug-report` | — |
| [#12435](https://github.com/doctrine/orm/issues/12435) | Improve nullable entities as DTO arguments | `not-applicable` | — |
| [#12428](https://github.com/doctrine/orm/issues/12428) | EntityIdentityCollisionException in OneToOne Id column with inheritance | `not-applicable` | — |
| [#12417](https://github.com/doctrine/orm/issues/12417) | Chained identifying associations (nested #[ORM\Id]) cause "Object could not be converted to int" on lazy load | `not-applicable` | — |
| [#12402](https://github.com/doctrine/orm/issues/12402) | `DateTime` object in `Criteria` is treated as `date` when used in `EntityRepository::matching()` but is treated as `datetime` when used in `QueryBuilder::addCriteria()` | `not-applicable` | — |
| [#12390](https://github.com/doctrine/orm/issues/12390) | SequenceGenerator not working in MappedSuperclass | `reproduced` | `fixed (e98c822)` |
| [#12389](https://github.com/doctrine/orm/issues/12389) | Don't let EntityManager::getReference() return null | `not-bug-report` | — |
| [#12381](https://github.com/doctrine/orm/issues/12381) | 💡 Support QueryBuilder CTE (Common Table Expression)  | `not-bug-report` | — |
| [#12420](https://github.com/doctrine/orm/issues/12420) | Unnecessary changesets when using unsigned bigint | `not-applicable` | — |
| [#12372](https://github.com/doctrine/orm/issues/12372) | Multiple inheritance mapped superclasses in different namespaces | `probe-not-reproduced` | — |
| [#12352](https://github.com/doctrine/orm/issues/12352) | Entity references break change computing | `related-failure` | — |
| [#12333](https://github.com/doctrine/orm/issues/12333) | Id field is at random position in CREATE SQL when using Traits | `not-bug-report` | — |
| [#12332](https://github.com/doctrine/orm/issues/12332) | Add support for object pools / context | `not-bug-report` | — |
| [#12305](https://github.com/doctrine/orm/issues/12305) | Allow to create Custom Type with metadata accessible from convertToPHPValue | `not-bug-report` | — |
| [#12261](https://github.com/doctrine/orm/issues/12261) | EntityRepository#count() should support Criteria as parameter | `not-bug-report` | — |
| [#12260](https://github.com/doctrine/orm/issues/12260) | Unnecessary refresh() while acquiring pessimistick lock with find() leads to loosing changes done to entity | `observed-contract` | — |
| [#12259](https://github.com/doctrine/orm/issues/12259) | Integrate TypedExpression::getReturnType into FunctionNode by default | `not-bug-report` | — |
| [#12258](https://github.com/doctrine/orm/issues/12258) | Lazy Fields automatically create Partial Objects omitting columns from initial load. | `not-bug-report` | — |
| [#12203](https://github.com/doctrine/orm/issues/12203) | Introducing a compile step for Metadata | `not-bug-report` | — |
| [#12178](https://github.com/doctrine/orm/issues/12178) | Querybuilder: Expected =, <, <=, <>, >, >=, !=, got 'IN' | `not-applicable` | — |
| [#12128](https://github.com/doctrine/orm/issues/12128) | Queries using `MEMBER OF` fail if the root entity has a composite primary key that contains a relation | `not-applicable` | — |
| [#12084](https://github.com/doctrine/orm/issues/12084) | Explicitly set ID should be honored with `@GeneratedValue` or rejected | `not-bug-report` | — |
| [#12077](https://github.com/doctrine/orm/issues/12077) | Deleting an entity with hooked identifier property on PHP 8.4 throws an error | `reproduced` | `fixed` |
| [#12017](https://github.com/doctrine/orm/issues/12017) | Generated columns with insertable/updatable=false cause false positive change detection | `reproduced` | — |
| [#11991](https://github.com/doctrine/orm/issues/11991) | [3.4.0, PHP <8.4] Cannot hydrate readonly properties from superclass of embeddable | `not-applicable` | — |
| [#11964](https://github.com/doctrine/orm/issues/11964) | The order of JoinColumn Attributes affects the SQL statements of the repository methods | `not-applicable` | — |
| [#11957](https://github.com/doctrine/orm/issues/11957) | Calling setAssociationOverride in prePersist/preUpdate throws an exception | `reproduced` | `fixed (e98c822)` |
| [#11956](https://github.com/doctrine/orm/issues/11956) | Premature postLoad firing during fetch join result hydration with OneToOne association | `not-applicable` | — |
| [#11936](https://github.com/doctrine/orm/issues/11936) | Can't use readonly class because of doctrine proxies | `not-applicable` | — |
| [#11910](https://github.com/doctrine/orm/issues/11910) | [Feature] Ablity to write custom change detection logic for custom doctrine types | `not-bug-report` | — |
| [#11909](https://github.com/doctrine/orm/issues/11909) | UniqueConstraint Attribute does not support `flags` while DBAL does | `not-bug-report` | — |
| [#11898](https://github.com/doctrine/orm/issues/11898) | Schema Tool keeps recreating table `user` when inside a schema in PostgreSQL | `probe-not-reproduced` | — |
| [#11890](https://github.com/doctrine/orm/issues/11890) | Lazy Ghost Proxy Hydration Overwrites Local Entity Changes | `not-applicable` | — |
| [#11880](https://github.com/doctrine/orm/issues/11880) | Using lastval() instead of currval('sequence_name') for fetching IDs can return wrong ID | `covered-related` | — |
| [#11819](https://github.com/doctrine/orm/issues/11819) | Like expression with case statement error | `not-applicable` | — |
| [#11787](https://github.com/doctrine/orm/issues/11787) | Incorrect / lost cloning of an associated entity with LazyGhosts | `not-applicable` | — |
| [#11785](https://github.com/doctrine/orm/issues/11785) | Undefined array key when calling refresh() after updating to 2.20.1 | `needs-upstream-reproducer` | — |
| [#11784](https://github.com/doctrine/orm/issues/11784) | Extra parentheses causing DQL errors? | `not-applicable` | — |
| [#11744](https://github.com/doctrine/orm/issues/11744) | Using IDENTITY in a update does not quote table name | `not-applicable` | — |
| [#11735](https://github.com/doctrine/orm/issues/11735) | SimpleConditionalExpressions can not parse CASE ... BETWEEN | `not-applicable` | — |
| [#11721](https://github.com/doctrine/orm/issues/11721) | Cannot safely type `bigint` column as `int` when used with dbal 3.x | `not-applicable` | — |
| [#11712](https://github.com/doctrine/orm/issues/11712) | Unable to query by an array of Uuid | `probe-not-reproduced` | — |
| [#11706](https://github.com/doctrine/orm/issues/11706) | `SQLFilter` gives an unclear error when using non-`Stringable` parameters | `not-bug-report` | — |
| [#11701](https://github.com/doctrine/orm/issues/11701) | `doctrine:schema:drop` does not remove custom schema in PostgreSQL | `not-bug-report` | — |
| [#11696](https://github.com/doctrine/orm/issues/11696) | Support mysql unique index column name NULL | `not-bug-report` | — |
| [#11638](https://github.com/doctrine/orm/issues/11638) | Arithmetic Operations in OrderBy with Aliases | `not-applicable` | — |
| [#11637](https://github.com/doctrine/orm/issues/11637) | Custom Collections through delegation | `not-bug-report` | — |
| [#11620](https://github.com/doctrine/orm/issues/11620) | Metadata field type validation against Entity property type does not detect nullability | `not-bug-report` | — |
| [#11608](https://github.com/doctrine/orm/issues/11608) | Schema validator doesn't report invalid `indexBy` configuration. | `reproduced` | — |
| [#11600](https://github.com/doctrine/orm/issues/11600) | [Feature Request] Add source to ConversionException | `not-bug-report` | — |
| [#11599](https://github.com/doctrine/orm/issues/11599) | setFetchMode does not respect second level association | `not-applicable` | — |
| [#11593](https://github.com/doctrine/orm/issues/11593) | Dirty solution to detect changes at columnDefinition | `not-bug-report` | — |
| [#11580](https://github.com/doctrine/orm/issues/11580) | Removing one side of BiDi 1-1 relation throws | `probe-not-reproduced` | — |
| [#11577](https://github.com/doctrine/orm/issues/11577) | Long column names break select query (Oracle) | `not-applicable` | — |
| [#11572](https://github.com/doctrine/orm/issues/11572) | Initializing a collection overwrites nulled field | `probe-not-reproduced` | — |
| [#11571](https://github.com/doctrine/orm/issues/11571) | Add support for 'select null' | `not-bug-report` | — |
| [#11567](https://github.com/doctrine/orm/issues/11567) | [Parser] Combination of discriminator, WhereInWalker, cloneQuery and apostrophe in like query causes parameters to not be bound | `not-applicable` | — |
| [#11566](https://github.com/doctrine/orm/issues/11566) | Allow changing the `CacheMode` when using 2nd level cache | `not-applicable` | — |
| [#11563](https://github.com/doctrine/orm/issues/11563) | Caching null returns for `findOneBy` queries | `not-applicable` | — |
| [#11561](https://github.com/doctrine/orm/issues/11561) | Replace `addCustomXxxFunction` with single `addCustomFunction` | `not-bug-report` | — |
| [#11538](https://github.com/doctrine/orm/issues/11538) | Sync type of parameters accepted by ORM NativeQuery setParameter and the one returned by DBAL QueryBuilder getParameterTypes | `not-bug-report` | — |
| [#11494](https://github.com/doctrine/orm/issues/11494) | Automated type checking of code samples in docs | `not-bug-report` | — |
| [#11480](https://github.com/doctrine/orm/issues/11480) | Entity scheduled for deletion and original entity retrieved from database points on the same object since 2.19.5 | `observed-contract` | — |
| [#11459](https://github.com/doctrine/orm/issues/11459) | groupBY with entity root DQL alias is missing discriminator field | `not-applicable` | — |
| [#11405](https://github.com/doctrine/orm/issues/11405) | Custom types converted to database value by __toString() in loadAll instead of Type::convertToDatabaseValue() | `probe-not-reproduced` | — |
| [#11390](https://github.com/doctrine/orm/issues/11390) | AssociationOverride bug | `reproduced` | — |
| [#11340](https://github.com/doctrine/orm/issues/11340) | Add support of relations / associations for DTOs | `not-bug-report` | — |
| [#11319](https://github.com/doctrine/orm/issues/11319) | Binding parameters optimisation for `BackedEnum` (`array` of `BackedEnum` values) | `not-bug-report` | — |
| [#11280](https://github.com/doctrine/orm/issues/11280) | Support enum-type for id field in XML mapping | `not-applicable` | — |
| [#11258](https://github.com/doctrine/orm/issues/11258) | SecondLevelCache throws exception when there's a not cached relation in the entity | `not-applicable` | — |
| [#11254](https://github.com/doctrine/orm/issues/11254) | Nested setFetchMode | `not-bug-report` | — |
| [#11236](https://github.com/doctrine/orm/issues/11236) | Error handling when misuse of find() with array values | `probe-not-reproduced` | — |
| [#11223](https://github.com/doctrine/orm/issues/11223) | Creating a index with invalid fields in the metadata does not report any error | `probe-not-reproduced` | — |
| [#11208](https://github.com/doctrine/orm/issues/11208) | How to choose the right branch | `not-applicable` | — |
| [#11151](https://github.com/doctrine/orm/issues/11151) | Respect many-to-many inverse onDelete=cascade | `not-bug-report` | — |
| [#11137](https://github.com/doctrine/orm/issues/11137) | QueryBuilder IndexBy not indexing a result set | `not-applicable` | — |
| [#11123](https://github.com/doctrine/orm/issues/11123) | Allow custom XSD schemas | `not-applicable` | — |
| [#11110](https://github.com/doctrine/orm/issues/11110) | Version attribute does not work when column type is inferred instead of explicitly given | `probe-not-reproduced` | — |
| [#11099](https://github.com/doctrine/orm/issues/11099) | Embed SPL object ID sometimes seems to change when second level cache is active | `not-applicable` | — |
| [#11096](https://github.com/doctrine/orm/issues/11096) | Add Lockmode to findBy and findOneBy | `not-bug-report` | — |
| [#11093](https://github.com/doctrine/orm/issues/11093) | Lazy loading OneToMany modifies the owning side | `probe-not-reproduced` | — |
| [#11042](https://github.com/doctrine/orm/issues/11042) | Implement a   ReturningIdGenerator that leverage `INSERT INTO .... RETURNING id`  | `not-bug-report` | — |
| [#11021](https://github.com/doctrine/orm/issues/11021) | Incompatibilities between lazy and initialized collections | `not-bug-report` | — |
| [#10982](https://github.com/doctrine/orm/issues/10982) | Find a way to allow calling `EntityManager::flush()` from within running commit phases | `not-bug-report` | — |
| [#10949](https://github.com/doctrine/orm/issues/10949) | Functional Indexes | `not-bug-report` | — |
| [#10934](https://github.com/doctrine/orm/issues/10934) | Add Type (discriminator) function | `not-bug-report` | — |
| [#10905](https://github.com/doctrine/orm/issues/10905) | Upgrading from 2.13 => 2.14 breaks 2nd level cache | `not-applicable` | — |
| [#10903](https://github.com/doctrine/orm/issues/10903) | Unique Constraints etc. defined on STI entities are ignored | `not-applicable` | — |
| [#10898](https://github.com/doctrine/orm/issues/10898) | Embeddables not correctly compared in WHERE clause of DQL | `not-applicable` | — |
| [#10844](https://github.com/doctrine/orm/issues/10844) | doctrine.ulid_generator is not accepted by xml pattern | `not-applicable` | — |
| [#10831](https://github.com/doctrine/orm/issues/10831) | `DateTime`/`DateTimeImmutable` as an part of composite key | `not-applicable` | — |
| [#10829](https://github.com/doctrine/orm/issues/10829) | EntityListenerPass unaware of ResolveTargetEntityListener::$resolveTargetEntities | `not-bug-report` | — |
| [#10783](https://github.com/doctrine/orm/issues/10783) | Warn about "ON DELETE RESTRICT" | `not-bug-report` | — |
| [#10770](https://github.com/doctrine/orm/issues/10770) | Add field name into SchemaValidator error messages. | `not-bug-report` | — |
| [#10576](https://github.com/doctrine/orm/issues/10576) | row constructors multi fields in() | `not-bug-report` | — |
| [#10527](https://github.com/doctrine/orm/issues/10527) | Trying to access array offset on value of type null in BasicEntityPersister | `reproduced` | `fixed (b70f8f5)` |
| [#10524](https://github.com/doctrine/orm/issues/10524) | Join are lost by the queryBuilder when building a Delete Query | `not-applicable` | — |
| [#10514](https://github.com/doctrine/orm/issues/10514) | 2.14.0 BC Break: UOW::normalizeIdentifier() bug | `not-applicable` | — |
| [#10499](https://github.com/doctrine/orm/issues/10499) | cascade refresh on entity that is managed but not saved yet | `reproduced` | — |
| [#10432](https://github.com/doctrine/orm/issues/10432) | BC Break for findBy with custom enum types | `reproduced` | `fixed (b70f8f5)` |
| [#10407](https://github.com/doctrine/orm/issues/10407) | Dropping schema should drop L2 caches | `not-bug-report` | — |
| [#10314](https://github.com/doctrine/orm/issues/10314) | Add a new DQL construct to support initialization of to-many associations? | `not-applicable` | — |
| [#10226](https://github.com/doctrine/orm/issues/10226) | Proposal: Ability to select specific type when using inheritance | `not-applicable` | — |
| [#10152](https://github.com/doctrine/orm/issues/10152) | PersistentCollection uses Ulid Rfc4122 as indexBy value instead of uid one | `reproduced` | — |
| [#10044](https://github.com/doctrine/orm/issues/10044) | setDefaultQueryHint in doctrine.yaml | `not-bug-report` | — |
| [#10038](https://github.com/doctrine/orm/issues/10038) | Enums instead constants | `not-bug-report` | — |
| [#10018](https://github.com/doctrine/orm/issues/10018) | Explicitly support traversable as parameter | `not-bug-report` | — |
| [#10014](https://github.com/doctrine/orm/issues/10014) | AnsiQuoteStrategy does not convert field names to column names for identifiers | `reproduced` | `fixed (e98c822)` |
| [#10003](https://github.com/doctrine/orm/issues/10003) | Fetching again an entity with unpersisted changes on a many-to-many relation makes UoW inconsistent | `probe-not-reproduced` | — |
| [#9999](https://github.com/doctrine/orm/issues/9999) | Doctrine 'ORM\Cache' with inherited classes | `not-applicable` | — |
| [#9952](https://github.com/doctrine/orm/issues/9952) | Paginator fails with `$fetchJoinCollection = true` and query ordered by a join column. | `not-applicable` | — |
| [#9951](https://github.com/doctrine/orm/issues/9951) | LazyCriteriaCollection::count returns ambiguous results when paired with Criteria::setMaxResults | `not-applicable` | — |
| [#9916](https://github.com/doctrine/orm/issues/9916) | Implement Insertable / Updateable for ManyToOne and OneToOne | `not-bug-report` | — |
| [#9820](https://github.com/doctrine/orm/issues/9820) | dot prefixed TableName on AssociationOverrides via XmlDriver when not defining a schema | `not-applicable` | — |
| [#9745](https://github.com/doctrine/orm/issues/9745) | `orphanRemoval` not working for `OneToMany` collections when using a custom type for the identifier and oracle database | `not-applicable` | — |
| [#9744](https://github.com/doctrine/orm/issues/9744) | [RFC] Read nullability from PHP type | `not-bug-report` | — |
| [#9689](https://github.com/doctrine/orm/issues/9689) | Multiple composite foreign keys sharing a common column | `not-applicable` | — |
| [#9681](https://github.com/doctrine/orm/issues/9681) | Entity Annotation & EntityRepository Template causes Psalm Covariance Issues | `not-bug-report` | — |
| [#9625](https://github.com/doctrine/orm/issues/9625) | `SchemaTool::getCreateSchemaSQL` does not respect asset filter | `observed-contract` | — |
| [#9601](https://github.com/doctrine/orm/issues/9601) | Flush an object with NULL values in LOB fields leads to an error, OCI8 | `not-applicable` | — |
| [#9575](https://github.com/doctrine/orm/issues/9575) | Check if Data Type implementes Stringable interface for Primary keys | `not-bug-report` | — |
| [#9558](https://github.com/doctrine/orm/issues/9558) | Set `JoinColumn::referencedColumnName` based on the target entity instead of `id` | `not-bug-report` | — |
| [#9505](https://github.com/doctrine/orm/issues/9505) | Bug: UnitOfWork attempts to update readonly property (object IDs don't match) | `reproduced` | `fixed` |
| [#9460](https://github.com/doctrine/orm/issues/9460) |  Add possibility to use `toIterable` for a join which does just a `SUM`. | `not-bug-report` | — |
| [#9427](https://github.com/doctrine/orm/issues/9427) | Overwriting entities relation field when hydrating a collection | `probe-not-reproduced` | — |
| [#9201](https://github.com/doctrine/orm/issues/9201) | After refresh my entity it is updated (doctrine do SQL UPDATE) | `probe-not-reproduced` | — |
| [#9185](https://github.com/doctrine/orm/issues/9185) | Array hydrator returns null values instead of actual elements in one-to-many relationships | `reproduced` | `fixed (46eb4b8)` |
| [#9183](https://github.com/doctrine/orm/issues/9183) | `BasicEntityPersister::expandParameters()` expands criteria parameters with custom types wrong | `probe-not-reproduced` | — |
| [#9178](https://github.com/doctrine/orm/issues/9178) | Condition using "CASE WHEN" not expecting "LIKE" keyword | `not-applicable` | — |
| [#9124](https://github.com/doctrine/orm/issues/9124) | Undefined index on second flush removing an Entity that's into an initialized PersistentCollection | `probe-not-reproduced` | — |
| [#9041](https://github.com/doctrine/orm/issues/9041) | Use accented colunm name in query builder | `not-applicable` | — |
| [#9040](https://github.com/doctrine/orm/issues/9040) | Support for composite keys on groupBy caluse | `not-applicable` | — |
| [#9013](https://github.com/doctrine/orm/issues/9013) | QueryBulder -> join alias parameter not expect 'as' word as alias | `not-applicable` | — |
| [#9011](https://github.com/doctrine/orm/issues/9011) | Make ArrayHydrator less memory voracious  | `not-bug-report` | — |
| [#8926](https://github.com/doctrine/orm/issues/8926) | Null-object error in spl_object_hash() when loading associations with association-keys | `not-applicable` | — |
| [#8868](https://github.com/doctrine/orm/issues/8868) | Attribute on traits | `not-bug-report` | — |
| [#8866](https://github.com/doctrine/orm/issues/8866) | Not all ClassMetadata is loaded  in prod | `covered-related` | — |
| [#8762](https://github.com/doctrine/orm/issues/8762) | Tests for MappingDriver::isTransient | `not-bug-report` | — |
| [#8741](https://github.com/doctrine/orm/issues/8741) | Attempted to call an undefined method named "storeEntityCache" of class "BasicEntityPersister" | `not-applicable` | — |
| [#8739](https://github.com/doctrine/orm/issues/8739) | removeElement from EXTRA_LAZY collection is loading all elements | `known-limitation` | — |
| [#8702](https://github.com/doctrine/orm/issues/8702) | "Too many parameters: ..." when adding multiple criteria having clauses on the same property | `not-applicable` | — |
| [#8607](https://github.com/doctrine/orm/issues/8607) | Extra lazy PersistentCollection::get() with indexBy doesn't check mappedBy value | `covered-related` | — |
| [#8580](https://github.com/doctrine/orm/issues/8580) |  Paginator's CountOutputWalker not remove ORDER BY for MSSQL platform | `not-applicable` | — |
| [#8554](https://github.com/doctrine/orm/issues/8554) | CaseExpression as first parameter of LikeExpression not working as intended | `not-applicable` | — |
| [#8436](https://github.com/doctrine/orm/issues/8436) | Bidirectional OneToOne with orphanRemoval leads to the deletion of a still referenced entity | `reproduced` | `fixed (46eb4b8)` |
| [#8429](https://github.com/doctrine/orm/issues/8429) | Fetching untracked entities | `not-bug-report` | — |
| [#8411](https://github.com/doctrine/orm/issues/8411) | SQLite schema namespace adjustment incorrectly implied for ManyToMany inserts. | `not-applicable` | — |
| [#8404](https://github.com/doctrine/orm/issues/8404) | DQL functions return type is respected by `getSingleResult` but not `getSingleScalarResult`. | `not-applicable` | — |
| [#8397](https://github.com/doctrine/orm/issues/8397) | Incorrect SQL generated for unidirectional many-to-many indexed extra lazy associations | `covered-related` | — |
| [#8394](https://github.com/doctrine/orm/issues/8394) | NEW operator overwrite entity | `not-applicable` | — |
| [#8379](https://github.com/doctrine/orm/issues/8379) | Deprecate type inference in `Query::setParameter` | `not-bug-report` | — |
| [#8361](https://github.com/doctrine/orm/issues/8361) | [RFC] Set multiple parameters at once in the QueryBuilder | `not-bug-report` | — |
| [#8358](https://github.com/doctrine/orm/issues/8358) | Confusing parameter name $classes | `not-bug-report` | — |
| [#8346](https://github.com/doctrine/orm/issues/8346) | [join table inheritance]Suboptimal JOIN clause order for ToMany-association to parent class | `not-applicable` | — |
| [#8324](https://github.com/doctrine/orm/issues/8324) | Issue with bidirectional OneToMany when removing from a collection | `probe-not-reproduced` | — |
| [#8323](https://github.com/doctrine/orm/issues/8323) | UnitOfWork->createEntity: idHash-creation from DateTime fails | `not-applicable` | — |
| [#8282](https://github.com/doctrine/orm/issues/8282) | OneToOne relation is not updated if proxy is loaded afterwords | `reproduced` | `fixed (46eb4b8)` |
| [#8280](https://github.com/doctrine/orm/issues/8280) | Scalars aren't necessarily related to their nearest root entity | `not-applicable` | — |
| [#8278](https://github.com/doctrine/orm/issues/8278) | [RFC] Smarter paginator default | `not-bug-report` | — |
| [#8255](https://github.com/doctrine/orm/issues/8255) | PHP 7.4, Type Hinted Properties, and Composite Primary key with a Foreign Key gives a type error | `not-applicable` | — |
| [#8254](https://github.com/doctrine/orm/issues/8254) | LimitSubqueryOutputWalker walkSelectStatementWithoutRowNumber doesn't preserve order | `not-applicable` | — |
| [#8205](https://github.com/doctrine/orm/issues/8205) | Refetching entity with eager relation overwrites nulled relation | `reproduced` | `fixed (46eb4b8)` |
| [#8191](https://github.com/doctrine/orm/issues/8191) | convertToDatabaseValueSQL is not called when using a QuoteStrategy | `reproduced` | `fixed (b70f8f5)` |
| [#8181](https://github.com/doctrine/orm/issues/8181) | Entities with custom DBAL type identifier bound to DQL query parameters are not converted through DBAL types | `not-applicable` | — |
| [#8177](https://github.com/doctrine/orm/issues/8177) | Support unique yet stable identifier generation in "Specification"-style designs | `not-bug-report` | — |
| [#8164](https://github.com/doctrine/orm/issues/8164) | Querying Embeddables using a Criteria  | `not-applicable` | — |
| [#8155](https://github.com/doctrine/orm/issues/8155) | GetSingleScalarResult/GetScalarResult should always return an int when the field is typed as integer | `not-applicable` | — |
| [#8141](https://github.com/doctrine/orm/issues/8141) | BasicEntityPersister & Composite keys | `not-applicable` | — |
| [#8133](https://github.com/doctrine/orm/issues/8133) | Unexpected INSERT when replacing collection of B with another one after persisting A. | `reproduced` | — |
| [#8117](https://github.com/doctrine/orm/issues/8117) | Allow Entity Listener to match interface | `not-bug-report` | — |
| [#8108](https://github.com/doctrine/orm/issues/8108) | Property "*" in "*" was already declared, but it must be declared only once | `not-applicable` | — |
| [#8099](https://github.com/doctrine/orm/issues/8099) | Discriminator Bug: lib/Doctrine/ORM/Internal/Hydration/ObjectHydrator.php | `not-applicable` | — |
| [#8066](https://github.com/doctrine/orm/issues/8066) | Cache id "[*_1 1][2]" contains unauthorized character " " | `not-applicable` | — |
| [#8052](https://github.com/doctrine/orm/issues/8052) | Validate Embeddables in SchemaValidator | `not-bug-report` | — |
| [#8051](https://github.com/doctrine/orm/issues/8051) | The discriminator column "`key`" is missing - initialize collection | `not-applicable` | — |
| [#8047](https://github.com/doctrine/orm/issues/8047) | UPDATE query on class table inherited entity throwing error from MultiTableUpdateExecutor | `not-applicable` | — |
| [#8045](https://github.com/doctrine/orm/issues/8045) | Error: Expected =, <, <=, <>, >, >=, !=, got 'BETWEEN' | `not-applicable` | — |
| [#8043](https://github.com/doctrine/orm/issues/8043) | Make IDENTITY function work with joined columns | `not-applicable` | — |
| [#8024](https://github.com/doctrine/orm/issues/8024) | Deprecate FunctionNode without TypedExpression | `not-bug-report` | — |
| [#8003](https://github.com/doctrine/orm/issues/8003) | Can not represent query with subquery, that has group by and alias | `not-applicable` | — |
| [#7998](https://github.com/doctrine/orm/issues/7998) | WeakReference to free up memory automatically | `not-bug-report` | — |
| [#7980](https://github.com/doctrine/orm/issues/7980) | SQLFilters are too locked down | `not-bug-report` | — |
| [#7972](https://github.com/doctrine/orm/issues/7972) | ORM should load platform lazily on some places | `not-bug-report` | — |
| [#7961](https://github.com/doctrine/orm/issues/7961) | One-to-one relation resulting in two identically named indexes | `not-applicable` | — |
| [#7943](https://github.com/doctrine/orm/issues/7943) | Allow relationship overriding | `not-bug-report` | — |
| [#7932](https://github.com/doctrine/orm/issues/7932) | Referencing inverse collection is clobbering modified association on owning side. | `probe-not-reproduced` | — |
| [#7924](https://github.com/doctrine/orm/issues/7924) | Suggest solutions for semantical errors in Parser | `not-applicable` | — |
| [#7859](https://github.com/doctrine/orm/issues/7859) | Composite foreign keys with shared columns | `not-applicable` | — |
| [#7812](https://github.com/doctrine/orm/issues/7812) | Unable to update belongs-to relationship when loaded through query from the parent (incomplete hydration) | `probe-not-reproduced` | — |
| [#7810](https://github.com/doctrine/orm/issues/7810) | Cache count operation for Extra Lazy | `not-bug-report` | — |
| [#7806](https://github.com/doctrine/orm/issues/7806) | "preRemove" given wrong entity | `related-failure` | — |
| [#7804](https://github.com/doctrine/orm/issues/7804) | Using DTOs with groups | `not-applicable` | — |
| [#7775](https://github.com/doctrine/orm/issues/7775) | LimitSubqueryOutputWalker adds selects for aggregated fields | `not-applicable` | — |
| [#7746](https://github.com/doctrine/orm/issues/7746) | Implement FOR UPDATE SKIP LOCKED | `not-bug-report` | — |
| [#7721](https://github.com/doctrine/orm/issues/7721) | Unique index generated for one-to-one relation causes errors when replacing entities. | `known-limitation` | — |
| [#7708](https://github.com/doctrine/orm/issues/7708) | Second Level Cache makes 350 requests to Redis for 20-rows result | `not-applicable` | — |
| [#7707](https://github.com/doctrine/orm/issues/7707) | Undefined index and invalid number of prepared parameters | `not-applicable` | — |
| [#7655](https://github.com/doctrine/orm/issues/7655) | Between with arithmetic operation in left operand is not working | `not-applicable` | — |
| [#7638](https://github.com/doctrine/orm/issues/7638) | PersistentCollection::get with fetch EXTRA_LAZY fetches not associated entities | `covered-related` | — |
| [#7615](https://github.com/doctrine/orm/issues/7615) | Removed entity included when hydrating collection  | `covered-related` | — |
| [#7605](https://github.com/doctrine/orm/issues/7605) | Condition using "CASE WHEN" not expecting "IN" keyword | `not-applicable` | — |
| [#7595](https://github.com/doctrine/orm/issues/7595) | Pass target entity class name as an argument to NamingStrategy::referenceColumnName | `not-bug-report` | — |
| [#7588](https://github.com/doctrine/orm/issues/7588) | [MSSQL] Invalid query generation when using GroupBy with a ResultVariable | `not-applicable` | — |
| [#7579](https://github.com/doctrine/orm/issues/7579) | Bidirectional one-to-one relations with multiple join columns break in DQL queries | `not-applicable` | — |
| [#7570](https://github.com/doctrine/orm/issues/7570) | Different mapping driver returns different isTransient() response for same class | `not-applicable` | — |
| [#7534](https://github.com/doctrine/orm/issues/7534) | Criteria::create()->orWhere() transform to And in Mysql | `not-applicable` | — |
| [#7513](https://github.com/doctrine/orm/issues/7513) | Annotation conflict between Column and Relation does not throw an exception | `reproduced` | — |
| [#7463](https://github.com/doctrine/orm/issues/7463) | Error on data hydration when field name equals discriminator column name | `not-applicable` | — |
| [#7460](https://github.com/doctrine/orm/issues/7460) | Cannot use custom type with quoted field | `reproduced` | `fixed (b70f8f5)` |
| [#7439](https://github.com/doctrine/orm/issues/7439) | Comma Seperated lists, values are not trimmed for unique-constraint columns attribute XML Schema | `not-applicable` | — |
| [#7426](https://github.com/doctrine/orm/issues/7426) | postUpdate event not triggered when removing from EXTRA_LAZY association | `observed-contract` | — |
| [#7418](https://github.com/doctrine/orm/issues/7418) | Cascade remove to generate bulk delete SQL | `not-bug-report` | — |
| [#7389](https://github.com/doctrine/orm/issues/7389) | Low performance when executing setParameter() on a query that has a big amount of parameters | `source-confirmed-risk` | — |
| [#7365](https://github.com/doctrine/orm/issues/7365) | Problems with tracking changes in associated relation | `probe-not-reproduced` | — |
| [#7251](https://github.com/doctrine/orm/issues/7251) | DiscriminatorColumn does not works with CustomType | `not-applicable` | — |
| [#7248](https://github.com/doctrine/orm/issues/7248) | Entity changes created by an onFlush subscriber are not executed during flush if no other changes were scheduled | `covered-related` | — |
| [#7245](https://github.com/doctrine/orm/issues/7245) | Timezone handling for DateTime arguments for queries | `not-bug-report` | — |
| [#7243](https://github.com/doctrine/orm/issues/7243) | RSM addFieldResult undefined index | `reproduced` | — |
| [#7223](https://github.com/doctrine/orm/issues/7223) | Applying sort on fetch-joined table's column while using paginator with an output walker. | `not-applicable` | — |
| [#7204](https://github.com/doctrine/orm/issues/7204) | Embedded class not changed, but keeps being marked as changed in the EntityChangeset | `probe-not-reproduced` | — |
| [#7202](https://github.com/doctrine/orm/issues/7202) | ALTER TABLE ... DROP FOREIGN KEY, ALTER TABLE ... ADD FOREIGN KEY (mutliple queries, bad order, dangerous) | `observed-contract` | — |
| [#7169](https://github.com/doctrine/orm/issues/7169) | New related entities not removed during cascade refresh of empty relationship | `reproduced` | — |
| [#7161](https://github.com/doctrine/orm/issues/7161) | EntityManagerDecorator::transactional injects wrapped EM into closure | `observed-contract` | — |
| [#7151](https://github.com/doctrine/orm/issues/7151) | PersistentCollection::matching() lacks support for inheritance | `not-applicable` | — |
| [#7133](https://github.com/doctrine/orm/issues/7133) | ClassMetadataInfo::getFieldMapping() MappingException not properly caught | `reproduced` | — |
| [#7125](https://github.com/doctrine/orm/issues/7125) | Duplicate definition of column … on inherited embeddable | `not-applicable` | — |
| [#7117](https://github.com/doctrine/orm/issues/7117) | Inconsistent naming for different caches | `not-bug-report` | — |
| [#7096](https://github.com/doctrine/orm/issues/7096) | Identifier flattener issues when L2C is enabled | `not-applicable` | — |
| [#7041](https://github.com/doctrine/orm/issues/7041) | Collections/ExpressionBuilder::contains generate CONTAINS instead of LIKE in MySQL | `not-applicable` | — |
| [#6999](https://github.com/doctrine/orm/issues/6999) | orphan removal removes children, not orphans | `covered-related` | — |
| [#6986](https://github.com/doctrine/orm/issues/6986) | Correct phpdocs of methods marked internal | `not-bug-report` | — |
| [#6934](https://github.com/doctrine/orm/issues/6934) | query WHERE xxx IN (:parameter) does not work with array of date | `probe-not-reproduced` | — |
| [#6816](https://github.com/doctrine/orm/issues/6816) | Schema update with inheritance mapping and unique keys edge case | `not-applicable` | — |
| [#6783](https://github.com/doctrine/orm/issues/6783) | Redundant index created when FK column part of composite index | `reproduced` | — |
| [#6776](https://github.com/doctrine/orm/issues/6776) | oneToMany orphanRemoval with unique - constraint violation | `known-limitation` | — |
| [#6667](https://github.com/doctrine/orm/issues/6667) | Allow custom collections | `not-bug-report` | — |
| [#6650](https://github.com/doctrine/orm/issues/6650) | Should locking a result for PESSIMISTIC_WRITE refresh the entity cache? | `observed-contract` | — |
| [#6589](https://github.com/doctrine/orm/issues/6589) | Synchronization of bidirectional association cannot be performed in any order | `probe-not-reproduced` | — |
| [#6528](https://github.com/doctrine/orm/issues/6528) | Update Sql on inheritance Entity : error | `not-applicable` | — |
| [#6502](https://github.com/doctrine/orm/issues/6502) | Wrong column order in inherited table metadata | `not-applicable` | — |
| [#6470](https://github.com/doctrine/orm/issues/6470) | OneToOne second level cache load | `not-applicable` | — |
| [#6450](https://github.com/doctrine/orm/issues/6450) | ExpressionBuilder::between() returns string instead of Expression/Function | `not-applicable` | — |
| [#6426](https://github.com/doctrine/orm/issues/6426) | Strange behavior when trying to update 'json_array' value with QueryBuilder | `not-applicable` | — |
| [#6363](https://github.com/doctrine/orm/issues/6363) | Unable tu use LIKE on numbers and subqueries | `not-bug-report` | — |
| [#6344](https://github.com/doctrine/orm/issues/6344) | OrphanRemoval doesn't work if set new collection without explicit remove() | `covered-related` | — |
| [#6339](https://github.com/doctrine/orm/issues/6339) | Indexed one-to-many not setting the original entity property in PersistentCollection | `probe-not-reproduced` | — |
| [#6331](https://github.com/doctrine/orm/issues/6331) | Cascade remove tries to delete child entities with wrong colum name for fk column | `not-applicable` | — |
| [#6310](https://github.com/doctrine/orm/issues/6310) | GroupBy discriminator | `not-applicable` | — |
| [#6293](https://github.com/doctrine/orm/issues/6293) | Combination of ManyToMany relation and LEFT JOIN "WITH" produces extra rows | `not-applicable` | — |
| [#6278](https://github.com/doctrine/orm/issues/6278) | User Defined Function and Comparison Operator | `not-applicable` | — |
| [#6272](https://github.com/doctrine/orm/issues/6272) | When a RetryableException is thrown, entitymanager should not be closed | `not-bug-report` | — |
| [#6255](https://github.com/doctrine/orm/issues/6255) | Allow private/protected lifecycle callbacks | `not-bug-report` | — |
| [#6248](https://github.com/doctrine/orm/issues/6248) | UniqueConstraint declared in inheritance (child) entities is silently ignored. | `not-applicable` | — |
| [#6173](https://github.com/doctrine/orm/issues/6173) | ManyToMany association can't use IN / OR criteria  | `reproduced` | `fixed (b70f8f5)` |
| [#6153](https://github.com/doctrine/orm/issues/6153) | Issue trying to persist Entity with composite relationship set as null | `not-applicable` | — |
| [#6124](https://github.com/doctrine/orm/issues/6124) | OneToOne relationship with owning side having key of inverse side broken | `not-applicable` | — |
| [#6100](https://github.com/doctrine/orm/issues/6100) | getOneOrNullResult contains duplicate code in Doctrine\ORM\AbstractQuery | `not-bug-report` | — |
| [#6096](https://github.com/doctrine/orm/issues/6096) | Second Level Cache, QueryCache, Eviction | `not-applicable` | — |
| [#6079](https://github.com/doctrine/orm/issues/6079) | Lifecycle Event "postRemove" suddenly contains lot of unexpected children (OneToMany relation) | `related-failure` | — |
| [#6047](https://github.com/doctrine/orm/issues/6047) | Embedded mapping cannot be overridden | `not-bug-report` | — |
| [#6035](https://github.com/doctrine/orm/issues/6035) | Problem to update DiscriminatorColumn name | `not-applicable` | — |
| [#6031](https://github.com/doctrine/orm/issues/6031) | Doctrine refresh occurring without refresh added to cascade options | `probe-not-reproduced` | — |
| [#6006](https://github.com/doctrine/orm/issues/6006) | SchemaTool silently overrides an explicit nullable=false when using STI | `not-applicable` | — |
| [#5990](https://github.com/doctrine/orm/issues/5990) | Comparison support for many-to-many associations | `not-bug-report` | — |
| [#5983](https://github.com/doctrine/orm/issues/5983) | COUNT entities with OneToOne as identifier does not work since 2.5 | `not-applicable` | — |
| [#5978](https://github.com/doctrine/orm/issues/5978) | Schema error not caught by validate: Column & JoinColumn | `reproduced` | — |
| [#5959](https://github.com/doctrine/orm/issues/5959) | Boolean expressions (like comparison expressions, without the comparison) | `not-applicable` | — |
| [#5957](https://github.com/doctrine/orm/issues/5957) | Versioned Embeddable will not propagate version to Entity | `not-bug-report` | — |
| [#5956](https://github.com/doctrine/orm/issues/5956) | DBAL SchemaManager ignores float `precision` and `scale` | `not-bug-report` | — |
| [#5933](https://github.com/doctrine/orm/issues/5933) | Make the EntityManager resettable | `not-bug-report` | — |
| [#5923](https://github.com/doctrine/orm/issues/5923) | UnitOfWork does not distinguish between ['', ' '] and [' ', ''] when hashing identifiers | `not-applicable` | — |
| [#5922](https://github.com/doctrine/orm/issues/5922) | UnitOfWork does not distinguish between empty string or boolean false when hashing identifiers | `covered-related` | — |
| [#5908](https://github.com/doctrine/orm/issues/5908) | `INSTANCE OF` support in Criteria API Expressions | `not-applicable` | — |
| [#5905](https://github.com/doctrine/orm/issues/5905) | [Feature request][DQL] Support CoalesceExpression in ORDER BY | `not-applicable` | — |
| [#5889](https://github.com/doctrine/orm/issues/5889) | "indexBy" configuration on associations is not respected by L2C | `not-applicable` | — |
| [#5868](https://github.com/doctrine/orm/issues/5868) | Iterate with fetch join in subquery | `not-applicable` | — |
| [#5857](https://github.com/doctrine/orm/issues/5857) | InExpression will give Syntax error if using non-named empty array | `not-applicable` | — |
| [#5818](https://github.com/doctrine/orm/issues/5818) | About DQL order by (c1+c2) desc | `not-applicable` | — |
| [#5770](https://github.com/doctrine/orm/issues/5770) | findBy / matching should work with join table | `reproduced` | `fixed (b70f8f5)` |
| [#5754](https://github.com/doctrine/orm/issues/5754) | Multiple GROUP BY clauses don't work | `not-applicable` | — |
| [#5750](https://github.com/doctrine/orm/issues/5750) | Expected behvior when an entity is refreshed when its row has been deleted from DB. | `observed-contract` | — |
| [#5747](https://github.com/doctrine/orm/issues/5747) | ObjectHydrator overwriting changes to entity of "$targetClass" | `reproduced` | `fixed (46eb4b8)` |
| [#5746](https://github.com/doctrine/orm/issues/5746) | orm:validate-schema inversed-by instead of inversedBy | `not-bug-report` | — |
| [#5742](https://github.com/doctrine/orm/issues/5742) | Flush could delete entities before inserting new ones | `known-limitation` | — |
| [#5732](https://github.com/doctrine/orm/issues/5732) | problem with persisting entities when using doctrine events | `reproduced` | `fixed (46eb4b8)` |
| [#5723](https://github.com/doctrine/orm/issues/5723) | NamingStrategy::joinKeyColumnName cannot disambiguate self-reference | `not-bug-report` | — |
| [#5722](https://github.com/doctrine/orm/issues/5722) | Allow to select embeddable using NEW() | `not-applicable` | — |
| [#5694](https://github.com/doctrine/orm/issues/5694) | DQL badly translated when having Class Table Inheritance | `not-applicable` | — |
| [#5685](https://github.com/doctrine/orm/issues/5685) | oneToOne unidirectional association update fails | `known-limitation` | — |
| [#5681](https://github.com/doctrine/orm/issues/5681) | Can not define procedure | `not-bug-report` | — |
| [#5665](https://github.com/doctrine/orm/issues/5665) | Cascade deletion complicate-referenced entities does not work [ForeignKeyConstraintViolationException] | `known-limitation` | — |
| [#5644](https://github.com/doctrine/orm/issues/5644) | Matching criteria on the InverseSide of a ManyToMany relation fails | `not-applicable` | — |
| [#5640](https://github.com/doctrine/orm/issues/5640) | References with entity identifier are improperly tracked, need flattened identifier. | `not-applicable` | — |
| [#5609](https://github.com/doctrine/orm/issues/5609) | query parameters mapping : make better error message | `not-bug-report` | — |
| [#5544](https://github.com/doctrine/orm/issues/5544) | $qb->expr()->countDistinct() fails with multiple values | `not-applicable` | — |
| [#5543](https://github.com/doctrine/orm/issues/5543) | Single table inheritance and indexes | `not-applicable` | — |
| [#5542](https://github.com/doctrine/orm/issues/5542) | Allow to not Compare Object Types by reference  | `not-bug-report` | — |
| [#5536](https://github.com/doctrine/orm/issues/5536) | Allow field aliases in subselects | `not-applicable` | — |
| [#4762](https://github.com/doctrine/orm/issues/4762) | DDC-3905: Eager fetching yields N+1 queries in *ToMany associations when using DQL | `not-bug-report` | — |
| [#4693](https://github.com/doctrine/orm/issues/4693) | DDC-3843: indexBy collection loses index key after calling a ->matching(criteria) on it | `not-applicable` | — |
| [#4641](https://github.com/doctrine/orm/issues/4641) | DDC-3797: IDENTITY function does not hydrate custom types | `not-applicable` | — |
| [#4624](https://github.com/doctrine/orm/issues/4624) | DDC-3781: Fix Optimistic Locking Scenarios for Versioned Entities in Bidirectional Relationships | `not-bug-report` | — |
| [#4592](https://github.com/doctrine/orm/issues/4592) | DDC-3752: no walkers for WhenClauseExpression | `not-applicable` | — |
| [#4462](https://github.com/doctrine/orm/issues/4462) | DDC-3635: QueryBuilder - INSTANCE OF with parameter not working | `not-applicable` | — |
| [#4415](https://github.com/doctrine/orm/issues/4415) | DDC-3593: INDEX BY doesnt't work for NEW ArticleDTO  | `not-applicable` | — |
| [#4384](https://github.com/doctrine/orm/issues/4384) | DDC-3565: "Missing value for primary key" error using JoinColumns | `not-applicable` | — |
| [#4173](https://github.com/doctrine/orm/issues/4173) | DDC-3372: PersistentCollection clear function takes snapshot when the collection is cleared | `probe-not-reproduced` | — |
| [#4153](https://github.com/doctrine/orm/issues/4153) | DDC-3354: Replacing indexed item on association with indexBy cannot comply with unicity constraint | `known-limitation` | — |
| [#4106](https://github.com/doctrine/orm/issues/4106) | DDC-3311: Add ESCAPE clause support after LIKE pattern, in Expr class | `not-applicable` | — |
| [#4076](https://github.com/doctrine/orm/issues/4076) | DDC-3285: \Doctrine\ORM\Event\PreUpdateEventArgs::getOldValue and ::getNewValue return wrong values for ManyToMany association | `reproduced` | — |
| [#3927](https://github.com/doctrine/orm/issues/3927) | DDC-3148: ResultSetMappingBuilder::generateSelectClause ignores quoted column names | `reproduced` | `fixed (e98c822)` |
| [#3752](https://github.com/doctrine/orm/issues/3752) | DDC-2988: Notice: Undefined index: joinColumns in BasicEntityPersister.php | `reproduced` | `fixed (b70f8f5)` |
| [#3689](https://github.com/doctrine/orm/issues/3689) | DDC-2929: Pessimistic lock on Query does not update the entity with the DB values if it's already cached | `observed-contract` | — |
| [#3649](https://github.com/doctrine/orm/issues/3649) | DDC-2891: Impossible to pass a limit to a subquery | `not-applicable` | — |
| [#3620](https://github.com/doctrine/orm/issues/3620) | DDC-2864: New type of lock: OPTIMISTIC_FORCE_INCREMENT | `not-bug-report` | — |
| [#3519](https://github.com/doctrine/orm/issues/3519) | DDC-2772: One class can be mapped to multiple discriminator values | `not-applicable` | — |
| [#3509](https://github.com/doctrine/orm/issues/3509) | DDC-2763: Inheritance. CTI & STI. Improve lazy load associated entity, when target entity in association mapping is not last leaf in class hierarchy. | `not-applicable` | — |
| [#2633](https://github.com/doctrine/orm/issues/2633) | DDC-1960: mapping joins in native queries breaks if select columns are starting with columns from joined table | `probe-not-reproduced` | — |
| [#2272](https://github.com/doctrine/orm/issues/2272) | DDC-1630: Get PersistentCollection::getDeleteDiff is empty when collection changes from 1 item to zero items | `probe-not-reproduced` | — |
| [#5109](https://github.com/doctrine/orm/issues/5109) | DDC-601: UnitOfWork commit order problem | `known-limitation` | — |
