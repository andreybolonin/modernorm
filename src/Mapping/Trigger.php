<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use ModernORM\Exception\MappingException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Trigger implements MappingAttribute
{
    /** @param list<string> $events */
    public function __construct(
        public string $name,
        public string $timing,
        public array $events,
        public ?string $function = null,
        public ?string $functionSql = null,
    ) {
    }

    /** @internal */
    public function validate(ClassMetadata $metadata): void
    {
        $context = $metadata->name . ' trigger ' . $this->name;
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
            throw new MappingException($context . ' requires a concrete Entity.');
        }
        if (trim($this->name) === '' || strlen($this->name) > 63 || strpbrk($this->name, ".\0") !== false
            || preg_match('//u', $this->name) !== 1) {
            throw new MappingException($context . ' requires an unqualified UTF-8 name of at most 63 bytes.');
        }
        if (! in_array(strtoupper($this->timing), ['BEFORE', 'AFTER'], true)) {
            throw new MappingException($context . ' requires BEFORE or AFTER timing.');
        }
        $this->eventNames($context);
        $this->functionName($context);
        if ($this->functionSql !== null && (trim($this->functionSql) === '' || str_contains($this->functionSql, "\0"))) {
            throw new MappingException($context . ' requires a nonempty SQL file path without NUL bytes.');
        }
    }

    /**
     * @internal
     * @return list<string>
     */
    public function eventNames(string $context): array
    {
        $events = [];
        if (array_is_list($this->events)) {
            foreach ($this->events as $event) {
                $normalized = is_string($event) ? strtoupper($event) : '';
                if (! in_array($normalized, ['INSERT', 'UPDATE', 'DELETE'], true) || isset($events[$normalized])) {
                    throw new MappingException($context . ' requires unique INSERT, UPDATE or DELETE events.');
                }
                $events[$normalized] = true;
            }
        }
        if ($events === []) {
            throw new MappingException($context . ' requires at least one event.');
        }

        return array_values(array_intersect(['INSERT', 'UPDATE', 'DELETE'], array_keys($events)));
    }

    /** @internal */
    public function functionName(string $context): string
    {
        $name = $this->function;
        if ($name === null) {
            if (! str_starts_with($this->name, 'trg_') || strlen($this->name) === 4) {
                throw new MappingException($context . ' requires a trg_ prefix or an explicit function.');
            }
            $name = 'fn_' . substr($this->name, 4);
        }

        $parts = explode('.', $name);
        if (count($parts) > 2) {
            throw new MappingException($context . ' requires a function name or schema.function.');
        }
        foreach ($parts as $part) {
            if (strlen($part) > 63 || preg_match('/^[\p{L}_][\p{L}\p{N}_$]*$/uD', $part) !== 1) {
                throw new MappingException($context . ' requires valid function identifier components of at most 63 bytes.');
            }
        }

        return $name;
    }
}
