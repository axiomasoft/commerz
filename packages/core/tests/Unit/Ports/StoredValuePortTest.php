<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Data\AppliedTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\TenderInstrument;
use Commerz\Ports\StoredValue\Data\TenderSettlement;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Ports\StoredValue\Internal\NullStoredValueTender;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Stores\Data\ChannelId;
use Packman\Config\Exceptions\InvalidConfiguration;

function svPortQuote(int $subtotalMinor, int $taxMinor): QuoteResult
{
    $currency = Currency::of('USD');
    $subtotal = Money::ofMinor($subtotalMinor, $currency);
    $tax = Money::ofMinor($taxMinor, $currency);
    $total = $subtotal->plus($tax);

    return new QuoteResult(
        context: CommerceContext::for(ChannelId::generate(), $currency),
        lines: [],
        orderAdjustments: [],
        delivery: null,
        deliveryAdjustments: [],
        taxLines: [],
        totals: new QuoteTotals(
            subtotal: $subtotal,
            discountTotal: Money::zero($currency),
            deliveryTotal: Money::zero($currency),
            taxTotal: $tax,
            total: $total,
        ),
        rejections: [],
        codes: [],
        fingerprint: str_repeat('a', 64),
    );
}

it('validates stored-value tender instruments, settlements, and command DTOs', function (): void {
    $customer = CustomerRef::of('user', '42');
    $gc = TenderInstrument::giftCardCode('GC-ABCD-EFGH-2345-6789', Money::of('25.00', 'USD'));
    $wallet = TenderInstrument::walletFor($customer);

    expect($gc->kind)->toBe(StoredValueKind::GiftCard)
        ->and($gc->giftCardCode)->toBe('GC-ABCD-EFGH-2345-6789')
        ->and($wallet->kind)->toBe(StoredValueKind::Wallet)
        ->and((string) $wallet->customer)->toBe('user:42');

    expect(fn () => new TenderInstrument(StoredValueKind::GiftCard))->toThrow(InvalidCommand::class, 'giftCardCode')
        ->and(fn () => new TenderInstrument(StoredValueKind::Wallet))->toThrow(InvalidCommand::class, 'customer')
        ->and(fn () => TenderInstrument::giftCardCode('GC-12345678', Money::zero('USD')))->toThrow(InvalidCommand::class, 'maxAmount')
        ->and(fn () => new HoldStoredValue('acc-1', 'order:1', Money::zero('USD'), 'k1'))->toThrow(InvalidCommand::class, 'amount')
        ->and(fn () => new CaptureStoredValue('hold-1', 'order:1', '', Money::of('1.00', 'USD')))->toThrow(InvalidCommand::class, 'idempotencyKey')
        ->and(fn () => new ReleaseStoredValue('hold-1', 'k1', Money::of('-1.00', 'USD')))->toThrow(InvalidCommand::class, 'amount')
        ->and(fn () => new RefundToStoredValue(Money::of('5.00', 'USD'), 'ref:1', 'k1'))->toThrow(InvalidCommand::class, 'accountId');

    $allocation = new AppliedTender(
        accountId: '01900000-0000-7000-8000-000000000001',
        kind: StoredValueKind::GiftCard,
        amount: Money::of('50.00', 'USD'),
        remainingBalance: Money::of('10.00', 'USD'),
        codeLast4: '6789',
    );

    $settlement = new TenderSettlement(
        grandTotal: Money::of('120.00', 'USD'),
        taxTotal: Money::of('20.00', 'USD'),
        tenderTotal: Money::of('50.00', 'USD'),
        payableTotal: Money::of('70.00', 'USD'),
        allocations: [$allocation],
    );

    expect($settlement->taxTotal->equals(Money::of('20.00', 'USD')))->toBeTrue()
        ->and($settlement->payableTotal->equals(Money::of('70.00', 'USD')))->toBeTrue();

    expect(fn () => new TenderSettlement(
        grandTotal: Money::of('120.00', 'USD'),
        taxTotal: Money::of('20.00', 'USD'),
        tenderTotal: Money::of('130.00', 'USD'),
        payableTotal: Money::zero('USD'),
    ))->toThrow(InvalidCommand::class, 'tenderTotal');
});

it('fails closed on NullStoredValueTender when tenders or mutations are requested, and settles empty tenders cleanly', function (): void {
    $null = new NullStoredValueTender;
    $quote = svPortQuote(10_000, 2_000);

    $settlement = $null->applyToQuote($quote, []);

    expect($settlement->grandTotal->equals(Money::of('120.00', 'USD')))->toBeTrue()
        ->and($settlement->taxTotal->equals(Money::of('20.00', 'USD')))->toBeTrue()
        ->and($settlement->tenderTotal->isZero())->toBeTrue()
        ->and($settlement->payableTotal->equals(Money::of('120.00', 'USD')))->toBeTrue();

    $null->release(new ReleaseStoredValue('hold-1', 'rel-1'));

    expect(fn () => $null->applyToQuote($quote, [TenderInstrument::giftCardCode('GC-ABCD-EFGH')]))
        ->toThrow(InvalidConfiguration::class, 'StoredValueTender')
        ->and(fn () => $null->hold(new HoldStoredValue('acc-1', 'order:1', Money::of('10.00', 'USD'), 'h1')))
        ->toThrow(InvalidConfiguration::class, 'StoredValueTender')
        ->and(fn () => $null->capture(new CaptureStoredValue('hold-1', 'order:1', 'c1')))
        ->toThrow(InvalidConfiguration::class, 'StoredValueTender')
        ->and(fn () => $null->refundCredit(new RefundToStoredValue(Money::of('10.00', 'USD'), 'refund:1', 'r1', customer: CustomerRef::of('user', '1'))))
        ->toThrow(InvalidConfiguration::class, 'StoredValueTender');
});
