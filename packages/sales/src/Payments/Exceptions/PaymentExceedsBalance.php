<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Money\Money;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * The order owes less than asked: its balance is the amount due less every payment that is not failed or cancelled
 * (captured ones by what they took). Nothing is left to pay when another attempt already covers the order.
 */
#[Api(since: '0.1')]
final class PaymentExceedsBalance extends CommerzException
{
    public function __construct(public readonly string $order, public readonly Money $requested, public readonly Money $balance)
    {
        parent::__construct("Order '{$order}' has a balance of {$balance->toDecimal()} {$balance->currency->code}, not {$requested->toDecimal()}.");
    }

    public function code(): string
    {
        return 'sales.payment_exceeds_balance';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'order' => $this->order,
            'requested_minor' => $this->requested->amountMinor,
            'balance_minor' => $this->balance->amountMinor,
            'currency' => $this->balance->currency->code,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::PaymentExceedsBalance,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
