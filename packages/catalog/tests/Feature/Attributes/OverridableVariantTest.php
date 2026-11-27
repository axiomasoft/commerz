<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\ProductTypes\Data\AttributeSlotView;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\UpdateProductType;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Catalog\Tests\Support\CatalogEngineSqliteHarness;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Catalog\Variants\Data\SetVariantAttributes;
use Commerz\Measures\LocalizedText;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $booted = CatalogEngineSqliteHarness::boot();
    $this->sqliteDbPath = $booted['dbPath'];
});

afterEach(function (): void {
    CatalogEngineSqliteHarness::cleanup(CatalogEngineSqliteHarness::path($this->sqliteDbPath));
});

function ovLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/**
 * @return array<string, array{id: int, value: string}> variantId => [rowId, value]
 */
function ovVariantIndexFacts(string $attributeCode): array
{
    $rows = DB::connection('catalog_sqlite')
        ->table('cmz_attribute_index as i')
        ->join('cmz_attributes as a', 'a.id', '=', 'i.attribute_id')
        ->leftJoin('cmz_attribute_choices as c', 'c.id', '=', 'i.choice_id')
        ->where('a.code', $attributeCode)
        ->whereNotNull('i.variant_id')
        ->get(['i.id', 'i.variant_id', 'c.code as choice', 'i.term', 'i.num', 'i.flag']);

    $facts = [];

    foreach ($rows as $row) {
        $variantId = $row->variant_id;
        $id = $row->id;
        if (! is_string($variantId) || (! is_int($id) && (! is_string($id) || ! ctype_digit($id)))) {
            throw new RuntimeException('Attribute index row is missing its id.');
        }

        $choice = $row->choice;
        $term = $row->term;
        $num = $row->num;
        $flag = $row->flag;
        $raw = $choice ?? $term ?? $num;
        $raw ??= (in_array($flag, [true, 1, '1', 't', 'true'], true)) ? 'true' : 'false';
        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            throw new RuntimeException('Attribute index value must be scalar.');
        }

        $value = is_string($raw) ? $raw : (is_int($raw) ? (string) $raw : sprintf('%.10F', $raw));
        $facts[$variantId] = ['id' => is_int($id) ? $id : (int) $id, 'value' => $value];
    }

    return $facts;
}

