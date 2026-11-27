<?php

declare(strict_types=1);

use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Commerce;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

// ORD-03: the ledger of a line moves only through OrderLedger's conditional updates; CHECK constraints are the second line.

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'), slsSnapshot('fake:b', '30.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 3, 'fake:b' => 1]));
    $this->line = slsLine($this->order, 'fake:a');
});

/** @return array{int, int, int} cancelled, fulfilled, returned */
function ldgCounters(OrderView $order, OrderLineId $line): array
{
    $view = slsOrders()->find($order->id)->line($line);

    if ($view === null) {
        throw new RuntimeException('Expected the order line.');
    }

    return [$view->cancelledQuantity, $view->fulfilledQuantity, $view->returnedQuantity];
}

it('cancels, fulfills and returns units of a line', function (): void {
    slsLedger()->cancel($this->order->id, new LineQuantity($this->line, 1));
    slsLedger()->fulfill($this->order->id, new LineQuantity($this->line, 2));
    slsLedger()->returnReceived($this->order->id, new LineQuantity($this->line, 1));

    expect(ldgCounters($this->order, $this->line))->toBe([1, 2, 1])
        ->and(slsOrders()->find($this->order->id)->line($this->line)?->outstanding())->toBe(0)
        ->and(ldgCounters($this->order, slsLine($this->order, 'fake:b')))->toBe([0, 0, 0]);
});

it('refuses more units than are outstanding and names what is left', function (): void {
    slsLedger()->fulfill($this->order->id, new LineQuantity($this->line, 2));

    try {
        slsLedger()->cancel($this->order->id, new LineQuantity($this->line, 2));
        Assert::fail('cancelled fulfilled units');
    } catch (QuantityExceedsOutstanding $e) {
        expect([$e->lineId, $e->requested, $e->available, $e->code()])->toBe([$this->line->value, 2, 1, 'sales.quantity_exceeds_outstanding']);
    }

    expect(fn () => slsLedger()->fulfill($this->order->id, new LineQuantity($this->line, 2)))->toThrow(QuantityExceedsOutstanding::class)
        ->and(ldgCounters($this->order, $this->line))->toBe([0, 2, 0]);
});

it('refuses to return more than was fulfilled', function (): void {
    slsLedger()->fulfill($this->order->id, new LineQuantity($this->line, 1));

    expect(fn () => slsLedger()->returnReceived($this->order->id, new LineQuantity($this->line, 2)))
        ->toThrow(QuantityExceedsOutstanding::class, 'has 1 units left')
        ->and(ldgCounters($this->order, $this->line))->toBe([0, 1, 0]);
});

it('finds no line that is unknown or belongs to another order', function (): void {
    $other = slsPlace(slsCartWith(slsChannel('shop'), ['fake:a' => 1]));

    expect(fn () => slsLedger()->cancel($this->order->id, new LineQuantity(OrderLineId::generate(), 1)))->toThrow(LineNotFound::class)
        ->and(fn () => slsLedger()->cancel($other->id, new LineQuantity($this->line, 1)))->toThrow(LineNotFound::class, "Order line '{$this->line->value}' was not found")
        ->and(ldgCounters($this->order, $this->line))->toBe([0, 0, 0]);
});

it('keeps the constraints of the ledger in the database as the second line', function (string $column, string $constraint): void {
    $lines = app(TableNames::class)->table('order_lines');

    expect(fn () => DB::transaction(fn () => DB::table($lines)->where('id', $this->line->value)->update([$column => 4])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'cancelled and fulfilled within the quantity' => ['cancelled_quantity', 'ledger_within_quantity'],
    'returned within the fulfilled' => ['returned_quantity', 'returned_within_fulfilled'],
]);
