<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Facades\Commerz;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Plugins\PricingPlugin;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

trait TsEnvironment
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [TsPlugin::class]);
    }
}

uses(RefreshDatabase::class, TsEnvironment::class);

require_once __DIR__.'/../Taxes/Helpers.php';

/** Adds the line, order and delivery discounts and delivery charge given by the presented codes. */
final readonly class TsCodeStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.tax-codes';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Delivery;
    }

    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    public function apply(QuoteState $state): QuoteState
    {
        foreach ($state->input->codes as $code) {
            $parts = explode(':', $code);
            $state = match ($parts[0]) {
                'order' => $state->withAdjustment(Adjustment::order(Money::of($parts[1], $state->currency())->negate(), $this->key(), $code)),
                'line' => $state->withAdjustment(Adjustment::line($parts[1], Money::of($parts[2], $state->currency())->negate(), $this->key(), $code)),
                'delivery_discount' => $state->withAdjustment(Adjustment::delivery(Money::of($parts[1], $state->currency())->negate(), $this->key(), $code)),
                'ship' => $state->withDelivery(new DeliveryCharge('courier', 'Courier', 'manual', Money::of($parts[1], $state->currency()), ($parts[2] ?? '') === 'included')),
                default => $state,
            };
        }

        return $state;
    }
}

/** A calculator that taxes more than it was asked about. */
final class TsJunkCalculator implements TaxCalculator
{
    public function key(): string
    {
        return 'test.junk';
    }

    public function calculate(TaxRequest $request): TaxResult
    {
        return new TaxResult([new TaxAmount($request->items[0]->target, 'ghost', 'vat', Rate::bp(2200), Money::of('1.00', 'RUB'))]);
    }
}

final class TsPlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.tax-stage', '1.0.0', [CorePlugin::ID, PricingPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, TsCodeStage::class);
        $registrar->contribute(TaxCalculator::KIND, TsJunkCalculator::class);
    }
}

/**
 * @param  list<string>  $prices  unit price of each line; line ids are 1, 2, …
 * @param  list<string>  $codes
 */
function tsInput(array $prices, array $codes = [], ?CommerceContext $context = null, ?DeliverySelection $delivery = null, int $quantity = 1): QuoteInput
{
    $context ??= CommerceContext::for(ChannelId::fromString('0190a000-0000-7000-8000-000000000001'), Currency::of('RUB'), 'ru', txAt('2026-10-08 12:00:00'));
    $lines = [];

    foreach ($prices as $index => $price) {
        $id = (string) ($index + 1);
        $lines[] = new QuoteLineInput($id, FakeSellables::snapshot("item-{$id}", Money::of($price, 'RUB')), $quantity);
    }

    return new QuoteInput($context, $lines, $codes, $delivery);
}

function tsRun(QuoteInput $input): QuoteResult
{
    return Commerz::quotes()->run($input);
}

/** @return list<string> `lineId|code|bp|amount|included` of every tax line */
function tsTaxes(QuoteResult $result): array
{
    return array_map(
        static fn (TaxLine $tax): string => ($tax->lineId ?? $tax->target->value).'|'.$tax->code.'|'.$tax->rate->basisPoints.'|'.$tax->amount->toDecimal().'|'.($tax->included ? 'in' : 'ex'),
        $result->taxLines,
    );
}

function tsReconfigure(array $tax): void
{
    config()->set('commerz-pricing.tax', [...config('commerz-pricing.tax'), ...$tax]);
    app()->forgetInstance(PricingOptions::class);
}

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    tsReconfigure(['default_zone' => 'ru']);
    txClass();
    txZone();
});

it('taxes a net line on top (G1)', function (): void {
    txRate();
    $result = tsRun(tsInput(['1000.00'], quantity: 3));

    expect(tsTaxes($result))->toBe(['1|vat|2200|660.00|ex'])
        ->and($result->totals->taxTotal->toDecimal())->toBe('660.00')
        ->and($result->totals->total->toDecimal())->toBe('3660.00');
});

