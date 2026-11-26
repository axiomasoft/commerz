<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\StoredValueCapture;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\Ports\StoredValue\Data\StoredValueHold;
use Commerz\Ports\StoredValue\Data\TenderInstrument;
use Commerz\Ports\StoredValue\Data\TenderSettlement;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Ports\StoredValue\Exceptions\StoredValueNotFound;
use Commerz\Quotes\Data\QuoteResult;
use Override;

/**
 * In-memory {@see StoredValueTender} test double enforcing `balance - held >= 0` (INV-SV-001).
 */
final class InMemoryStoredValueTender implements StoredValueTender
{
    /** @var array<string, array{currency: Currency, balance: int, held: int}> */
    private array $accounts = [];

    /** @var array<string, array{holdId: string, accountId: string, orderRef: string, amount: int, captured: int, released: int, currency: Currency}> */
    private array $holds = [];

    /** @var array<string, StoredValueHold> */
    private array $holdByKey = [];

    /** @var array<string, StoredValueCapture> */
    private array $captureByKey = [];

    private int $sequence = 0;

    public function seed(string $accountId, Money $balance): void
    {
        $this->accounts[$accountId] = [
            'currency' => $balance->currency,
            'balance' => $balance->amountMinor,
            'held' => 0,
        ];
    }

    public function balanceOf(string $accountId): Money
    {
        $account = $this->accounts[$accountId] ?? throw new StoredValueNotFound($accountId);

        return Money::ofMinor($account['balance'], $account['currency']);
    }

    public function heldOf(string $accountId): Money
    {
        $account = $this->accounts[$accountId] ?? throw new StoredValueNotFound($accountId);

        return Money::ofMinor($account['held'], $account['currency']);
    }

    /** @param  list<TenderInstrument>  $tenders */
    #[Override]
    public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement
    {
        return new TenderSettlement(
            grandTotal: $quote->totals->total,
            taxTotal: $quote->totals->taxTotal,
            tenderTotal: Money::zero($quote->context->currency),
            payableTotal: $quote->totals->total,
        );
    }

    #[Override]
    public function hold(HoldStoredValue $command): StoredValueHold
    {
        if (isset($this->holdByKey[$command->idempotencyKey])) {
            return $this->holdByKey[$command->idempotencyKey];
        }

        if (! isset($this->accounts[$command->accountId])) {
            throw new StoredValueNotFound($command->accountId);
        }

        $account = &$this->accounts[$command->accountId];
        $available = $account['balance'] - $account['held'];

        if ($command->amount->amountMinor > $available) {
            throw new InsufficientStoredValue(
                $command->accountId,
                $command->amount,
                Money::ofMinor(max(0, $available), $account['currency']),
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
            throw new StoredValueNotFound($command->holdId);
        }

        $hold = &$this->holds[$command->holdId];
        $remaining = $hold['amount'] - $hold['captured'] - $hold['released'];
        $amountMinor = $command->amount?->amountMinor ?? $remaining;

        if ($amountMinor > $remaining) {
            throw new InsufficientStoredValue(
                $hold['accountId'],
                Money::ofMinor($amountMinor, $hold['currency']),
                Money::ofMinor(max(0, $remaining), $hold['currency']),
            );
        }

        $account = &$this->accounts[$hold['accountId']];
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
        if (! isset($this->holds[$command->holdId])) {
            return;
        }

        $hold = &$this->holds[$command->holdId];
        $remaining = $hold['amount'] - $hold['captured'] - $hold['released'];

        if ($remaining <= 0) {
            return;
        }

        $amountMinor = min($remaining, $command->amount?->amountMinor ?? $remaining);
        $hold['released'] += $amountMinor;
        $this->accounts[$hold['accountId']]['held'] -= $amountMinor;
    }

    #[Override]
    public function refundCredit(RefundToStoredValue $command): StoredValueCredit
    {
        $accountId = $command->accountId ?? 'wallet';

        if (! isset($this->accounts[$accountId])) {
            throw new StoredValueNotFound($accountId);
        }

        $this->accounts[$accountId]['balance'] += $command->amount->amountMinor;

        return new StoredValueCredit(
            accountId: $accountId,
            credited: $command->amount,
            balanceAfter: Money::ofMinor($this->accounts[$accountId]['balance'], $command->amount->currency),
            journalId: 'jnl-'.(++$this->sequence),
        );
    }
}
