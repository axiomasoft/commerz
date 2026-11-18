<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Money\Money;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * A payment holds less than asked: what can still be refunded is what it captured less every refund of it that has not
 * failed. Without a named payment, `$refundable` is the most any captured payment of the order still holds.
 */
#[Api(since: '0.1')]
final class RefundExceedsBalance extends CommerzException
{
    public function __construct(public readonly string $order, public readonly Money $requested, public readonly Money $refundable)
    {
        parent::__construct("Order '{$order}' can be refunded {$refundable->toDecimal()} {$refundable->currency->code} by one payment, not {$requested->toDecimal()}.");
    }

    public function code(): string
    {
        return 'sales.refund_exceeds_balance';
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
            'refundable_minor' => $this->refundable->amountMinor,
            'currency' => $this->refundable->currency->code,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::RefundExceedsBalance,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
