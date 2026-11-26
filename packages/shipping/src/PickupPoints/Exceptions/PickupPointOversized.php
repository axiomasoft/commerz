<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Thrown when a parcel or item exceeds a selected pickup point or locker's weight or dimension limits. */
#[Api(since: '0.1')]
final class PickupPointOversized extends CommerzException
{
    public function __construct(
        public readonly string $pointId,
        public readonly string $provider,
        public readonly string $reason,
    ) {
        parent::__construct("Pickup point '{$provider}:{$pointId}' cannot accept parcel: {$reason}");
    }

    public function code(): string
    {
        return 'shipping.pickup_point_oversized';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return [
            'point_id' => $this->pointId,
            'provider' => $this->provider,
            'reason' => $this->reason,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::PickupPointOversized,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
