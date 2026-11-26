<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Identity\CustomerRef;
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
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Ports\StoredValue\Exceptions\StoredValueNotFound;
use Commerz\Ports\StoredValue\Exceptions\StoredValueUnavailable;
use Commerz\Quotes\Data\QuoteResult;
use Override;
use Packman\Attributes\Api;

/**
 * In-memory `StoredValueTender` enforcing `balance - held >= 0` (INV-SV-001) and idempotent ledger operations.
 */
#[Api(since: '0.1')]
final class FakeStoredValue implements StoredValueTender
{
    /** @var array<string, array{kind: StoredValueKind, currency: Currency, balance: int, held: int, frozenReason: ?string, customer: ?CustomerRef, giftCardCode: ?string}> */
    private array $accounts = [];

    /** @var array<string, string> */
    private array $byGiftCardCode = [];

    /** @var array<string, string> */
    private array $byCustomer = [];

    /** @var array<string, array{holdId: string, accountId: string, orderRef: string, amount: int, captured: int, released: int, currency: Currency}> */
    private array $holds = [];

    /** @var array<string, StoredValueHold> */
    private array $holdByKey = [];

    /** @var array<string, StoredValueCapture> */
    private array $captureByKey = [];

    /** @var array<string, bool> */
    private array $releaseByKey = [];

    /** @var array<string, StoredValueCredit> */
    private array $creditByKey = [];

    private int $sequence = 0;

    public function seed(
        string $accountId,
        Money $balance,
        StoredValueKind $kind = StoredValueKind::GiftCard,
        ?CustomerRef $customer = null,
        ?string $giftCardCode = null,
    ): self {
        $this->accounts[$accountId] = [
            'kind' => $kind,
            'currency' => $balance->currency,
            'balance' => $balance->amountMinor,
            'held' => 0,
            'frozenReason' => null,
            'customer' => $customer,
            'giftCardCode' => $giftCardCode,
        ];

        if ($giftCardCode !== null) {
            $this->byGiftCardCode[$giftCardCode] = $accountId;
        }

        if ($customer !== null) {
            $this->byCustomer[(string) $customer] = $accountId;
        }

        return $this;
    }

    public function freeze(string $accountId, string $reason = 'frozen'): self
    {
        if (! isset($this->accounts[$accountId])) {
            throw new StoredValueNotFound('account', $accountId);
        }

        $this->accounts[$accountId]['frozenReason'] = $reason;

        return $this;
    }

    public function unfreeze(string $accountId): self
    {
        if (! isset($this->accounts[$accountId])) {
            throw new StoredValueNotFound('account', $accountId);
        }

        $this->accounts[$accountId]['frozenReason'] = null;

        return $this;
    }

    public function balanceOf(string $accountId): Money
    {
        $account = $this->accounts[$accountId] ?? throw new StoredValueNotFound('account', $accountId);

        return Money::ofMinor($account['balance'], $account['currency']);
    }

    public function heldOf(string $accountId): Money
    {
        $account = $this->accounts[$accountId] ?? throw new StoredValueNotFound('account', $accountId);

        return Money::ofMinor($account['held'], $account['currency']);
    }

    /** @param  list<TenderInstrument>  $tenders */
    #[Override]
    public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement
    {
        $grandTotal = $quote->totals->total;
        $currency = $quote->context->currency;
        $remainingMinor = $grandTotal->amountMinor;
        $appliedMinor = 0;
        $allocations = [];
        $rejections = [];

        foreach ($tenders as $tender) {
            if ($remainingMinor <= 0) {
                break;
            }

            $accountId = $this->resolveInstrumentAccountId($tender);
            $reference = $tender->accountId ?? $tender->giftCardCode ?? (string) $tender->customer;

            if ($accountId === null || ! isset($this->accounts[$accountId])) {
                $rejections[] = new TenderRejection($reference, 'not_found', 'Stored-value account not found.');

                continue;
            }

            $account = $this->accounts[$accountId];

            if ($account['frozenReason'] !== null) {
                $rejections[] = new TenderRejection($accountId, 'unavailable', $account['frozenReason']);

                continue;
            }

            if (! $account['currency']->equals($currency)) {
                $rejections[] = new TenderRejection($accountId, 'currency_mismatch', 'Currency does not match quote.');

                continue;
            }

            $available = $account['balance'] - $account['held'];

            if ($available <= 0) {
                $rejections[] = new TenderRejection($accountId, 'insufficient_balance', 'No available balance.');

                continue;
            }

            $cap = $tender->maxAmount !== null ? $tender->maxAmount->amountMinor : $remainingMinor;
            $take = min($remainingMinor, $available, $cap);

            if ($take <= 0) {
                continue;
            }

            $remainingMinor -= $take;
            $appliedMinor += $take;
            $last4 = $account['giftCardCode'] !== null ? substr($account['giftCardCode'], -4) : null;

            $allocations[] = new AppliedTender(
                accountId: $accountId,
                kind: $tender->kind,
                amount: Money::ofMinor($take, $currency),
                remainingBalance: Money::ofMinor($available - $take, $currency),
                codeLast4: $last4,
            );
        }

        $tenderTotal = Money::ofMinor($appliedMinor, $currency);

        return new TenderSettlement(
            grandTotal: $grandTotal,
            taxTotal: $quote->totals->taxTotal,
            tenderTotal: $tenderTotal,
            payableTotal: $grandTotal->minus($tenderTotal),
            allocations: $allocations,
            rejections: $rejections,
        );
    }

