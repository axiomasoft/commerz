<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Internal\Types\EnumType;
use Commerz\Catalog\Attributes\Internal\Types\MultiEnumType;
use Commerz\Catalog\Tests\Support\Attrs;

describe('enum', function (): void {
    it('owns choices and has an empty config', function (): void {
        $type = new EnumType;

        expect($type->usesChoices())->toBeTrue()
            ->and($type->config([]))->toBe([])
            ->and(Attrs::rejectsConfig($type, ['x' => 1]))->toBeTrue();
    });

    it('accepts only a known choice code', function (): void {
        $type = new EnumType;
        $definition = Attrs::definition($type, [], ['red', 'blue']);

        expect($type->normalize('red', $definition))->toBe('red')
            ->and($type->normalize($type->normalize('blue', $definition), $definition))->toBe('blue');

        foreach (['green', 'Red', '', 1, null, ['red']] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }
    });

    it('indexes the choice id', function (): void {
        $type = new EnumType;
        $definition = Attrs::definition($type, [], ['red', 'blue']);
        $facts = $type->index('blue', $definition);

        expect($facts)->toHaveCount(1)
            ->and($facts[0]->choice)->toBe($definition->choice('blue')->id)
            ->and($type->index('green', $definition))->toBe([])
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::In]);
    });
});

describe('multi_enum', function (): void {
    it('orders by position, then code, and collapses duplicates', function (): void {
        $type = new MultiEnumType;
        $definition = Attrs::definition($type, [], ['s', 'm', 'l']);

        $value = $type->normalize(['l', 's', 'l', 'm'], $definition);

        expect($type->usesChoices())->toBeTrue()
            ->and($value)->toBe(['s', 'm', 'l'])
            ->and($type->normalize($value, $definition))->toBe($value);
    });

    it('rejects empty, unknown, non-list and non-string input', function (): void {
        $type = new MultiEnumType;
        $definition = Attrs::definition($type, [], ['s', 'm']);

        foreach ([[], ['x'], ['s', 'x'], ['a' => 's'], 's', null, [1], [['s']]] as $input) {
            expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
        }
    });

    it('indexes a fact per choice', function (): void {
        $type = new MultiEnumType;
        $definition = Attrs::definition($type, [], ['s', 'm']);
        $facts = $type->index(['s', 'm'], $definition);

        expect($facts)->toHaveCount(2)
            ->and($facts[1]->choice)->toBe($definition->choice('m')->id)
            ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::In]);
    });
});
