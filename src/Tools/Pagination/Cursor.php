<?php

/*
 * Adapted from Doctrine ORM. Copyright (c) Doctrine Project.
 * MIT license: licenses/Doctrine-ORM-MIT.txt.
 */

declare(strict_types=1);

namespace ModernORM\Tools\Pagination;

use ModernORM\Tools\Pagination\Exception\InvalidCursor;

/** A position and navigation direction; the page size and SQL structure stay on the server. */
final class Cursor
{
    /** @param array<string, scalar> $parameters Database values, not values read from managed entities. */
    public function __construct(private readonly array $parameters, private readonly bool $isNext = true)
    {
        if ($parameters === []) {
            throw new InvalidCursor('A cursor requires at least one sorting value.');
        }
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! is_scalar($value) || (is_float($value) && ! is_finite($value))) {
                throw new InvalidCursor('Cursor values must be named, non-null, finite scalars.');
            }
        }
    }

    /** @return array<string, scalar> */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function isNext(): bool
    {
        return $this->isNext;
    }

    public function isPrevious(): bool
    {
        return ! $this->isNext;
    }

    public function toArray(): array
    {
        return ['v' => 1, 'next' => $this->isNext, 'values' => $this->parameters];
    }

    public function encodeToString(): string
    {
        return rtrim(strtr(base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), '+/', '-_'), '=');
    }

    public static function fromEncodedString(mixed $encodedString): self
    {
        if (! is_string($encodedString) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $encodedString)) {
            throw new InvalidCursor('Invalid URL-safe cursor encoding.');
        }
        $decoded = base64_decode(strtr($encodedString, '-_', '+/'), strict: true);
        if ($decoded === false) {
            throw new InvalidCursor('Invalid cursor encoding.');
        }
        try {
            $payload = json_decode($decoded, associative: true, depth: 4, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            throw new InvalidCursor('Invalid cursor JSON.', previous: $error);
        }
        if (! is_array($payload) || count($payload) !== 3 || ($payload['v'] ?? null) !== 1
            || ! isset($payload['next'], $payload['values']) || ! is_bool($payload['next']) || ! is_array($payload['values'])) {
            throw new InvalidCursor('Invalid cursor payload or unsupported version.');
        }
        return new self($payload['values'], $payload['next']);
    }
}
