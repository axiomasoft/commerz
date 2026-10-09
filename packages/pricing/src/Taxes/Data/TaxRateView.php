<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Rate;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class TaxRateView
{
    /**
     * @param  string  $taxClass  tax class code
     * @param  string  $zone  tax zone code
     * @param  string  $code  tax identifier, e.g. `vat`
     */
    public function __construct(
        public TaxRateId $id,
        public string $taxClass,
        public string $zone,
        public string $code,
        public Rate $rate,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
    ) {}
}
