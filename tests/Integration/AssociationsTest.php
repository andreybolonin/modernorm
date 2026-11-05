<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use ModernORM\Mapping as ORM;
use ModernORM\EntityManager;
use ModernORM\Mapping as Mapping;
use ModernORM\ORMSetup;
use ModernORM\PersistentCollection;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
#[ORM\Table(name: 'modernorm_test_teams')]
final class Team
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'team';

    /** @var Collection<int, Member> */
    #[ORM\OneToMany(mappedBy: 'team', targetEntity: Member::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    public Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }

    public function add(Member $member): void
    {
        $this->members->add($member);
        $member->team = $this;
    }
}

#[Mapping\Entity]
#[Mapping\Table(name: 'modernorm_test_members')]
final class Member
{
    #[Mapping\Id, Mapping\Column, Mapping\GeneratedValue]
    public ?int $id = null;

    #[Mapping\Column]
    public string $name = 'member';

    #[Mapping\ManyToOne(inversedBy: 'members')]
    #[Mapping\JoinColumn(nullable: false)]
    public Team $team;

    #[ORM\OneToOne(targetEntity: Profile::class, mappedBy: 'member', cascade: ['persist'])]
    public ?Profile $profile = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'members', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'modernorm_test_member_tags')]
    public Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'modernorm_test_tags')]
final class Tag
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $label = 'tag';

    /** @var Collection<int, Member> */
    #[ORM\ManyToMany(targetEntity: Member::class, mappedBy: 'tags')]
    public Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'modernorm_test_profiles')]
final class Profile
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $bio = 'profile';

    #[ORM\OneToOne(targetEntity: Member::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(nullable: false)]
    public Member $member;
}

final class QueryRecorder extends AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class AssociationsTest extends TestCase
{
    private EntityManager $em;
    private QueryRecorder $queries;

