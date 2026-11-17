<?php

declare(strict_types=1);

namespace Commerz\Measures;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** @internal Shared unit-code validation for Quantity and Measure. */
#[Api(since: '0.1')]
final class Unit
{
    public static function assert(string $unit): void
    {
        if (preg_match('/\A[a-z0-9_.\-]{1,16}\z/', $unit) !== 1) {
            throw new InvalidCommand('unit', "'{$unit}' must match [a-z0-9_.-]{1,16}", 'measures.invalid_unit');
        }
    }
}
