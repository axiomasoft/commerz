<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Exchanges\Contracts\Exchanges;
use Commerz\Sales\Exchanges\Data\ClaimItem;
use Commerz\Sales\Exchanges\Data\CompleteOrderExchange;
use Commerz\Sales\Exchanges\Data\CreateOrderClaim;
use Commerz\Sales\Exchanges\Data\CreateOrderExchange;
use Commerz\Sales\Exchanges\Data\ExchangeOutboundItem;
use Commerz\Sales\Exchanges\Enums\ClaimKind;
use Commerz\Sales\Exchanges\Enums\ExchangeStatus;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeStock;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

it('runs OrderExchange and OrderClaim with PostgreSQL constraints enabled', function (): void {
    $commerce = Commerce::fake();
    $commerce->sellables->add(
        slsSnapshot('fake:a', '100.00'),
        slsSnapshot('fake:b', '150.00'),
    );
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $channel = slsChannel();
    $order = slsPlace(slsCartWith($channel, ['fake:a' => 2]));
    $lineA = slsLine($order, 'fake:a');
    slsFulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity($lineA, 2)], locationCode: FakeStock::LOCATION));

    $exchanges = app(Exchanges::class);
    $exchange = $exchanges->createExchange(new CreateOrderExchange(
        order: $order->id,
        inboundLines: [new LineQuantity($lineA, 1)],
        outboundItems: [new ExchangeOutboundItem(SellableRef::fromString('fake:b'), 1)],
        reason: ReturnReason::SizeOrFit,
    ));
    $completed = $exchanges->completeExchange(new CompleteOrderExchange(
        exchange: $exchange->id,
        disposition: ReturnDisposition::DamagedWriteOff,
        returnLocationCode: FakeStock::LOCATION,
        fulfillmentLocationCode: FakeStock::LOCATION,
    ));

    $claim = $exchanges->createClaim(new CreateOrderClaim(
        order: $order->id,
        items: [ClaimItem::orderLine($lineA, 1)],
        kind: ClaimKind::Reshipment,
        reason: ReturnReason::DamagedInTransit,
        locationCode: FakeStock::LOCATION,
    ));

    expect($completed->status)->toBe(ExchangeStatus::Completed)
        ->and($claim->invoiceTotal->toDecimal())->toBe('0.00');
});
