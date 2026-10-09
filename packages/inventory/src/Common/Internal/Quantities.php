<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

/** Overflow-safe int64 arithmetic on non-negative quantities: a result beyond PHP_INT_MAX is null, never a float. */
final class Quantities
{
    public static function mul(int $a, int $b): ?int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $a > intdiv(PHP_INT_MAX, $b) ? null : $a * $b;
    }

    public static function add(int $a, int $b): ?int
    {
        return $b > PHP_INT_MAX - $a ? null : $a + $b;
    }
}
