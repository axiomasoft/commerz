<?php

declare(strict_types=1);

use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Illuminate\Support\Facades\Event;

// No RefreshDatabase: these tests need to start outside any transaction.

final class CommittedEventsProbe
{
    public function __construct(public readonly string $name) {}
}

it('dispatches only after the surrounding transaction commits', function (): void {
    $seen = [];
    Event::listen(CommittedEventsProbe::class, function (CommittedEventsProbe $event) use (&$seen): void {
        $seen[] = $event->name;
    });
    $inside = null;

    app(Transactions::class)->run(function () use (&$seen, &$inside): void {
        app(CommittedEvents::class)->dispatch(new CommittedEventsProbe('a'));
        $inside = $seen;
    });

    expect($inside)->toBe([])->and($seen)->toBe(['a']);
});

it('drops the event when the transaction rolls back', function (): void {
    $seen = [];
    Event::listen(CommittedEventsProbe::class, function (CommittedEventsProbe $event) use (&$seen): void {
        $seen[] = $event->name;
    });

    expect(fn () => app(Transactions::class)->run(function (): void {
        app(CommittedEvents::class)->dispatch(new CommittedEventsProbe('lost'));

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom')
        ->and($seen)->toBe([]);
});

it('dispatches at once outside a transaction', function (): void {
    $seen = [];
    Event::listen(CommittedEventsProbe::class, function (CommittedEventsProbe $event) use (&$seen): void {
        $seen[] = $event->name;
    });

    app(CommittedEvents::class)->dispatch(new CommittedEventsProbe('now'));

    expect($seen)->toBe(['now']);
});
