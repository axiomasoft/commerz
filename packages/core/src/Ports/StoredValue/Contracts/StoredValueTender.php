<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Contracts;

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
use Commerz\Ports\StoredValue\Exceptions\StoredValueUnavailable;
use Commerz\Quotes\Data\QuoteResult;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Stored-value payment tender port (gift cards and customer store-credit wallets).
 *
 * Stored value is ALWAYS deducted from `grandTotal` AFTER taxes are calculated — gift cards and store credit are
 * payment tenders and NEVER reduce the taxable VAT base.
 *
 * Ledger mutations (`hold`, `capture`, `release`, `refundCredit`) join the caller's database transaction on the
 * Commerz connection (or open their own), enforce `balance_minor - held_minor >= 0` (`INV-SV-001`) atomically,
 * and are idempotent by `idempotencyKey`.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface StoredValueTender
{
    /**
     * Computes how much of the settled quote's post-tax `grandTotal` (`$quote->totals->total`) can be paid using the
     * presented stored-value tenders without altering subtotal, discounts, or tax lines.
     *
     * @param  list<TenderInstrument>  $tenders
     */
    public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement;

    /**
     * Places an idempotent hold against available stored value (`balance_minor - held_minor >= amount`).
     *
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     * @throws InsufficientStoredValue
     */
    public function hold(HoldStoredValue $command): StoredValueHold;

    /**
     * Captures all or part of an existing hold into a final debit. Idempotent per `$command->idempotencyKey`.
     *
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     * @throws InsufficientStoredValue
     */
    public function capture(CaptureStoredValue $command): StoredValueCapture;

    /**
     * Releases all or part of an existing hold back to available balance. Idempotent per `$command->idempotencyKey`.
     * Releasing an already-released hold is a no-op.
     *
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     */
    public function release(ReleaseStoredValue $command): void;

    /**
     * Credits a stored-value account (or customer store-credit wallet) for a refund. Idempotent per `$command->idempotencyKey`.
     *
     * @throws StoredValueNotFound
     * @throws StoredValueUnavailable
     */
    public function refundCredit(RefundToStoredValue $command): StoredValueCredit;
}
