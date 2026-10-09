<?php

declare(strict_types=1);

// Golden examples G11–G13 and the quote side of G15 and G17 of knowledge/shipping-design#golden, through the quote
// pipeline with the fixture order-discount stage (After promotions.cart).

use Commerz\Money\Money;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Shipping\Methods\Data\SetMethodRates;
use Commerz\Shipping\Options\Exceptions\DeliveryUnavailable;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, OpEnvironment::class);

beforeEach(function (): void {
    $this->golden = opGolden();
    $this->web = $this->golden['channel'];
    Commerce::fake();
});

/** The quote's failure as `stage reason cause verdict`. */
function opQuoteFailure(Closure $quote): string
{
    try {
        $quote();
    } catch (QuoteFailed $failed) {
        $cause = $failed->getPrevious();

        return "{$failed->stage} {$failed->reason} {$failed->context()['cause']} ".($cause instanceof DeliveryUnavailable ? $cause->verdict : '-');
    }

    return 'no failure';
}

it('prices the selected method on the goods after the order discount (G11)', function (): void {
    mtMethods()->setRates(new SetMethodRates(
        $this->golden['methods']['courier']->id,
        opCourierRates($this->golden['zones'], ['freeAbove' => Money::of('2500.00', 'RUB')]),
    ));

    $discounted = opQuote($this->web, ['1500.00', '1500.00'], codes: ['order:600.00']);
    $full = opQuote($this->web, ['1500.00', '1500.00']);

    expect($discounted->delivery?->price->toDecimal())->toBe('250.00')
        ->and($discounted->totals->total->toDecimal())->toBe('2650.00')
        ->and($full->delivery?->price->toDecimal())->toBe('0.00');
});

it('ignores the parcel value the caller declared', function (): void {
    expect(opQuote($this->web, ['1000.00'], declaredValue: '99999.00')->delivery?->price->toDecimal())->toBe('250.00');
});

it('takes the weight from the selection', function (): void {
    expect(opQuote($this->web, ['1000.00'], region: 'RU-SPE', grams: 1001)->delivery?->price->toDecimal())->toBe('500.00');
});

it('fails the quote when the selected method cannot deliver (G12)', function (string $method, string $region, int $grams, string $verdict): void {
    expect(opQuoteFailure(fn () => opQuote($this->web, ['1000.00'], $method, $region, $grams)))
        ->toBe("delivery method_unavailable shipping.delivery_unavailable {$verdict}");
})->with([
    'disabled' => ['post', 'RU-MOW', 800, 'method_disabled'],
    'unknown code' => ['nope', 'RU-MOW', 800, 'method_not_found'],
    'not in the channel' => ['express', 'RU-MOW', 800, 'not_in_channel'],
    'too heavy' => ['courier', 'RU-SPE', 5001, 'weight_out_of_range'],
]);

it('charges no delivery when no line requires shipping (G13)', function (): void {
    $quote = opQuote($this->web, ['1000.00'], physical: false);

    expect($quote->delivery)->toBeNull()
        ->and($quote->rejections)->toEqual([new Rejection('delivery', 'delivery_not_required', 'no line of the quote requires shipping')])
        ->and($quote->totals->total->toDecimal())->toBe('1000.00');
});

it('leaves a quote without a delivery selection alone', function (): void {
    $quote = opQuote($this->web, ['1000.00'], method: null);

    expect($quote->delivery)->toBeNull()->and($quote->rejections)->toBe([]);
});

it('carries tax_included of the tariff into the charge (G15)', function (): void {
    expect(opQuote($this->web, ['1000.00'])->delivery?->taxIncluded)->toBeFalse();

    mtMethods()->setRates(new SetMethodRates($this->golden['methods']['courier']->id, opCourierRates($this->golden['zones'], ['taxIncluded' => true])));

    expect(opQuote($this->web, ['1000.00'])->delivery?->taxIncluded)->toBeTrue();
});

it('names the charge in the context locale (G17)', function (): void {
    $en = opQuote($this->web, ['1000.00'], locale: 'en')->delivery;
    $ru = opQuote($this->web, ['1000.00'], locale: 'ru')->delivery;

    expect([$en?->name, $en?->carrier, $en?->methodCode])->toBe(['Courier', 'manual', 'courier'])
        ->and($ru?->name)->toBe('Курьер');
});
