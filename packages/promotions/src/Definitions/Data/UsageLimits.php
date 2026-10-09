<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** How many orders may use a promotion in all and per customer; null is no limit. Counted when an order is placed. */
#[Api(since: '0.1')]
final readonly class UsageLimits
{
    private const int MAX = 2_147_483_647;

    public function __construct(public ?int $total = null, public ?int $perCustomer = null)
    {
        self::assertLimit('total', $total);
        self::assertLimit('perCustomer', $perCustomer);
    }

    private static function assertLimit(string $field, ?int $value): void
    {
        if ($value !== null && ($value < 1 || $value > self::MAX)) {
            throw new InvalidCommand("limits.{$field}", 'must be from 1 to 2147483647', 'promotions.invalid_limits');
        }
    }
}
