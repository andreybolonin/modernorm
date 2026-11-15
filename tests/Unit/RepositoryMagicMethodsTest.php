<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use BadMethodCallException;
use ModernORM\EntityManagerInterface;
use ModernORM\EntityRepository;
use ModernORM\Exception\InvalidMagicMethodCall;
use ModernORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MagicRepositoryRecord
{
    public int $id = 1;
    public string $firstName = 'first';
    public string $iD = 'identifier';
    public string $uRL = 'url';
    public string $eMail = 'email';
    public string $mixedCaseID = 'case';
    public ?self $accountOwner = null;
}

final class RepositoryMagicMethodsTest extends TestCase
{
    private function metadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(MagicRepositoryRecord::class);
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        foreach (['firstName', 'iD', 'uRL', 'eMail', 'mixedCaseID'] as $field) {
            $metadata->mapField(['fieldName' => $field, 'type' => 'string']);
        }
        $metadata->mapManyToOne(['fieldName' => 'accountOwner', 'targetEntity' => MagicRepositoryRecord::class]);
        return $metadata;
    }

    public static function methodNames(): iterable
    {
        foreach (['FirstName' => 'firstName', 'firstName' => 'firstName', 'first_name' => 'firstName', 'first-name' => 'firstName', 'first name' => 'firstName', ' _first__name- ' => 'firstName', 'ID' => 'iD', 'URL' => 'uRL', 'e_mail' => 'eMail', 'MixedCaseID' => 'mixedCaseID', 'account_owner' => 'accountOwner'] as $suffix => $field) {
            foreach (['findBy', 'findOneBy', 'countBy'] as $prefix) {
                yield $prefix . $suffix => [$prefix, $suffix, $field];
            }
        }
    }

    #[DataProvider('methodNames')]
    public function testMagicQueriesPreserveNamesAndForwardAllArguments(string $prefix, string $suffix, string $field): void
    {
        $repository = $this->getMockBuilder(EntityRepository::class)
            ->setConstructorArgs([$this->createStub(EntityManagerInterface::class), $this->metadata()])
            ->onlyMethods(['findBy', 'findOneBy', 'count'])->getMock();
        $entity = new MagicRepositoryRecord();
        $value = $field === 'accountOwner' ? $entity : 'value';
        [$method, $extra, $result] = match ($prefix) {
            'findBy' => ['findBy', [['id' => 'DESC'], 3, 1], [$entity]],
            'findOneBy' => ['findOneBy', [['id' => 'DESC']], $entity],
            'countBy' => ['count', [], 7],
            default => throw new \LogicException('Unexpected provider prefix.'),
        };
        $repository->expects(self::once())->method($method)->with([$field => $value], ...$extra)->willReturn($result);
        self::assertSame($result, $repository->__call($prefix . $suffix, [$value, ...$extra]));
    }

    public static function invalidMethods(): iterable
    {
        yield 'missing parameter' => ['findByFirstName', [], InvalidMagicMethodCall::class];
        yield 'unknown field' => ['findOneByMissingField', ['value'], InvalidMagicMethodCall::class];
        yield 'empty field' => ['countBy', ['value'], InvalidMagicMethodCall::class];
        yield 'unsupported operation' => ['deleteByFirstName', ['value'], BadMethodCallException::class];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('invalidMethods')]
    public function testInvalidMagicCallsRetainTheirExceptions(string $method, array $arguments, string $exception): void
    {
        $repository = new EntityRepository($this->createStub(EntityManagerInterface::class), $this->metadata());
        $this->expectException($exception);
        $repository->__call($method, $arguments);
    }
}
