<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::PriceListArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
