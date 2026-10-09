<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;

/** What a rate is validated against: the currency of its tariff row. */
#[Api(since: '0.1')]
final readonly class RateScope
{
    public function __construct(public Currency $currency) {}
}
