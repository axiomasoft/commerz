<?php

declare(strict_types=1);

use Commerz\StoredValue\Configuration\StoredValueOptions;
use Packman\Config\Exceptions\InvalidConfiguration;

it('compiles default stored-value options and validates bounds', function (): void {
    $defaults = StoredValueOptions::fromArray([]);

    expect($defaults->maxTendersPerQuote)->toBe(5)
        ->and($defaults->defaultHoldTtlSeconds)->toBe(1800)
        ->and($defaults->codeLength)->toBe(16)
        ->and($defaults->codePrefix)->toBe('GC')
        ->and($defaults->defaultExpiryDays)->toBeNull();

    $custom = StoredValueOptions::fromArray([
        'max_tenders_per_quote' => 3,
        'default_hold_ttl_seconds' => null,
        'gift_cards' => [
            'code_length' => 12,
            'code_prefix' => 'GIFT',
            'default_expiry_days' => 365,
        ],
    ]);

    expect($custom->maxTendersPerQuote)->toBe(3)
        ->and($custom->defaultHoldTtlSeconds)->toBeNull()
        ->and($custom->codeLength)->toBe(12)
        ->and($custom->codePrefix)->toBe('GIFT')
        ->and($custom->defaultExpiryDays)->toBe(365);

    expect(fn () => StoredValueOptions::fromArray(['unknown_key' => 1]))
        ->toThrow(InvalidConfiguration::class)
        ->and(fn () => StoredValueOptions::fromArray(['max_tenders_per_quote' => 0]))
        ->toThrow(InvalidConfiguration::class)
        ->and(fn () => StoredValueOptions::fromArray(['gift_cards' => ['code_alphabet' => 'AAA']]))
        ->toThrow(InvalidConfiguration::class);
});
