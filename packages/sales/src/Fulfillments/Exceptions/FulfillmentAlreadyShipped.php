<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The fulfillment already left with another carrier or tracking number; a shipment is never overwritten. */
#[Api(since: '0.1')]
final class FulfillmentAlreadyShipped extends CommerzException
{
    public function __construct(public readonly string $fulfillment, public readonly ?string $carrier, public readonly ?string $tracking)
    {
        parent::__construct("Fulfillment '{$fulfillment}' is already shipped with another carrier or tracking number.");
    }

    public function code(): string
    {
        return 'sales.fulfillment_already_shipped';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['fulfillment' => $this->fulfillment, 'carrier' => $this->carrier, 'tracking' => $this->tracking];
    }
}
