<?php

declare(strict_types=1);

namespace Commerz\Identity;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Stringable;

/**
 * A pointer to something the package does not own, written `type:id` (fits `varchar(191)`).
 * `type` is [a-z0-9_.-]{1,64}; `id` is 1..120 bytes of printable text.
 */
#[Api(since: '0.1')]
abstract readonly class Ref implements Stringable
{
    final public function __construct(public string $type, public string $id)
    {
        if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $type) !== 1) {
            throw new InvalidCommand('type', "'{$type}' must match [a-z0-9_.-]{1,64}", 'identity.invalid_ref');
        }

        if (strlen($id) < 1 || strlen($id) > 120 || preg_match('/\A[^\p{Cc}]+\z/u', $id) !== 1) {
            throw new InvalidCommand('id', 'must be 1..120 bytes of valid printable UTF-8', 'identity.invalid_ref');
        }
    }

    final public static function of(string $type, string $id): static
    {
        return new static($type, $id);
    }

    final public static function fromString(string $ref): static
    {
        $separator = strpos($ref, ':');

        if ($separator === false) {
            throw new InvalidCommand('ref', "'{$ref}' must look like type:id", 'identity.invalid_ref');
        }

        return new static(substr($ref, 0, $separator), substr($ref, $separator + 1));
    }

    final public function equals(self $other): bool
    {
        return static::class === $other::class && $this->type === $other->type && $this->id === $other->id;
    }

    final public function __toString(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
