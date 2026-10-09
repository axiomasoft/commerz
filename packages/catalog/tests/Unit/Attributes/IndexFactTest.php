<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\AttributeChoiceId;
use Commerz\Catalog\Attributes\Data\AttributeDefinition;
use Commerz\Catalog\Attributes\Data\IndexFact;

it('sets exactly one field per fact', function (): void {
    $id = AttributeChoiceId::generate();

    foreach ([IndexFact::choice($id), IndexFact::number('-1.5'), IndexFact::flag(false), IndexFact::day('2026-01-02'), IndexFact::term('x')] as $fact) {
        $set = array_filter((array) $fact, static fn (mixed $value): bool => $value !== null);

        expect($set)->toHaveCount(1);
    }
});

it('rejects malformed numbers and days', function (): void {
    expect(fn () => IndexFact::number('1e3'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IndexFact::number(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IndexFact::day('2026-1-2'))->toThrow(InvalidArgumentException::class);
});

it('finds a choice by code', function (): void {
    $definition = new AttributeDefinition('attr', []);

    expect($definition->choice('x'))->toBeNull();
});
