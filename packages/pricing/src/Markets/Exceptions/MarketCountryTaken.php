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
final class MarketCountryTaken extends CommerzException
{
    public function __construct(
        public readonly string $channel,
        public readonly string $country,
        public readonly ?string $otherMarket,
    ) {
        parent::__construct("Country '{$country}' in channel '{$channel}' already belongs to market '".($otherMarket ?? '?')."'.");
    }

    public function code(): string
    {
        return 'pricing.market_country_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'channel' => $this->channel,
            'country' => $this->country,
            'market' => $this->otherMarket,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::MarketCountryTaken,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
