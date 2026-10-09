<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Shipping\Common\Data\ShippingCodes;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;

it('accepts a code of lower-case letters, digits, dots, dashes and underscores', function (string $code): void {
    expect(ShippingCodes::code('code', $code))->toBe($code)
        ->and(ShippingCodes::carrierKey('carrier', $code))->toBe($code);
})->with(['courier', 'moscow-center.2026', 'a', '9_lives', str_repeat('a', 64)]);

it('refuses a malformed code or carrier key', function (string $code): void {
    expect(fn () => ShippingCodes::code('code', $code))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('shipping.invalid_code')->and($e->field)->toBe('code'))
        ->and(fn () => ShippingCodes::carrierKey('carrier', $code))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('shipping.invalid_code')->and($e->field)->toBe('carrier'));
})->with(['', 'Courier', '-courier', 'a b', 'ёлка', str_repeat('a', 65), "courier\n"]);

it('reports a taken code as a conflict', function (): void {
    $e = new DuplicateCode('zone', 'russia');

    expect($e->code())->toBe('shipping.duplicate_code')
        ->and($e->context())->toBe(['entity' => 'zone', 'code' => 'russia'])
        ->and($e->getMessage())->toContain("zone code 'russia'");
});
