<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Contracts;

use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Data\CompensationOutcome;
use Commerz\Sales\OrderChanges\Data\CompensationPreference;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\Orders\Data\OrderView;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Compensating financial workflow executed after an OrderChange confirms with a non-zero net delta.
 *
 * Hosts or acquiring extensions (e.g. `PaymentCollection` / incremental auth gateways) may bind a custom
 * implementation; the default delegates to `Payments`, `Refunds`, and `StoredValueTender`.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface OrderChangeCompensator
{
    /**
     * Handles `amountDelta > 0` (customer owes more): incremental authorization, delta charge, or deferred.
     * Runs OUTSIDE any database transaction (D33) so gateway calls never hold DB locks.
     */
    public function compensateIncrease(
        OrderChangeId $changeId,
        OrderView $order,
        Money $delta,
        CompensationPreference $preference,
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): CompensationOutcome;

    /**
     * Handles `amountDelta < 0` when the order is overpaid (`overpaid > 0`): partial refund via `Refunds`,
     * store-credit wallet refund via `StoredValueTender`, or deferred.
     * Runs OUTSIDE any database transaction (D33) when issuing gateway refunds.
     */
    public function compensateDecrease(
        OrderChangeId $changeId,
        OrderView $order,
        Money $overpaid,
        CompensationPreference $preference,
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): CompensationOutcome;
}
