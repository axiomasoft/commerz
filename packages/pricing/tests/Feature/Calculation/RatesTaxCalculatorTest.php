<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Pricing\Calculation\Internal\RatesTaxCalculator;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneNotFound;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Taxes/Helpers.php';

function rcItem(string $base, bool $included = false, string $class = 'standard', string $lineId = '1'): TaxableItem
{
    return new TaxableItem(AdjustmentTarget::Line, $lineId, SellableRef::of('variant', 'tee'), $class, 1, Money::of($base, 'RUB'), $included);
}

/** @param  list<TaxableItem>  $items */
function rcCalculate(array $items, ?Address $to = new Address('RU'), string $at = '2026-06-01 12:00:00'): TaxResult
{
    $context = CommerceContext::for(ChannelId::generate(), Currency::of('RUB'), at: txAt($at));

    return app(RatesTaxCalculator::class)->calculate(new TaxRequest($context, $to, $items));
}

/** @return list<string> `lineId|code|bp|amount` of every tax, in the order given */
function rcTaxes(TaxResult $result): array
{
    return array_map(
        static fn (TaxAmount $tax): string => ($tax->lineId ?? $tax->target->value).'|'.$tax->code.'|'.$tax->rate->basisPoints.'|'.$tax->amount->toDecimal(),
        $result->amounts,
    );
}

/** @param array<string, string|null> $tax */
function rcReconfigure(array $tax): void
{
    $current = config('commerz-pricing.tax');
    if (! is_array($current)) {
        throw new RuntimeException('commerz-pricing.tax must be an array.');
    }

    config()->set('commerz-pricing.tax', [...$current, ...$tax]);
    app()->forgetInstance(PricingOptions::class);
}

it('is the contributed `rates` calculator', function (): void {
    $calculator = app(RatesTaxCalculator::class);

    expect($calculator->key())->toBe('rates');
});

it('takes the tax out of a price that holds it and splits it between the taxes (G7)', function (): void {
    txClass();
    txZone();
    txRate(bp: 500, code: 'gst');
    txRate(bp: 700, code: 'pst');

    // 11200 × 1200 / 11200 = 1200 minor: 5.00 and 7.00.
    expect(rcTaxes(rcCalculate([rcItem('112.00', included: true)])))->toBe(['1|gst|500|5.00', '1|pst|700|7.00']);
});

it('adds the taxes on top of a net price (G8)', function (): void {
    txClass();
    txZone();
    txRate(bp: 500, code: 'gst');
    txRate(bp: 700, code: 'pst');

    expect(rcTaxes(rcCalculate([rcItem('100.00')])))->toBe(['1|gst|500|5.00', '1|pst|700|7.00']);
});

it('takes the rate in force at the moment of the context (G10)', function (): void {
    txClass();
    txZone();
    txRate(bp: 2000, until: txAt('2026-01-01'));
    txRate(bp: 2200, from: txAt('2026-01-01'));

    expect(rcTaxes(rcCalculate([rcItem('100.00')], at: '2025-12-31 23:59:59')))->toBe(['1|vat|2000|20.00'])
        ->and(rcTaxes(rcCalculate([rcItem('100.00')], at: '2026-01-01 00:00:00')))->toBe(['1|vat|2200|22.00'])
        ->and(rcTaxes(rcCalculate([rcItem('100.00', included: true)], at: '2026-01-01 00:00:00')))->toBe(['1|vat|2200|18.03']);
});

it('taxes nothing outside the tax zones (G12)', function (): void {
    txClass();
    txZone();
    txRate();

    expect(rcCalculate([rcItem('100.00')], new Address('DE'))->amounts)->toBe([])
        ->and(rcCalculate([rcItem('100.00')], to: null)->amounts)->toBe([]);
});

it('fails closed when a class has no rate in the zone (G13)', function (): void {
    txClass();
    txClass('reduced');
    txZone();
    txRate();

    try {
        rcCalculate([rcItem('100.00'), rcItem('50.00', class: 'reduced', lineId: '2')]);
        Assert::fail('The missing rate was taxed as zero.');
    } catch (TaxRateNotFound $e) {
        expect($e->code())->toBe('pricing.tax_rate_not_found')
            ->and($e->context())->toBe(['tax_class' => 'reduced', 'zone' => 'ru', 'at' => '2026-06-01T12:00:00+00:00']);
    }

    // The same when the only rate of the class has ended.
    txRate(class: 'reduced', bp: 1000, until: txAt('2026-01-01'));

    expect(fn () => rcCalculate([rcItem('50.00', class: 'reduced')]))->toThrow(TaxRateNotFound::class)
        ->and(fn () => rcCalculate([rcItem('50.00', class: 'undefined')]))->toThrow(TaxRateNotFound::class);
});

