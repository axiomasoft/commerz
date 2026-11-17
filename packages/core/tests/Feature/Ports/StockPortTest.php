<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Ports\Stock\Internal\UnlimitedStock;
use Commerz\Stores\Data\ChannelId;
use Packman\Config\Exceptions\InvalidConfiguration;

function spLine(string $id = 'a', int $quantity = 1): RestockLine
{
    return new RestockLine(SellableRef::of('variant', $id), $quantity);
}

it('keeps consume without a location valid and checks a given location code', function (): void {
    $sellable = SellableRef::of('variant', 'a');

    expect(new ConsumeReservation('order:1', $sellable, 1, 'fulfillment:1')->locationCode)->toBeNull()
        ->and(new ConsumeReservation('order:1', $sellable, 1, 'fulfillment:1', 'main.wh-1')->locationCode)->toBe('main.wh-1');

    foreach (['', 'Main', '-a', 'a b', str_repeat('a', 65)] as $code) {
        expect(fn () => new ConsumeReservation('order:1', $sellable, 1, 'fulfillment:1', $code))
            ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('stock.invalid_location'));
    }
});

it('builds a restock command and rejects malformed ones', function (): void {
    $channel = ChannelId::generate();
    $command = new RestockStock('return:1', $channel, [spLine('a', 2), spLine('b')], 'wh-1');

    expect($command->locationCode)->toBe('wh-1')
        ->and($command->lines)->toHaveCount(2)
        ->and(new RestockStock('return:1', $channel, [spLine()])->locationCode)->toBeNull();

    $invalid = [
        'stock.invalid_reference' => fn () => new RestockStock('', $channel, [spLine()]),
        'stock.invalid_lines' => fn () => new RestockStock('return:1', $channel, []),
        'stock.invalid_location' => fn () => new RestockStock('return:1', $channel, [spLine()], 'Bad Code'),
        'stock.invalid_quantity' => fn () => spLine('a', 0),
    ];

    foreach ($invalid as $code => $build) {
        expect($build)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe($code));
    }

    expect(fn () => new RestockStock('return:1', $channel, [spLine('a'), spLine('a', 2)]))
        ->toThrow(InvalidCommand::class, 'more than once')
        ->and(fn () => new RestockStock(str_repeat('r', 192), $channel, [spLine()]))->toThrow(InvalidCommand::class);
});

it('restocks nothing with unlimited stock', function (): void {
    config(['commerz.stock' => 'unlimited']);

    expect(app(StockReturns::class))->toBeInstanceOf(UnlimitedStock::class)
        ->and(app(StockReturns::class)->restock(new RestockStock('return:1', ChannelId::generate(), [spLine()])))->toBeNull();
});

it('fails to resolve returns in inventory mode without an inventory package', function (): void {
    expect(fn () => app(StockReturns::class))->toThrow(InvalidConfiguration::class, 'StockReturns');
});
