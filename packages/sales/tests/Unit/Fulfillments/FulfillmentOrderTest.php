<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Fulfillments\Data\CreateFulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrderId;
use Commerz\Sales\Fulfillments\Data\ListFulfillmentOrders;
use Commerz\Sales\Fulfillments\Data\ReassignFulfillmentOrder;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Commerz\Sales\Fulfillments\Exceptions\InvalidFulfillmentOrderState;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Testkit\Fakes\FrozenClock;

it('manages the FulfillmentOrder aggregate lifecycle and enforces state transitions', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-26T10:00:00+00:00'));
    $orderId = OrderId::generate();
    $lineA = new LineQuantity(OrderLineId::generate(), 2);
    $lineB = new LineQuantity(OrderLineId::generate(), 1);
    $constraints = new FulfillmentConstraints(coldChain: true, mustShipTogether: true, requiredLocationTags: ['pharma']);

    $fo = FulfillmentOrder::assign($orderId, 'wh-cold-1', [$lineA, $lineB], $constraints, $clock->now());

    expect($fo->status)->toBe(FulfillmentOrderStatus::Open)
        ->and($fo->locationCode)->toBe('wh-cold-1')
        ->and($fo->constraints)->toEqual($constraints)
        ->and($fo->totalQuantity())->toBe(3)
        ->and($fo->fulfillment)->toBeNull();

    // Hold, reassign while on hold, release hold, start picking, then fulfill.
    $clock->advance('60 seconds');
    $held = $fo->hold($clock->now());
    expect($held->status)->toBe(FulfillmentOrderStatus::OnHold);

    $clock->advance('60 seconds');
    $reassigned = $held->reassign('wh-cold-2', $clock->now());
    expect($reassigned->locationCode)->toBe('wh-cold-2')
        ->and($reassigned->assignedAt)->toEqual($clock->now());

    $clock->advance('60 seconds');
    $reopened = $reassigned->releaseHold($clock->now());
    expect($reopened->status)->toBe(FulfillmentOrderStatus::Open);

    $clock->advance('60 seconds');
    $started = $reopened->start($clock->now());
    expect($started->status)->toBe(FulfillmentOrderStatus::InProgress);

    // Cannot reassign once warehouse started picking.
    expect(fn () => $started->reassign('wh-cold-3', $clock->now()))
        ->toThrow(InvalidFulfillmentOrderState::class, 'reassign');

    $fulfillmentId = FulfillmentId::generate();
    $clock->advance('120 seconds');
    $fulfilled = $started->markFulfilled($clock->now(), $fulfillmentId);

    expect($fulfilled->status)->toBe(FulfillmentOrderStatus::Fulfilled)
        ->and($fulfilled->fulfillment?->equals($fulfillmentId))->toBeTrue()
        ->and($fulfilled->fulfilledAt)->toEqual($clock->now())
        ->and($fulfilled->status->isTerminal())->toBeTrue();

    // Terminal state rejects further transitions.
    expect(fn () => $fulfilled->cancel($clock->now()))->toThrow(InvalidFulfillmentOrderState::class)
        ->and(fn () => $fulfilled->start($clock->now()))->toThrow(InvalidFulfillmentOrderState::class);

    // Idempotent same-state transition returns unchanged aggregate.
    expect($fulfilled->markFulfilled($clock->now()))->toBe($fulfilled);

    // Cancellation path from Open.
    $cancelled = $fo->cancel($clock->now());
    expect($cancelled->status)->toBe(FulfillmentOrderStatus::Cancelled)
        ->and($cancelled->cancelledAt)->toEqual($clock->now())
        ->and(fn () => $cancelled->start($clock->now()))->toThrow(InvalidFulfillmentOrderState::class);
});

it('rejects malformed FulfillmentOrder commands', function (): void {
    $orderId = OrderId::generate();
    $line = new LineQuantity(OrderLineId::generate(), 1);

    expect(fn () => new CreateFulfillmentOrder($orderId, '', [$line]))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateFulfillmentOrder($orderId, 'Bad Warehouse', [$line]))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateFulfillmentOrder($orderId, 'wh-1', [$line, $line]))->toThrow(InvalidCommand::class, 'each line once')
        ->and(fn () => new ReassignFulfillmentOrder(FulfillmentOrderId::generate(), ''))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListFulfillmentOrders(locationCode: 'Bad Space'))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListFulfillmentOrders(limit: 0))->toThrow(InvalidCommand::class);
});

it('leaves writes of fulfillment_orders tables exclusively to FulfillmentOrderRows', function (): void {
    $src = dirname(__DIR__, 3).'/src';
    $writers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        $writes = array_any(
            explode(';', $code),
            static fn (string $statement): bool => preg_match('/->(update|increment|decrement|upsert|insert)\w*\(|\b(update|insert\s+into)\s+\S+/i', $statement) === 1
                && preg_match("/table\\('fulfillment_order(s|_lines)'\\)|\\bcmz_fulfillment_order(s|_lines)\\b/", $statement) === 1,
        );

        if ($writes) {
            $writers[] = substr($file->getPathname(), strlen($src) + 1);
        }
    }

    expect($writers)->toBe(['Fulfillments/Internal/FulfillmentOrderRows.php']);
});
