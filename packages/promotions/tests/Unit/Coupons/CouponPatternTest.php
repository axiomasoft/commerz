<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Errors\InvalidCommand;
use Commerz\Promotions\Coupons\Internal\CouponPattern;

const CPT_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

function cptFailure(string $pattern, string $alphabet = CPT_ALPHABET): string
{
    try {
        CouponPattern::parse($pattern, $alphabet);
    } catch (InvalidCommand $e) {
        return $e->code();
    }

    return 'no error';
}

it('upper-cases the pattern and counts the slots', function (): void {
    $pattern = CouponPattern::parse('spring_##-ab-##', CPT_ALPHABET);

    expect($pattern->pattern)->toBe('SPRING_##-AB-##')
        ->and($pattern->slots)->toBe(4);
});

it('generates codes of the pattern from the alphabet only', function (): void {
    $pattern = CouponPattern::parse('AB-####-C#', CPT_ALPHABET);

    for ($i = 0; $i < 200; $i++) {
        $code = $pattern->generate();

        expect($pattern->matches($code))->toBeTrue()
            ->and($code)->toMatch('/\AAB-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-C[23456789ABCDEFGHJKMNPQRSTUVWXYZ]\z/');
    }
});

it('draws different characters across codes', function (): void {
    $pattern = CouponPattern::parse('########', CPT_ALPHABET);
    $codes = [];

    for ($i = 0; $i < 50; $i++) {
        $codes[$pattern->generate()] = true;
    }

    $codes = array_keys($codes);
    $characters = array_unique(str_split(implode('', $codes)));

    expect(count($codes))->toBe(50)
        ->and(count($characters))->toBeGreaterThan(20);
});

it('matches only codes of the pattern', function (): void {
    $pattern = CouponPattern::parse('AB-####', CPT_ALPHABET);

    expect($pattern->matches('AB-2345'))->toBeTrue()
        ->and($pattern->matches('AB-234'))->toBeFalse()
        ->and($pattern->matches('AC-2345'))->toBeFalse()
        ->and($pattern->matches('AB-0123'))->toBeFalse() // 0, 1, O and I are not in the alphabet
        ->and($pattern->matches('AB-23456'))->toBeFalse();
});

it('wants a hundred times as many codes as it gives', function (): void {
    $pattern = CouponPattern::parse('####', CPT_ALPHABET); // 31^4 = 923,521

    expect($pattern->holds(9_235))->toBeTrue()
        ->and($pattern->holds(9_236))->toBeFalse()
        ->and(CouponPattern::parse('################', CPT_ALPHABET)->holds(1_000_000))->toBeTrue() // far past 2^63 if multiplied out
        ->and(CouponPattern::parse('####', 'AB')->holds(1))->toBeFalse(); // 16
});

it('refuses a pattern of the wrong shape', function (): void {
    expect(cptFailure(''))->toBe('promotions.invalid_pattern')
        ->and(cptFailure('-####'))->toBe('promotions.invalid_pattern')
        ->and(cptFailure('_####'))->toBe('promotions.invalid_pattern')
        ->and(cptFailure('A####!'))->toBe('promotions.invalid_pattern')
        ->and(cptFailure('A #### B'))->toBe('promotions.invalid_pattern')
        ->and(cptFailure(str_repeat('#', 65)))->toBe('promotions.invalid_pattern')
        ->and(cptFailure(str_repeat('#', 64)))->toBe('no error')
        ->and(cptFailure('ПРОМО-####'))->toBe('promotions.invalid_pattern');
});

it('wants at least four slots', function (): void {
    expect(cptFailure('A-###'))->toBe('promotions.pattern_too_small')
        ->and(cptFailure('ABCDEF'))->toBe('promotions.pattern_too_small')
        ->and(cptFailure('A-####'))->toBe('no error');
});

it('is a command error', function (): void {
    expect(fn () => CouponPattern::parse('#', CPT_ALPHABET))->toThrow(CommerzException::class);
});
