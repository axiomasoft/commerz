<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Pricing\Common\Internal\Windows;
use Illuminate\Database\QueryException;

it('accepts well-formed codes, groups and tax codes', function (): void {
    expect(PricingCodes::code('code', 'retail.eu-1'))->toBe('retail.eu-1')
        ->and(PricingCodes::code('code', str_repeat('a', 64)))->toHaveLength(64)
        ->and(PricingCodes::group('customerGroup', 'vip_gold.2'))->toBe('vip_gold.2')
        ->and(PricingCodes::group('customerGroup', '-'))->toBe('-')
        ->and(PricingCodes::taxCode('code', 'vat'))->toBe('vat')
        ->and(PricingCodes::taxCode('code', 'gst_2'))->toBe('gst_2');
});

it('rejects malformed values with the package error code', function (callable $call, string $code): void {
    expect($call)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe($code));
})->with([
    'code uppercase' => [fn () => PricingCodes::code('code', 'Retail'), 'pricing.invalid_code'],
    'code too long' => [fn () => PricingCodes::code('code', str_repeat('a', 65)), 'pricing.invalid_code'],
    'code leading dash' => [fn () => PricingCodes::code('code', '-a'), 'pricing.invalid_code'],
    'code empty' => [fn () => PricingCodes::code('code', ''), 'pricing.invalid_code'],
    'group with space' => [fn () => PricingCodes::group('customerGroup', 'v i p'), 'pricing.invalid_group'],
    'group empty' => [fn () => PricingCodes::group('customerGroup', ''), 'pricing.invalid_group'],
    'group too long' => [fn () => PricingCodes::group('customerGroup', str_repeat('a', 65)), 'pricing.invalid_group'],
    'tax code uppercase' => [fn () => PricingCodes::taxCode('code', 'VAT'), 'pricing.invalid_tax_code'],
    'tax code with dash' => [fn () => PricingCodes::taxCode('code', 'vat-1'), 'pricing.invalid_tax_code'],
    'tax code too long' => [fn () => PricingCodes::taxCode('code', str_repeat('a', 33)), 'pricing.invalid_tax_code'],
]);

it('names the field it checked', function (): void {
    expect(fn () => PricingCodes::group('customerGroup', ''))
        ->toThrow(fn (InvalidCommand $e) => expect($e->field)->toBe('customerGroup'));
});

it('accepts open and ordered windows and rejects an empty or reversed one', function (): void {
    $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $until = new DateTimeImmutable('2026-02-01T00:00:00Z');

    Windows::assertOrdered(null, null);
    Windows::assertOrdered($from, null);
    Windows::assertOrdered(null, $until);
    Windows::assertOrdered($from, $until);

    foreach ([[$from, $from], [$until, $from]] as [$start, $end]) {
        expect(fn () => Windows::assertOrdered($start, $end))
            ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('pricing.invalid_window')->and($e->field)->toBe('validUntil'));
    }
});

it('tells a unique violation from an exclusion violation by SQLSTATE', function (): void {
    $violation = static function (string $state): QueryException {
        $pdo = new PDOException('violation');
        $pdo->errorInfo = [$state, 7, 'violation'];

        return new QueryException('pgsql', 'insert into t values (?)', [1], $pdo);
    };

    expect(Windows::isExclusionViolation($violation('23P01')))->toBeTrue()
        ->and(Windows::isUniqueViolation($violation('23P01')))->toBeFalse()
        ->and(Windows::isUniqueViolation($violation('23505')))->toBeTrue()
        ->and(Windows::isExclusionViolation($violation('23505')))->toBeFalse()
        ->and(Windows::isExclusionViolation($violation('40001')))->toBeFalse()
        ->and(Windows::isExclusionViolation(new RuntimeException('23P01')))->toBeFalse()
        ->and(Windows::isUniqueViolation(new RuntimeException('23505')))->toBeFalse();
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
