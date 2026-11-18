<?php

declare(strict_types=1);

namespace Commerz\Pricing\Calculation\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A tax calculator answered with something the quote cannot use. */
#[Api(since: '0.1')]
final class InvalidTaxResult extends CommerzException
{
    public function __construct(public readonly string $calculator, public readonly string $reason)
    {
        parent::__construct("Tax calculator '{$calculator}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'pricing.invalid_tax_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }

    public function context(): array
    {
        return ['calculator' => $this->calculator, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PricingErrorCode::InvalidTaxResult,
            category: ErrorCategory::Unavailable,
            retryable: false,
            details: $this->context(),
        );
    }
}
