<?php

declare(strict_types=1);

use Commerz\Shipping\Common\Data\PostalCode;

it('upper-cases and drops spaces and dashes', function (?string $given, ?string $canonical): void {
    expect(PostalCode::normalize($given))->toBe($canonical);
})->with([
    'plain' => ['101000', '101000'],
    'lower case' => ['sw1a', 'SW1A'],
    'spaced' => ['SW1A 1AA', 'SW1A1AA'],
    'dashed' => ['123-45', '12345'],
    'both' => [' ab 1-2 ', 'AB12'],
    'longest' => ['1234567890', '1234567890'],
    'null' => [null, null],
    'empty' => ['', null],
    'only separators' => [' - ', null],
    'too long' => ['12345678901', null],
    'other characters' => ['10.1', null],
    'non-latin' => ['ЯЯЯ', null],
    'newline' => ["101\n000", null],
]);
