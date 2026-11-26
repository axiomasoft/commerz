<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Common\Internal\Quantities;
use Commerz\Schema\Rows;

it('accepts well-formed codes, skus, references and reasons', function (): void {
    expect(InventoryCodes::code('code', 'main.wh-1'))->toBe('main.wh-1')
        ->and(InventoryCodes::sku('TEE-RED/42'))->toBe('TEE-RED/42')
        ->and(InventoryCodes::reference('receipt:2026-10-1'))->toBe('receipt:2026-10-1')
        ->and(InventoryCodes::reason('Annual count, aisle 4'))->toBe('Annual count, aisle 4');
});

it('rejects malformed values with the package error code', function (callable $call, string $code): void {
    expect($call)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe($code));
})->with([
    'code uppercase' => [fn () => InventoryCodes::code('code', 'Main'), 'inventory.invalid_code'],
    'code too long' => [fn () => InventoryCodes::code('code', str_repeat('a', 65)), 'inventory.invalid_code'],
    'sku with space' => [fn () => InventoryCodes::sku('A B'), 'inventory.invalid_sku'],
    'sku empty' => [fn () => InventoryCodes::sku(''), 'inventory.invalid_sku'],
    'reference empty' => [fn () => InventoryCodes::reference(''), 'inventory.invalid_reference'],
    'reference leading space' => [fn () => InventoryCodes::reference(' x'), 'inventory.invalid_reference'],
    'reason too long' => [fn () => InventoryCodes::reason(str_repeat('é', 96)), 'inventory.invalid_reason'],
    'reason control char' => [fn () => InventoryCodes::reason("a\nb"), 'inventory.invalid_reason'],
]);

it('names the field it checked', function (): void {
    expect(fn () => InventoryCodes::reason('', 'note'))
        ->toThrow(fn (InvalidCommand $e) => expect($e->field)->toBe('note'));
});

it('adds and multiplies quantities without leaving int64', function (): void {
    expect(Quantities::mul(3, 4))->toBe(12)
        ->and(Quantities::mul(0, PHP_INT_MAX))->toBe(0)
        ->and(Quantities::mul(PHP_INT_MAX, 1))->toBe(PHP_INT_MAX)
        ->and(Quantities::mul(PHP_INT_MAX, 2))->toBeNull()
        ->and(Quantities::add(1, 2))->toBe(3)
        ->and(Quantities::add(PHP_INT_MAX, 0))->toBe(PHP_INT_MAX)
        ->and(Quantities::add(PHP_INT_MAX, 1))->toBeNull();
});

it('reads typed columns from a row and refuses a mistyped one', function (): void {
    $row = (object) ['s' => 'x', 'n' => null, 'i' => 5, 'b' => true, 'd' => '2026-10-10 10:00:00+00'];

    expect(Rows::string($row, 's'))->toBe('x')
        ->and(Rows::nullableString($row, 'n'))->toBeNull()
        ->and(Rows::nullableInt($row, 'n'))->toBeNull()
        ->and(Rows::int($row, 'i'))->toBe(5)
        ->and(Rows::bool($row, 'b'))->toBeTrue()
        ->and(Rows::date($row, 'd')->format('H'))->toBe('10')
        ->and(fn () => Rows::int($row, 's'))->toThrow(RuntimeException::class)
        ->and(fn () => Rows::string($row, 'missing'))->toThrow(RuntimeException::class, 'missing');
});