it('prefers the zone of the region over the zone of the country (G14)', function (): void {
    txClass();
    txZone('ru', [new TaxZoneMember('RU')]);
    txZone('moscow', [new TaxZoneMember('RU', 'RU-MOW')]);
    txRate(zone: 'ru', bp: 2200);
    txRate(zone: 'moscow', bp: 1000);

    expect(rcTaxes(rcCalculate([rcItem('100.00')], new Address('RU', 'RU-MOW'))))->toBe(['1|vat|1000|10.00'])
        ->and(rcTaxes(rcCalculate([rcItem('100.00')], new Address('RU', 'RU-SPE'))))->toBe(['1|vat|2200|22.00'])
        ->and(rcTaxes(rcCalculate([rcItem('100.00')], new Address('RU'))))->toBe(['1|vat|2200|22.00']);
});

it('uses the default zone when the quote has no address', function (): void {
    txClass();
    txZone();
    txRate();

    rcReconfigure(['default_zone' => 'ru']);

    expect(rcTaxes(rcCalculate([rcItem('100.00')], to: null)))->toBe(['1|vat|2200|22.00'])
        // An address wins over the default zone, even when it leads nowhere.
        ->and(rcCalculate([rcItem('100.00')], new Address('DE'))->amounts)->toBe([]);

    rcReconfigure(['default_zone' => 'missing']);

    expect(fn () => rcCalculate([rcItem('100.00')], to: null))->toThrow(TaxZoneNotFound::class);
});

it('returns zero amounts for a zero rate, set on purpose', function (): void {
    txClass();
    txClass('books');
    txZone();
    txRate(class: 'books', bp: 0);
    txRate(bp: 0, code: 'vat');
    txRate(bp: 0, code: 'extra');

    expect(rcTaxes(rcCalculate([rcItem('100.00', class: 'books')])))->toBe(['1|vat|0|0.00'])
        ->and(rcTaxes(rcCalculate([rcItem('100.00', included: true, class: 'books')])))->toBe(['1|vat|0|0.00'])
        ->and(rcTaxes(rcCalculate([rcItem('100.00', included: true)])))->toBe(['1|extra|0|0.00', '1|vat|0|0.00']);
});

it('gives the odd minor unit of an included tax to the lowest tax code, whatever the insertion order', function (): void {
    txClass();
    txZone();
    txRate(bp: 100, code: 'b');
    txRate(bp: 100, code: 'a');

    // 50 × 200 / 10200 = 0.98 rounds to 1 minor unit; the tie of the split goes to `a`.
    expect(rcTaxes(rcCalculate([rcItem('0.50', included: true)])))->toBe(['1|a|100|0.01', '1|b|100|0.00']);
});

it('rounds by the configured mode', function (): void {
    txClass();
    txZone();
    txRate(bp: 1000);

    expect(rcTaxes(rcCalculate([rcItem('0.05')])))->toBe(['1|vat|1000|0.01']);

    rcReconfigure(['rounding' => 'half_even']);

    expect(rcTaxes(rcCalculate([rcItem('0.05')])))->toBe(['1|vat|1000|0.00']);
});

it('taxes every line and the delivery by the rates of its own class in one call', function (): void {
    txClass();
    txClass('reduced');
    txZone();
    txRate(bp: 2200);
    txRate(class: 'reduced', bp: 1000);

    $delivery = new TaxableItem(AdjustmentTarget::Delivery, null, null, 'standard', 1, Money::of('300.00', 'RUB'), false);

    $result = rcCalculate([rcItem('1000.00'), rcItem('50.00', class: 'reduced', lineId: '2'), $delivery]);

    expect(rcTaxes($result))->toBe(['1|vat|2200|220.00', '2|vat|1000|5.00', 'delivery|vat|2200|66.00'])
        ->and($result->amounts[2]->target)->toBe(AdjustmentTarget::Delivery)
        ->and($result->amounts[2]->lineId)->toBeNull();
});
