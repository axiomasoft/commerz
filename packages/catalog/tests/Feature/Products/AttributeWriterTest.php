<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotWritable;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ProductTypeView;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function awLabel(string $text): LocalizedText
{
    return LocalizedText::of('en', $text);
}

/** A `gear` type with one product-level attribute per kind of projection fact, plus non-filterable `note`. */
function awGear(): ProductTypeView
{
    $attributes = Catalog::attributes();
    $attributes->define(new DefineAttribute('color', 'enum', awLabel('Color'), choices: ['red' => awLabel('Red'), 'blue' => awLabel('Blue')], filterable: true));
    $attributes->define(new DefineAttribute('tags', 'multi_enum', awLabel('Tags'), choices: ['eco' => awLabel('Eco'), 'new' => awLabel('New'), 'hit' => awLabel('Hit')], filterable: true));
    $attributes->define(new DefineAttribute('weight', 'measure', awLabel('Weight'), ['unit' => 'g'], filterable: true));
    $attributes->define(new DefineAttribute('stock', 'integer', awLabel('Stock'), filterable: true));
    $attributes->define(new DefineAttribute('vegan', 'boolean', awLabel('Vegan'), filterable: true));
    $attributes->define(new DefineAttribute('released', 'date', awLabel('Released'), filterable: true));
    $attributes->define(new DefineAttribute('brand', 'text', awLabel('Brand'), filterable: true));
    $attributes->define(new DefineAttribute('note', 'text', awLabel('Note')));

    return Catalog::productTypes()->create(new CreateProductType('gear', awLabel('Gear'), productAttributes: [
        'color', 'tags', 'weight', 'stock', 'vegan', 'released', 'brand', 'note',
    ]));
}

/** @param  array<string, mixed>  $attributes */
function awProduct(ProductTypeView $type, string $handle, array $attributes = []): ProductView
{
    return Catalog::products()->create(new CreateProduct($type->id, $handle, awLabel($handle), attributes: $attributes));
}

/**
 * Projection rows of a product as `code:value` strings, sorted.
 *
 * @return list<string>
 */
function awFacts(ProductId $product): array
{
    $rows = DB::table('cmz_attribute_index as i')
        ->join('cmz_attributes as a', 'a.id', '=', 'i.attribute_id')
        ->leftJoin('cmz_attribute_choices as c', 'c.id', '=', 'i.choice_id')
        ->where('i.product_id', $product->value)
        ->whereNull('i.variant_id')
        ->get(['a.code', 'c.code as choice', 'i.num', 'i.flag', 'i.day', 'i.term']);

    $facts = [];

    foreach ($rows as $row) {
        $value = match (true) {
            $row->choice !== null => $row->choice,
            $row->num !== null => rtrim(rtrim((string) $row->num, '0'), '.'),
            $row->flag !== null => $row->flag ? 'true' : 'false',
            $row->day !== null => $row->day,
            default => $row->term,
        };
        $facts[] = "{$row->code}:{$value}";
    }

    sort($facts);

    return $facts;
}

/** @return array<string, mixed> */
function awDocument(ProductId $product): array
{
    $document = json_decode((string) DB::table('cmz_products')->where('id', $product->value)->value('attributes'), true);
    ksort($document, SORT_STRING);

    return $document;
}

it('projects exactly the facts of filterable attributes', function (): void {
    $gear = awGear();

    $product = awProduct($gear, 'tent', [
        'color' => 'red',
        'tags' => ['new', 'eco', 'new'],
        'weight' => Measure::of('1500', 'g'),
        'stock' => '-3',
        'vegan' => true,
        'released' => '2026-01-31',
        'brand' => 'Acme',
        'note' => 'not indexed',
    ]);

    expect($product->attributes['tags'])->toBe(['eco', 'new'])
        ->and($product->attributes['stock'])->toBe(-3)
        ->and(awFacts($product->id))->toBe([
            'brand:Acme', 'color:red', 'released:2026-01-31', 'stock:-3', 'tags:eco', 'tags:new', 'vegan:true', 'weight:1500',
        ]);

    Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['color' => 'blue', 'tags' => ['hit']], ['brand', 'vegan']));

    expect(awFacts($product->id))->toBe(['color:blue', 'released:2026-01-31', 'stock:-3', 'tags:hit', 'weight:1500'])
        ->and(DB::table('cmz_attribute_index')->count())->toBe(5);
});

it('leaves the document and the projection untouched when an import value is invalid', function (array $values): void {
    $gear = awGear();
    $product = awProduct($gear, 'tent', ['color' => 'red', 'stock' => 5]);
    $document = awDocument($product->id);
    $facts = awFacts($product->id);

    expect(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, $values)))
        ->toThrow(InvalidAttributeValue::class);
    expect(awDocument($product->id))->toBe($document)
        ->and(awFacts($product->id))->toBe($facts);
})->with([
    'bad choice after a good value' => [['stock' => 7, 'color' => 'green']],
    'float' => [['stock' => 1.5]],
    'wrong unit' => [['weight' => Measure::of('1', 'kg')]],
    'impossible date' => [['released' => '2026-02-30']],
]);

