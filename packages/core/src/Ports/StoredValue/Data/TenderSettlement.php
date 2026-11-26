<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * Post-tax settlement of stored-value tenders against a quote's `grandTotal`.
 *
 * Invariant: `payableTotal = grandTotal − tenderTotal`, `0 <= tenderTotal <= grandTotal`, and `taxTotal` is carried
 * unchanged from the settled quote — gift cards and store credit are payment tenders, never pre-tax discounts.
 */
#[Api(since: '0.1')]
final readonly class TenderSettlement
{
    /**
     * @param  list<AppliedTender>  $allocations
     * @param  list<TenderRejection>  $rejections
     */
    public function __construct(
        public Money $grandTotal,
        public Money $taxTotal,
        public Money $tenderTotal,
        public Money $payableTotal,
        public array $allocations = [],
        public array $rejections = [],
    ) {
        if (! $grandTotal->currency->equals($taxTotal->currency)
            || ! $grandTotal->currency->equals($tenderTotal->currency)
            || ! $grandTotal->currency->equals($payableTotal->currency)) {
            throw new InvalidCommand('currency', 'all settlement totals must share one currency', 'stored_value.currency_mismatch');
        }

        if ($grandTotal->isNegative() || $tenderTotal->isNegative() || $payableTotal->isNegative()) {
            throw new InvalidCommand('amount', 'settlement totals cannot be negative', 'stored_value.invalid_amount');
        }

        if ($tenderTotal->amountMinor > $grandTotal->amountMinor) {
            throw new InvalidCommand('tenderTotal', 'tender cannot exceed grandTotal', 'stored_value.invalid_amount');
        }

        if (! $payableTotal->equals($grandTotal->minus($tenderTotal))) {
            throw new InvalidCommand('payableTotal', 'payableTotal must equal grandTotal minus tenderTotal', 'stored_value.invalid_amount');
        }

        $sum = Money::zero($grandTotal->currency);

        foreach ($allocations as $allocation) {
            $sum = $sum->plus($allocation->amount);
        }

        if (! $sum->equals($tenderTotal)) {
            throw new InvalidCommand('allocations', 'allocation amounts must sum to tenderTotal', 'stored_value.invalid_amount');
        }
    }
}
