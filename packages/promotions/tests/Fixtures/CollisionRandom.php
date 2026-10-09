<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Internal;

/** Loaded only in forked test workers: force cross-round collisions without changing the production generator. */
function random_int(int $min, int $max): int
{
    $indexes = $GLOBALS['coupon_collision_indexes'] ?? [];

    if (! is_array($indexes) || $indexes === []) {
        return \random_int($min, $max);
    }

    $index = array_shift($indexes);
    $GLOBALS['coupon_collision_indexes'] = $indexes;
    assert(is_int($index) && $index >= $min && $index <= $max);

    return $index;
}
