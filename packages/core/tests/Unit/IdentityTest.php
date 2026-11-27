<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\EntityId;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Stores\Data\ChannelId;
use PHPUnit\Framework\Assert;

afterEach(fn () => Uuid7::reset());

it('generates valid, strictly increasing UUIDv7', function (): void {
    $ids = array_map(Uuid7::generate(...), range(1, 5000));

    foreach ($ids as $id) {
        expect($id)->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/');
    }
    $sorted = $ids;
    sort($sorted, SORT_STRING);
    expect($sorted)->toBe($ids)->and(array_unique($ids))->toHaveCount(5000);
});

it('embeds the current time in the first 48 bits', function (): void {
    $before = (int) (microtime(true) * 1000);
    $id = Uuid7::generate();
    $ms = hexdec(substr(str_replace('-', '', $id), 0, 12));

    expect($ms)->toBeGreaterThanOrEqual($before)->and($ms)->toBeLessThan($before + 5000);
});

it('pins ids with fixed() and fails when they run out', function (): void {
    $a = '018f0000-0000-7000-8000-000000000001';
    $b = '018f0000-0000-7000-8000-000000000002';
    Uuid7::fixed($a, $b);

    expect(ChannelId::generate()->value)->toBe($a)
        ->and(Uuid7::generate())->toBe($b)
        ->and(fn () => Uuid7::generate())->toThrow(LogicException::class);
});

it('validates and normalizes entity ids', function (): void {
    $id = ChannelId::fromString('018F0000-0000-7000-8000-00000000000A');

    expect((string) $id)->toBe('018f0000-0000-7000-8000-00000000000a')
        ->and($id->equals(ChannelId::fromString('018f0000-0000-7000-8000-00000000000a')))->toBeTrue()
        ->and(fn () => ChannelId::fromString('nope'))->toThrow(InvalidCommand::class)
        ->and(fn () => ChannelId::fromString(''))->toThrow(InvalidCommand::class);
});

it('does not equate ids of different classes', function (): void {
    $other = new readonly class('018f0000-0000-7000-8000-00000000000a') extends EntityId {};

    expect(ChannelId::fromString('018f0000-0000-7000-8000-00000000000a')->equals($other))->toBeFalse();
});

it('builds and parses refs', function (): void {
    $ref = CustomerRef::of('user', '42');

    expect((string) $ref)->toBe('user:42')
        ->and(CustomerRef::fromString('crm.contact:a:b')->id)->toBe('a:b')
        ->and(CustomerRef::fromString('crm.contact:a:b')->type)->toBe('crm.contact')
        ->and($ref->equals(CustomerRef::fromString('user:42')))->toBeTrue()
        ->and($ref->equals(SellableRef::of('user', '42')))->toBeFalse()
        ->and(SellableRef::of('variant', 'x')::class)->toBe(SellableRef::class)
        ->and(ActorRef::of('system', 'cron')->type)->toBe('system');
});

it('rejects malformed refs', function (string $type, string $id): void {
    try {
        CustomerRef::of($type, $id);
        Assert::fail('expected InvalidCommand');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('identity.invalid_ref');
    }
})->with([
    ['', '1'],
    ['User', '1'],
    ['us er', '1'],
    [str_repeat('a', 65), '1'],
    ['user', ''],
    ['user', str_repeat('x', 121)],
    ['user', "a\nb"],
    ['user', "\xff"],
]);

it('accepts boundary lengths and rejects refs without a separator', function (): void {
    expect(strlen((string) CustomerRef::of(str_repeat('a', 64), str_repeat('é', 60))))->toBe(64 + 1 + 120)
        ->and(fn () => CustomerRef::fromString('nocolon'))->toThrow(InvalidCommand::class);
});

it('bounds idempotency keys', function (): void {
    expect(IdempotencyKey::of(str_repeat('k', 191))->value)->toHaveLength(191)
        ->and((string) IdempotencyKey::of('order-1'))->toBe('order-1')
        ->and(IdempotencyKey::of('a')->equals(IdempotencyKey::of('a')))->toBeTrue()
        ->and(fn () => IdempotencyKey::of(''))->toThrow(InvalidCommand::class)
        ->and(fn () => IdempotencyKey::of(str_repeat('k', 192)))->toThrow(InvalidCommand::class)
        ->and(fn () => IdempotencyKey::of("a\tb"))->toThrow(InvalidCommand::class);
});
