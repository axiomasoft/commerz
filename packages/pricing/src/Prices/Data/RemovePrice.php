<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Attributes\Api;

/** Deletes a price; removing one that is gone changes nothing. */
#[Api(since: '0.1')]
final readonly class RemovePrice
{
    public function __construct(public PriceId $price) {}
}
