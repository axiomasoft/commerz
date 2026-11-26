<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * `total = subtotal − discountTotal + deliveryTotal + excluded taxes`. `discountTotal` (positive) sums line and
 * order adjustments; `deliveryTotal` is the delivery price after delivery adjustments; `taxTotal` sums all taxes,
 * included and excluded. `dueNowAmount` and `remainingBalanceAmount` partition `total` across immediate checkout
 * collection and deferred / deposit collection (`dueNowAmount + remainingBalanceAmount === total`).
 */
#[Api(since: '0.1')]
final readonly class QuoteTotals
{
    public Money $dueNowAmount;

    public Money $remainingBalanceAmount;

    public function __construct(
        public Money $subtotal,
        public Money $discountTotal,
        public Money $deliveryTotal,
        public Money $taxTotal,
        public Money $total,
        ?Money $dueNowAmount = null,
        ?Money $remainingBalanceAmount = null,
    ) {
        if ($dueNowAmount === null && $remainingBalanceAmount === null) {
            $resolvedDueNow = $total;
            $resolvedRemaining = Money::zero($total->currency);
        } elseif ($dueNowAmount !== null && $remainingBalanceAmount === null) {
            self::assertCurrency($dueNowAmount, $total, 'dueNowAmount');
            $resolvedDueNow = $dueNowAmount;
            $resolvedRemaining = $total->minus($dueNowAmount);
        } elseif ($dueNowAmount === null) {
            /** @var Money $remainingBalanceAmount */
            self::assertCurrency($remainingBalanceAmount, $total, 'remainingBalanceAmount');
            $resolvedRemaining = $remainingBalanceAmount;
            $resolvedDueNow = $total->minus($remainingBalanceAmount);
        } else {
            self::assertCurrency($dueNowAmount, $total, 'dueNowAmount');
            self::assertCurrency($remainingBalanceAmount, $total, 'remainingBalanceAmount');
            $resolvedDueNow = $dueNowAmount;
            $resolvedRemaining = $remainingBalanceAmount;
        }

        if ($resolvedDueNow->isNegative()) {
            throw new InvalidCommand('dueNowAmount', 'must not be negative', 'quotes.invalid_totals');
        }

        if ($resolvedRemaining->isNegative()) {
            throw new InvalidCommand('remainingBalanceAmount', 'must not be negative', 'quotes.invalid_totals');
        }

        if (! $resolvedDueNow->plus($resolvedRemaining)->equals($total)) {
            throw new InvalidCommand(
                'dueNowAmount',
                'dueNowAmount + remainingBalanceAmount must equal total',
                'quotes.invalid_totals',
            );
        }

        $this->dueNowAmount = $resolvedDueNow;
        $this->remainingBalanceAmount = $resolvedRemaining;
    }

    private static function assertCurrency(Money $amount, Money $total, string $field): void
    {
        if (! $amount->currency->equals($total->currency)) {
            throw new InvalidCommand(
                $field,
                "{$amount->currency->code} differs from the quote currency {$total->currency->code}",
                'quotes.currency_mismatch',
            );
        }
    }
}
