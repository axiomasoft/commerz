<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Money;

/** What orders placed with a promotion have used so far (orders not restored), counted from the usage log. */
#[Api(since: '0.1')]
final readonly class PromotionUsageSummary
{
    /** @param  list<Money>  $discounts  one total per currency, sorted by currency */
    public function __construct(public int $orders, public array $discounts) {}
}
