<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TypeRegistry;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: '`QueryOwners`')]
final class QueryTypeOwner
{
    #[ORM\Id, ORM\Column(name: '`OwnerKey`', type: 'string')]
    public string $id;
    /** @var Collection<int, QueryTypeTag> */
    #[ORM\ManyToMany(targetEntity: QueryTypeTag::class, inversedBy: 'owners')]
    #[ORM\JoinTable(name: '`QueryLinks`', joinColumns: [new ORM\JoinColumn(name: '`OwnerRef`', referencedColumnName: '`OwnerKey`')], inverseJoinColumns: [new ORM\JoinColumn(name: '`TagRef`', referencedColumnName: '`TagKey`')])]
    public Collection $tags;
    /** @var Collection<int, QueryTypeTag> */
    #[ORM\ManyToMany(targetEntity: QueryTypeTag::class)]
    #[ORM\JoinTable(name: 'query_starred', joinColumns: [new ORM\JoinColumn(name: 'owner_ref', referencedColumnName: '`OwnerKey`')], inverseJoinColumns: [new ORM\JoinColumn(name: 'tag_ref', referencedColumnName: '`TagKey`')])]
    public Collection $starred;
    /** @var Collection<int, QueryTypeOwner> */
    #[ORM\ManyToMany(targetEntity: QueryTypeOwner::class, inversedBy: 'followers')]
    #[ORM\JoinTable(name: 'query_friends', joinColumns: [new ORM\JoinColumn(name: 'from_key', referencedColumnName: '`OwnerKey`')], inverseJoinColumns: [new ORM\JoinColumn(name: 'to_key', referencedColumnName: '`OwnerKey`')])]
    public Collection $friends;
    /** @var Collection<int, QueryTypeOwner> */
    #[ORM\ManyToMany(targetEntity: QueryTypeOwner::class, mappedBy: 'friends')]
    public Collection $followers;
    public function __construct(string $id)
    {
        $this->id = $id;
        $this->tags = new ArrayCollection();
        $this->starred = new ArrayCollection();
        $this->friends = new ArrayCollection();
        $this->followers = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'query_tags')]
final class QueryTypeTag
{
    #[ORM\Id, ORM\Column(name: '`TagKey`', type: 'integer')]
    public int $id;
    /** @var Collection<int, QueryTypeOwner> */
    #[ORM\ManyToMany(targetEntity: QueryTypeOwner::class, mappedBy: 'tags')]
    public Collection $owners;
    public function __construct(int $id)
    {
        $this->id = $id;
        $this->owners = new ArrayCollection();
    }
}

enum QueryState: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final class QueryStateType extends StringType
{
    public function __construct(private readonly string $prefix)
    {
    }
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof QueryState) {
            throw new \InvalidArgumentException('QueryStateType requires the enum domain value.');
        }
        return $this->prefix . $value->value;
    }
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?QueryState
    {
        return $value === null ? null : QueryState::from(substr($value, strlen($this->prefix)));
    }
}

final class QueryLowerType extends StringType
{
    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return 'LOWER(' . $sqlExpr . ')';
    }
}

final class QueryAllQuoteStrategy extends ORM\DefaultQuoteStrategy
{
    public function getColumnName(string $fieldName, ORM\ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $platform->quoteSingleIdentifier($class->getColumnName($fieldName));
    }
}

#[ORM\Entity, ORM\Table(name: 'query_values')]
final class QueryTypeValue
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(name: '`MixedToken`', type: 'query_lower')]
    public string $token = 'UPPER';
    #[ORM\Column(name: 'plain_token', type: 'query_lower')]
    public string $second = 'SECOND';
    #[ORM\Column(type: 'query_state', nullable: true)]
    public ?QueryState $state = QueryState::Active;
    #[ORM\Column(type: 'string', enumType: QueryState::class)]
    public QueryState $native = QueryState::Active;
    #[ORM\Column(type: 'string')]
    public string $plain = 'active';
}