it('creates and updates product types with overridable_variant slots and rejects cross-level collisions', function (): void {
    Catalog::attributes()->define(new DefineAttribute('brand', 'text', ovLabel('Brand'), filterable: true));
    Catalog::attributes()->define(new DefineAttribute('material', 'enum', ovLabel('Material'), choices: [
        'cotton' => ovLabel('Cotton'),
        'silk' => ovLabel('Silk'),
    ], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('care', 'text', ovLabel('Care'), filterable: true));
    Catalog::attributes()->define(new DefineAttribute('fit', 'text', ovLabel('Fit'), filterable: true));

    expect(fn () => new CreateProductType(
        'bad',
        ovLabel('Bad'),
        productAttributes: ['material'],
        overridableVariantAttributes: ['material'],
    ))->toThrow(InvalidProductType::class)
        ->and(fn () => new CreateProductType(
            'bad2',
            ovLabel('Bad 2'),
            variantAttributes: ['material'],
            overridableVariantAttributes: ['material'],
        ))->toThrow(InvalidProductType::class);

    $type = Catalog::productTypes()->create(new CreateProductType(
        code: 'shirt',
        name: ovLabel('Shirt'),
        productAttributes: ['brand'],
        variantAttributes: ['fit'],
        required: ['material'],
        overridableVariantAttributes: ['material'],
    ));

    $updated = Catalog::productTypes()->update(new UpdateProductType(
        type: $type->id,
        addOverridableVariantAttributes: ['care'],
    ));

    expect(array_map(static fn (AttributeSlotView $s): array => [$s->code, $s->level, $s->required], $updated->slots))->toBe([
        ['brand', AttributeLevel::Product, false],
        ['material', AttributeLevel::OverridableVariant, true],
        ['care', AttributeLevel::OverridableVariant, false],
        ['fit', AttributeLevel::Variant, false],
    ]);
});

it('materializes effective values synchronously and reindexes only unoverridden variants on product save', function (): void {
    Catalog::options()->define(new DefineOption('color', ovLabel('Color'), [
        'red' => ovLabel('Red'),
        'blue' => ovLabel('Blue'),
        'green' => ovLabel('Green'),
    ]));

    Catalog::attributes()->define(new DefineAttribute('material', 'enum', ovLabel('Material'), choices: [
        'cotton' => ovLabel('Cotton'),
        'linen' => ovLabel('Linen'),
        'silk' => ovLabel('Silk'),
    ], filterable: true));
    Catalog::attributes()->define(new DefineAttribute('origin', 'text', ovLabel('Origin'), filterable: true));

    $type = Catalog::productTypes()->create(new CreateProductType(
        code: 'apparel',
        name: ovLabel('Apparel'),
        variantAxes: ['color'],
        required: ['material'],
        overridableVariantAttributes: ['material', 'origin'],
    ));

    // Missing required overridable_variant attribute on product creation is rejected.
    expect(fn () => CatalogEngineSqliteHarness::createProduct(new CreateProduct($type->id, 'tee', ovLabel('Tee'))))
        ->toThrow(MissingRequiredAttributes::class);

    $product = CatalogEngineSqliteHarness::createProduct(new CreateProduct(
        $type->id,
        'tee',
        ovLabel('Tee'),
        attributes: ['material' => 'cotton', 'origin' => 'Portugal'],
    ));

    // Overridable attributes never write `variant_id IS NULL` rows into cmz_attribute_index.
    expect(DB::connection('catalog_sqlite')->table('cmz_attribute_index')->whereNull('variant_id')->count())->toBe(0);

    // Variant red: no local override -> inherits product fallback ('cotton', 'Portugal').
    $red = Catalog::variants()->create(new CreateVariant($product->id, 'TEE-RED', ['color' => 'red']));
    // Variant blue: local override ('silk', 'Italy').
    $blue = Catalog::variants()->create(new CreateVariant($product->id, 'TEE-BLUE', ['color' => 'blue'], attributes: [
        'material' => 'silk',
        'origin' => 'Italy',
    ]));
    // Variant green: no local override initially -> inherits ('cotton', 'Portugal').
    $green = Catalog::variants()->create(new CreateVariant($product->id, 'TEE-GREEN', ['color' => 'green']));

    $beforeMaterial = ovVariantIndexFacts('material');
    expect($beforeMaterial[$red->id->value]['value'])->toBe('cotton')
        ->and($beforeMaterial[$blue->id->value]['value'])->toBe('silk')
        ->and($beforeMaterial[$green->id->value]['value'])->toBe('cotton');

    $blueRowIdBefore = $beforeMaterial[$blue->id->value]['id'];

    // Change product fallback material from 'cotton' to 'linen' and remove 'origin':
    // - red and green (unoverridden) MUST reindex to 'linen' (and drop 'origin')
    // - blue (locally overridden to 'silk' & 'Italy') MUST stay completely untouched (same row id in cmz_attribute_index!)
    Catalog::products()->setAttributes(new SetProductAttributes(
        $product->id,
        values: ['material' => 'linen'],
        remove: ['origin'],
    ));

    $afterMaterial = ovVariantIndexFacts('material');
    $afterOrigin = ovVariantIndexFacts('origin');

    expect($afterMaterial[$red->id->value]['value'])->toBe('linen')
        ->and($afterMaterial[$green->id->value]['value'])->toBe('linen')
        ->and($afterMaterial[$blue->id->value]['value'])->toBe('silk')
        ->and($afterMaterial[$blue->id->value]['id'])->toBe($blueRowIdBefore)
        ->and($afterOrigin)->toHaveKey($blue->id->value)
        ->and($afterOrigin[$blue->id->value]['value'])->toBe('Italy')
        ->and($afterOrigin)->not->toHaveKey($red->id->value)
        ->and($afterOrigin)->not->toHaveKey($green->id->value);

    // CAT-03 search: red+cotton no longer matches; red+linen matches; blue+silk matches; blue+linen does NOT match!
    $findHandles = static fn (array $filters): array => array_map(
        static fn (ProductView $p): string => $p->handle,
        Catalog::products()->list(new ListProducts(filters: $filters))->items,
    );

    expect($findHandles([VariantFilter::axes(['color' => 'red']), AttributeFilter::equals('material', 'cotton')]))->toBe([])
        ->and($findHandles([VariantFilter::axes(['color' => 'red']), AttributeFilter::equals('material', 'linen')]))->toBe(['tee'])
        ->and($findHandles([VariantFilter::axes(['color' => 'blue']), AttributeFilter::equals('material', 'linen')]))->toBe([])
        ->and($findHandles([VariantFilter::axes(['color' => 'blue']), AttributeFilter::equals('material', 'silk')]))->toBe(['tee'])
        ->and($findHandles([VariantFilter::axes(['color' => 'blue']), AttributeFilter::equals('origin', 'Italy')]))->toBe(['tee']);

    // Removing blue's local override restores the product fallback ('linen') synchronously!
    Catalog::variants()->setAttributes(new SetVariantAttributes($blue->id, remove: ['material', 'origin']));

    $restoredMaterial = ovVariantIndexFacts('material');
    expect($restoredMaterial[$blue->id->value]['value'])->toBe('linen')
        ->and($findHandles([VariantFilter::axes(['color' => 'blue']), AttributeFilter::equals('material', 'linen')]))->toBe(['tee']);
});

it('reindexes overridable_variant slots accurately when filterable toggles or attribute retires', function (): void {
    Catalog::options()->define(new DefineOption('size', ovLabel('Size'), ['s' => ovLabel('S'), 'm' => ovLabel('M')]));
    $weave = Catalog::attributes()->define(new DefineAttribute('weave', 'text', ovLabel('Weave'), filterable: false));

    $type = Catalog::productTypes()->create(new CreateProductType(
        code: 'knit',
        name: ovLabel('Knit'),
        variantAxes: ['size'],
        overridableVariantAttributes: ['weave'],
    ));

    $product = CatalogEngineSqliteHarness::createProduct(new CreateProduct(
        $type->id,
        'sweater',
        ovLabel('Sweater'),
        attributes: ['weave' => 'ribbed'],
    ));
    $s = Catalog::variants()->create(new CreateVariant($product->id, 'SW-S', ['size' => 's']));
    $m = Catalog::variants()->create(new CreateVariant($product->id, 'SW-M', ['size' => 'm'], attributes: ['weave' => 'cable']));

    expect(ovVariantIndexFacts('weave'))->toBe([]);

    Catalog::attributes()->update(new UpdateAttribute($weave->id, filterable: true));

    $indexed = ovVariantIndexFacts('weave');
    expect($indexed[$s->id->value]['value'])->toBe('ribbed')
        ->and($indexed[$m->id->value]['value'])->toBe('cable');

    Catalog::attributes()->update(new UpdateAttribute($weave->id, status: AttributeStatus::Retired));

    expect(ovVariantIndexFacts('weave'))->toBe([]);
});
