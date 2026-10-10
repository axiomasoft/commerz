<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Returns\Data\ReturnId;

/**
 * Gives money of an order back through the gateway of one of its captured payments. The caller sets the amount; the
 * refund may name the return it compensates, but never touches the stock (REF-01).
 */
#[Api(since: '0.1')]
final readonly class IssueRefund
{
    private const string CODE = 'sales.invalid_refund';

    /**
     * @param  PaymentId|null  $payment  the payment to refund; by default the oldest captured one that still holds the amount
     * @param  ReturnId|null  $return  the return of the order the money is for, if any
     */
    public function __construct(
        public OrderId $order,
        public Money $amount,
        public ?PaymentId $payment = null,
        public ?ReturnId $return = null,
        public ?string $reason = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        if ($reason !== null && ($reason === '' || strlen($reason) > 191 || ! mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0"))) {
            throw new InvalidCommand('reason', 'must be null or 1..191 bytes of UTF-8 without NUL', self::CODE);
        }
    }
}
