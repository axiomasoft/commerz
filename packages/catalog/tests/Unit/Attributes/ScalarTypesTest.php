<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Internal\Types\BooleanType;
use Commerz\Catalog\Attributes\Internal\Types\DateType;
use Commerz\Catalog\Attributes\Internal\Types\IntegerType;
use Commerz\Catalog\Attributes\Internal\Types\LocalizedTextType;
use Commerz\Catalog\Attributes\Internal\Types\TextType;
use Commerz\Catalog\Tests\Support\Attrs;
use Commerz\Measures\LocalizedText;

describe('text', function (): void {
    it('fills defaults and canonicalizes the config', function (): void {
        $type = new TextType;

        expect($type->config([]))->toBe(['maxLength' => 255])
            ->and($type->config($type->config(['maxLength' => 10])))->toBe(['maxLength' => 10])
            ->and(Attrs::rejectsConfig($type, ['maxLength' => 0]))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['maxLength' => '5']))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['maxLength' => 10001]))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['other' => 1]))->toBeTrue();
    });

    it('keeps the text as is and rejects blanks, non-strings and overlong values', function (): void {
        $type = new TextType;
        $definition = Attrs::definition($type, ['maxLength' => 5]);

        expect($type->normalize(' ab ', $definition))->toBe(' ab ')
            ->and($type->normalize($type->normalize('żółw', $definition), $definition))->toBe('żółw')
            ->and(Attrs::rejects($type, '', $definition))->toBeTrue()
            ->and(Attrs::rejects($type, "  \n", $definition))->toBeTrue()
            ->and(Attrs::rejects($type, 'abcdef', $definition))->toBeTrue()
            ->and(Attrs::rejects($type, 12, $definition))->toBeTrue()
            ->and(Attrs::rejects($type, null, $definition))->toBeTrue()
            ->and(Attrs::rejects($type, "\xff", $definition))->toBeTrue();
    });

    it('indexes a term', function (): void {
        $type = new TextType;
        $facts = $type->index(str_repeat('я', 300), Attrs::definition($type));

        expect($facts)->toHaveCount(1)
            ->and(mb_strlen((string) $facts[0]->term))->toBe(255)
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::In]);
    });
});

describe('localized_text', function (): void {
    it('canonicalizes the config', function (): void {
        $type = new LocalizedTextType;

        expect($type->config([]))->toBe(['maxLength' => 10000])
            ->and(Attrs::rejectsConfig($type, ['maxLength' => 100001]))->toBeTrue();
    });

    it('sorts locales and accepts its own stored form', function (): void {
        $type = new LocalizedTextType;
        $definition = Attrs::definition($type);

        $value = $type->normalize(['ru' => 'Привет', 'en' => 'Hello'], $definition);

        expect($value)->toBe(['en' => 'Hello', 'ru' => 'Привет'])
            ->and($type->normalize($value, $definition))->toBe($value)
            ->and($type->normalize(new LocalizedText(['ru' => 'a', 'de' => 'b']), $definition))->toBe(['de' => 'b', 'ru' => 'a']);
    });

    it('rejects empty, blank, malformed and overlong input', function (): void {
        $type = new LocalizedTextType;
        $definition = Attrs::definition($type, ['maxLength' => 3]);

        foreach ([[], ['en' => ' '], ['EN!' => 'x'], ['en' => 'abcd'], ['en' => 1], 'text', null, ['en' => "\xff"]] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }
    });

    it('is not filterable and has no facts', function (): void {
        $type = new LocalizedTextType;

        expect($type->operators())->toBe([])
            ->and($type->index(['en' => 'x'], Attrs::definition($type)))->toBe([]);
    });
});

describe('integer', function (): void {
    it('canonicalizes bounds', function (): void {
        $type = new IntegerType;

        expect($type->config([]))->toBe([])
            ->and($type->config(['min' => 1, 'max' => 5]))->toBe(['max' => 5, 'min' => 1])
            ->and($type->config($type->config(['min' => 1])))->toBe(['min' => 1])
            ->and(Attrs::rejectsConfig($type, ['min' => 5, 'max' => 1]))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['min' => '1']))->toBeTrue();
    });

    it('accepts ints and integer strings', function (): void {
        $type = new IntegerType;
        $definition = Attrs::definition($type, ['min' => -10, 'max' => 10]);

        expect($type->normalize(5, $definition))->toBe(5)
            ->and($type->normalize('-007', $definition))->toBe(-7)
            ->and($type->normalize('-0', $definition))->toBe(0)
            ->and($type->normalize($type->normalize('7', $definition), $definition))->toBe(7);
    });

    it('rejects floats, bools, null, junk and out-of-range values', function (): void {
        $type = new IntegerType;
        $definition = Attrs::definition($type, ['min' => -10, 'max' => 10]);

        foreach ([1.0, true, null, '', '1.0', '1e1', ' 1', 'x', 11, -11, '99999999999999999999'] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }

        expect(Attrs::rejects($type, '9223372036854775808', Attrs::definition($type)))->toBeTrue()
            ->and($type->normalize('9223372036854775807', Attrs::definition($type)))->toBe(PHP_INT_MAX);
    });

    it('indexes a number', function (): void {
        $type = new IntegerType;

        expect($type->index(-3, Attrs::definition($type))[0]->number)->toBe('-3')
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::In, FilterOperator::Range]);
    });
});

describe('boolean', function (): void {
    it('takes only real bools', function (): void {
        $type = new BooleanType;
        $definition = Attrs::definition($type);

        expect($type->config([]))->toBe([])
            ->and(Attrs::rejectsConfig($type, ['x' => 1]))->toBeTrue()
            ->and($type->normalize(false, $definition))->toBeFalse()
            ->and($type->normalize($type->normalize(true, $definition), $definition))->toBeTrue();

        foreach ([0, 1, 'true', 'yes', null, []] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }
    });

    it('indexes a flag', function (): void {
        $type = new BooleanType;

        expect($type->index(false, Attrs::definition($type))[0]->flag)->toBeFalse()
            ->and($type->operators())->toBe([FilterOperator::Equals]);
    });
});

describe('date', function (): void {
    it('accepts real calendar days and date objects', function (): void {
        $type = new DateType;
        $definition = Attrs::definition($type);

        expect($type->config([]))->toBe([])
            ->and($type->normalize('2024-02-29', $definition))->toBe('2024-02-29')
            ->and($type->normalize(new DateTimeImmutable('2026-10-09 23:59:59'), $definition))->toBe('2026-10-09')
            ->and($type->normalize($type->normalize('2026-01-02', $definition), $definition))->toBe('2026-01-02');
    });

    it('rejects impossible and malformed dates', function (): void {
        $type = new DateType;
        $definition = Attrs::definition($type);

        foreach (['2023-02-29', '2026-13-01', '2026-1-1', '26-01-01', '2026-01-01 10:00', '', 20260101, null] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }
    });

    it('indexes a day', function (): void {
        $type = new DateType;

        expect($type->index('2026-01-02', Attrs::definition($type))[0]->day)->toBe('2026-01-02')
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::Range]);
    });
});
