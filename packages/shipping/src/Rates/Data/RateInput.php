<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Data;

use Commerz\Money\Currency;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Packman\Attributes\Api;

/**
 * What a rate prices. `$request->parcel->value` is the value of the goods after discounts; in a quote the stage
 * recomputes it. `$currency` is the currency of the tariff and `$zoneCode` the zone the address was matched to.
 */
#[Api(since: '0.1')]
final readonly class RateInput
{
    public function __construct(
        public DeliveryRequest $request,
        public RateParams $params,
        public Currency $currency,
        public string $zoneCode,
    ) {}
}
