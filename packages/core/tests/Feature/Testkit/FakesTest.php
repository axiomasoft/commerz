<?php

declare(strict_types=1);

use Commerz\Commerz;
use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeStock;

function fkChannel(): ChannelId
{
    return ChannelId::fromString('0190a000-0000-7000-8000-000000000002');
}

function fkContext(string $currency = 'RUB'): CommerceContext
{
    return CommerceContext::for(fkChannel(), Currency::of($currency));
}

it('swaps every port for its fake, including an already resolved Commerz root', function (): void {
    config(['commerz.stock' => 'unlimited']);
    $before = app(Commerz::class);

    $commerce = Commerce::fake();

    expect(app(StockAvailability::class))->toBe($commerce->stock)
        ->and(app(StockReservations::class))->toBe($commerce->stock)
        ->and(app(PriceResolver::class))->toBe($commerce->prices)
        ->and(app(PromotionUsage::class))->toBe($commerce->promotions)
        ->and(app(DeliveryOptions::class))->toBe($commerce->delivery)
        ->and(app(ShipmentBooking::class))->toBe($commerce->delivery)
        ->and(app(CustomerDirectory::class))->toBe($commerce->customers)
        ->and(app(Commerz::class))->not->toBe($before);

    $commerce->sellables->add($commerce->sellables::snapshot('variant:1', Money::of('5.00', 'RUB')));

    expect(app(Sellables::class)->resolve(SellableRef::of('variant', '1'), fkContext())->sku)->toBe('1')
        ->and(\Commerz\Facades\Commerz::sellables()->resolveMany([SellableRef::of('variant', '1')], fkContext()))->toHaveKey('variant:1')
        ->and(fn () => app(Sellables::class)->resolve(SellableRef::of('variant', '2'), fkContext()))->toThrow(SellableUnavailable::class);
});

it('reserves all lines or none and is idempotent per demand', function (): void {
    $stock = (new FakeStock)->limit($a = SellableRef::of('fake', 'a'), 5);
    $b = SellableRef::of('fake', 'b');

    expect(fn () => $stock->reserve(new ReserveStock('order:1', fkChannel(), [new ReserveLine($b, 100), new ReserveLine($a, 6)])))
        ->toThrow(fn (InsufficientStock $e) => expect([$e->sellable, $e->requested, $e->available])->toBe(['fake:a', 6, 5]));
    expect($stock->held('order:1'))->toBe([]);

    $first = $stock->reserve(new ReserveStock('order:1', fkChannel(), [new ReserveLine($a, 3), new ReserveLine($b, 100)]));
    $again = $stock->reserve(new ReserveStock('order:1', fkChannel(), [new ReserveLine($a, 3), new ReserveLine($b, 100)]));

    expect($again)->toEqual($first)
        ->and($first->lines[0]->allocations[0]->quantity)->toBe(3)
        ->and($first->lines[1]->allocations)->toBe([])
        ->and($stock->available($a))->toBe(2)
        ->and($stock->available($b))->toBeNull()
        ->and($stock->check(new CheckAvailability(fkChannel(), [new AvailabilityLine($a, 3)]))->isSatisfiable())->toBeFalse()
        ->and($stock->reserved)->toHaveCount(3);
});

it('releases and consumes held stock', function (): void {
    $stock = (new FakeStock)->limit($a = SellableRef::of('fake', 'a'), 10);
    $stock->reserve(new ReserveStock('order:1', fkChannel(), [new ReserveLine($a, 6)]));

    $stock->release(new ReleaseReservation('order:1', $a, 2));
    $stock->consume(new ConsumeReservation('order:1', $a, 3, 'fulfillment:1'));

    expect($stock->held('order:1'))->toBe(['fake:a' => 1])
        ->and($stock->onHand($a))->toBe(7)
        ->and($stock->available($a))->toBe(6)
        ->and(fn () => $stock->consume(new ConsumeReservation('order:1', $a, 2, 'fulfillment:2')))->toThrow(ReservationNotFound::class);

    $stock->release(new ReleaseReservation('order:1'));
    $stock->release(new ReleaseReservation('order:404'));

    expect($stock->held('order:1'))->toBe([])->and($stock->available($a))->toBe(7);
});

it('counts promotion usage with limits, idempotently per order', function (): void {
    $usage = Commerce::fake()->promotions->limit('p1', 1);
    $applied = [new AppliedPromotion('p1', 'AUTUMN', Money::of('10.00', 'RUB'))];

    $usage->consume(new ConsumePromotionUsage('order:1', null, $applied));
    $usage->consume(new ConsumePromotionUsage('order:1', null, $applied));

    expect($usage->uses('p1'))->toBe(1)
        ->and(fn () => $usage->consume(new ConsumePromotionUsage('order:2', null, $applied)))
        ->toThrow(fn (UsageLimitReached $e) => expect([$e->code(), $e->couponCode])->toBe(['promotions.usage_limit_reached', 'AUTUMN']));

    $usage->restore(new RestorePromotionUsage('order:1', RestoreReason::Cancelled));
    $usage->restore(new RestorePromotionUsage('order:1', RestoreReason::Cancelled));
    $usage->consume(new ConsumePromotionUsage('order:2', CustomerRef::of('user', '1'), $applied));

    expect($usage->uses('p1'))->toBe(1)->and($usage->restored)->toHaveCount(1)->and($usage->consumed)->toHaveKeys(['order:2']);
});

it('offers delivery in the request currency and books with tracking numbers', function (): void {
    $delivery = Commerce::fake()->delivery->offer(
        new DeliveryOption('courier', 'Courier', Money::of('300.00', 'RUB'), 'manual', 2),
        new DeliveryOption('courier', 'Courier', Money::of('5.00', 'USD'), 'manual'),
    );
    $request = new DeliveryRequest(fkContext(), new Address('RU', 'RU-MOW'), new Parcel(500, Money::of('100.00', 'RUB')));

    expect($delivery->options($request))->toHaveCount(1)
        ->and($delivery->book(new BookShipment('fulfillment:1', 'manual', 'courier', $request->destination, $request->parcel))->tracking)->toBe('FAKE-1')
        ->and($delivery->bookings)->toHaveCount(1);
});

it('finds customers it was given', function (): void {
    $customers = Commerce::fake()->customers->add(new CustomerProfile(CustomerRef::of('user', '1'), ['vip', 'vip'], taxExempt: true));

    expect($customers->find(CustomerRef::of('user', '1'))?->groups)->toBe(['vip'])
        ->and($customers->find(CustomerRef::of('user', '2')))->toBeNull();
});
