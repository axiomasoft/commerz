<?php

declare(strict_types=1);

use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Facades\Sales as SalesFacade;
use Commerz\Sales\Fulfillments\Contracts\FulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Commerz\Sales\Fulfillments\Internal\DatabaseFulfillmentOrders;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Time\Contracts\Clock;

it('binds FulfillmentOrders in the Laravel container and exposes it through the Sales facade', function (): void {
    $service = app(FulfillmentOrders::class);

    expect($service)->toBeInstanceOf(DatabaseFulfillmentOrders::class)
        ->and(SalesFacade::fulfillmentOrders())->toBe($service);

    $aggregate = FulfillmentOrder::assign(
        order: OrderId::generate(),
        locationCode: 'wh-cold-eu',
        lines: [new LineQuantity(OrderLineId::generate(), 4)],
        constraints: new FulfillmentConstraints(coldChain: true, requiredLocationTags: ['pharma']),
        assignedAt: app(Clock::class)->now(),
    );

    expect($aggregate->status)->toBe(FulfillmentOrderStatus::Open)
        ->and($aggregate->constraints->coldChain)->toBeTrue()
        ->and($aggregate->locationCode)->toBe('wh-cold-eu');
});
