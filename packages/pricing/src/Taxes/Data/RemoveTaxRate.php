<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Data;

use Commerz\Attributes\Api;

/** Deletes a rate; removing one that is gone changes nothing. */
#[Api(since: '0.1')]
final readonly class RemoveTaxRate
{
    public function __construct(public TaxRateId $rate) {}
}
