<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A gateway answered with something that breaks its contract; the outcome of the attempt is treated as unknown. */
#[Api(since: '0.1')]
final class InvalidGatewayResult extends CommerzException
{
    public function __construct(public readonly string $gateway, public readonly string $reason)
    {
        parent::__construct("Gateway '{$gateway}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'sales.invalid_gateway_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['gateway' => $this->gateway, 'reason' => $this->reason];
    }
}
