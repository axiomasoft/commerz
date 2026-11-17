<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Pricing\Common\Data\PricingCodes;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Rates, optionally of one zone, of one class, or only those whose window contains `$at`. */
#[Api(since: '0.1')]
final readonly class ListTaxRates
{
    /**
     * @param  string|null  $zone  zone code
     * @param  string|null  $taxClass  tax class code
     */
    public function __construct(
        public ?string $zone = null,
        public ?string $taxClass = null,
        public ?DateTimeImmutable $at = null,
    ) {
        if ($zone !== null) {
            PricingCodes::code('zone', $zone);
        }

        if ($taxClass !== null) {
            PricingCodes::code('taxClass', $taxClass);
        }
    }
}
