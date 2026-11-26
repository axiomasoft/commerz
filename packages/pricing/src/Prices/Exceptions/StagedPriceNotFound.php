<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class StagedPriceNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Staged price', $id, 'pricing.staged_price_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::StagedPriceNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
