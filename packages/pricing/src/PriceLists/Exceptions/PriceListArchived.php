<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The price list is archived: restore it before changing it or setting prices in it. */
#[Api(since: '0.1')]
final class PriceListArchived extends CommerzException
{
    public function __construct(public readonly string $priceList)
    {
        parent::__construct("Price list '{$priceList}' is archived.");
    }

    public function code(): string
    {
        return 'pricing.price_list_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['price_list' => $this->priceList];
    }
}
