<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FakeStock;

it('restocks limited refs once per reference and ignores unlimited ones', function (): void {
    $stock = new FakeStock;
    $limited = SellableRef::of('fake', 'a');
    $unlimited = SellableRef::of('fake', 'b');
    $stock->limit($limited, 5);
    $command = new RestockStock('return:1', ChannelId::generate(), [new RestockLine($limited, 3), new RestockLine($unlimited, 2)]);

    $stock->restock($command);
    $stock->restock($command);
    $stock->restock(new RestockStock('return:2', $command->channel, [new RestockLine($limited, 1)]));

    expect($stock)->toBeInstanceOf(StockReturns::class)
        ->and($stock->onHand($limited))->toBe(9)
        ->and($stock->onHand($unlimited))->toBeNull()
        ->and($stock->restocked)->toHaveCount(3);
});

it('consumes only from its own location', function (): void {
    $stock = new FakeStock;
    $sellable = SellableRef::of('fake', 'a');
    $stock->limit($sellable, 5)->reserve(new ReserveStock('order:1', ChannelId::generate(), [new ReserveLine($sellable, 2)]));

    expect(fn () => $stock->consume(new ConsumeReservation('order:1', $sellable, 1, 'fulfillment:1', 'elsewhere')))
        ->toThrow(ReservationNotFound::class)
        ->and($stock->held('order:1'))->toBe(['fake:a' => 2]);

    $stock->consume(new ConsumeReservation('order:1', $sellable, 1, 'fulfillment:1', FakeStock::LOCATION));
    $stock->consume(new ConsumeReservation('order:1', $sellable, 1, 'fulfillment:2'));

    expect($stock->onHand($sellable))->toBe(3)
        ->and($stock->consumed)->toHaveCount(2);
});
