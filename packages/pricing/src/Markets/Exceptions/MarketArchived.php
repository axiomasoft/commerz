<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class MarketArchived extends CommerzException
{
    public function __construct(public readonly string $market)
    {
        parent::__construct("Market '{$market}' is archived.");
    }

    public function code(): string
    {
        return 'pricing.market_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['market' => $this->market];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::MarketArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
