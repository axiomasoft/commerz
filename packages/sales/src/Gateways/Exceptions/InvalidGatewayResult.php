<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::InvalidGatewayResult,
            category: PackmanErrorCategory::Unavailable,
            retryable: false,
            details: $this->context(),
        );
    }
}
