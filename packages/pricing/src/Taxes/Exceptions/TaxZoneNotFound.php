<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class TaxZoneNotFound extends NotFound
{
    public function __construct(string $code)
    {
        parent::__construct('Tax zone', $code, 'pricing.tax_zone_not_found');
    }
}
