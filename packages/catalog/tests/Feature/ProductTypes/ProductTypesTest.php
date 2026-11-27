<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\ProductTypes\Data\ArchiveProductType;
use Commerz\Catalog\ProductTypes\Data\AttributeSlotView;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ListProductTypes;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Data\UpdateProductType;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\ProductTypes\Enums\ProductTypeStatus;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

function ptLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/**
 * Defines text attributes and options with the given codes.
 *
 * @param  list<string>  $attributes
 * @param  list<string>  $options
 */
function ptVocabulary(array $attributes = ['material', 'density', 'color_name', 'weave'], array $options = ['color', 'size']): void
{
    foreach ($attributes as $code) {
        Catalog::attributes()->define(new DefineAttribute($code, 'text', ptLabel($code)));
    }

    foreach ($options as $code) {
        Catalog::options()->define(new DefineOption($code, ptLabel($code), ['a' => ptLabel('A')]));
    }
}

it('exposes productTypes as the bound singleton', function (): void {
    expect(Catalog::productTypes())->toBe(app(ProductTypes::class));
});

it('contributes the three built-in product kinds', function (): void {
    $kinds = app(Contributions::class);
    $physical = $kinds->get(ProductKind::KIND, 'physical', ProductKind::class);
    $digital = $kinds->get(ProductKind::KIND, 'digital', ProductKind::class);
    $service = $kinds->get(ProductKind::KIND, 'service', ProductKind::class);

    expect($physical->sellableKind())->toBe(SellableKind::Physical)
        ->and($physical->requiresShipping())->toBeTrue()
        ->and($digital->sellableKind())->toBe(SellableKind::Digital)
        ->and($digital->requiresShipping())->toBeFalse()
        ->and($service->sellableKind())->toBe(SellableKind::Service)
        ->and($service->requiresShipping())->toBeFalse();
});

it('creates a type with slots on two levels, required flags and ordered axes', function (): void {
    ptVocabulary();

    $view = Catalog::productTypes()->create(new CreateProductType(
        'apparel',
        ptLabel('Apparel'),
        productAttributes: ['material', 'density'],
        variantAttributes: ['color_name'],
        variantAxes: ['size', 'color'],
        required: ['material', 'color_name'],
    ));

    expect($view->kind)->toBe('physical')
        ->and($view->status)->toBe(ProductTypeStatus::Active)
        ->and($view->axes)->toBe(['size', 'color'])
        ->and(array_map(static fn (AttributeSlotView $s) => [$s->code, $s->level, $s->required, $s->position], $view->slots))->toBe([
            ['material', AttributeLevel::Product, true, 0],
            ['density', AttributeLevel::Product, false, 1],
            ['color_name', AttributeLevel::Variant, true, 0],
        ])
        ->and(Catalog::productTypes()->findByCode('apparel')->id->equals($view->id))->toBeTrue()
        ->and(Catalog::productTypes()->find($view->id)->slots)->toHaveCount(3);
});

it('creates a bare type of another kind', function (): void {
    $view = Catalog::productTypes()->create(new CreateProductType('course', ptLabel('Course'), 'service'));

    expect($view->kind)->toBe('service')->and($view->slots)->toBe([])->and($view->axes)->toBe([]);
});

it('rejects unknown kinds, attributes and options and duplicate codes', function (): void {
    ptVocabulary();

    expect(fn () => Catalog::productTypes()->create(new CreateProductType('t', ptLabel('T'), 'acme.x')))
        ->toThrow(UnsupportedContribution::class)
        ->and(fn () => Catalog::productTypes()->create(new CreateProductType('t', ptLabel('T'), productAttributes: ['ghost'])))
        ->toThrow(AttributeNotFound::class)
        ->and(fn () => Catalog::productTypes()->create(new CreateProductType('t', ptLabel('T'), variantAxes: ['ghost'])))
        ->toThrow(OptionNotFound::class);

    Catalog::productTypes()->create(new CreateProductType('t', ptLabel('T')));

    expect(fn () => Catalog::productTypes()->create(new CreateProductType('t', ptLabel('Again'))))->toThrow(DuplicateCode::class);
    expect(Catalog::productTypes()->list(new ListProductTypes)->items)->toHaveCount(1);
});

it('rejects retired attributes', function (): void {
    ptVocabulary();
    $attribute = Catalog::attributes()->findByCode('material');
    Catalog::attributes()->update(new UpdateAttribute($attribute->id, status: AttributeStatus::Retired));

    expect(fn () => Catalog::productTypes()->create(new CreateProductType('t', ptLabel('T'), productAttributes: ['material'])))
        ->toThrow(InvalidProductType::class);
});

