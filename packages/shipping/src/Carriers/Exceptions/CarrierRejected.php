<?php

declare(strict_types=1);

namespace Commerz\Shipping\Carriers\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The carrier refused the shipment for good and certainly created nothing; `reason` is snake_case. */
#[Api(since: '0.1')]
final class CarrierRejected extends CommerzException
{
    public function __construct(public readonly string $carrier, public readonly string $reason, public readonly string $detail)
    {
        parent::__construct("Carrier '{$carrier}' rejected the shipment ({$reason}): {$detail}");
    }

    public function code(): string
    {
        return 'shipping.carrier_rejected';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['carrier' => $this->carrier, 'reason' => $this->reason, 'detail' => $this->detail];
    }
}
