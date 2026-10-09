<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Enums\FilterOperator;
use Commerz\Catalog\Attributes\Internal\Types\ReferenceType;
use Commerz\Catalog\Tests\Support\Attrs;
use Commerz\Identity\CustomerRef;

it('requires a ref type in the config', function (): void {
    $type = new ReferenceType;

    expect($type->config(['refType' => 'brand']))->toBe(['refType' => 'brand'])
        ->and($type->config($type->config(['refType' => 'brand'])))->toBe(['refType' => 'brand'])
        ->and(Attrs::rejectsConfig($type, []))->toBeTrue()
        ->and(Attrs::rejectsConfig($type, ['refType' => 'Brand']))->toBeTrue()
        ->and(Attrs::rejectsConfig($type, ['refType' => 'brand', 'x' => 1]))->toBeTrue();
});

it('accepts a Ref or type:id text of the configured type', function (): void {
    $type = new ReferenceType;
    $definition = Attrs::definition($type, ['refType' => 'brand']);

    expect($type->normalize('brand:acme:1', $definition))->toBe('brand:acme:1')
        ->and($type->normalize(CustomerRef::of('brand', '42'), $definition))->toBe('brand:42')
        ->and($type->normalize($type->normalize('brand:7', $definition), $definition))->toBe('brand:7');
});

it('rejects another type, malformed text and bad ids', function (): void {
    $type = new ReferenceType;
    $definition = Attrs::definition($type, ['refType' => 'brand']);

    foreach (['maker:1', 'brand', 'brand:', ':1', "brand:a\x01b", 'brand:'.str_repeat('x', 121), 5, null, ['brand:1'], CustomerRef::of('maker', '1')] as $input) {
        expect(Attrs::rejects($type, $input, $definition))->toBeTrue();
    }
});

it('indexes the ref as a term', function (): void {
    $type = new ReferenceType;

    expect($type->index('brand:7', Attrs::definition($type, ['refType' => 'brand']))[0]->term)->toBe('brand:7')
        ->and($type->operators())->toBe([FilterOperator::Equals, FilterOperator::In]);
});
