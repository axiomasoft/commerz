<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;
use Stringable;

/** A caller-chosen key that makes a mutation safe to retry. 1..191 bytes of printable text. */
#[Api(since: '0.1')]
final readonly class IdempotencyKey implements Stringable
{
    public function __construct(public string $value)
    {
        if (strlen($value) < 1 || strlen($value) > 191 || preg_match('/\A[^\p{Cc}]+\z/u', $value) !== 1) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 bytes of valid printable UTF-8', 'identity.invalid_idempotency_key');
        }
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