    protected function setUp(): void
    {
        $this->queries = new QueryRecorder();
        $config = new DBALConfiguration();
        $config->setMiddlewares([new Middleware($this->queries)]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $config);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig(
            [__DIR__],
            true,
            cache: new ArrayAdapter(),
        ));
        $metadata = array_map($this->em->getClassMetadata(...), [Team::class, Member::class, Tag::class, Profile::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_profiles, modernorm_test_member_tags, modernorm_test_members, modernorm_test_tags, modernorm_test_teams CASCADE');
        $this->em->getConnection()->close();
    }

    /** @return array{int, int, int} */
    private function seed(): array
    {
        $team = new Team();
        $first = new Member();
        $second = new Member();
        $team->add($first);
        $team->add($second);
        $this->em->persist($team);
        $this->em->flush();
        $ids = [$team->id, $first->id, $second->id];
        $this->em->clear();
        $this->queries->sql = [];
        return $ids;
    }

    public function testToOneUsesNativeLazyObjectAndIdentityMap(): void
    {
        [$teamId, $memberId] = $this->seed();
        $member = $this->em->find(Member::class, $memberId);
        self::assertCount(1, $this->queries->sql);
        $reflection = new \ReflectionClass(Team::class);
        self::assertTrue($reflection->isUninitializedLazyObject($member->team));
        self::assertSame($teamId, $member->team->id);
        self::assertCount(1, $this->queries->sql);
        self::assertSame('team', $member->team->name);
        self::assertCount(2, $this->queries->sql);
        self::assertFalse($reflection->isUninitializedLazyObject($member->team));
        self::assertSame($member->team, $this->em->find(Team::class, $teamId));
        self::assertSame('team', $member->team->name);
        self::assertCount(2, $this->queries->sql);
    }

    public function testExtraLazyCountAndCollectionInitialization(): void
    {
        [$teamId] = $this->seed();
        $team = $this->em->find(Team::class, $teamId);
        self::assertInstanceOf(PersistentCollection::class, $team->members);
        self::assertFalse($team->members->isInitialized());
        self::assertCount(1, $this->queries->sql);
        self::assertCount(2, $team->members);
        self::assertCount(2, $this->queries->sql);
        self::assertFalse($team->members->isInitialized());
        $members = $team->members->toArray();
        self::assertCount(2, $members);
        self::assertCount(3, $this->queries->sql);
        self::assertTrue($team->members->isInitialized());
        foreach ($members as $member) {
            self::assertSame($team, $member->team);
            self::assertSame($member, $this->em->find(Member::class, $member->id));
        }
        self::assertCount(3, $this->queries->sql);
    }

    public function testOrphanRemovalAndCascadeRemove(): void
    {
        [$teamId, $firstId, $secondId] = $this->seed();
        $team = $this->em->find(Team::class, $teamId);
        $first = $this->em->find(Member::class, $firstId);
        $team->members->removeElement($first);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(Member::class, $firstId));
        self::assertNotNull($this->em->find(Member::class, $secondId));
        $this->em->remove($this->em->find(Team::class, $teamId));
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(Team::class, $teamId));
        self::assertNull($this->em->find(Member::class, $secondId));
    }

    public function testManyToManyOwningSideWritesAndInverseSideLoads(): void
    {
        [, $memberId] = $this->seed();
        $member = $this->em->find(Member::class, $memberId);
        $tag = new Tag();
        $member->tags->add($tag);
        $this->em->flush();
        $tagId = $tag->id;
        $this->em->clear();
        $tag = $this->em->find(Tag::class, $tagId);
        self::assertFalse($tag->members->isInitialized());
        self::assertSame($memberId, $tag->members->first()->id);
        $member = $this->em->find(Member::class, $memberId);
        self::assertSame($tag, $member->tags->first());
        $member->tags->removeElement($tag);
        $this->em->flush();
        $this->em->clear();
        self::assertCount(0, $this->em->find(Tag::class, $tagId)->members);
        self::assertNotNull($this->em->find(Tag::class, $tagId));
    }
    public function testInverseSideAloneDoesNotWriteManyToMany(): void
    {
        [, $memberId] = $this->seed();
        $tag = new Tag();
        $tag->members->add($this->em->find(Member::class, $memberId));
        $this->em->persist($tag);
        $this->em->flush();
        $id = $tag->id;
        $this->em->clear();
        self::assertCount(0, $this->em->find(Tag::class, $id)->members);
    }

    public function testOneToOneOwningReferenceIsLazyAndInverseReferenceIsIdentical(): void
    {
        [, $memberId] = $this->seed();
        $member = $this->em->find(Member::class, $memberId);
        $profile = new Profile();
        $profile->member = $member;
        $member->profile = $profile;
        $this->em->flush();
        $profileId = $profile->id;
        $this->em->clear();
        $profile = $this->em->find(Profile::class, $profileId);
        self::assertTrue((new \ReflectionClass(Member::class))->isUninitializedLazyObject($profile->member));
        self::assertSame($memberId, $profile->member->id);
        self::assertSame($profile, $profile->member->profile);
        self::assertSame($profile->member, $this->em->find(Member::class, $memberId));
        $this->em->clear();
        // Inverse one-to-one needs a query to determine whether the row exists.
        $member = $this->em->find(Member::class, $memberId);
        self::assertNotNull($member->profile);
        self::assertSame($profileId, $member->profile->id);
        self::assertSame($member, $member->profile->member);
    }

    public function testExplicitInitializationOfReferenceAndCollection(): void
    {
        [$teamId] = $this->seed();
        $team = $this->em->getReference(Team::class, $teamId);
        self::assertTrue($this->em->isUninitializedObject($team));
        self::assertCount(0, $this->queries->sql);
        $this->em->initializeObject($team);
        self::assertFalse($this->em->isUninitializedObject($team));
        self::assertSame('team', $team->name);
        self::assertCount(1, $this->queries->sql);
        $this->em->initializeObject($team->members);
        self::assertTrue($team->members->isInitialized());
        self::assertCount(2, $team->members);
        self::assertCount(2, $this->queries->sql);
        $this->em->initializeObject($team);
        self::assertCount(2, $this->queries->sql);
    }

    public function testMissingReferenceThrowsWhenInitialized(): void
    {
        $reference = $this->em->getReference(Team::class, 2147483647);
        self::assertTrue($this->em->isUninitializedObject($reference));
        $this->expectException(\ModernORM\Exception\EntityNotFoundException::class);
        $this->em->initializeObject($reference);
    }

}
