<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** The shipment names a carrier other than the one of its delivery method. */
#[Api(since: '0.1')]
final class CarrierMismatch extends InvalidCommand
{
    public function __construct(public readonly string $method, public readonly string $expected, public readonly string $given)
    {
        parent::__construct('carrier', "method '{$method}' ships with '{$expected}', not '{$given}'", 'shipping.carrier_mismatch');
    }

    public function context(): array
    {
        return parent::context() + ['method' => $this->method, 'expected' => $this->expected, 'given' => $this->given];
    }
}
