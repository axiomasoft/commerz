<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The zone is archived: restore it before changing it. */
#[Api(since: '0.1')]
final class ZoneArchived extends CommerzException
{
    public function __construct(public readonly string $zone)
    {
        parent::__construct("Shipping zone '{$zone}' is archived.");
    }

    public function code(): string
    {
        return 'shipping.zone_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['zone' => $this->zone];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::ZoneArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
