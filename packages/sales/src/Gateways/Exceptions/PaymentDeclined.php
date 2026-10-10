<?php

declare(strict_types=1);

namespace Commerz\Sales\Gateways\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The gateway refused for good and certainly did nothing: the attempt becomes `failed`. Any other exception from a gateway leaves the outcome unknown. */
#[Api(since: '0.1')]
final class PaymentDeclined extends CommerzException
{
    public function __construct(public readonly string $gateway, public readonly string $reason)
    {
        parent::__construct("Gateway '{$gateway}' declined: {$reason}");
    }

    public function code(): string
    {
        return 'sales.payment_declined';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['gateway' => $this->gateway, 'reason' => $this->reason];
    }
}
