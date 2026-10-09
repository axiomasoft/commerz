<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Internal\Types\DecimalType;
use Commerz\Catalog\Attributes\Internal\Types\MeasureType;
use Commerz\Catalog\Tests\Support\Attrs;
use Commerz\Measures\Measure;

describe('decimal', function (): void {
    it('canonicalizes the config', function (): void {
        $type = new DecimalType;

        expect($type->config([]))->toBe(['scale' => 2])
            ->and($type->config(['scale' => 1, 'min' => '0', 'max' => 5]))->toBe(['max' => '5.0', 'min' => '0.0', 'scale' => 1])
            ->and($type->config($type->config(['scale' => 1, 'min' => '0'])))->toBe(['min' => '0.0', 'scale' => 1])
            ->and(Attrs::rejectsConfig($type, ['scale' => 13]))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['min' => 1.5]))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['min' => '1.555']))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['min' => '5', 'max' => '1']))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['min' => 'abc']))->toBeTrue();
    });

    it('pads to the scale without rounding', function (): void {
        $type = new DecimalType;
        $definition = Attrs::definition($type);

        expect($type->normalize('1.5', $definition))->toBe('1.50')
            ->and($type->normalize(3, $definition))->toBe('3.00')
            ->and($type->normalize('1.500', $definition))->toBe('1.50')
            ->and($type->normalize('-0.00', $definition))->toBe('0.00')
            ->and($type->normalize($type->normalize('2.1', $definition), $definition))->toBe('2.10');
    });

    it('rejects floats, extra places, junk and out-of-range values', function (): void {
        $type = new DecimalType;
        $definition = Attrs::definition($type, ['min' => '0', 'max' => '10']);

        foreach ([1.5, '1.555', '1e2', '+1', ' 1', '', 'x', null, true, '-0.01', '10.01', '1,5'] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }

        expect($type->normalize('10', $definition))->toBe('10.00')
            ->and($type->normalize('0', $definition))->toBe('0.00');
    });

    it('indexes a number', function (): void {
        $type = new DecimalType;

        expect($type->index('1.50', Attrs::definition($type))[0]->number)->toBe('1.50')
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::In, FilterOperator::Range]);
    });
});

describe('measure', function (): void {
    it('requires a valid unit and canonicalizes the config', function (): void {
        $type = new MeasureType;

        expect($type->config(['unit' => 'kg']))->toBe(['scale' => 3, 'unit' => 'kg'])
            ->and($type->config($type->config(['unit' => 'gsm', 'scale' => 0])))->toBe(['scale' => 0, 'unit' => 'gsm'])
            ->and(Attrs::rejectsConfig($type, []))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['unit' => 'g/m2']))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['unit' => 'kg', 'scale' => 13]))->toBeTrue()
            ->and(Attrs::rejectsConfig($type, ['unit' => 5]))->toBeTrue();
    });

    it('accepts a Measure or its array form', function (): void {
        $type = new MeasureType;
        $definition = Attrs::definition($type, ['unit' => 'kg']);

        $value = $type->normalize(Measure::of('1.5', 'kg'), $definition);

        expect($value)->toBe(['unit' => 'kg', 'value' => '1.500'])
            ->and($type->normalize($value, $definition))->toBe($value)
            ->and($type->normalize(['value' => 2, 'unit' => 'kg'], $definition))->toBe(['unit' => 'kg', 'value' => '2.000']);
    });

    it('rejects another unit, floats, extra places and malformed arrays', function (): void {
        $type = new MeasureType;
        $definition = Attrs::definition($type, ['unit' => 'kg']);

        foreach ([
            Measure::of('1', 'g'), ['value' => '1', 'unit' => 'g'], ['value' => 1.5, 'unit' => 'kg'], ['value' => '1.2345', 'unit' => 'kg'],
            ['value' => '1'], ['value' => '1', 'unit' => 'kg', 'x' => 1], [], '1 kg', 1, null,
        ] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }
    });

    it('indexes the number', function (): void {
        $type = new MeasureType;
        $definition = Attrs::definition($type, ['unit' => 'kg']);

        expect($type->index(['unit' => 'kg', 'value' => '1.500'], $definition)[0]->number)->toBe('1.500')
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::Range]);
    });
});