it('refuses new values of a deprecated attribute but keeps and projects the old ones', function (): void {
    $gear = awGear();
    $product = awProduct($gear, 'tent', ['color' => 'red']);
    $color = Catalog::attributes()->findByCode('color');

    Catalog::attributes()->update(new UpdateAttribute($color->id, status: AttributeStatus::Deprecated));

    expect(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['color' => 'blue'])))
        ->toThrow(AttributeNotWritable::class);

    Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['stock' => 1]));

    expect(Catalog::products()->find($product->id)->attributes)->toBe(['color' => 'red', 'stock' => 1])
        ->and(awFacts($product->id))->toBe(['color:red', 'stock:1']);

    Catalog::products()->setAttributes(new SetProductAttributes($product->id, remove: ['color']));

    expect(awDocument($product->id))->toBe(['stock' => 1]);
});

it('rebuilds the projection of an attribute when filterable changes or it retires', function (): void {
    Catalog::attributes()->define(new DefineAttribute('fabric', 'text', awLabel('Fabric')));
    Catalog::attributes()->define(new DefineAttribute('size', 'integer', awLabel('Size'), filterable: true));
    $type = Catalog::productTypes()->create(new CreateProductType('cloth', awLabel('Cloth'), productAttributes: ['fabric', 'size']));
    $fabric = Catalog::attributes()->findByCode('fabric');

    $products = [];

    foreach (['a', 'b', 'c'] as $handle) {
        $products[] = awProduct($type, $handle, ['fabric' => "wool-{$handle}", 'size' => 1]);
    }

    awProduct($type, 'd', ['size' => 2]);
    $fabricRows = static fn (): int => DB::table('cmz_attribute_index')->where('attribute_id', $fabric->id->value)->count();

    expect($fabricRows())->toBe(0);

    Catalog::attributes()->update(new UpdateAttribute($fabric->id, filterable: true));

    expect($fabricRows())->toBe(3)
        ->and(awFacts($products[1]->id))->toBe(['fabric:wool-b', 'size:1']);

    Catalog::attributes()->update(new UpdateAttribute($fabric->id, filterable: false));

    expect($fabricRows())->toBe(0);

    Catalog::attributes()->update(new UpdateAttribute($fabric->id, filterable: true));
    Catalog::attributes()->update(new UpdateAttribute($fabric->id, status: AttributeStatus::Retired));

    expect($fabricRows())->toBe(0)
        ->and(DB::table('cmz_attribute_index')->count())->toBe(4)
        ->and(awDocument($products[0]->id))->toBe(['fabric' => 'wool-a', 'size' => 1])
        ->and(Catalog::products()->find($products[0]->id)->attributes)->toBe(['size' => 1]);

    Catalog::products()->setAttributes(new SetProductAttributes($products[0]->id, ['size' => 3]));

    expect(awDocument($products[0]->id))->toBe(['fabric' => 'wool-a', 'size' => 3])
        ->and($fabricRows())->toBe(0);
});

it('keeps a value of an uninstalled type as stored, unprojected and read-only', function (): void {
    $gear = awGear();
    $now = now()->toDateTimeString();
    $missing = Uuid7::generate();

    DB::table('cmz_attributes')->insert([
        'id' => $missing, 'code' => 'swatch', 'type' => 'acme.missing', 'name' => '{"en":"Swatch"}', 'config' => '{}',
        'config_version' => 1, 'filterable' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('cmz_product_type_attributes')->insert([
        'product_type_id' => $gear->id->value, 'attribute_id' => $missing, 'level' => 'product', 'required' => false, 'position' => 9,
    ]);

    $product = awProduct($gear, 'tent', ['color' => 'red']);
    DB::table('cmz_products')->where('id', $product->id->value)->update(['attributes' => '{"color": "red", "swatch": {"hex": "#f00"}}']);

    expect(Catalog::attributes()->findByCode('swatch')->supported)->toBeFalse()
        ->and(Catalog::products()->find($product->id)->attributes)->toBe(['color' => 'red', 'swatch' => ['hex' => '#f00']])
        ->and(fn () => Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['swatch' => ['hex' => '#00f']])))
        ->toThrow(UnsupportedContribution::class);

    Catalog::products()->setAttributes(new SetProductAttributes($product->id, ['stock' => 2]));

    expect(awDocument($product->id))->toBe(['color' => 'red', 'stock' => 2, 'swatch' => ['hex' => '#f00']])
        ->and(awFacts($product->id))->toBe(['color:red', 'stock:2']);
});

it('has the attribute writer as the only writer of attribute documents and the projection (CAT-02)', function (): void {
    $root = dirname(__DIR__, 3).'/src';
    $writers = [];
    $readers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $path = substr($file->getPathname(), strlen($root) + 1);
        $writes = preg_match('/->(insert|insertUsing|insertOrIgnore|upsert|update|updateOrInsert|delete|truncate)\(/', $source) === 1;

        if (preg_match("/'attributes'\\s*=>|\\bset\\s+\"?attributes\"?\\s*=/i", $source) === 1
            || (preg_match("/table\\('attribute_index'\\)/", $source) === 1 && $writes)) {
            $writers[] = $path;
        } elseif (preg_match("/table\\('attribute_index'\\)/", $source) === 1) {
            $readers[] = $path;
        }
    }

    // Readers of the projection (the filter compiler) issue no write statement at all.
    expect($writers)->toBe(['Attributes/Internal/AttributeWriter.php'])
        ->and($readers)->toBe(['Products/Internal/FilterCompiler.php']);
});
