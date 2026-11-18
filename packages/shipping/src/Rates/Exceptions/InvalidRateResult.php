<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A delivery rate answered with something the package cannot trust, e.g. a price in another currency. */
#[Api(since: '0.1')]
final class InvalidRateResult extends CommerzException
{
    public function __construct(public readonly string $rate, public readonly string $reason)
    {
        parent::__construct("Rate '{$rate}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'shipping.invalid_rate_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }

    public function context(): array
    {
        return ['rate' => $this->rate, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::InvalidRateResult,
            category: ErrorCategory::Unavailable,
            retryable: false,
            details: $this->context(),
        );
    }
}