it('takes the tax out of a gross line (G2)', function (): void {
    txRate();
    $this->commerce->prices->taxIncluded = true;
    $result = tsRun(tsInput(['1220.00']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|220.00|in'])
        ->and($result->totals->total->toDecimal())->toBe('1220.00');
});

it('rounds the included part to the minor unit (G3)', function (): void {
    txRate();
    $this->commerce->prices->taxIncluded = true;
    $result = tsRun(tsInput(['99.99']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|18.03|in'])
        ->and($result->totals->total->toDecimal())->toBe('99.99');
});

it('taxes each gross line on its own share after an order discount (G4)', function (): void {
    txRate();
    $this->commerce->prices->taxIncluded = true;
    $result = tsRun(tsInput(['1000.00', '500.00'], ['order:150.00']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|162.30|in', '2|vat|2200|81.15|in'])
        ->and($result->totals->taxTotal->toDecimal())->toBe('243.45')
        ->and($result->totals->total->toDecimal())->toBe('1350.00');
});

it('spreads the odd minor unit of an order discount to the first line (G5)', function (): void {
    txRate();
    $this->commerce->prices->taxIncluded = true;
    $result = tsRun(tsInput(['100.00', '100.00', '100.00'], ['order:100.00']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|12.02|in', '2|vat|2200|12.02|in', '3|vat|2200|12.02|in'])
        ->and($result->totals->taxTotal->toDecimal())->toBe('36.06')
        ->and($result->totals->total->toDecimal())->toBe('200.00');
});

it('taxes the delivery charge by its own class (G6)', function (): void {
    txRate();
    $result = tsRun(tsInput(['1000.00'], ['ship:300.00']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|220.00|ex', 'delivery|vat|2200|66.00|ex'])
        ->and($result->totals->total->toDecimal())->toBe('1586.00');
});

it('takes the tax out of a delivery price that holds it', function (): void {
    txRate();
    $result = tsRun(tsInput(['1000.00'], ['ship:122.00:included']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|220.00|ex', 'delivery|vat|2200|22.00|in'])
        ->and($result->totals->total->toDecimal())->toBe('1342.00');
});

it('leaves delivery untaxed without a delivery class', function (): void {
    txRate();
    tsReconfigure(['delivery_class' => null]);
    $result = tsRun(tsInput(['1000.00'], ['ship:300.00']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|220.00|ex'])
        ->and($result->totals->total->toDecimal())->toBe('1520.00');
});

it('splits a gross line between several taxes (G7)', function (): void {
    txRate(bp: 500, code: 'gst');
    txRate(bp: 700, code: 'pst');
    $this->commerce->prices->taxIncluded = true;

    expect(tsTaxes(tsRun(tsInput(['112.00']))))->toBe(['1|gst|500|5.00|in', '1|pst|700|7.00|in']);
});

it('adds several taxes on top of a net line (G8)', function (): void {
    txRate(bp: 500, code: 'gst');
    txRate(bp: 700, code: 'pst');
    $result = tsRun(tsInput(['100.00']));

    expect(tsTaxes($result))->toBe(['1|gst|500|5.00|ex', '1|pst|700|7.00|ex'])
        ->and($result->totals->total->toDecimal())->toBe('112.00');
});

it('adds no tax for a tax-exempt buyer (G9)', function (): void {
    txRate();
    $customer = CustomerRef::of('user', '7');
    $this->commerce->customers->add(new CustomerProfile($customer, taxExempt: true));
    $context = CommerceContext::for(ChannelId::fromString('0190a000-0000-7000-8000-000000000001'), Currency::of('RUB'), 'ru', txAt('2026-10-08 12:00:00'))->withCustomer($customer);

    $net = tsRun(tsInput(['1000.00'], context: $context));
    $this->commerce->prices->taxIncluded = true;
    $gross = tsRun(tsInput(['1220.00'], context: $context));

    expect(tsTaxes($net))->toBe([])
        ->and($net->totals->total->toDecimal())->toBe('1000.00')
        ->and(tsTaxes($gross))->toBe([])
        ->and($gross->totals->total->toDecimal())->toBe('1220.00');
});

it('keeps taxing a buyer the directory does not know', function (): void {
    txRate();
    $context = CommerceContext::for(ChannelId::fromString('0190a000-0000-7000-8000-000000000001'), Currency::of('RUB'), 'ru', txAt('2026-10-08 12:00:00'))
        ->withCustomer(CustomerRef::of('user', '8'));

    expect(tsTaxes(tsRun(tsInput(['1000.00'], context: $context))))->toBe(['1|vat|2200|220.00|ex']);
});

it('uses the rate in force at the moment of the quote (G10)', function (): void {
    txRate(bp: 2000, until: txAt('2026-01-01 00:00:00'));
    txRate(bp: 2200, from: txAt('2026-01-01 00:00:00'));

    $context = fn (string $at): CommerceContext => CommerceContext::for(ChannelId::fromString('0190a000-0000-7000-8000-000000000001'), Currency::of('RUB'), 'ru', txAt($at));

    expect(tsTaxes(tsRun(tsInput(['100.00'], context: $context('2025-12-31 23:59:59')))))->toBe(['1|vat|2000|20.00|ex'])
        ->and(tsTaxes(tsRun(tsInput(['100.00'], context: $context('2026-01-01 00:00:00')))))->toBe(['1|vat|2200|22.00|ex']);
});

it('rounds half up by default (G11)', function (): void {
    txRate(bp: 1000);

    expect(tsTaxes(tsRun(tsInput(['0.05']))))->toBe(['1|vat|1000|0.01|ex']);
});

it('rounds half to even when configured (G11)', function (): void {
    txRate(bp: 1000);
    tsReconfigure(['rounding' => 'half_even']);

    expect(tsTaxes(tsRun(tsInput(['0.05']))))->toBe(['1|vat|1000|0.00|ex']);
});

it('adds no tax outside every tax zone (G12)', function (): void {
    tsReconfigure(['default_zone' => null]);
    $to = new DeliverySelection('courier', new Address('KZ'), new Parcel(500, Money::of('10.00', 'RUB')));

    expect(tsTaxes(tsRun(tsInput(['100.00'], delivery: $to))))->toBe([])
        ->and(tsTaxes(tsRun(tsInput(['100.00']))))->toBe([]);
});

it('fails the quote for a tax class without a rate in the zone (G13)', function (): void {
    txClass('reduced');
    tsReconfigure(['default_class' => 'reduced']);

    try {
        tsRun(tsInput(['100.00']));
        $this->fail('expected QuoteFailed');
    } catch (QuoteFailed $failed) {
        expect($failed->stage)->toBe('tax')
            ->and($failed->reason)->toBe('stage_error')
            ->and($failed->context()['cause'])->toBe('pricing.tax_rate_not_found');
    }
});

it('prefers the zone of the delivery region over the country zone (G14)', function (): void {
    txRate();
    txZone('moscow', [new TaxZoneMember('RU', 'RU-MOW')]);
    txRate(zone: 'moscow', bp: 1000);
    $to = new DeliverySelection('courier', new Address('RU', 'RU-MOW'), new Parcel(500, Money::of('10.00', 'RUB')));
    $elsewhere = new DeliverySelection('courier', new Address('RU', 'RU-SPE'), new Parcel(500, Money::of('10.00', 'RUB')));

    expect(tsTaxes(tsRun(tsInput(['100.00'], delivery: $to))))->toBe(['1|vat|1000|10.00|ex'])
        ->and(tsTaxes(tsRun(tsInput(['100.00'], delivery: $elsewhere))))->toBe(['1|vat|2200|22.00|ex']);
});

it('rejects a calculator answer for an item that was not asked about', function (): void {
    txRate();
    tsReconfigure(['calculator' => 'test.junk']);

    try {
        tsRun(tsInput(['100.00']));
        $this->fail('expected QuoteFailed');
    } catch (QuoteFailed $failed) {
        expect($failed->stage)->toBe('tax')
            ->and($failed->context()['cause'])->toBe('pricing.invalid_tax_result');
    }
});

it('adds no tax lines to an empty quote', function (): void {
    txRate();
    $result = tsRun(tsInput([]));

    expect(tsTaxes($result))->toBe([])
        ->and($result->totals->total->toDecimal())->toBe('0.00');
});

it('fails with negative_total when the order discount exceeds the items', function (): void {
    txRate();
    try {
        tsRun(tsInput(['100.00'], ['order:150.00']));
        $this->fail('expected QuoteFailed');
    } catch (QuoteFailed $failed) {
        expect($failed->stage)->toBe('tax')->and($failed->reason)->toBe('negative_total');
    }
});

it('reports a negative line before attempting to allocate an order discount', function (): void {
    txRate();

    try {
        tsRun(tsInput(['10.00', '100.00'], ['line:1:11.00', 'order:1.00']));
        $this->fail('expected QuoteFailed');
    } catch (QuoteFailed $failed) {
        expect([$failed->stage, $failed->reason])->toBe(['tax', 'negative_line']);
    }
});

it('taxes the bases after line, order and delivery discounts', function (): void {
    txRate();
    $result = tsRun(tsInput(['100.00'], ['line:1:20.00', 'order:30.00', 'ship:100.00', 'delivery_discount:40.00']));

    expect(tsTaxes($result))->toBe(['1|vat|2200|11.00|ex', 'delivery|vat|2200|13.20|ex'])
        ->and($result->lines[0]->total->toDecimal())->toBe('61.00')
        ->and($result->totals->deliveryTotal->toDecimal())->toBe('60.00')
        ->and($result->totals->total->toDecimal())->toBe('134.20');
});
