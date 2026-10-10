<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Sales\Common\Internal\Lease;
use Commerz\Sales\Common\Internal\SalesCodec;

const CODEC_LINE = '0198a000-0000-7000-8000-000000000001';

function codecRub(int $minor): Money
{
    return Money::ofMinor($minor, 'RUB');
}

it('round-trips a full address and an address with only a country', function (): void {
    $full = new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1', 'apt 2'], 'Ivan Ivanov', '+79990000000');
    $bare = new Address('DE');

    expect(SalesCodec::addressFromJson(SalesCodec::addressToJson($full)))->toEqual($full)
        ->and(SalesCodec::addressFromJson(SalesCodec::addressToJson($bare)))->toEqual($bare)
        ->and(json_decode(SalesCodec::addressToJson($full), true))->toHaveKeys(['country', 'region', 'city', 'postal_code', 'lines', 'recipient', 'phone']);
});

it('keeps unicode in an address readable', function (): void {
    $address = new Address('RU', city: 'Москва', lines: ['ул. Тверская, 1']);

    expect(SalesCodec::addressToJson($address))->toContain('Москва')
        ->and(SalesCodec::addressFromJson(SalesCodec::addressToJson($address)))->toEqual($address);
});

it('round-trips money with its currency, negative amounts included', function (int $minor, string $currency): void {
    $money = Money::ofMinor($minor, $currency);

    expect(SalesCodec::moneyFromJson(SalesCodec::moneyToJson($money)))->toEqual($money);
})->with([[0, 'RUB'], [199_000, 'RUB'], [-500, 'USD'], [PHP_INT_MAX, 'JPY']]);

it('restores the line id of a line adjustment and of a share of an order adjustment from the owning line', function (): void {
    $line = Adjustment::line(CODEC_LINE, codecRub(-1000), 'promotions.line', 'Autumn -10%', 'promo-1', 'AUTUMN');
    $share = Adjustment::order(codecRub(-3000), 'promotions.cart', 'Cart -300')->allocatedTo(CODEC_LINE, codecRub(-1000));
    $list = [$line, $share];

    $decoded = SalesCodec::adjustmentsFromJson(SalesCodec::adjustmentsToJson($list), Currency::of('RUB'), CODEC_LINE);

    expect($decoded)->toEqual($list)
        ->and($decoded[0]->lineId)->toBe(CODEC_LINE)
        ->and($decoded[1]->target)->toBe(AdjustmentTarget::Order)
        ->and($decoded[1]->lineId)->toBe(CODEC_LINE)
        ->and($decoded[0]->promotionId)->toBe('promo-1')
        ->and($decoded[0]->couponCode)->toBe('AUTUMN');
});

it('restores declared order and delivery adjustments without a line id', function (): void {
    $order = [Adjustment::order(codecRub(-3000), 'promotions.cart', 'Cart -300', 'promo-2')];
    $delivery = [Adjustment::delivery(codecRub(-200), 'promotions.shipping', 'Free shipping')];

    expect(SalesCodec::adjustmentsFromJson(SalesCodec::adjustmentsToJson($order), Currency::of('RUB')))->toEqual($order)
        ->and(SalesCodec::adjustmentsFromJson(SalesCodec::adjustmentsToJson($delivery), Currency::of('RUB')))->toEqual($delivery);
});

it('does not give a delivery adjustment a line id even when the list has an owner', function (): void {
    $delivery = [Adjustment::delivery(codecRub(-200), 'promotions.shipping', 'Free shipping')];

    expect(SalesCodec::adjustmentsFromJson(SalesCodec::adjustmentsToJson($delivery), Currency::of('RUB'), CODEC_LINE)[0]->lineId)->toBeNull();
});

it('stores an empty list as an empty JSON list', function (): void {
    expect(SalesCodec::adjustmentsToJson([]))->toBe('[]')
        ->and(SalesCodec::adjustmentsFromJson('[]', Currency::of('RUB')))->toBe([])
        ->and(SalesCodec::taxLinesToJson([]))->toBe('[]')
        ->and(SalesCodec::taxLinesFromJson('[]', Currency::of('RUB')))->toBe([])
        ->and(SalesCodec::stringsToJson([]))->toBe('[]')
        ->and(SalesCodec::stringsFromJson('[]'))->toBe([]);
});

