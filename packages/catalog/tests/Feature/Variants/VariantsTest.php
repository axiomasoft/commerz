<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\AddOptionValues;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Variants\Contracts\Variants;
use Commerz\Catalog\Variants\Data\ArchiveVariant;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Catalog\Variants\Data\Dimensions;
use Commerz\Catalog\Variants\Data\GeneratedVariants;
use Commerz\Catalog\Variants\Data\GenerateVariants;
use Commerz\Catalog\Variants\Data\ListVariants;
use Commerz\Catalog\Variants\Data\PlannedVariant;
use Commerz\Catalog\Variants\Data\RestoreVariant;
use Commerz\Catalog\Variants\Data\SetVariantAttributes;
use Commerz\Catalog\Variants\Data\UpdateVariant;
use Commerz\Catalog\Variants\Data\VariantId;
use Commerz\Catalog\Variants\Data\VariantView;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Catalog\Variants\Events\VariantCreated;
use Commerz\Catalog\Variants\Events\VariantUpdated;
use Commerz\Catalog\Variants\Exceptions\DuplicateCombination;
use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Catalog\Variants\Exceptions\TooManyVariants;
use Commerz\Catalog\Variants\Exceptions\VariantNotFound;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Measures\LocalizedText;
use Commerz\Pagination\Cursor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function varLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/**
 * Options color (red, blue) and size (s, m); attributes material (product), fit and note (variant); an `apparel`
 * type with axes color, size and a `gift` type without axes. Returns the tee and the gift card.
 *
 * @return array{ProductView, ProductView}
 */
function varCatalog(bool $requiredFit = false): array
{
    Catalog::options()->define(new DefineOption('color', varLabel('Color'), ['red' => varLabel('Red'), 'blue' => varLabel('Blue')]));
    Catalog::options()->define(new DefineOption('size', varLabel('Size'), ['s' => varLabel('S'), 'm' => varLabel('M')]));
    Catalog::attributes()->define(new DefineAttribute('material', 'enum', varLabel('Material'), choices: ['cotton' => varLabel('Cotton')], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('fit', 'text', varLabel('Fit'), filterable: true));
    Catalog::attributes()->define(new DefineAttribute('note', 'text', varLabel('Note')));

    $apparel = Catalog::productTypes()->create(new CreateProductType(
        'apparel',
        varLabel('Apparel'),
        productAttributes: ['material'],
        variantAttributes: ['fit', 'note'],
        variantAxes: ['color', 'size'],
        required: $requiredFit ? ['fit'] : [],
    ));
    $gift = Catalog::productTypes()->create(new CreateProductType('gift', varLabel('Gift'), variantAttributes: ['note']));

    return [
        Catalog::products()->create(new CreateProduct($apparel->id, 'basic-tee', varLabel('Tee'))),
        Catalog::products()->create(new CreateProduct($gift->id, 'gift-card', varLabel('Gift card'))),
    ];
}

/** @param  list<array<string, string>>  $except */
function varGenerate(ProductView $tee, array $except = [['color' => 'blue', 'size' => 's']], bool $dryRun = false): GeneratedVariants
{
    return Catalog::variants()->generate(new GenerateVariants(
        $tee->id,
        ['color' => ['red', 'blue'], 'size' => ['s', 'm']],
        'TEE-{color}-{size}',
        $except,
        $dryRun,
    ));
}

it('exposes variants as the bound singleton', function (): void {
    expect(Catalog::variants())->toBe(app(Variants::class));
});

it('gives a product without axes exactly one variant with the empty signature', function (): void {
    [, $gift] = varCatalog();

    $variant = Catalog::variants()->create(new CreateVariant(
        $gift->id,
        'GIFT-100',
        barcode: '4600000000001',
        attributes: ['note' => 'printed'],
        taxClass: 'vat-0',
        weightGrams: 10,
        dimensions: new Dimensions(90, 55, 1),
    ));

    expect($variant->product->equals($gift->id))->toBeTrue()
        ->and($variant->sku)->toBe('GIFT-100')
        ->and($variant->barcode)->toBe('4600000000001')
        ->and($variant->axes)->toBe([])
        ->and($variant->status)->toBe(VariantStatus::Active)
        ->and($variant->position)->toBe(0)
        ->and($variant->attributes)->toBe(['note' => 'printed'])
        ->and($variant->taxClass)->toBe('vat-0')
        ->and($variant->weightGrams)->toBe(10)
        ->and($variant->dimensions)->toEqual(new Dimensions(90, 55, 1))
        ->and($variant->sellable->equals(SellableRef::of('variant', $variant->id->value)))->toBeTrue()
        ->and(DB::table('cmz_variants')->value('option_signature'))->toBe('')
        ->and(fn () => Catalog::variants()->create(new CreateVariant($gift->id, 'GIFT-200')))->toThrow(DuplicateCombination::class)
        ->and(Catalog::variants()->findBySku('GIFT-100')->id->equals($variant->id))->toBeTrue();
});

it('creates a variant with the full combination and refuses a second one', function (): void {
    [$tee] = varCatalog();
    Event::fake([VariantCreated::class]);

    $variant = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['size' => 'm', 'color' => 'red'], attributes: ['fit' => 'slim']));

    expect($variant->axes)->toBe(['color' => 'red', 'size' => 'm'])
        ->and($variant->attributes)->toBe(['fit' => 'slim'])
        ->and(DB::table('cmz_variant_option_values')->count())->toBe(2)
        ->and(DB::table('cmz_attribute_index')->whereNotNull('variant_id')->count())->toBe(1);

    Event::assertDispatched(VariantCreated::class, fn (VariantCreated $event): bool => $event->variant->id->equals($variant->id));

    try {
        Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-2', ['color' => 'red', 'size' => 'm']));
        $this->fail('A second variant of one combination was created.');
    } catch (DuplicateCombination $e) {
        expect($e->axes)->toBe(['color' => 'red', 'size' => 'm'])
            ->and($e->context())->toBe(['axes' => 'color=red,size=m']);
    }

    expect(fn () => Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'blue', 'size' => 'm'])))
        ->toThrow(DuplicateCode::class)
        ->and(DB::table('cmz_variants')->count())->toBe(1);
});

