<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Fingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{0: int, 1: array{total: int}} */
function placeOnce(string $fingerprint, int &$runs, int $total = 100): array
{
    $result = app(OperationReceipts::class)->once(
        'sales.place_order',
        IdempotencyKey::of('checkout-42'),
        $fingerprint,
        function () use (&$runs, $total): array {
            $runs++;

            return ['total' => $total];
        },
        fn (array $result): array => $result,
        fn (array $stored): array => ['total' => $stored['total'], 'replayed' => true],
    );

    return [$runs, $result];
}

it('runs the work once and replays the stored result for the same fingerprint', function (): void {
    $runs = 0;
    $fingerprint = Fingerprint::of(['cart' => 'c1', 'version' => 3]);

    [, $first] = placeOnce($fingerprint, $runs);
    [, $second] = placeOnce($fingerprint, $runs, total: 999);

    expect($runs)->toBe(1)
        ->and($first)->toBe(['total' => 100])
        ->and($second)->toBe(['total' => 100, 'replayed' => true]);

    $row = DB::table('cmz_operation_receipts')->first();
    expect($row->scope)->toBe('sales.place_order')
        ->and($row->key)->toBe('checkout-42')
        ->and($row->fingerprint)->toBe($fingerprint)
        ->and(json_decode($row->result, true))->toBe(['total' => 100]);
});

it('rejects the same key with a different payload', function (): void {
    $runs = 0;
    placeOnce(Fingerprint::of(['cart' => 'c1']), $runs);

    expect(fn () => placeOnce(Fingerprint::of(['cart' => 'c2']), $runs))
        ->toThrow(function (IdempotencyConflict $e): void {
            expect($e->code())->toBe('common.idempotency_conflict')
                ->and($e->context())->toBe(['operation' => 'sales.place_order', 'key' => 'checkout-42']);
        });
    expect($runs)->toBe(1);
});

it('keeps scopes apart', function (): void {
    $receipts = app(OperationReceipts::class);
    $key = IdempotencyKey::of('same');
    $fingerprint = Fingerprint::of('x');
    $identity = fn (array $value): array => $value;

    $a = $receipts->once('inventory.receive_stock', $key, $fingerprint, fn () => ['a'], $identity, $identity);
    $b = $receipts->once('sales.place_order', $key, $fingerprint, fn () => ['b'], $identity, $identity);

    expect([$a, $b])->toBe([['a'], ['b']]);
});

it('refuses re-entering the same key before a result exists', function (): void {
    $receipts = app(OperationReceipts::class);
    $key = IdempotencyKey::of('loop');
    $fingerprint = Fingerprint::of('x');
    $identity = fn (array $value): array => $value;

    expect(fn () => $receipts->once('sales.place_order', $key, $fingerprint, fn () => $receipts->once('sales.place_order', $key, $fingerprint, fn () => [], $identity, $identity), $identity, $identity))
        ->toThrow(LogicException::class, 're-entered itself');
});

it('validates scope and fingerprint', function (): void {
    $receipts = app(OperationReceipts::class);
    $identity = fn (array $value): array => $value;

    expect(fn () => $receipts->once('Bad Scope', IdempotencyKey::of('k'), Fingerprint::of(1), fn () => [], $identity, $identity))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $receipts->once('sales.x', IdempotencyKey::of('k'), 'not-a-hash', fn () => [], $identity, $identity))
        ->toThrow(InvalidArgumentException::class);
});

it('fingerprints canonical payloads independent of map key order', function (): void {
    expect(Fingerprint::of(['b' => 1, 'a' => ['y' => 2.0, 'x' => null]]))->toBe(Fingerprint::of(['a' => ['x' => null, 'y' => 2.0], 'b' => 1]))
        ->and(Fingerprint::of([1, 2]))->not->toBe(Fingerprint::of([2, 1]))
        ->and(Fingerprint::of(['0' => 'a']))->toBe(Fingerprint::of(['a']))
        ->and(Fingerprint::of(['k' => IdempotencyKey::of('v')]))->toBe(Fingerprint::of(['k' => 'v']))
        ->and(Fingerprint::of('x'))->toBe(hash('sha256', '"x"'));

    expect(fn () => Fingerprint::of(new stdClass))->toThrow(InvalidArgumentException::class);
});

it('prunes receipts and delivered outbox messages older than the retention', function (): void {
    DB::table('cmz_operation_receipts')->insert([
        ['scope' => 's', 'key' => 'old', 'fingerprint' => str_repeat('a', 64), 'created_at' => now()->subDays(31)],
        ['scope' => 's', 'key' => 'new', 'fingerprint' => str_repeat('a', 64), 'created_at' => now()->subDays(29)],
    ]);
    $message = ['type' => 'sales.x', 'version' => 1, 'aggregate_ref' => 'order:1', 'payload' => '{}', 'occurred_at' => now()];
    DB::table('cmz_outbox_messages')->insert([
        $message + ['event_id' => '0190a0a0-0000-7000-8000-000000000001', 'delivered_at' => now()->subDays(40)],
        $message + ['event_id' => '0190a0a0-0000-7000-8000-000000000002', 'delivered_at' => null],
        $message + ['event_id' => '0190a0a0-0000-7000-8000-000000000003', 'delivered_at' => now()->subDay()],
    ]);

    $this->artisan('commerz:operations:prune')->assertSuccessful()->expectsOutputToContain('Pruned 1 receipts and 1 delivered outbox messages');

    expect(DB::table('cmz_operation_receipts')->pluck('key')->all())->toBe(['new'])
        ->and(DB::table('cmz_outbox_messages')->count())->toBe(2);
});

it('rolls back a failed receipt and its work when the host catches the exception', function (): void {
    $receipts = app(OperationReceipts::class);
    $key = IdempotencyKey::of('caught-failure');
    $fingerprint = Fingerprint::of('request');
    $identity = fn (array $value): array => $value;

    expect(fn () => $receipts->once('test.atomic', $key, $fingerprint, function (): array {
        DB::table('cmz_schema_state')->insert(['key' => 'failed-work', 'value' => '{}']);
        throw new RuntimeException('work failed');
    }, $identity, $identity))->toThrow(RuntimeException::class, 'work failed');

    expect(DB::table('cmz_operation_receipts')->where('key', $key->value)->exists())->toBeFalse()
        ->and(DB::table('cmz_schema_state')->where('key', 'failed-work')->exists())->toBeFalse()
        ->and($receipts->once('test.atomic', $key, $fingerprint, fn () => ['retry'], $identity, $identity))->toBe(['retry']);
});
