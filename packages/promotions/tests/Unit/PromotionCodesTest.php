<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;

it('accepts a promotion code of lower-case letters, digits, dots, dashes and underscores', function (string $code): void {
    expect(PromotionCodes::code('code', $code))->toBe($code);
})->with(['autumn', 'black-friday.2026', 'a', '9_lives', str_repeat('a', 64)]);

it('refuses a malformed promotion code', function (string $code): void {
    expect(fn () => PromotionCodes::code('code', $code))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('promotions.invalid_code')->and($e->field)->toBe('code'));
})->with(['', 'Autumn', '-autumn', 'a b', 'ёлка', str_repeat('a', 65)]);

it('normalizes a coupon code to trimmed upper case', function (string $presented, string $canonical): void {
    expect(PromotionCodes::couponCode('code', $presented))->toBe($canonical);
})->with([
    'upper case' => ['save10', 'SAVE10'],
    'mixed case' => ['Save-10', 'SAVE-10'],
    'padded' => ["  save_10 \n", 'SAVE_10'],
    'already canonical' => ['AUTUMN-0001', 'AUTUMN-0001'],
    'longest' => [str_repeat('a', 64), str_repeat('A', 64)],
]);

it('refuses a coupon code that is not canonical after normalization', function (string $code): void {
    expect(fn () => PromotionCodes::couponCode('code', $code))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('promotions.invalid_coupon_code'));
})->with(['', '   ', '-save', '_save', 'sa ve', 'sale!', 'скидка', str_repeat('a', 65)]);

it('accepts a customer group of the shape the context uses', function (): void {
    expect(PromotionCodes::group('group', 'vip.gold_1'))->toBe('vip.gold_1')
        ->and(fn () => PromotionCodes::group('group', 'VIP'))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('promotions.invalid_group'));
});

it('reports a taken code as a conflict', function (): void {
    $e = new DuplicateCode('coupon', 'SAVE10');

    expect($e->code())->toBe('promotions.duplicate_code')
        ->and($e->context())->toBe(['entity' => 'coupon', 'code' => 'SAVE10'])
        ->and($e->getMessage())->toContain("coupon code 'SAVE10'");
});