final class QueryTypeRegressionTest extends TestCase
{
    private EntityManager $em;
    private string $schema;
    protected function setUp(): void
    {
        $this->schema = 'query_type_' . bin2hex(random_bytes(5));
        $db = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $db->getConfiguration()->setTypeProvider(new TypeRegistry(['query_lower' => new QueryLowerType(), 'query_state' => new QueryStateType('private:')]));
        $this->em = new EntityManager($db, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
    }
    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $db = $this->em->getConnection();
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $db->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $db->close();
        }
    }
    private function create(array $classes): void
    {
        $metadata = array_map($this->em->getClassMetadata(...), $classes);
        foreach ($metadata as $class) {
            $class->table['schema'] = $this->schema;
            foreach ($class->associationMappings as $association) {
                if ($association->isManyToManyOwningSide()) {
                    $association->joinTable->schema = $this->schema;
                }
            }
        }
        (new SchemaTool($this->em))->createSchema($metadata);
    }
    private function seedMembership(): void
    {
        $this->create([QueryTypeOwner::class, QueryTypeTag::class]);
        [$a, $b, $empty] = [new QueryTypeOwner('a'), new QueryTypeOwner('b'), new QueryTypeOwner('empty')];
        [$one, $two] = [new QueryTypeTag(1), new QueryTypeTag(2)];
        $a->tags->add($one);
        $a->tags->add($two);
        $b->tags->add($two);
        $a->starred->add($two);
        $b->starred->add($one);
        $a->friends->add($b);
        $a->friends->add($empty);
        $b->friends->add($empty);
        foreach ([$a, $b, $empty, $one, $two] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();
    }
    private function ids(array $entities): array
    {
        return array_map(static fn ($entity) => $entity->id, $entities);
    }
    public function testOwningManyToManyCriteriaUseMembershipWithoutDuplicateRoots(): void
    {
        $this->seedMembership();
        $repository = $this->em->getRepository(QueryTypeOwner::class);
        self::assertSame(['a'], $this->ids($repository->findBy(['tags' => 1])));
        $tag = $this->em->getReference(QueryTypeTag::class, 2);
        self::assertSame(['a', 'b'], $this->ids($repository->findBy(['tags' => $tag], ['id' => 'ASC'])));
        self::assertSame(['a', 'b'], $this->ids($repository->findBy(['tags' => [1, 2]], ['id' => 'ASC'])));
        self::assertSame(2, $repository->count(['tags' => [1, 2]]));
        self::assertSame('b', $repository->findOneBy(['tags' => 2], ['id' => 'DESC'])->id);
        self::assertSame(['b'], $this->ids($repository->findBy(['tags' => [1, 2]], ['id' => 'ASC'], 1, 1)));
        self::assertSame(['a'], $this->ids($repository->findBy(['tags' => 1, 'starred' => 2])));
        self::assertSame(['b'], $this->ids($repository->findBy(['tags' => 2, 'starred' => 1])));
    }
    public function testInverseAndSelfReferencingMembershipUseCorrectIdentifierTypes(): void
    {
        $this->seedMembership();
        $tags = $this->em->getRepository(QueryTypeTag::class);
        self::assertSame([1, 2], $this->ids($tags->findBy(['owners' => 'a'], ['id' => 'ASC'])));
        self::assertSame([1, 2], $this->ids($tags->findBy(['owners' => ['a', 'b']], ['id' => 'ASC'])));
        self::assertSame(2, $tags->count(['owners' => $this->em->getReference(QueryTypeOwner::class, 'a')]));
        $owners = $this->em->getRepository(QueryTypeOwner::class);
        self::assertSame(['a', 'b'], $this->ids($owners->findBy(['friends' => 'empty'], ['id' => 'ASC'])));
        self::assertSame(['b', 'empty'], $this->ids($owners->findBy(['followers' => 'a'], ['id' => 'ASC'])));
    }
    public function testEmptyAndNullManyToManyCriteria(): void
    {
        $this->seedMembership();
        $repository = $this->em->getRepository(QueryTypeOwner::class);
        self::assertSame([], $repository->findBy(['tags' => []]));
        self::assertSame(['empty'], $this->ids($repository->findBy(['tags' => null])));
        self::assertSame(['empty'], $this->ids($repository->findBy(['tags' => [null]])));
        self::assertSame(['a', 'empty'], $this->ids($repository->findBy(['tags' => [null, 1]], ['id' => 'ASC'])));
        self::assertSame(2, $repository->count(['tags' => [null, 1]]));
        self::assertSame([], $repository->findBy(['tags' => 999]));
    }
    public static function quoteStrategies(): iterable
    {
        yield 'mapped quotes' => [false];
        yield 'custom quotes' => [true];
    }
    #[DataProvider('quoteStrategies')]
    public function testInsertAppliesMappedSqlConversionWithQuotedColumns(bool $custom): void
    {
        if ($custom) {
            $this->em->getConfiguration()->setQuoteStrategy(new QueryAllQuoteStrategy());
        }
        $this->create([QueryTypeValue::class]);
        $row = new QueryTypeValue();
        $row->id = 1;
        $this->em->persist($row);
        $this->em->flush();
        self::assertSame(['token' => 'upper', 'second' => 'second'], $this->em->getConnection()->fetchAssociative('SELECT "MixedToken" AS token, plain_token AS second FROM ' . $this->schema . '.query_values'));
        $this->em->clear();
        $row = $this->em->getRepository(QueryTypeValue::class)->findOneBy(['token' => 'UPPER']);
        self::assertNotNull($row);
        self::assertSame('upper', $row->token);
        $row->token = 'CHANGED';
        $this->em->flush();
        self::assertSame('changed', $this->em->getConnection()->fetchOne('SELECT "MixedToken" FROM ' . $this->schema . '.query_values'));
    }
    public function testCustomEnumCriteriaPreserveDomainValuesAndNativeEnumCoercion(): void
    {
        $this->create([QueryTypeValue::class]);
        foreach ([1 => QueryState::Active, 2 => QueryState::Inactive, 3 => null] as $id => $state) {
            $row = new QueryTypeValue();
            $row->id = $id;
            $row->state = $state;
            $this->em->persist($row);
        }
        $this->em->flush();
        $this->em->clear();
        $repository = $this->em->getRepository(QueryTypeValue::class);
        self::assertSame([1], $this->ids($repository->findBy(['state' => QueryState::Active])));
        self::assertSame(2, $repository->count(['state' => [QueryState::Active, QueryState::Inactive]]));
        self::assertSame([1, 3], $this->ids($repository->findBy(['state' => [QueryState::Active, null]], ['id' => 'ASC'])));
        self::assertSame(QueryState::Inactive, $repository->findOneBy(['state' => QueryState::Inactive])->state);
        self::assertSame(3, $repository->count(['native' => QueryState::Active]));
        self::assertSame(3, $repository->count(['plain' => QueryState::Active]));
        self::assertSame(0, $repository->count(['token' => QueryState::Active]));
    }
}
