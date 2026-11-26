<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Raised when a payment operation requires a capability the selected gateway does not advertise. */
#[Api(since: '0.1')]
final class GatewayCapabilityUnsupported extends CommerzException
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $capability,
    ) {
        parent::__construct("Gateway '{$gateway}' does not support capability '{$capability}'.");
    }

    public function code(): string
    {
        return SalesErrorCode::GatewayCapabilityUnsupported->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['gateway' => $this->gateway, 'capability' => $this->capability];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::GatewayCapabilityUnsupported,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
