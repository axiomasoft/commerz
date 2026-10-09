<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Events;

use Commerz\Attributes\Api;

/** A rate of the class in the zone was set, rescheduled or removed in a committed operation. */
#[Api(since: '0.1')]
final readonly class TaxRatesChanged
{
    /**
     * @param  string  $taxClass  tax class code
     * @param  string  $zone  tax zone code
     */
    public function __construct(public string $taxClass, public string $zone) {}
}
