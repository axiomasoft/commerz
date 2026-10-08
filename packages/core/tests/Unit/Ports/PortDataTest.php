<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Stores\Data\ChannelId;

function pdtSnapshot(string $id = '1'): SellableSnapshot
{
    return new SellableSnapshot(SellableRef::of('fake', $id), "SKU-{$id}", 'Thing', SellableKind::Physical, true);
}

it('validates stock commands', function (): void {
    $ref = SellableRef::of('fake', '1');

    expect(fn () => new ReserveStock('order:1', ChannelId::generate(), [new ReserveLine($ref, 1), new ReserveLine($ref, 2)]))
        ->toThrow(InvalidCommand::class, 'appears more than once')
        ->and(fn () => new ReserveStock('order:1', ChannelId::generate(), []))->toThrow(InvalidCommand::class, 'must not be empty')
        ->and(fn () => new ReserveStock(' ', ChannelId::generate(), [new ReserveLine($ref, 1)]))->toThrow(InvalidCommand::class, 'demand')
        ->and(fn () => new ReserveLine($ref, 0))->toThrow(InvalidCommand::class, 'at least 1')
        ->and(fn () => new ReleaseReservation('order:1', null, 2))->toThrow(InvalidCommand::class, 'needs a sellable');
});

it('validates sellable snapshots and addresses', function (): void {
    expect(fn () => new SellableSnapshot(SellableRef::of('fake', '1'), '', 'Thing', SellableKind::Service, false))->toThrow(InvalidCommand::class, 'sku')
        ->and(fn () => new SellableSnapshot(SellableRef::of('fake', '1'), 'S', 'Thing', SellableKind::Service, false, true, 'sold out'))
        ->toThrow(InvalidCommand::class, 'unavailableReason')
        ->and(fn () => new SellableSnapshot(SellableRef::of('fake', '1'), 'S', 'Thing', SellableKind::Service, false, basePrice: Money::of('-1.00', 'RUB')))
        ->toThrow(InvalidCommand::class, 'basePrice')
        ->and(fn () => new Address('ru'))->toThrow(InvalidCommand::class, 'country')
        ->and(fn () => new Address('RU', 'KZ-ALA'))->toThrow(InvalidCommand::class, 'region')
        ->and((new Address('RU', 'RU-MOW'))->region)->toBe('RU-MOW');
});

it('validates quote inputs, adjustments and taxes', function (): void {
    $context = CommerceContext::for(ChannelId::generate(), Currency::of('RUB'));
    $rub = Money::of('1.00', 'RUB');

    expect(fn () => new QuoteInput($context, [new QuoteLineInput('a', pdtSnapshot(), 1), new QuoteLineInput('a', pdtSnapshot('2'), 1)]))
        ->toThrow(InvalidCommand::class, 'unique')
        ->and(fn () => new QuoteLineInput('a b', pdtSnapshot(), 1))->toThrow(InvalidCommand::class, 'lineId')
        ->and((new QuoteInput($context, [], [' AUTUMN ', 'AUTUMN', 'X']))->codes)->toBe(['AUTUMN', 'X'])
        ->and(fn () => Adjustment::order($rub, 'p', 'x'))->toThrow(InvalidCommand::class, 'negative')
        ->and(fn () => new Adjustment(AdjustmentTarget::Line, null, $rub->negate(), 'p', 'x'))->toThrow(InvalidCommand::class, 'line id')
        ->and(fn () => Adjustment::line('a', $rub->negate(), 'p', 'x')->allocatedTo('a', $rub->negate()))->toThrow(InvalidCommand::class, 'order-level')
        ->and(fn () => new TaxLine(AdjustmentTarget::Order, null, 'vat', Rate::bp(2000), $rub, false))->toThrow(InvalidCommand::class, 'line or to delivery')
        ->and(fn () => new TaxLine(AdjustmentTarget::Delivery, 'a', 'vat', Rate::bp(2000), $rub, false))->toThrow(InvalidCommand::class, 'line id');
});

it('orders anchors by declaration', function (): void {
    expect(array_map(static fn (QuoteAnchor $a): int => $a->order(), QuoteAnchor::cases()))->toBe([0, 1, 2, 3, 4, 5, 6]);
});
