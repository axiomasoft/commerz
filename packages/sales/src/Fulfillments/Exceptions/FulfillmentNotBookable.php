<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The fulfillment has nothing to book with a carrier. */
#[Api(since: '0.1')]
final class FulfillmentNotBookable extends CommerzException
{
    /** @param  string  $reason  `no_delivery` (the order has no delivery or address) or `nothing_to_ship` (no line needs shipping) */
    public function __construct(public readonly string $fulfillment, public readonly string $reason)
    {
        parent::__construct("Fulfillment '{$fulfillment}' cannot be booked: {$reason}.");
    }

    public function code(): string
    {
        return 'sales.fulfillment_not_bookable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['fulfillment' => $this->fulfillment, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::FulfillmentNotBookable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
