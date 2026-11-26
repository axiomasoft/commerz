<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Exceptions;

use Commerz\Subscriptions\Errors\Enums\SubscriptionsErrorCode;
use Commerz\Subscriptions\Errors\Exceptions\SubscriptionException;
use Packman\Attributes\Api;
use Packman\Errors\Enums\ErrorCategory;
use Throwable;

/**
 * Raised when charging a saved payment method or creating a renewal order fails transiently.
 */
#[Api(since: '0.1')]
final class RenewalPaymentFailed extends SubscriptionException
{
    public function __construct(
        string $contractId,
        string $cycleId,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            SubscriptionsErrorCode::RenewalPaymentFailed,
            "Renewal payment failed for contract '{$contractId}' cycle '{$cycleId}': {$reason}",
            ErrorCategory::Unavailable,
            '/paymentMethodRef',
            [
                'contract_id' => $contractId,
                'cycle_id' => $cycleId,
                'reason' => $reason,
            ],
            isRetryable: true,
            previous: $previous,
        );
    }
}
