<?php

declare(strict_types=1);

use Commerz\Measures\LocalizedText;
use Commerz\Schema\Rows;

it('reads typed columns from raw database rows', function (): void {
    $row = (object) [
        'name' => 'Widget',
        'subtitle' => null,
        'qty' => 3,
        'limit' => null,
        'active' => true,
        'featured' => null,
        'meta' => '{"k":"v"}',
        'labels' => '{"en":"Widget","de":"Geraet"}',
        'created_at' => '2026-01-01 00:00:00+00:00',
    ];

    expect(Rows::string($row, 'name'))->toBe('Widget')
        ->and(Rows::nullableString($row, 'subtitle'))->toBeNull()
        ->and(Rows::nullableString($row, 'name'))->toBe('Widget')
        ->and(Rows::int($row, 'qty'))->toBe(3)
        ->and(Rows::nullableInt($row, 'limit'))->toBeNull()
        ->and(Rows::nullableInt($row, 'qty'))->toBe(3)
        ->and(Rows::bool($row, 'active'))->toBeTrue()
        ->and(Rows::nullableBool($row, 'featured'))->toBeNull()
        ->and(Rows::nullableBool($row, 'active'))->toBeTrue()
        ->and(Rows::json($row, 'meta'))->toBe(['k' => 'v'])
        ->and(Rows::strings($row, 'labels'))->toBe(['en' => 'Widget', 'de' => 'Geraet'])
        ->and(Rows::localized($row, 'labels'))->toEqual(new LocalizedText(['en' => 'Widget', 'de' => 'Geraet']))
        ->and(Rows::date($row, 'created_at')->format('Y-m-d'))->toBe('2026-01-01');
});

it('throws on missing or mistyped columns', function (): void {
    $row = (object) ['name' => 123];

    expect(fn () => Rows::string($row, 'missing'))->toThrow(RuntimeException::class, "Column 'missing' is missing.")
        ->and(fn () => Rows::string($row, 'name'))->toThrow(RuntimeException::class, "Column 'name' is missing or not a string.");
});
