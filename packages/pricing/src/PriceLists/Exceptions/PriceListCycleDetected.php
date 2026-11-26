<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class PriceListCycleDetected extends CommerzException
{
    public function __construct(
        public readonly string $priceList,
        public readonly string $parentPriceList,
    ) {
        parent::__construct("Assigning parent '{$parentPriceList}' to price list '{$priceList}' creates a cycle.");
    }

    public function code(): string
    {
        return 'pricing.price_list_cycle';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'price_list' => $this->priceList,
            'parent_price_list' => $this->parentPriceList,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::PriceListCycle,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
