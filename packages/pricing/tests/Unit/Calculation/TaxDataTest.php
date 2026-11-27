<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxAmount;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Calculation\Data\TaxResult;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Stores\Data\ChannelId;

function taxContext(string $currency = 'RUB'): CommerceContext
{
    return CommerceContext::for(ChannelId::generate(), Currency::of($currency));
}

/** @param array<string, mixed> $args */
function taxableItem(array $args): TaxableItem
{
    $target = $args['target'] ?? null;
    $lineId = $args['lineId'] ?? null;
    $sellable = $args['sellable'] ?? null;
    $taxClass = $args['taxClass'] ?? null;
    $quantity = $args['quantity'] ?? null;
    $base = $args['base'] ?? null;
    $included = $args['included'] ?? null;

    if (! $target instanceof AdjustmentTarget
        || ($lineId !== null && ! is_string($lineId))
        || ($sellable !== null && ! $sellable instanceof SellableRef)
        || ! is_string($taxClass)
        || ! is_int($quantity)
        || ! $base instanceof Money
        || ! is_bool($included)
    ) {
        throw new InvalidArgumentException('Taxable item fields have the wrong type.');
    }

    return new TaxableItem($target, $lineId, $sellable, $taxClass, $quantity, $base, $included);
}

/** @param array<string, mixed> $extra */
function lineItem(string $lineId = '1', string $base = '100.00', array $extra = []): TaxableItem
{
    return taxableItem([
        'target' => AdjustmentTarget::Line,
        'lineId' => $lineId,
        'sellable' => SellableRef::of('variant', '7'),
        'taxClass' => 'standard',
        'quantity' => 1,
        'base' => Money::of($base, 'RUB'),
        'included' => false,
        ...$extra,
    ]);
}

/** @param array<string, mixed> $extra */
function deliveryItem(array $extra = []): TaxableItem
{
    return taxableItem([
        'target' => AdjustmentTarget::Delivery,
        'lineId' => null,
        'sellable' => null,
        'taxClass' => 'standard',
        'quantity' => 1,
        'base' => Money::of('300.00', 'RUB'),
        'included' => false,
        ...$extra,
    ]);
}

it('builds a line item and a delivery item and keys them', function (): void {
    expect(lineItem('a1')->key())->toBe('line|a1')
        ->and(deliveryItem()->key())->toBe('delivery|')
        ->and(lineItem(extra: ['sellable' => null, 'base' => Money::zero('RUB')])->base->isZero())->toBeTrue();
});

it('rejects a malformed item', function (callable $call, string $field): void {
    expect($call)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('pricing.invalid_tax_item')->and($e->field)->toBe($field));
})->with([
    'order target' => [fn () => lineItem(extra: ['target' => AdjustmentTarget::Order, 'lineId' => null]), 'target'],
    'line without id' => [fn () => lineItem(extra: ['lineId' => null]), 'lineId'],
    'line with empty id' => [fn () => lineItem(extra: ['lineId' => '']), 'lineId'],
    'delivery with line id' => [fn () => deliveryItem(['lineId' => '1']), 'lineId'],
    'delivery with sellable' => [fn () => deliveryItem(['sellable' => SellableRef::of('variant', '7')]), 'lineId'],
    'zero quantity' => [fn () => lineItem(extra: ['quantity' => 0]), 'quantity'],
    'negative base' => [fn () => lineItem(extra: ['base' => Money::ofMinor(-1, 'RUB')]), 'base'],
]);

it('checks the tax class of an item', function (): void {
    expect(fn () => lineItem(extra: ['taxClass' => 'Standard']))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('pricing.invalid_code')->and($e->field)->toBe('taxClass'));
});

it('builds a request of distinct items in the context currency', function (): void {
    $request = new TaxRequest(taxContext(), null, [lineItem('1'), lineItem('2'), deliveryItem()]);

    expect($request->items)->toHaveCount(3)->and($request->destination)->toBeNull();
});

it('rejects a malformed request', function (callable $call): void {
    expect($call)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('pricing.invalid_tax_request')->and($e->field)->toBe('items'));
})->with([
    'no items' => [fn () => new TaxRequest(taxContext(), null, [])],
    'foreign currency' => [fn () => new TaxRequest(taxContext('EUR'), null, [lineItem()])],
    'duplicate line' => [fn () => new TaxRequest(taxContext(), null, [lineItem('1'), lineItem('1')])],
    'two deliveries' => [fn () => new TaxRequest(taxContext(), null, [deliveryItem(), deliveryItem()])],
]);

it('builds a tax amount for a line and for delivery and keys it like its item', function (): void {
    $line = new TaxAmount(AdjustmentTarget::Line, '1', 'vat', Rate::bp(2200), Money::of('22.00', 'RUB'));
    $delivery = new TaxAmount(AdjustmentTarget::Delivery, null, 'vat', Rate::bp(0), Money::zero('RUB'));

    expect($line->itemKey())->toBe(lineItem('1')->key())
        ->and($delivery->itemKey())->toBe(deliveryItem()->key());
});

it('rejects a malformed tax amount', function (callable $call, string $field): void {
    expect($call)->toThrow(fn (InvalidCommand $e) => expect($e->field)->toBe($field));
})->with([
    'order target' => [fn () => new TaxAmount(AdjustmentTarget::Order, null, 'vat', Rate::bp(1), Money::zero('RUB')), 'target'],
    'line without id' => [fn () => new TaxAmount(AdjustmentTarget::Line, null, 'vat', Rate::bp(1), Money::zero('RUB')), 'lineId'],
    'delivery with id' => [fn () => new TaxAmount(AdjustmentTarget::Delivery, '1', 'vat', Rate::bp(1), Money::zero('RUB')), 'lineId'],
    'bad code' => [fn () => new TaxAmount(AdjustmentTarget::Line, '1', 'VAT', Rate::bp(1), Money::zero('RUB')), 'code'],
    'negative amount' => [fn () => new TaxAmount(AdjustmentTarget::Line, '1', 'vat', Rate::bp(1), Money::ofMinor(-1, 'RUB')), 'amount'],
]);

it('wraps a list of tax amounts', function (): void {
    $amount = new TaxAmount(AdjustmentTarget::Line, '1', 'vat', Rate::bp(2200), Money::of('22.00', 'RUB'));

    expect((new TaxResult([]))->amounts)->toBe([])
        ->and((new TaxResult([$amount]))->amounts)->toBe([$amount]);
});
