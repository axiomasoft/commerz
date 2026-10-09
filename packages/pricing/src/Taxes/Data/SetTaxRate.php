<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\Common\Internal\Windows;
use DateTimeImmutable;

/**
 * The rate of one tax of a class in a zone, valid in `[validFrom, validUntil)`. The same class, zone, tax code and
 * window update the rate; another window of the same key must not overlap it (TAX-01).
 */
#[Api(since: '0.1')]
final readonly class SetTaxRate
{
    /**
     * @param  string  $taxClass  tax class code
     * @param  string  $zone  tax zone code
     * @param  int  $rateBp  basis points: 2200 = 22%; 0 is a rate of its own, not a missing one
     * @param  string  $code  tax identifier, e.g. `vat`; several codes of one class and zone are separate taxes on the same base
     */
    public function __construct(
        public string $taxClass,
        public string $zone,
        public int $rateBp,
        public string $code = 'vat',
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {
        PricingCodes::code('taxClass', $taxClass);
        PricingCodes::code('zone', $zone);
        PricingCodes::taxCode('code', $code);

        if ($rateBp < 0 || $rateBp > 10_000) {
            throw new InvalidCommand('rateBp', 'must be from 0 to 10000', 'pricing.invalid_rate');
        }

        Windows::assertOrdered($validFrom, $validUntil);
    }
}
