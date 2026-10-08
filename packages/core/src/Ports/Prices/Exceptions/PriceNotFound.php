<?php

declare(strict_types=1);

namespace Commerz\Ports\Prices\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

/** No price applies to the sellable in this context and currency. */
#[Api(since: '0.1')]
final class PriceNotFound extends NotFound
{
    public function __construct(string $sellable, string $currency)
    {
        parent::__construct('Price', "{$sellable} in {$currency}", 'prices.price_not_found');
    }
}
