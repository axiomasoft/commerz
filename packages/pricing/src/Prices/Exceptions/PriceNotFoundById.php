<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

/** No price row has this id. Not the core's `prices.price_not_found`: that one says no price applies to a sellable. */
#[Api(since: '0.1')]
final class PriceNotFoundById extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Price', $id, 'pricing.price_not_found');
    }
}
