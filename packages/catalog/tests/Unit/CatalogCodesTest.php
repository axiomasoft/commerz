<?php

declare(strict_types=1);

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Errors\InvalidCommand;
use Packman\Errors\Enums\ErrorCategory;

it('accepts and rejects codes', function (string $value, bool $valid): void {
    if ($valid) {
        expect(CatalogCodes::code('code', $value))->toBe($value);

        return;
    }

    try {
        CatalogCodes::code('code', $value);
        $this->fail('expected InvalidCommand');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('catalog.invalid_code')->and($e->field)->toBe('code');
    }
})->with([
    'simple' => ['color', true],
    'dots dashes underscores' => ['a.b-c_d9', true],
    'max length' => [str_repeat('a', 64), true],
    'too long' => [str_repeat('a', 65), false],
    'empty' => ['', false],
    'upper case' => ['Color', false],
    'leading dash' => ['-a', false],
    'space' => ['a b', false],
    'newline suffix' => ["a\n", false],
]);

it('accepts and rejects handles', function (string $value, bool $valid): void {
    if ($valid) {
        expect(CatalogCodes::handle($value))->toBe($value);

        return;
    }

    expect(fn () => CatalogCodes::handle($value))->toThrow(function (InvalidCommand $e): void {
        expect($e->code())->toBe('catalog.invalid_handle');
    });
})->with([
    'one char' => ['a', true],
    'words' => ['blue-t-shirt-2', true],
    'max length' => [str_repeat('a', 191), true],
    'too long' => [str_repeat('a', 192), false],
    'trailing dash' => ['a-', false],
    'leading dash' => ['-a', false],
    'underscore' => ['a_b', false],
    'upper case' => ['A', false],
    'empty' => ['', false],
]);

it('accepts and rejects SKUs', function (string $value, bool $valid): void {
    if ($valid) {
        expect(CatalogCodes::sku($value))->toBe($value);

        return;
    }

    expect(fn () => CatalogCodes::sku($value))->toThrow(function (InvalidCommand $e): void {
        expect($e->code())->toBe('catalog.invalid_sku');
    });
})->with([
    'plain' => ['TS-BLU-M', true],
    'unicode' => ['Футболка-1', true],
    'max bytes' => [str_repeat('a', 191), true],
    'too many bytes' => [str_repeat('a', 192), false],
    'multibyte over bytes' => [str_repeat('ы', 96), false],
    'empty' => ['', false],
    'space' => ['a b', false],
    'control' => ["a\x00b", false],
]);

it('describes a duplicate code as a conflict', function (): void {
    $e = new DuplicateCode('variant', 'TS-1');

    expect($e->code())->toBe('catalog.duplicate_code')
        ->and($e->category())->toBe(ErrorCategory::Conflict)
        ->and($e->context())->toBe(['entity' => 'variant', 'code' => 'TS-1']);
});
