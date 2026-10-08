<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Stores\Data\ChannelId;

beforeEach(function (): void {
    $this->channel = ChannelId::fromString('018f0000-0000-7000-8000-000000000001');
});

it('builds a context with defaults', function (): void {
    $ctx = CommerceContext::for($this->channel, Currency::of('RUB'));

    expect($ctx->locale)->toBe('en')
        ->and($ctx->customer)->toBeNull()
        ->and($ctx->customerGroups)->toBe([])
        ->and($ctx->at->getTimezone()->getName())->toBe('UTC')
        ->and($ctx->currency->code)->toBe('RUB');
});

it('derives immutable variants', function (): void {
    $at = new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('Europe/Moscow'));
    $base = CommerceContext::for($this->channel, Currency::of('RUB'), 'ru', $at);
    $withCustomer = $base->withCustomer(CustomerRef::of('user', '42'), ['vip', 'vip', 'wholesale']);
    $later = $withCustomer->withAt(new DateTimeImmutable('2027-01-01T00:00:00+00:00'));

    expect($base->customer)->toBeNull()
        ->and($base->at->format('c'))->toBe('2026-10-08T09:00:00+00:00')
        ->and((string) $withCustomer->customer)->toBe('user:42')
        ->and($withCustomer->customerGroups)->toBe(['vip', 'wholesale'])
        ->and($later->at->format('Y'))->toBe('2027')
        ->and($later->customerGroups)->toBe(['vip', 'wholesale'])
        ->and($later->locale)->toBe('ru');
});

it('rejects bad locales and groups', function (): void {
    expect(fn () => CommerceContext::for($this->channel, Currency::of('RUB'), 'RU'))->toThrow(InvalidCommand::class)
        ->and(fn () => CommerceContext::for($this->channel, Currency::of('RUB'))->withCustomer(CustomerRef::of('user', '1'), ['Bad Group']))
        ->toThrow(InvalidCommand::class);
});
