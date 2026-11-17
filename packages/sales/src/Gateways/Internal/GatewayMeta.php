<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Internal;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Internal;

/** Shape checks shared by the gateway DTOs. */
#[Internal]
final class GatewayMeta
{
    /** @param  array<array-key, mixed>  $meta */
    public static function assert(array $meta, string $field, string $code): void
    {
        foreach ($meta as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_scalar($value)) {
                throw new InvalidCommand($field, 'must map non-empty string keys to scalar values', $code);
            }
        }
    }

    public static function assertReference(string $reference, string $field, string $code): void
    {
        if ($reference === '' || strlen($reference) > 191) {
            throw new InvalidCommand($field, 'must be 1..191 bytes', $code);
        }
    }
}