it('round-trips tax lines of a line and of delivery, included and excluded', function (): void {
    $line = [
        new TaxLine(AdjustmentTarget::Line, CODEC_LINE, 'vat', Rate::percent('20'), codecRub(20_000), true),
        new TaxLine(AdjustmentTarget::Line, CODEC_LINE, 'eco', Rate::bp(150), codecRub(300), false),
    ];
    $delivery = [new TaxLine(AdjustmentTarget::Delivery, null, 'vat', Rate::bp(0), codecRub(0), false)];

    expect(SalesCodec::taxLinesFromJson(SalesCodec::taxLinesToJson($line), Currency::of('RUB'), CODEC_LINE))->toEqual($line)
        ->and(SalesCodec::taxLinesFromJson(SalesCodec::taxLinesToJson($delivery), Currency::of('RUB')))->toEqual($delivery);
});

it('round-trips the delivery charge', function (): void {
    $charge = new DeliveryCharge('courier', 'Courier', 'manual', codecRub(35_000), true);

    expect(SalesCodec::deliveryFromJson(SalesCodec::deliveryToJson($charge), Currency::of('RUB')))->toEqual($charge)
        ->and(SalesCodec::deliveryFromJson(SalesCodec::deliveryToJson(new DeliveryCharge('pickup', 'Pickup', 'manual', codecRub(0))), Currency::of('RUB'))->taxIncluded)->toBeFalse();
});

it('round-trips options as an object, empty options included', function (): void {
    expect(SalesCodec::optionsFromJson(SalesCodec::optionsToJson(['Colour' => 'Red', 'Size' => 'M'])))->toBe(['Colour' => 'Red', 'Size' => 'M'])
        ->and(SalesCodec::optionsToJson([]))->toBe('{}')
        ->and(SalesCodec::optionsFromJson('{}'))->toBe([]);
});

it('round-trips lists of strings in order', function (): void {
    expect(SalesCodec::stringsFromJson(SalesCodec::stringsToJson(['SAVE10', 'FREESHIP'])))->toBe(['SAVE10', 'FREESHIP']);
});

it('fails with a LogicException on data that does not decode', function (Closure $decode): void {
    expect($decode)->toThrow(LogicException::class, 'corrupt');
})->with([
    'not JSON' => [fn () => SalesCodec::addressFromJson('{')],
    'address of the wrong shape' => [fn () => SalesCodec::addressFromJson('[]')],
    'address with a lower-case country' => [fn () => SalesCodec::addressFromJson('{"country":"ru"}')],
    'address lines not a list' => [fn () => SalesCodec::addressFromJson('{"country":"RU","lines":{"a":"b"}}')],
    'money without a currency' => [fn () => SalesCodec::moneyFromJson('{"amount_minor":1}')],
    'money with an unknown currency' => [fn () => SalesCodec::moneyFromJson('{"amount_minor":1,"currency":"XXX"}')],
    'adjustments not a list' => [fn () => SalesCodec::adjustmentsFromJson('{"a":1}', Currency::of('RUB'))],
    'adjustment with an unknown target' => [fn () => SalesCodec::adjustmentsFromJson('[{"target":"cart","amount_minor":-1,"source":"s","explanation":"e"}]', Currency::of('RUB'))],
    'line adjustment without an owning line' => [fn () => SalesCodec::adjustmentsFromJson('[{"target":"line","amount_minor":-1,"source":"s","explanation":"e"}]', Currency::of('RUB'))],
    'positive adjustment' => [fn () => SalesCodec::adjustmentsFromJson('[{"target":"order","amount_minor":1,"source":"s","explanation":"e"}]', Currency::of('RUB'))],
    'tax with a string flag' => [fn () => SalesCodec::taxLinesFromJson('[{"target":"delivery","code":"vat","rate_bp":0,"amount_minor":0,"included":"yes"}]', Currency::of('RUB'))],
    'line tax without an owning line' => [fn () => SalesCodec::taxLinesFromJson('[{"target":"line","code":"vat","rate_bp":0,"amount_minor":0,"included":true}]', Currency::of('RUB'))],
    'delivery without a price' => [fn () => SalesCodec::deliveryFromJson('{"method_code":"c","name":"n","carrier":"m","tax_included":false}', Currency::of('RUB'))],
    'option that is not a string' => [fn () => SalesCodec::optionsFromJson('{"Size":1}')],
    'strings with a number' => [fn () => SalesCodec::stringsFromJson('["a",1]')],
]);

it('tells a fresh lease from a lapsed one', function (): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    expect(Lease::isFresh($now, 120))->toBeTrue()
        ->and(Lease::isFresh($now->modify('-119 seconds'), 120))->toBeTrue()
        ->and(Lease::isFresh($now->modify('-121 seconds'), 120))->toBeFalse()
        ->and(Lease::isFresh($now->modify('-30 seconds')->format('Y-m-d H:i:s.uP'), 60))->toBeTrue()
        ->and(Lease::isFresh($now->modify('-61 seconds')->format('Y-m-d H:i:s.uP'), 60))->toBeFalse();
});
