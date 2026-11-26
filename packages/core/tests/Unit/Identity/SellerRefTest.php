<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellerRef;

it('creates, formats, parses, and compares seller refs', function (): void {
    $ref = SellerRef::of('marketplace', 'seller-42');
    $parsed = SellerRef::fromString('marketplace:seller-42');
    $customer = CustomerRef::of('marketplace', 'seller-42');

    expect((string) $ref)->toBe('marketplace:seller-42')
        ->and($ref->type)->toBe('marketplace')
        ->and($ref->id)->toBe('seller-42')
        ->and($ref->equals($parsed))->toBeTrue()
        ->and($ref->equals($customer))->toBeFalse();
});

it('rejects malformed seller refs', function (): void {
    expect(fn () => SellerRef::fromString('missing-colon'))->toThrow(InvalidCommand::class)
        ->and(fn () => SellerRef::of('BadType', '1'))->toThrow(InvalidCommand::class);
});
