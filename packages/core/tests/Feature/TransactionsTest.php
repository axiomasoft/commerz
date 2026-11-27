<?php

declare(strict_types=1);

use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Illuminate\Support\Facades\DB;

// No RefreshDatabase: these tests need to start outside any transaction.

function lockTimeout(): string
{
    $row = DB::selectOne("select current_setting('lock_timeout') as value");

    if (! $row instanceof stdClass || ! is_string($row->value)) {
        throw new RuntimeException('Expected lock_timeout.');
    }

    return $row->value;
}

it('opens a transaction with a local lock_timeout and restores it after commit', function (): void {
    $transactions = app(Transactions::class);
    $before = lockTimeout();

    $inside = $transactions->run(fn (): array => [lockTimeout(), DB::transactionLevel(), $transactions->inTransaction()], 250);

    expect($inside)->toBe(['250ms', 1, true])
        ->and(lockTimeout())->toBe($before)
        ->and($transactions->inTransaction())->toBeFalse();
});

it('uses a savepoint in an open transaction while retaining the host settings', function (): void {
    $transactions = app(Transactions::class);

    $inner = $transactions->run(fn (): array => $transactions->run(fn (): array => [lockTimeout(), DB::transactionLevel()], 9000), 400);
    expect($inner)->toBe(['400ms', 2]);

    $host = DB::transaction(fn (): array => $transactions->run(fn (): array => [lockTimeout(), DB::transactionLevel()], 400));
    expect($host[1])->toBe(2)->and($host[0])->not->toBe('400ms');
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

it('recovers the host transaction after PostgreSQL concurrency errors in nested work', function (string $state): void {
    DB::statement('create temporary table tx_deadlock_probe (n int) on commit preserve rows');
    try {
        DB::transaction(function () use ($state): void {
            DB::insert('insert into tx_deadlock_probe values (1)');
            expect(fn () => app(Transactions::class)->run(function () use ($state): void {
                DB::insert('insert into tx_deadlock_probe values (2)');
                $deadlock = match ($state) {
                    '40P01' => "do $$ begin raise exception 'deadlock detected' using errcode = '40P01'; end $$",
                    '40001' => "do $$ begin raise exception 'deadlock detected' using errcode = '40001'; end $$",
                    default => throw new InvalidArgumentException("Unexpected SQLSTATE {$state}."),
                };
                DB::unprepared($deadlock);
            }))->toThrow(PDOException::class);
            DB::insert('insert into tx_deadlock_probe values (3)');
        });
        expect(DB::table('tx_deadlock_probe')->orderBy('n')->pluck('n')->all())->toBe([1, 3]);
    } finally {
        DB::statement('drop table tx_deadlock_probe');
    }
})->with(['deadlock' => ['40P01'], 'serialization' => ['40001']]);
