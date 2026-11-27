<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Sales\Numbering\Data\OrderNumbering;
use Commerz\Sales\Numbering\Internal\SequenceOrderNumbers;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\StoreId;
use Commerz\Stores\Data\StoreView;
use Commerz\Stores\Enums\StoreStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seqNumbering(string $prefix): OrderNumbering
{
    $now = new DateTimeImmutable('2026-10-14T10:00:00+00:00');

    return new OrderNumbering(
        new StoreView(StoreId::generate(), 'main', 'Main', Currency::of('RUB'), $prefix, StoreStatus::Active, $now, $now),
        ChannelId::generate(),
        OrderId::generate(),
        $now,
    );
}

it('numbers with the store prefix and the next value of the sequence', function (): void {
    $generator = app(SequenceOrderNumbers::class);

    $first = $generator->next(seqNumbering('ORD-'));
    $second = $generator->next(seqNumbering('ORD-'));

    expect($generator->key())->toBe('sequence')
        ->and($first)->toMatch('/\AORD-\d{4,}\z/')
        ->and((int) substr($second, 4))->toBe((int) substr($first, 4) + 1);
});

it('numbers from 1001 and shares one sequence between stores', function (): void {
    $generator = app(SequenceOrderNumbers::class);

    $a = $generator->next(seqNumbering('A-'));
    $b = $generator->next(seqNumbering('B-'));

    expect((int) substr($a, 2))->toBeGreaterThanOrEqual(1001)
        ->and((int) substr($b, 2))->toBe((int) substr($a, 2) + 1);
});

it('takes an empty prefix and a long one and still matches the order number pattern', function (): void {
    $generator = app(SequenceOrderNumbers::class);

    expect($generator->next(seqNumbering('')))->toMatch('/\A(?=[A-Za-z0-9-]{1,64}\z)[A-Za-z0-9-]*[A-Za-z0-9]/')
        ->and($generator->next(seqNumbering(str_repeat('A', 16))))->toMatch('/\A[A-Z0-9]{16}\d+\z/');
});

it('is the contribution `sequence` of order_number_generator', function (): void {
    expect(app(SequenceOrderNumbers::class)->key())->toBe('sequence');
});
