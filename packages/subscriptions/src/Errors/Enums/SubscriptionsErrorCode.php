<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-subscriptions`.
 */
#[Api(since: '0.1')]
enum SubscriptionsErrorCode: string implements ErrorCode
{
    case ContractCanceled = 'subscriptions.contract_canceled';
    case ContractNotFound = 'subscriptions.contract_not_found';
    case CurrencyMismatch = 'subscriptions.currency_mismatch';
    case CycleAlreadyProcessed = 'subscriptions.cycle_already_processed';
    case CycleNotFound = 'subscriptions.cycle_not_found';
    case IdempotencyConflict = 'subscriptions.idempotency_conflict';
    case InvalidCommand = 'subscriptions.invalid_command';
    case InvalidRetryPolicy = 'subscriptions.invalid_retry_policy';
    case InvalidSellingPlan = 'subscriptions.invalid_selling_plan';
    case InvalidStatusTransition = 'subscriptions.invalid_status_transition';
    case MissingPaymentMethod = 'subscriptions.missing_payment_method';
    case RenewalOrderNotFound = 'subscriptions.renewal_order_not_found';
    case RenewalPaymentFailed = 'subscriptions.renewal_payment_failed';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
