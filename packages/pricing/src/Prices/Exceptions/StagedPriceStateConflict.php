<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Commerz\Pricing\Prices\Enums\StagedPriceStatus;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class StagedPriceStateConflict extends CommerzException
{
    public function __construct(
        public readonly string $stagedPrice,
        public readonly StagedPriceStatus $status,
    ) {
        parent::__construct("Staged price '{$stagedPrice}' is already '{$status->value}'.");
    }

    public function code(): string
    {
        return 'pricing.staged_price_state_conflict';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'staged_price' => $this->stagedPrice,
            'status' => $this->status->value,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::StagedPriceStateConflict,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
