<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Zones\Data\ZoneId;
use Packman\Attributes\Api;

/**
 * A stored tariff. `$rate` carries the canonical parameters the rule prices with; when the rule cannot be loaded
 * (`$unsupported` holds the reason) they are the parameters as stored, and the tariff is not offered.
 */
#[Api(since: '0.1')]
final readonly class RateView
{
    public function __construct(
        public ZoneId $zone,
        public string $zoneCode,
        public Currency $currency,
        public RateSpec $rate,
        public ?Money $freeAbove,
        public bool $taxIncluded,
        public ?int $etaDays,
        public ?string $unsupported,
    ) {}
}
