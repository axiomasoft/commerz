<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** No price row has this id. Not the core's `prices.price_not_found`: that one says no price applies to a sellable. */
#[Api(since: '0.1')]
final class PriceNotFoundById extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Price', $id, 'pricing.price_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::PriceNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
