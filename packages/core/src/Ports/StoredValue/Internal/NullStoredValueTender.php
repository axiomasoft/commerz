<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Internal;

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
use Commerz\Quotes\Data\QuoteResult;
use Override;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * No-op default when `axiomasoft/commerz-stored-value` (or a host adapter) is not installed.
 *
 * Empty tender applications settle cleanly to zero tender; any attempt to apply, hold, capture, or refund stored value
 * fails closed rather than silently dropping tender liability.
 */
#[Internal]
final readonly class NullStoredValueTender implements StoredValueTender
{
    /** @param  list<TenderInstrument>  $tenders */
    #[Override]
    public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement
    {
        if ($tenders !== []) {
            throw InvalidConfiguration::fromProblems([
                'commerz: stored-value tenders were presented, but no package implements StoredValueTender (install axiomasoft/commerz-stored-value)',
            ]);
        }

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
        throw InvalidConfiguration::fromProblems([
            "commerz: order '{$command->orderRef}' requested a stored-value hold, but no package implements StoredValueTender (install axiomasoft/commerz-stored-value)",
        ]);
    }

    #[Override]
    public function capture(CaptureStoredValue $command): StoredValueCapture
    {
        throw InvalidConfiguration::fromProblems([
            "commerz: hold '{$command->holdId}' requested capture, but no package implements StoredValueTender (install axiomasoft/commerz-stored-value)",
        ]);
    }

    #[Override]
    public function release(ReleaseStoredValue $command): void {}

    #[Override]
    public function refundCredit(RefundToStoredValue $command): StoredValueCredit
    {
        throw InvalidConfiguration::fromProblems([
            "commerz: refund '{$command->reference}' requested stored-value credit, but no package implements StoredValueTender (install axiomasoft/commerz-stored-value)",
        ]);
    }
}
