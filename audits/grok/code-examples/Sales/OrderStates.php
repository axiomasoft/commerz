<?php

declare(strict_types=1);

// Три независимых оси вместо одного «статуса заказа» на 15 значений.
// Каждый переход пишется в cmz_order_events (bigint identity) и публикуется после коммита через outbox.

namespace Commerz\Sales\Enums;

use Commerz\Attributes\Api;

#[Api(since: '1.0')]
enum OrderState: string
{
    case Draft = 'draft';           // заказ, собираемый менеджером вручную; корзина — отдельная сущность
    case Placed = 'placed';
    case Completed = 'completed';   // оплачен и полностью исполнен (или возвращён)
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Placed, self::Cancelled],
            self::Placed => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}

#[Api(since: '1.0')]
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case Authorized = 'authorized';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /** Выводится из сумм платежей, а не выставляется руками: authorized/captured/refunded_minor заказа. */
    public static function derive(int $totalMinor, int $authorizedMinor, int $capturedMinor, int $refundedMinor): self
    {
        return match (true) {
            $refundedMinor > 0 && $refundedMinor >= $capturedMinor => self::Refunded,
            $refundedMinor > 0 => self::PartiallyRefunded,
            $capturedMinor >= $totalMinor => self::Paid,
            $capturedMinor > 0 => self::PartiallyPaid,
            $authorizedMinor >= $totalMinor => self::Authorized,
            default => self::Unpaid,
        };
    }
}

#[Api(since: '1.0')]
enum FulfillmentState: string
{
    case Unfulfilled = 'unfulfilled';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';

    /** Тоже выводится — из количеств строк; $orderedQty = Σ(quantity − cancelled_quantity). */
    public static function derive(int $orderedQty, int $fulfilledQty, int $returnedQty): self
    {
        return match (true) {
            $returnedQty > 0 && $returnedQty >= $fulfilledQty => self::Returned,
            $returnedQty > 0 => self::PartiallyReturned,
            $fulfilledQty >= $orderedQty => self::Fulfilled,
            $fulfilledQty > 0 => self::PartiallyFulfilled,
            default => self::Unfulfilled,
        };
    }
}
