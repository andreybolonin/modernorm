<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use ModernORM\Exception\MappingException;

/** A PostgreSQL table CHECK constraint with a developer-authored SQL predicate. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Check implements MappingAttribute
{
    public function __construct(public string $name, public string $expression)
    {
    }

    /** @internal */
    public function validate(ClassMetadata $metadata): void
    {
        $context = $metadata->name . ' CHECK ' . $this->name;
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
            throw new MappingException($context . ' requires a concrete Entity.');
        }
        if (trim($this->name) === '' || strlen($this->name) > 63 || strpbrk($this->name, ".\0") !== false
            || preg_match('//u', $this->name) !== 1) {
            throw new MappingException($metadata->name . ' CHECK requires an unqualified UTF-8 identifier of at most 63 bytes.');
        }
        if (trim($this->expression) === '' || str_contains($this->expression, "\0")) {
            throw new MappingException($context . ' requires a nonempty SQL expression without NUL bytes.');
        }
    }
}
