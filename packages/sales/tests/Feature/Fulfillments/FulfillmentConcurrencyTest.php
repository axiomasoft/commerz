<?php

declare(strict_types=1);

use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\ShipFulfillment;
use Commerz\Sales\Orders\Data\CancelOrderLines;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Tests\Fixtures\StaticSellables;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

// C6 of knowledge/sales-design#tests across processes: the same units fulfilled at once, a fulfillment racing the
// cancellation of the same units, and one document shipped twice. Committed data, emptied after each test.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    slsCommitted();
    StaticSellables::define(StaticSellables::item('a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['test:a' => 2]));
    $this->line = slsLine($this->order, 'test:a');
});

afterEach(function (): void {
    StaticSellables::reset();
    slsTruncate();
});

/**
 * @param  list<string>  $outcomes
 * @return array<string, int>
 */
function fucTally(array $outcomes): array
{
    $tally = array_count_values($outcomes);
    ksort($tally);

    return $tally;
}

it('lets one of the fulfillments of the same units through (C6)', function (): void {
    $order = $this->order->id;
    $line = $this->line;

    $outcomes = Concurrency::run(4, static fn (): string => slsOutcome(static function () use ($order, $line): string {
        slsFulfillments()->create(new CreateFulfillment($order, [new LineQuantity($line, 2)]));

        return 'created';
    }), timeoutSeconds: 60);

    expect(fucTally($outcomes))->toBe(['created' => 1, 'sales.quantity_exceeds_outstanding' => 3])
        ->and(DB::table('cmz_fulfillments')->count())->toBe(1)
        ->and(DB::table('cmz_fulfillment_lines')->sum('quantity'))->toBe(2)
        ->and(slsOrders()->find($order)->line($line)?->fulfilledQuantity)->toBe(2);
})->group('concurrency');

it('fulfils or cancels the same units, never both', function (): void {
    $order = $this->order->id;
    $line = $this->line;

    $outcomes = Concurrency::run(2, static fn (int $worker): string => slsOutcome(static function () use ($order, $line, $worker): string {
        if ($worker === 0) {
            slsFulfillments()->create(new CreateFulfillment($order, [new LineQuantity($line, 2)]));

            return 'fulfilled';
        }

        slsOrders()->cancelLines(new CancelOrderLines($order, [new LineQuantity($line, 2)], CancelReason::Customer));

        return 'cancelled';
    }), timeoutSeconds: 60);

    $view = slsOrders()->find($order)->line($line);

    expect(count(array_filter($outcomes, static fn (string $outcome): bool => in_array($outcome, ['fulfilled', 'cancelled'], true))))->toBe(1)
        ->and($view === null ? 0 : $view->fulfilledQuantity + $view->cancelledQuantity)->toBe(2)
        ->and(DB::table('cmz_fulfillments')->count())->toBe(in_array('fulfilled', $outcomes, true) ? 1 : 0);
})->group('concurrency');

it('ships one document once however many operators ship it', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity($this->line, 2)]));
    $id = $created->id;

    $outcomes = Concurrency::run(4, static fn (): string => slsOutcome(static function () use ($id): string {
        slsFulfillments()->ship(new ShipFulfillment($id, 'manual', 'RB-1'));

        return 'shipped';
    }), timeoutSeconds: 60);

    expect(fucTally($outcomes))->toBe(['shipped' => 4])
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.fulfillment_shipped')->count())->toBe(1);
})->group('concurrency');
