<?php

declare(strict_types=1);

namespace Commerz\Shipping\Carriers\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A carrier answered with something the package cannot trust, e.g. a booking by another carrier or without tracking. */
#[Api(since: '0.1')]
final class InvalidCarrierResult extends CommerzException
{
    public function __construct(public readonly string $carrier, public readonly string $reason)
    {
        parent::__construct("Carrier '{$carrier}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'shipping.invalid_carrier_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }

    public function context(): array
    {
        return ['carrier' => $this->carrier, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::InvalidCarrierResult,
            category: ErrorCategory::Unavailable,
            retryable: false,
            details: $this->context(),
        );
    }
}
