<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Outbox\Internal\OutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

final class RecordingConsumer implements OutboxConsumer
{
    /** @var list<IntegrationEvent> */
    public array $received = [];

    public bool $failing = false;

    public function key(): string
    {
        return 'test.recording';
    }

    public function consume(IntegrationEvent $event): void
    {
        if ($this->failing) {
            throw new RuntimeException('crm is down');
        }

        $this->received[] = $event;
    }
}

function recordEvent(IntegrationEvent $event): void
{
    app(Transactions::class)->run(fn () => app(Outbox::class)->record($event));
}

function consumer(): RecordingConsumer
{
    app()->singletonIf(RecordingConsumer::class);
    app()->tag([RecordingConsumer::class], OutboxRelay::CONSUMER_TAG);

    return app(RecordingConsumer::class);
}

afterEach(fn () => Uuid7::reset());

it('validates integration events', function (): void {
    $event = IntegrationEvent::of('sales.customer_registered', CustomerRef::of('user', '42'), ['total' => 100], occurredAt: new DateTimeImmutable('2026-10-08 12:00:00+03:00'));

    expect($event->eventId)->toMatch('/\A[0-9a-f-]{36}\z/')
        ->and($event->aggregateRef)->toBe('user:42')
        ->and($event->occurredAt->format('c'))->toBe('2026-10-08T09:00:00+00:00');

    expect(fn () => IntegrationEvent::of('OrderPlaced', 'order:1', []))->toThrow(InvalidCommand::class)
        ->and(fn () => IntegrationEvent::of('sales.order_placed', 'order', []))->toThrow(InvalidCommand::class)
        ->and(fn () => IntegrationEvent::of('sales.order_placed', 'order:1', [], version: 0))->toThrow(InvalidCommand::class);
});

it('records an event inside the transaction', function (): void {
    Uuid7::fixed('0190a0a0-0000-7000-8000-00000000000a');
    recordEvent(IntegrationEvent::of('sales.order_placed', 'order:7', ['total' => 1.0, 'lines' => [1, 2]], version: 2, occurredAt: new DateTimeImmutable('2026-10-08T10:00:00Z')));

    $row = DB::table('cmz_outbox_messages')->first();

    expect($row->event_id)->toBe('0190a0a0-0000-7000-8000-00000000000a')
        ->and($row->type)->toBe('sales.order_placed')
        ->and($row->version)->toBe(2)
        ->and($row->aggregate_ref)->toBe('order:7')
        ->and(json_decode($row->payload, true))->toBe(['lines' => [1, 2], 'total' => 1.0])
        ->and($row->attempts)->toBe(0)
        ->and($row->delivered_at)->toBeNull();
});

it('relays to listeners and consumers once', function (): void {
    Event::fake([IntegrationEventDelivered::class]);
    $consumer = consumer();
    recordEvent(IntegrationEvent::of('sales.order_placed', 'order:1', ['n' => 1]));
    recordEvent(IntegrationEvent::of('sales.order_paid', 'order:1', []));

    $report = app(OutboxRelay::class)->relay(100);
    $again = app(OutboxRelay::class)->relay(100);

    expect([$report->claimed, $report->delivered, $report->failed])->toBe([2, 2, 0])
        ->and($again->claimed)->toBe(0)
        ->and(array_map(fn (IntegrationEvent $e): string => $e->type, $consumer->received))->toBe(['sales.order_placed', 'sales.order_paid'])
        ->and($consumer->received[0]->payload)->toBe(['n' => 1])
        ->and(DB::table('cmz_outbox_messages')->whereNull('delivered_at')->count())->toBe(0);

    Event::assertDispatchedTimes(IntegrationEventDelivered::class, 2);
});

it('reschedules a failed delivery with backoff and delivers it later', function (): void {
    $consumer = consumer();
    $consumer->failing = true;
    recordEvent(IntegrationEvent::of('sales.order_placed', 'order:1', []));

    $failed = app(OutboxRelay::class)->relay(10);
    $row = DB::table('cmz_outbox_messages')->first();

    expect([$failed->claimed, $failed->delivered, $failed->failed])->toBe([1, 0, 1])
        ->and($row->attempts)->toBe(1)
        ->and($row->delivered_at)->toBeNull()
        ->and($row->last_error)->toBe('RuntimeException: crm is down')
        ->and(DB::scalar("select available_at - now() = interval '30 seconds' from cmz_outbox_messages"))->toBeTrue()
        ->and(app(OutboxRelay::class)->relay(10)->claimed)->toBe(0);

    DB::update("update cmz_outbox_messages set available_at = now() - interval '1 second'");
    $consumer->failing = false;

    $this->artisan('commerz:outbox:relay', ['--once' => true])->assertSuccessful()->expectsOutputToContain('1 delivered, 0 failed');

    $row = DB::table('cmz_outbox_messages')->first();
    expect($row->attempts)->toBe(2)->and($row->delivered_at)->not->toBeNull()->and($row->last_error)->toBeNull()
        ->and($consumer->received)->toHaveCount(1);
});

it('caps the retry backoff at one hour', function (): void {
    expect(array_map(OutboxRelay::backoffSeconds(...), [1, 2, 3, 7, 8, 50]))->toBe([30, 60, 120, 1920, 3600, 3600]);
});

it('rejects a bad batch size', function (): void {
    $this->artisan('commerz:outbox:relay', ['--once' => true, '--batch' => 0])->assertFailed();
});
