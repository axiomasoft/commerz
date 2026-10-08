<?php

declare(strict_types=1);

use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Illuminate\Support\Facades\DB;

// No RefreshDatabase: these tests need to start outside any transaction.

function lockTimeout(): string
{
    return DB::selectOne("select current_setting('lock_timeout') as value")->value;
}

it('opens a transaction with a local lock_timeout and restores it after commit', function (): void {
    $transactions = app(Transactions::class);
    $before = lockTimeout();

    $inside = $transactions->run(fn (): array => [lockTimeout(), DB::transactionLevel(), $transactions->inTransaction()], 250);

    expect($inside)->toBe(['250ms', 1, true])
        ->and(lockTimeout())->toBe($before)
        ->and($transactions->inTransaction())->toBeFalse();
});

it('joins an open transaction instead of nesting one', function (): void {
    $transactions = app(Transactions::class);

    $inner = $transactions->run(fn (): array => $transactions->run(fn (): array => [lockTimeout(), DB::transactionLevel()], 9000), 400);
    expect($inner)->toBe(['400ms', 1]);

    $host = DB::transaction(fn (): array => $transactions->run(fn (): array => [lockTimeout(), DB::transactionLevel()], 400));
    expect($host[1])->toBe(1)->and($host[0])->not->toBe('400ms');
});

it('rolls back when the work throws and rethrows unchanged', function (): void {
    $transactions = app(Transactions::class);
    DB::statement('create temporary table tx_probe (n int) on commit preserve rows');

    try {
        expect(fn () => $transactions->run(function (): void {
            DB::insert('insert into tx_probe values (1)');

            throw new DomainException('boom');
        }))->toThrow(DomainException::class, 'boom');

        expect(DB::scalar('select count(*) from tx_probe'))->toBe(0)
            ->and(DB::transactionLevel())->toBe(0);
    } finally {
        DB::statement('drop table tx_probe');
    }
});

it('rejects a negative lock timeout', function (): void {
    expect(fn () => app(Transactions::class)->run(fn () => null, -1))->toThrow(InvalidArgumentException::class);
});

it('refuses to record an outbox event outside a transaction', function (): void {
    expect(fn () => app(Outbox::class)->record(IntegrationEvent::of('sales.order_placed', 'order:1', [])))
        ->toThrow(LogicException::class, 'needs an open transaction');
});
