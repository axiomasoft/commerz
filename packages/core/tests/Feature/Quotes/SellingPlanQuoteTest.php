<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Sellable\BalanceDueTrigger;
use Commerz\Sellable\PricingPolicy;
use Commerz\Sellable\SellingPlan;
use Commerz\Sellable\SellingPlanInterval;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FakePrices;
use Commerz\Testkit\Fakes\FakeSellables;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class SpQuoteDeliveryAndVatStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.sp_delivery_and_vat';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Tax;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        if ($state->input->delivery !== null) {
            $state = $state->withDelivery(new DeliveryCharge(
                methodCode: $state->input->delivery->methodCode,
                name: 'Standard Freight',
                carrier: 'dhl',
                price: Money::of('20.00', $state->currency()),
                taxIncluded: false,
            ));
        }

        $rate = Rate::percent('20');
        foreach ($state->lines as $line) {
            $net = $state->lineNet($line->lineId);
            $state = $state->withTaxLine(new TaxLine(
                target: AdjustmentTarget::Line,
                lineId: $line->lineId,
                code: 'VAT20',
                rate: $rate,
                amount: $net->percentage($rate, RoundingMode::HalfUp),
                included: false,
            ));
        }

        return $state;
    }
}

final class SpQuoteTestPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.sp-quote', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, new SpQuoteDeliveryAndVatStage);
    }
}

it('calculates 20% dueNowAmount and 80% remainingBalanceAmount before shipment for SellingPlan::Deposit', function (): void {
    config()->set('commerz.plugins', [SpQuoteTestPlugin::class]);
    app()->singleton(PriceResolver::class, static fn (): FakePrices => (new FakePrices)->set(
        SellableRef::of('product', 'espresso-machine'),
        Money::of('250.00', 'EUR'),
    ));

    $depositPlan = SellingPlan::deposit(
        id: 'preorder-20-now-80-before-shipment',
        name: '20% Deposit Now, 80% Before Shipment',
        depositRate: Rate::percent('20'),
        balanceDueTrigger: BalanceDueTrigger::BeforeShipment,
        requiresSavedPaymentMethod: true,
    );

    $context = CommerceContext::for(ChannelId::generate(), Currency::of('EUR'));
    $sellable = FakeSellables::snapshot('product:espresso-machine', Money::of('250.00', 'EUR'));

    // 250.00 item + 20% VAT (50.00) + 20.00 delivery = 320.00 total -> 20% due now (64.00), 80% remaining (256.00)
    $result = app(QuotePipeline::class)->run(new QuoteInput(
        context: $context,
        lines: [new QuoteLineInput('line-1', $sellable, 1, sellingPlan: $depositPlan)],
        delivery: new DeliverySelection(
            'freight',
            new Address('DE', 'DE-BE', 'Berlin', '10115', ['Unter den Linden 1'], 'Ada', '+4900000000'),
            new Parcel(5_000, Money::of('250.00', 'EUR')),
        ),
    ));

    expect($result->total)->toEqual(Money::of('320.00', 'EUR'))
        ->and($result->dueNowAmount)->toEqual(Money::of('64.00', 'EUR'))
        ->and($result->remainingBalanceAmount)->toEqual(Money::of('256.00', 'EUR'))
        ->and($result->totals->dueNowAmount)->toEqual(Money::of('64.00', 'EUR'))
        ->and($result->totals->remainingBalanceAmount)->toEqual(Money::of('256.00', 'EUR'))
        ->and($result->dueNowAmount->plus($result->remainingBalanceAmount))->toEqual($result->total)
        ->and($result->lines[0]->sellingPlan?->balanceDueTrigger)->toBe(BalanceDueTrigger::BeforeShipment);
});

it('partitions mixed one-time and deposit lines accurately and applies recurring Subscribe & Save discounts', function (): void {
    app()->singleton(PriceResolver::class, static fn (): FakePrices => (new FakePrices)
        ->set(SellableRef::of('product', 'mug'), Money::of('30.00', 'USD'))
        ->set(SellableRef::of('product', 'jacket'), Money::of('100.00', 'USD'))
        ->set(SellableRef::of('product', 'beans'), Money::of('40.00', 'USD')));

    $context = CommerceContext::for(ChannelId::generate(), Currency::of('USD'));

    $depositPlan = SellingPlan::deposit(
        id: 'deposit-20',
        name: '20% Deposit',
        depositRate: Rate::percent('20'),
        balanceDueTrigger: BalanceDueTrigger::BeforeShipment,
    );

    $mixedQuote = app(QuotePipeline::class)->run(new QuoteInput(
        context: $context,
        lines: [
            new QuoteLineInput('mug', FakeSellables::snapshot('product:mug', Money::of('30.00', 'USD')), 1),
            new QuoteLineInput('jacket', FakeSellables::snapshot('product:jacket', Money::of('100.00', 'USD')), 1, sellingPlan: $depositPlan),
        ],
    ));

    // Mug 30.00 (100% due now) + Jacket 100.00 (20.00 due now, 80.00 remaining) => total 130.00, dueNow 50.00, remaining 80.00
    expect($mixedQuote->total)->toEqual(Money::of('130.00', 'USD'))
        ->and($mixedQuote->dueNowAmount)->toEqual(Money::of('50.00', 'USD'))
        ->and($mixedQuote->remainingBalanceAmount)->toEqual(Money::of('80.00', 'USD'));

    $recurringPlan = SellingPlan::recurring(
        id: 'beans-monthly-10',
        name: 'Monthly Coffee (10% Off)',
        interval: SellingPlanInterval::Month,
        intervalCount: 1,
        pricingPolicy: PricingPolicy::percentageOff(Rate::percent('10')),
    );

    $recurringQuote = app(QuotePipeline::class)->run(new QuoteInput(
        context: $context,
        lines: [
            new QuoteLineInput('beans', FakeSellables::snapshot('product:beans', Money::of('40.00', 'USD')), 2, sellingPlan: $recurringPlan),
        ],
    ));

    // 40.00 - 10% = 36.00 unitPrice × 2 = 72.00 due now, 0.00 remaining, compareAt = 40.00
    expect($recurringQuote->lines[0]->unitPrice)->toEqual(Money::of('36.00', 'USD'))
        ->and($recurringQuote->lines[0]->compareAt)->toEqual(Money::of('40.00', 'USD'))
        ->and($recurringQuote->total)->toEqual(Money::of('72.00', 'USD'))
        ->and($recurringQuote->dueNowAmount)->toEqual(Money::of('72.00', 'USD'))
        ->and($recurringQuote->remainingBalanceAmount)->toEqual(Money::zero('USD'));

    // Backwards-compatible QuoteTotals constructor defaults
    $legacyTotals = new QuoteTotals(
        Money::of('50.00', 'USD'),
        Money::zero('USD'),
        Money::zero('USD'),
        Money::zero('USD'),
        Money::of('50.00', 'USD'),
    );
    expect($legacyTotals->dueNowAmount)->toEqual(Money::of('50.00', 'USD'))
        ->and($legacyTotals->remainingBalanceAmount)->toEqual(Money::zero('USD'));
});