    #[Override]
    public function hold(HoldStoredValue $command): StoredValueHold
    {
        if (isset($this->holdByKey[$command->idempotencyKey])) {
            return $this->holdByKey[$command->idempotencyKey];
        }

        if (! isset($this->accounts[$command->accountId])) {
            throw new StoredValueNotFound('account', $command->accountId);
        }

        $account = &$this->accounts[$command->accountId];

        if ($account['frozenReason'] !== null) {
            throw new StoredValueUnavailable($command->accountId, $account['frozenReason']);
        }

        $available = $account['balance'] - $account['held'];

        if ($command->amount->amountMinor > $available) {
            throw new InsufficientStoredValue(
                $command->accountId,
                $command->amount->amountMinor,
                max(0, $available),
                $account['currency']->code,
            );
        }

        $account['held'] += $command->amount->amountMinor;
        $holdId = 'hold-'.(++$this->sequence);

        $this->holds[$holdId] = [
            'holdId' => $holdId,
            'accountId' => $command->accountId,
            'orderRef' => $command->orderRef,
            'amount' => $command->amount->amountMinor,
            'captured' => 0,
            'released' => 0,
            'currency' => $command->amount->currency,
        ];

        return $this->holdByKey[$command->idempotencyKey] = new StoredValueHold(
            holdId: $holdId,
            accountId: $command->accountId,
            orderRef: $command->orderRef,
            amount: $command->amount,
            captured: Money::zero($command->amount->currency),
            released: Money::zero($command->amount->currency),
            state: 'active',
            expiresAt: $command->expiresAt,
        );
    }

    #[Override]
    public function capture(CaptureStoredValue $command): StoredValueCapture
    {
        if (isset($this->captureByKey[$command->idempotencyKey])) {
            return $this->captureByKey[$command->idempotencyKey];
        }

        if (! isset($this->holds[$command->holdId])) {
            throw new StoredValueNotFound('hold', $command->holdId);
        }

        $hold = &$this->holds[$command->holdId];
        $account = &$this->accounts[$hold['accountId']];

        if ($account['frozenReason'] !== null) {
            throw new StoredValueUnavailable($hold['accountId'], $account['frozenReason']);
        }

        $remaining = $hold['amount'] - $hold['captured'] - $hold['released'];
        $amountMinor = $command->amount !== null ? $command->amount->amountMinor : $remaining;

        if ($amountMinor > $remaining) {
            throw new InsufficientStoredValue(
                $hold['accountId'],
                $amountMinor,
                max(0, $remaining),
                $hold['currency']->code,
            );
        }

        $hold['captured'] += $amountMinor;
        $account['held'] -= $amountMinor;
        $account['balance'] -= $amountMinor;

        return $this->captureByKey[$command->idempotencyKey] = new StoredValueCapture(
            holdId: $command->holdId,
            accountId: $hold['accountId'],
            captured: Money::ofMinor($amountMinor, $hold['currency']),
            balanceAfter: Money::ofMinor($account['balance'], $hold['currency']),
            heldAfter: Money::ofMinor($account['held'], $hold['currency']),
            journalId: 'jnl-'.(++$this->sequence),
        );
    }

    #[Override]
    public function release(ReleaseStoredValue $command): void
    {
        if (isset($this->releaseByKey[$command->idempotencyKey])) {
            return;
        }

        if (! isset($this->holds[$command->holdId])) {
            throw new StoredValueNotFound('hold', $command->holdId);
        }

        $hold = &$this->holds[$command->holdId];
        $remaining = $hold['amount'] - $hold['captured'] - $hold['released'];

        if ($remaining > 0) {
            $requested = $command->amount !== null ? $command->amount->amountMinor : $remaining;
            $amountMinor = min($remaining, $requested);
            $hold['released'] += $amountMinor;
            $this->accounts[$hold['accountId']]['held'] -= $amountMinor;
        }

        $this->releaseByKey[$command->idempotencyKey] = true;
    }

    #[Override]
    public function refundCredit(RefundToStoredValue $command): StoredValueCredit
    {
        if (isset($this->creditByKey[$command->idempotencyKey])) {
            return $this->creditByKey[$command->idempotencyKey];
        }

        $accountId = $command->accountId ?? ($command->customer !== null ? ($this->byCustomer[(string) $command->customer] ?? null) : null);

        if ($accountId === null || ! isset($this->accounts[$accountId])) {
            throw new StoredValueNotFound('account', $command->accountId ?? (string) $command->customer);
        }

        if ($this->accounts[$accountId]['frozenReason'] !== null) {
            throw new StoredValueUnavailable($accountId, $this->accounts[$accountId]['frozenReason']);
        }

        $this->accounts[$accountId]['balance'] += $command->amount->amountMinor;

        return $this->creditByKey[$command->idempotencyKey] = new StoredValueCredit(
            accountId: $accountId,
            amount: $command->amount,
            balanceAfter: Money::ofMinor($this->accounts[$accountId]['balance'], $command->amount->currency),
            journalId: 'jnl-'.(++$this->sequence),
        );
    }

    private function resolveInstrumentAccountId(TenderInstrument $tender): ?string
    {
        if ($tender->accountId !== null) {
            return $tender->accountId;
        }

        if ($tender->giftCardCode !== null) {
            return $this->byGiftCardCode[$tender->giftCardCode] ?? null;
        }

        if ($tender->customer !== null) {
            return $this->byCustomer[(string) $tender->customer] ?? null;
        }

        return null;
    }
}
