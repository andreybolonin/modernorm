<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use ModernORM\Exception\MappingException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Exclude implements MappingAttribute
{
    /** @param list<string> $elements */
    public function __construct(
        public string $name,
        public array $elements,
        public string $using = 'gist',
        public ?string $where = null,
    ) {
    }

    /** @internal */
    public function validate(ClassMetadata $metadata): void
    {
        $context = $metadata->name . ' EXCLUDE ' . $this->name;
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || trim($this->name) === ''
            || strlen($this->name) > 63 || strpbrk($this->name, ".\0") !== false) {
            throw new MappingException($context . ' requires a concrete entity and a valid constraint name.');
        }
        if (strtolower($this->using) !== 'gist' || $this->elements === [] || ! array_is_list($this->elements)) {
            throw new MappingException($context . ' requires GiST and a nonempty list of elements.');
        }
        foreach ([...$this->elements, ...($this->where === null ? [] : [$this->where])] as $fragment) {
            if (! is_string($fragment) || trim($fragment) === '' || str_contains($fragment, "\0")) {
                throw new MappingException($context . ' contains an invalid SQL fragment.');
            }
        }
    }
}
