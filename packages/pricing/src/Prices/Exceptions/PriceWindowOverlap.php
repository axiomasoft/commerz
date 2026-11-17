<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The window overlaps another window of the same price key (list, sellable, group, quantity step) (PRC-01). */
#[Api(since: '0.1')]
final class PriceWindowOverlap extends CommerzException
{
    public function __construct(
        public readonly string $sellable,
        public readonly string $priceList,
        public readonly ?string $conflictingPrice,
    ) {
        parent::__construct("A price of '{$sellable}' in price list '{$priceList}' already covers part of this window.");
    }

    public function code(): string
    {
        return 'pricing.price_window_overlap';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'sellable' => $this->sellable,
            'price_list' => $this->priceList,
            'conflicting_price' => $this->conflictingPrice,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::PriceWindowOverlap,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