it('refuses axes that do not match the product', function (array $axes): void {
    [$tee] = varCatalog();

    expect(fn () => Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-X', $axes)))->toThrow(InvalidAxes::class)
        ->and(DB::table('cmz_variants')->count())->toBe(0);
})->with([
    'incomplete' => [['color' => 'red']],
    'none' => [[]],
    'extra' => [['color' => 'red', 'size' => 'm', 'fabric' => 'x']],
    'unknown value' => [['color' => 'green', 'size' => 'm']],
]);

it('generates the sparse set of the blueprint example', function (): void {
    [$tee] = varCatalog();
    Event::fake([VariantCreated::class]);

    $result = varGenerate($tee);

    expect($result->dryRun)->toBeFalse()
        ->and(array_map(fn (PlannedVariant $planned): string => $planned->sku, $result->planned))->toBe(['TEE-red-s', 'TEE-red-m', 'TEE-blue-m'])
        ->and(array_map(fn (VariantView $variant): string => $variant->sku, $result->created))->toBe(['TEE-red-s', 'TEE-red-m', 'TEE-blue-m'])
        ->and(array_map(fn (VariantView $variant): int => $variant->position, $result->created))->toBe([0, 1, 2])
        ->and($result->created[2]->axes)->toBe(['color' => 'blue', 'size' => 'm'])
        ->and($result->skipped)->toBe([]);

    Event::assertDispatchedTimes(VariantCreated::class, 3);
});

it('skips every existing combination when the generation is repeated', function (): void {
    [$tee] = varCatalog();
    varGenerate($tee);

    $again = varGenerate($tee, except: []);

    expect(array_map(fn (VariantView $variant): string => $variant->sku, $again->created))->toBe(['TEE-blue-s'])
        ->and($again->skipped)->toBe([['color' => 'red', 'size' => 's'], ['color' => 'red', 'size' => 'm'], ['color' => 'blue', 'size' => 'm']])
        ->and(varGenerate($tee, except: [])->created)->toBe([])
        ->and(DB::table('cmz_variants')->count())->toBe(4);
});

it('plans a dry run without writing', function (): void {
    [$tee] = varCatalog();

    $plan = varGenerate($tee, dryRun: true);

    expect($plan->dryRun)->toBeTrue()
        ->and($plan->planned)->toHaveCount(3)
        ->and($plan->created)->toBe([])
        ->and(DB::table('cmz_variants')->count())->toBe(0);
});

it('refuses a generation over the limit before writing', function (): void {
    [$tee] = varCatalog();
    $colors = array_map(fn (int $n): string => "c{$n}", range(1, 11));
    $sizes = array_map(fn (int $n): string => "s{$n}", range(1, 10));
    $color = Catalog::options()->findByCode('color');
    $size = Catalog::options()->findByCode('size');
    Catalog::options()->addValues(new AddOptionValues($color->id, array_fill_keys($colors, varLabel('C'))));
    Catalog::options()->addValues(new AddOptionValues($size->id, array_fill_keys($sizes, varLabel('S'))));

    try {
        Catalog::variants()->generate(new GenerateVariants($tee->id, ['color' => $colors, 'size' => $sizes], 'T-{color}-{size}'));
        $this->fail('The limit was not enforced.');
    } catch (TooManyVariants $e) {
        expect($e->requested)->toBe(110)->and($e->limit)->toBe(100);
    }

    $within = Catalog::variants()->generate(new GenerateVariants(
        $tee->id,
        ['color' => $colors, 'size' => $sizes],
        'T-{color}-{size}',
        array_map(fn (string $size): array => ['color' => 'c1', 'size' => $size], $sizes),
        dryRun: true,
    ));

    expect($within->planned)->toHaveCount(100)
        ->and(DB::table('cmz_variants')->count())->toBe(0);
});

it('rolls the whole generation back on a SKU collision', function (): void {
    [$tee, $gift] = varCatalog();
    Catalog::variants()->create(new CreateVariant($gift->id, 'TEE-blue-m'));

    try {
        varGenerate($tee);
        $this->fail('The SKU collision was not reported.');
    } catch (DuplicateCode $e) {
        expect($e->duplicate)->toBe('TEE-blue-m');
    }

    expect(DB::table('cmz_variants')->where('product_id', $tee->id->value)->count())->toBe(0)
        ->and(DB::table('cmz_variant_option_values')->count())->toBe(0);
});

it('checks the SKU pattern and the axes of a generation', function (): void {
    [$tee] = varCatalog();
    $generate = fn (array $axes, string $pattern, array $except = []) => fn () => Catalog::variants()->generate(new GenerateVariants($tee->id, $axes, $pattern, $except));
    $both = ['color' => ['red', 'blue'], 'size' => ['s', 'm']];

    expect($generate($both, 'TEE-{color}'))->toThrow(InvalidCommand::class, 'size')
        ->and($generate($both, 'TEE-{color}-{size}-{fabric}'))->toThrow(InvalidCommand::class, 'fabric')
        ->and($generate(['color' => ['red'], 'size' => ['s', 'm']], '{size} {color}'))->toThrow(InvalidCommand::class)
        ->and($generate(['color' => ['red']], 'TEE-{color}'))->toThrow(InvalidAxes::class)
        ->and($generate(['color' => ['red', 'green'], 'size' => ['s']], 'TEE-{color}'))->toThrow(InvalidAxes::class)
        ->and($generate($both, 'TEE-{color}-{size}', [['color' => 'red']]))->toThrow(InvalidAxes::class)
        ->and(fn () => new GenerateVariants($tee->id, ['color' => []], 'X'))->toThrow(InvalidCommand::class)
        ->and(fn () => new GenerateVariants($tee->id, ['color' => ['red', 'red']], 'X'))->toThrow(InvalidCommand::class)
        ->and(DB::table('cmz_variants')->count())->toBe(0);

    $single = Catalog::variants()->generate(new GenerateVariants($tee->id, ['color' => ['red'], 'size' => ['m']], 'TEE-RED-M'));

    expect($single->created[0]->sku)->toBe('TEE-RED-M');
});

it('refuses to generate variants of a type with required variant attributes', function (): void {
    [$tee] = varCatalog(requiredFit: true);

    expect(fn () => varGenerate($tee))->toThrow(MissingRequiredAttributes::class)
        ->and(fn () => Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm'])))->toThrow(MissingRequiredAttributes::class)
        ->and(DB::table('cmz_variants')->count())->toBe(0)
        ->and(Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm'], attributes: ['fit' => 'slim']))->attributes)->toBe(['fit' => 'slim']);
});

it('archives and restores a variant whose combination stays taken', function (): void {
    [$tee] = varCatalog();
    $variant = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm']));

    $archived = Catalog::variants()->archive(new ArchiveVariant($variant->id));

    expect($archived->status)->toBe(VariantStatus::Archived)
        ->and(Catalog::variants()->archive(new ArchiveVariant($variant->id))->updatedAt)->toEqual($archived->updatedAt)
        ->and(fn () => Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-2', ['color' => 'red', 'size' => 'm'])))->toThrow(DuplicateCombination::class)
        ->and(varGenerate($tee, except: [])->skipped)->toContain(['color' => 'red', 'size' => 'm'])
        ->and(Catalog::variants()->restore(new RestoreVariant($variant->id))->status)->toBe(VariantStatus::Active);

    Catalog::variants()->archive(new ArchiveVariant($variant->id));
    Catalog::products()->archive(new ArchiveProduct($tee->id));

    expect(fn () => Catalog::variants()->restore(new RestoreVariant($variant->id)))->toThrow(ProductArchived::class)
        ->and(fn () => Catalog::variants()->archive(new ArchiveVariant(VariantId::generate())))->toThrow(VariantNotFound::class);
});

it('refuses new variants and changes on an archived product', function (): void {
    [$tee] = varCatalog();
    $variant = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm']));
    Catalog::products()->archive(new ArchiveProduct($tee->id));

    expect(fn () => Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-2', ['color' => 'red', 'size' => 's'])))->toThrow(ProductArchived::class)
        ->and(fn () => varGenerate($tee))->toThrow(ProductArchived::class)
        ->and(fn () => varGenerate($tee, dryRun: true))->toThrow(ProductArchived::class)
        ->and(fn () => Catalog::variants()->update(new UpdateVariant($variant->id, sku: 'TEE-9')))->toThrow(ProductArchived::class)
        ->and(fn () => Catalog::variants()->setAttributes(new SetVariantAttributes($variant->id, ['fit' => 'slim'])))->toThrow(ProductArchived::class)
        ->and(Catalog::variants()->archive(new ArchiveVariant($variant->id))->status)->toBe(VariantStatus::Archived);
});

it('updates fields and clears the named ones', function (): void {
    [$tee] = varCatalog();
    $variant = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm'], barcode: 'B-1', taxClass: 'vat-20', weightGrams: 200, dimensions: new Dimensions(300, 200, 20)));
    Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-2', ['color' => 'red', 'size' => 's'], barcode: 'B-2'));
    Event::fake([VariantUpdated::class]);

    $updated = Catalog::variants()->update(new UpdateVariant($variant->id, sku: 'TEE-RM', weightGrams: 210, position: 5, clear: ['barcode', 'dimensions']));

    expect($updated->sku)->toBe('TEE-RM')
        ->and($updated->barcode)->toBeNull()
        ->and($updated->dimensions)->toBeNull()
        ->and($updated->taxClass)->toBe('vat-20')
        ->and($updated->weightGrams)->toBe(210)
        ->and($updated->position)->toBe(5)
        ->and($updated->axes)->toBe(['color' => 'red', 'size' => 'm'])
        ->and(Catalog::variants()->update(new UpdateVariant($variant->id, sku: 'TEE-RM'))->updatedAt)->toEqual($updated->updatedAt)
        ->and(fn () => Catalog::variants()->update(new UpdateVariant($variant->id, sku: 'TEE-2')))->toThrow(DuplicateCode::class, 'TEE-2')
        ->and(fn () => Catalog::variants()->update(new UpdateVariant($variant->id, barcode: 'B-2')))->toThrow(DuplicateCode::class, 'B-2')
        ->and(fn () => new UpdateVariant($variant->id, barcode: 'B', clear: ['barcode']))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateVariant($variant->id, clear: ['sku']))->toThrow(InvalidCommand::class)
        ->and(fn () => Catalog::variants()->update(new UpdateVariant(VariantId::generate(), sku: 'X')))->toThrow(VariantNotFound::class);

    Event::assertDispatchedTimes(VariantUpdated::class, 1);
});

it('writes variant-level attributes through the writer and refuses product-level ones', function (): void {
    [$tee] = varCatalog();
    $variant = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm']));

    $set = Catalog::variants()->setAttributes(new SetVariantAttributes($variant->id, ['fit' => 'slim', 'note' => 'hi']));

    expect($set->attributes)->toBe(['fit' => 'slim', 'note' => 'hi'])
        ->and(DB::table('cmz_attribute_index')->where('variant_id', $variant->id->value)->count())->toBe(1)
        ->and(Catalog::variants()->setAttributes(new SetVariantAttributes($variant->id, remove: ['fit']))->attributes)->toBe(['note' => 'hi'])
        ->and(DB::table('cmz_attribute_index')->where('variant_id', $variant->id->value)->count())->toBe(0)
        ->and(fn () => Catalog::variants()->setAttributes(new SetVariantAttributes($variant->id, ['material' => 'cotton'])))->toThrow(AttributeNotAllowed::class)
        ->and(fn () => Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-2', ['color' => 'red', 'size' => 's'], attributes: ['material' => 'cotton'])))->toThrow(AttributeNotAllowed::class)
        ->and(DB::table('cmz_variants')->count())->toBe(1);
});

it('lists variants by position with a keyset cursor', function (): void {
    [$tee] = varCatalog();
    $created = varGenerate($tee, except: [])->created;
    Catalog::variants()->archive(new ArchiveVariant($created[1]->id));

    $first = Catalog::variants()->list(new ListVariants($tee->id, first: 2));
    $rest = Catalog::variants()->list(new ListVariants($tee->id, first: 2, after: $first->next));
    $all = Catalog::variants()->list(new ListVariants($tee->id, includeArchived: true));
    $sku = fn (VariantView $variant): string => $variant->sku;

    expect(array_map($sku, $first->items))->toBe(['TEE-red-s', 'TEE-blue-s'])
        ->and(array_map($sku, $rest->items))->toBe(['TEE-blue-m'])
        ->and($rest->next)->toBeNull()
        ->and($all->items)->toHaveCount(4);
});

it('keeps an option value of a foreign option out of a combination (CAT-01 foreign keys)', function (): void {
    [$tee] = varCatalog();
    $variant = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-1', ['color' => 'red', 'size' => 'm']));
    $color = Catalog::options()->findByCode('color');
    $size = Catalog::options()->findByCode('size');

    expect(fn () => DB::transaction(fn () => DB::table('cmz_variant_option_values')
        ->where('variant_id', $variant->id->value)
        ->where('option_id', $size->id->value)
        ->update(['option_value_id' => $color->values[0]->id->value])))->toThrow(QueryException::class);
});

it('generates variants for numeric option codes without renumbering axes', function (): void {
    $label = varLabel('Numeric');
    Catalog::options()->define(new DefineOption('7', $label, ['a' => $label, 'b' => $label]));
    Catalog::options()->define(new DefineOption('9', $label, ['c' => $label, 'd' => $label]));
    $type = Catalog::productTypes()->create(new CreateProductType('numeric', $label, variantAxes: ['7', '9']));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'numeric', $label));
    $generated = Catalog::variants()->generate(new GenerateVariants($product->id, ['7' => ['a', 'b'], '9' => ['c', 'd']], 'NUM-{7}-{9}'));

    expect($generated->created)->toHaveCount(4)
        ->and($generated->created[0]->axes)->toBe([7 => 'a', 9 => 'c'])
        ->and($generated->created[3]->axes)->toBe([7 => 'b', 9 => 'd']);
});

it('round trips int64 measurements and positions accepted by variant commands', function (): void {
    [, $gift] = varCatalog();
    $large = 2_147_483_648;
    $variant = Catalog::variants()->create(new CreateVariant($gift->id, 'LARGE', weightGrams: $large, dimensions: new Dimensions($large, $large, $large), position: $large));
    expect($variant->weightGrams)->toBe($large)
        ->and($variant->dimensions->lengthMm)->toBe($large)
        ->and($variant->position)->toBe($large);
    $updated = Catalog::variants()->update(new UpdateVariant($variant->id, weightGrams: PHP_INT_MAX, position: PHP_INT_MAX));
    expect($updated->weightGrams)->toBe(PHP_INT_MAX)->and($updated->position)->toBe(PHP_INT_MAX);
});

it('rejects a malformed variant cursor before it reaches PostgreSQL', function (string $position): void {
    [, $gift] = varCatalog();
    expect(fn () => Catalog::variants()->list(new ListVariants($gift->id, after: Cursor::after($position))))
        ->toThrow(InvalidCommand::class, 'valid cursor');
})->with([
    'invalid UUID layout' => ['0:'.str_repeat('-', 36)],
    'position exceeds int64' => ['999999999999999999999:0190a0a0-0000-7000-8000-000000000001'],
]);

it('uses the catalog page limit for variants', function (): void {
    expect((new ListVariants(ProductId::generate(), first: 200))->first)->toBe(200)
        ->and(fn () => new ListVariants(ProductId::generate(), first: 201))->toThrow(InvalidCommand::class);
});

it('refuses to append positions beyond int64 without writing a variant', function (bool $generate): void {
    [$tee] = varCatalog();
    Catalog::variants()->create(new CreateVariant($tee->id, 'LAST', ['color' => 'red', 'size' => 's'], position: $generate ? PHP_INT_MAX - 1 : PHP_INT_MAX));
    expect(fn () => $generate
        ? varGenerate($tee)
        : Catalog::variants()->create(new CreateVariant($tee->id, 'NEXT', ['color' => 'blue', 'size' => 'm'])))
        ->toThrow(InvalidCommand::class, 'int64');
    expect(DB::table('cmz_variants')->where('product_id', $tee->id->value)->count())->toBe(1);
})->with([false, true]);
