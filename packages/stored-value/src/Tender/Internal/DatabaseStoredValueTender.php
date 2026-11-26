<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Tender\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\AppliedTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\StoredValueCapture;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\Ports\StoredValue\Data\StoredValueHold;
use Commerz\Ports\StoredValue\Data\TenderInstrument;
use Commerz\Ports\StoredValue\Data\TenderRejection;
use Commerz\Ports\StoredValue\Data\TenderSettlement;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\StoredValue\Configuration\StoredValueOptions;
use Commerz\StoredValue\GiftCards\Data\GiftCardCode;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Data\AccountSnapshot;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use Commerz\StoredValue\Ledger\Internal\DoubleEntryLedger;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Override;
use Packman\Attributes\Internal;

/**
 * Production implementation of {@see StoredValueTender}.
 *
 * Applies stored-value instruments strictly against `$quote->totals->total` (`grandTotal` AFTER taxes), leaving
 * subtotal, discounts, and taxable VAT base completely untouched.
 */
#[Internal]
final readonly class DatabaseStoredValueTender implements StoredValueTender
{
    public function __construct(
        private DoubleEntryLedger $ledger,
        private StoredValueOptions $options,
        private Clock $clock,
    ) {}

    /** @param  list<TenderInstrument>  $tenders */
    #[Override]
    public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement
    {
        $currency = $quote->context->currency;
        $grandTotal = $quote->totals->total;
        $taxTotal = $quote->totals->taxTotal;
        $remaining = $grandTotal;
        $tenderTotal = Money::zero($currency);
        $now = PgTimestamp::current($this->clock);

        $allocations = [];
        $rejections = [];
        $seenAccounts = [];

        foreach ($tenders as $index => $tender) {
            $ref = $this->tenderReference($tender, $index);

            if ($index >= $this->options->maxTendersPerQuote) {
                $rejections[] = new TenderRejection($ref, 'too_many_tenders', 'Exceeds maximum stored-value tenders per quote.');

                continue;
            }

            if ($remaining->isZero()) {
                $rejections[] = new TenderRejection($ref, 'nothing_remaining', 'Quote grand total is already fully covered by earlier tenders.');

                continue;
            }

            $account = $this->resolveAccount($tender, $currency);

            if ($account === null) {
                $rejections[] = new TenderRejection($ref, 'not_found', 'Stored-value account was not found.');

                continue;
            }

            if (isset($seenAccounts[$account->id->value])) {
                $rejections[] = new TenderRejection($ref, 'duplicate_account', 'Stored-value account was already presented.');

                continue;
            }

            $seenAccounts[$account->id->value] = true;

            if ($account->kind !== $tender->kind) {
                $rejections[] = new TenderRejection($ref, 'kind_mismatch', 'Stored-value account kind does not match presented tender.');

                continue;
            }

            if (! $account->currency->equals($currency)) {
                $rejections[] = new TenderRejection($ref, 'currency_mismatch', "Account currency {$account->currency->code} does not match quote currency {$currency->code}.");

                continue;
            }

            if ($account->status !== AccountStatus::Active) {
                $rejections[] = new TenderRejection($ref, $account->status->value, "Account status is '{$account->status->value}'.");

                continue;
            }

            if ($account->isExpiredAt($now)) {
                $rejections[] = new TenderRejection($ref, AccountStatus::Expired->value, 'Stored-value account has expired.');

                continue;
            }

            if (! $account->available->isPositive()) {
                $rejections[] = new TenderRejection($ref, 'empty_balance', 'Stored-value account has no available balance.');

                continue;
            }

            $cap = $account->available;

            if ($tender->maxAmount !== null) {
                if (! $tender->maxAmount->currency->equals($currency)) {
                    $rejections[] = new TenderRejection($ref, 'currency_mismatch', "Requested cap currency {$tender->maxAmount->currency->code} does not match quote currency {$currency->code}.");

                    continue;
                }

                if ($tender->maxAmount->amountMinor < $cap->amountMinor) {
                    $cap = $tender->maxAmount;
                }
            }

            $appliedMinor = min($cap->amountMinor, $remaining->amountMinor);
            $applied = Money::ofMinor($appliedMinor, $currency);

            $allocations[] = new AppliedTender(
                accountId: $account->id->value,
                kind: $account->kind,
                amount: $applied,
                remainingBalance: $account->available->minus($applied),
                codeLast4: $account->codeLast4,
            );

            $tenderTotal = $tenderTotal->plus($applied);
            $remaining = $remaining->minus($applied);
        }

        return new TenderSettlement(
            grandTotal: $grandTotal,
            taxTotal: $taxTotal,
            tenderTotal: $tenderTotal,
            payableTotal: $remaining,
            allocations: $allocations,
            rejections: $rejections,
        );
    }

    #[Override]
    public function hold(HoldStoredValue $command): StoredValueHold
    {
        return $this->ledger->hold($command);
    }

    #[Override]
    public function capture(CaptureStoredValue $command): StoredValueCapture
    {
        return $this->ledger->capture($command);
    }

    #[Override]
    public function release(ReleaseStoredValue $command): void
    {
        $this->ledger->release($command);
    }

    #[Override]
    public function refundCredit(RefundToStoredValue $command): StoredValueCredit
    {
        return $this->ledger->refundCredit($command);
    }

    private function resolveAccount(TenderInstrument $tender, Currency $currency): ?AccountSnapshot
    {
        if ($tender->accountId !== null) {
            try {
                return $this->ledger->findAccount(AccountId::fromString($tender->accountId));
            } catch (InvalidCommand) {
                return null;
            }
        }

        if ($tender->kind === StoredValueKind::GiftCard && $tender->giftCardCode !== null) {
            try {
                return $this->ledger->findAccountByCodeHash(GiftCardCode::hash($tender->giftCardCode));
            } catch (InvalidCommand) {
                return null;
            }
        }

        if ($tender->kind === StoredValueKind::Wallet && $tender->customer !== null) {
            return $this->ledger->findWalletByCustomer($tender->customer, $currency);
        }

        return null;
    }

    private function tenderReference(TenderInstrument $tender, int $index): string
    {
        if ($tender->accountId !== null) {
            return $tender->accountId;
        }

        if ($tender->giftCardCode !== null) {
            try {
                return 'gift_card:*'.GiftCardCode::last4($tender->giftCardCode);
            } catch (InvalidCommand) {
                return "gift_card:#{$index}";
            }
        }

        if ($tender->customer !== null) {
            return 'wallet:'.(string) $tender->customer;
        }

        return "tender:#{$index}";
    }
}
