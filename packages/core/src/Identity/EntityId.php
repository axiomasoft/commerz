<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Stringable;

/** Base of typed entity ids (`ProductId`, `OrderId`…): a canonical lowercase UUID, generated as UUIDv7. */
#[Api(since: '0.1')]
abstract readonly class EntityId implements Stringable
{
    public string $value;

    final public function __construct(string $value)
    {
        $value = strtolower($value);

        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidCommand('id', "'{$value}' is not a UUID", 'identity.invalid_id');
        }

        $this->value = $value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public static function generate(): static
    {
        return new static(Uuid7::generate());
    }

    public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