it('validates the shape of a create command', function (): void {
    expect(fn () => new CreateProductType('t', ptLabel('T'), productAttributes: ['a'], variantAttributes: ['a']))->toThrow(InvalidProductType::class)
        ->and(fn () => new CreateProductType('t', ptLabel('T'), productAttributes: ['a', 'a']))->toThrow(InvalidProductType::class)
        ->and(fn () => new CreateProductType('t', ptLabel('T'), productAttributes: ['a'], required: ['b']))->toThrow(InvalidProductType::class)
        ->and(fn () => new CreateProductType('T', ptLabel('T')))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListProductTypes(first: 0))->toThrow(InvalidCommand::class);

    $axes = array_map(static fn (int $i): string => "axis{$i}", range(1, 9));

    try {
        new CreateProductType('t', ptLabel('T'), variantAxes: $axes);
        Assert::fail('Nine axes must be refused.');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('catalog.too_many_axes');
    }

    expect((new CreateProductType('t', ptLabel('T'), variantAxes: array_slice($axes, 0, 8)))->variantAxes)->toHaveCount(8);
});

it('renames a type and appends optional slots after the existing ones', function (): void {
    ptVocabulary();
    $view = Catalog::productTypes()->create(new CreateProductType('apparel', ptLabel('Apparel'), productAttributes: ['material'], variantAxes: ['color']));

    $updated = Catalog::productTypes()->update(new UpdateProductType(
        $view->id,
        ptLabel('Clothes'),
        addProductAttributes: ['density'],
        addVariantAttributes: ['color_name'],
    ));

    expect($updated->name->texts)->toBe(['en' => 'Clothes'])
        ->and($updated->axes)->toBe(['color'])
        ->and(array_map(static fn (AttributeSlotView $s) => [$s->code, $s->level->value, $s->required, $s->position], $updated->slots))->toBe([
            ['material', 'product', false, 0],
            ['density', 'product', false, 1],
            ['color_name', 'variant', false, 0],
        ]);
});

it('refuses to repeat a slot or to add a missing attribute', function (): void {
    ptVocabulary();
    $view = Catalog::productTypes()->create(new CreateProductType('apparel', ptLabel('Apparel'), productAttributes: ['material']));

    expect(fn () => Catalog::productTypes()->update(new UpdateProductType($view->id, addVariantAttributes: ['material'])))
        ->toThrow(InvalidProductType::class)
        ->and(fn () => Catalog::productTypes()->update(new UpdateProductType($view->id, addProductAttributes: ['ghost'])))
        ->toThrow(AttributeNotFound::class)
        ->and(fn () => Catalog::productTypes()->update(new UpdateProductType(ProductTypeId::generate(), ptLabel('X'))))
        ->toThrow(ProductTypeNotFound::class)
        ->and(fn () => new UpdateProductType($view->id, addProductAttributes: ['a'], addVariantAttributes: ['a']))
        ->toThrow(InvalidProductType::class);
    expect(Catalog::productTypes()->find($view->id)->slots)->toHaveCount(1);
});

it('archives idempotently and then refuses updates', function (): void {
    ptVocabulary();
    $view = Catalog::productTypes()->create(new CreateProductType('apparel', ptLabel('Apparel')));

    $archived = Catalog::productTypes()->archive(new ArchiveProductType($view->id));
    $again = Catalog::productTypes()->archive(new ArchiveProductType($view->id));

    expect($archived->status)->toBe(ProductTypeStatus::Archived)
        ->and($again->status)->toBe(ProductTypeStatus::Archived)
        ->and(fn () => Catalog::productTypes()->update(new UpdateProductType($view->id, ptLabel('X'))))->toThrow(ProductTypeArchived::class)
        ->and(fn () => Catalog::productTypes()->archive(new ArchiveProductType(ProductTypeId::generate())))->toThrow(ProductTypeNotFound::class);
});

it('lists by code with a keyset cursor and hides archived types', function (): void {
    foreach (['c', 'a', 'b', 'd'] as $code) {
        Catalog::productTypes()->create(new CreateProductType($code, ptLabel($code)));
    }

    Catalog::productTypes()->archive(new ArchiveProductType(Catalog::productTypes()->findByCode('d')->id));

    $first = Catalog::productTypes()->list(new ListProductTypes(first: 2));
    $second = Catalog::productTypes()->list(new ListProductTypes(first: 2, after: $first->next));

    expect(array_map(static fn (object $v) => $v->code, $first->items))->toBe(['a', 'b'])
        ->and($first->hasMore())->toBeTrue()
        ->and(array_map(static fn (object $v) => $v->code, $second->items))->toBe(['c'])
        ->and($second->hasMore())->toBeFalse()
        ->and(Catalog::productTypes()->list(new ListProductTypes(includeArchived: true))->items)->toHaveCount(4);
});
