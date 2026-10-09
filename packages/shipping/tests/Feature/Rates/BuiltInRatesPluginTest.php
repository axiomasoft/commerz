<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Carriers\Internal\ManualCarrier;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Rates\Internal\FlatRate;
use Commerz\Shipping\Rates\Internal\RateCodec;
use Commerz\Shipping\Rates\Internal\WeightTableRate;

it('contributes the built-in rates and the manual carrier under their keys', function (): void {
    $contributions = app(Contributions::class);

    expect($contributions->keys(DeliveryRate::KIND))->toBe(['flat', 'weight_table'])
        ->and($contributions->keys(Carrier::KIND))->toBe(['manual'])
        ->and($contributions->get(DeliveryRate::KIND, 'flat', DeliveryRate::class))->toBeInstanceOf(FlatRate::class)
        ->and($contributions->get(DeliveryRate::KIND, 'weight_table', DeliveryRate::class))->toBeInstanceOf(WeightTableRate::class)
        ->and($contributions->get(Carrier::KIND, 'manual', Carrier::class))->toBeInstanceOf(ManualCarrier::class);
});

it('registers each key once: the built-ins agree with their own key()', function (): void {
    $contributions = app(Contributions::class);

    foreach ([DeliveryRate::KIND => DeliveryRate::class, Carrier::KIND => Carrier::class] as $kind => $interface) {
        foreach ($contributions->keys($kind) as $key) {
            expect($contributions->get($kind, $key, $interface)->key())->toBe($key);
        }
    }
});

it('encodes and decodes the built-in rates through the codec', function (): void {
    $codec = app(RateCodec::class);
    $scope = new RateScope(Currency::of('RUB'));

    $flat = $codec->encode(new RateSpec('flat', ['amount' => Money::of('300.00', 'RUB')]), $scope, 'rates.0.rate');
    $table = $codec->encode(new RateSpec('weight_table', ['steps' => [
        ['up_to_grams' => 1000, 'amount' => Money::of('300.00', 'RUB')],
        ['up_to_grams' => null, 'amount' => Money::of('900.00', 'RUB')],
    ]]), $scope, 'rates.1.rate');

    expect([$flat->key, $flat->version])->toBe(['flat', 1])
        ->and([$table->key, $table->version])->toBe(['weight_table', 1]);

    $loadedFlat = $codec->decode($flat, $scope);
    $loadedTable = $codec->decodeRow($table->key, $table->version, json_decode(json_encode($table->params->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), $scope);

    expect($loadedFlat->isSupported())->toBeTrue()
        ->and($loadedFlat->rate)->toBeInstanceOf(FlatRate::class)
        ->and($loadedFlat->params)->toEqual($flat->params)
        ->and($loadedTable->isSupported())->toBeTrue()
        ->and($loadedTable->rate)->toBeInstanceOf(WeightTableRate::class)
        ->and($loadedTable->params)->toEqual($table->params);
});

it('reads a stored built-in rate as unsupported once the tariff currency no longer matches', function (): void {
    $codec = app(RateCodec::class);
    $stored = $codec->encode(new RateSpec('flat', ['amount' => Money::of('300.00', 'RUB')]), new RateScope(Currency::of('RUB')), 'rates.0.rate');

    $loaded = $codec->decode($stored, new RateScope(Currency::of('USD')));

    expect($loaded->isSupported())->toBeFalse()->and($loaded->reason)->toContain('must be in USD');
});
