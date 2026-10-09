<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class PriceListNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Price list', $id, 'pricing.price_list_not_found');
    }
}
