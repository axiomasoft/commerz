<?php

declare(strict_types=1);

use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Categories\Data\ArchiveCategory;
use Commerz\Catalog\Categories\Data\AssignProductCategories;
use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Categories\Data\CategoryView;
use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Categories\Data\ListCategories;
use Commerz\Catalog\Categories\Data\MoveCategory;
use Commerz\Catalog\Categories\Data\UpdateCategory;
use Commerz\Catalog\Categories\Enums\CategoryStatus;
use Commerz\Catalog\Categories\Exceptions\CategoryArchived;
use Commerz\Catalog\Categories\Exceptions\CategoryCycle;
use Commerz\Catalog\Categories\Exceptions\CategoryHasChildren;
use Commerz\Catalog\Categories\Exceptions\CategoryNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function catCreate(string $code, ?CategoryId $parent = null, int $position = 0): CategoryView
{
    return Catalog::categories()->create(new CreateCategory($code, LocalizedText::of('en', ucfirst($code)), $parent, $position));
}

/** @param  list<CategoryView>  $categories */
function catCodes(array $categories): array
{
    return array_map(fn (CategoryView $category): string => $category->code, $categories);
}

it('exposes categories as the bound singleton', function (): void {
    expect(Catalog::categories())->toBe(app(Categories::class));
});

it('creates roots and children and refuses a taken code or a missing parent', function (): void {
    $apparel = catCreate('apparel');
    $tees = catCreate('tees', $apparel->id, 2);

    expect($apparel->parent)->toBeNull()
        ->and($apparel->status)->toBe(CategoryStatus::Active)
        ->and($tees->parent?->equals($apparel->id))->toBeTrue()
        ->and($tees->position)->toBe(2)
        ->and($tees->name->texts)->toBe(['en' => 'Tees'])
        ->and(Catalog::categories()->findByCode('tees')->id->equals($tees->id))->toBeTrue()
        ->and(fn () => catCreate('tees'))->toThrow(DuplicateCode::class)
        ->and(fn () => catCreate('lost', CategoryId::generate()))->toThrow(CategoryNotFound::class)
        ->and(fn () => new CreateCategory('Bad Code', LocalizedText::of('en', 'X')))->toThrow(InvalidCommand::class)
        ->and(fn () => Catalog::categories()->find(CategoryId::generate()))->toThrow(CategoryNotFound::class);
});

it('updates the name and position', function (): void {
    $apparel = catCreate('apparel');

    $updated = Catalog::categories()->update(new UpdateCategory($apparel->id, LocalizedText::of('en', 'Clothes'), 3));

    expect($updated->name->texts)->toBe(['en' => 'Clothes'])
        ->and($updated->position)->toBe(3)
        ->and(Catalog::categories()->update(new UpdateCategory($apparel->id))->updatedAt)->toEqual($updated->updatedAt);
});

it('moves a subtree and refuses a cycle', function (): void {
    $a = catCreate('a');
    $b = catCreate('b', $a->id);
    $c = catCreate('c', $b->id);
    $root = catCreate('root');

    expect(fn () => Catalog::categories()->move(new MoveCategory($a->id, $b->id)))->toThrow(CategoryCycle::class)
        ->and(fn () => Catalog::categories()->move(new MoveCategory($a->id, $c->id)))->toThrow(CategoryCycle::class)
        ->and(fn () => Catalog::categories()->move(new MoveCategory($a->id, $a->id)))->toThrow(CategoryCycle::class)
        ->and(Catalog::categories()->move(new MoveCategory($b->id, $root->id))->parent?->equals($root->id))->toBeTrue()
        ->and(Catalog::categories()->find($c->id)->parent?->equals($b->id))->toBeTrue()
        ->and(Catalog::categories()->move(new MoveCategory($a->id, $c->id))->parent?->equals($c->id))->toBeTrue()
        ->and(Catalog::categories()->move(new MoveCategory($a->id, null))->parent)->toBeNull()
        ->and(fn () => Catalog::categories()->move(new MoveCategory($a->id, CategoryId::generate())))->toThrow(CategoryNotFound::class);
});

it('archives a category only without active children and then refuses it', function (): void {
    $apparel = catCreate('apparel');
    $tees = catCreate('tees', $apparel->id);
    $other = catCreate('other');

    expect(fn () => Catalog::categories()->archive(new ArchiveCategory($apparel->id)))->toThrow(CategoryHasChildren::class);

    $archivedTees = Catalog::categories()->archive(new ArchiveCategory($tees->id));
    $archived = Catalog::categories()->archive(new ArchiveCategory($apparel->id));

    expect($archivedTees->status)->toBe(CategoryStatus::Archived)
        ->and($archived->status)->toBe(CategoryStatus::Archived)
        ->and(Catalog::categories()->archive(new ArchiveCategory($apparel->id))->updatedAt)->toEqual($archived->updatedAt)
        ->and(fn () => catCreate('shirts', $apparel->id))->toThrow(CategoryArchived::class)
        ->and(fn () => Catalog::categories()->move(new MoveCategory($other->id, $apparel->id)))->toThrow(CategoryArchived::class)
        ->and(fn () => Catalog::categories()->move(new MoveCategory($apparel->id, $other->id)))->toThrow(CategoryArchived::class)
        ->and(fn () => Catalog::categories()->update(new UpdateCategory($apparel->id, position: 1)))->toThrow(CategoryArchived::class);
});

it('lists by code: everything, the roots or the children of a parent', function (): void {
    $apparel = catCreate('apparel');
    catCreate('tees', $apparel->id);
    catCreate('shirts', $apparel->id);
    $old = catCreate('old');
    Catalog::categories()->archive(new ArchiveCategory($old->id));

    $first = Catalog::categories()->list(new ListCategories(first: 2));
    $rest = Catalog::categories()->list(new ListCategories(first: 2, after: $first->next));

    expect(catCodes($first->items))->toBe(['apparel', 'shirts'])
        ->and(catCodes($rest->items))->toBe(['tees'])
        ->and($rest->next)->toBeNull()
        ->and(catCodes(Catalog::categories()->list(new ListCategories(roots: true))->items))->toBe(['apparel'])
        ->and(catCodes(Catalog::categories()->list(new ListCategories(roots: true, includeArchived: true))->items))->toBe(['apparel', 'old'])
        ->and(catCodes(Catalog::categories()->list(new ListCategories($apparel->id))->items))->toBe(['shirts', 'tees'])
        ->and(fn () => new ListCategories($apparel->id, roots: true))->toThrow(InvalidCommand::class);
});

it('replaces the categories of a product in the given order', function (): void {
    $label = LocalizedText::of('en', 'X');
    $type = Catalog::productTypes()->create(new CreateProductType('apparel', $label));
    $product = Catalog::products()->create(new CreateProduct($type->id, 'basic-tee', $label));
    $tees = catCreate('tees');
    $sale = catCreate('sale');
    $old = catCreate('old');
    Catalog::categories()->archive(new ArchiveCategory($old->id));

    expect(catCodes(Catalog::categories()->assign(new AssignProductCategories($product->id, [$sale->id, $tees->id]))))->toBe(['sale', 'tees'])
        ->and(catCodes(Catalog::categories()->assign(new AssignProductCategories($product->id, [$tees->id]))))->toBe(['tees'])
        ->and(fn () => Catalog::categories()->assign(new AssignProductCategories($product->id, [$sale->id, $old->id])))->toThrow(CategoryArchived::class)
        ->and(fn () => Catalog::categories()->assign(new AssignProductCategories($product->id, [CategoryId::generate()])))->toThrow(CategoryNotFound::class)
        ->and(fn () => Catalog::categories()->assign(new AssignProductCategories(ProductId::generate(), [$tees->id])))->toThrow(ProductNotFound::class)
        ->and(fn () => new AssignProductCategories($product->id, [$tees->id, $tees->id]))->toThrow(InvalidCommand::class)
        ->and(catCodes(Catalog::categories()->ofProduct($product->id)))->toBe(['tees'])
        ->and(Catalog::categories()->assign(new AssignProductCategories($product->id, [])))->toBe([])
        ->and(fn () => Catalog::categories()->ofProduct(ProductId::generate()))->toThrow(ProductNotFound::class);
});

it('round trips int64 positions accepted by category commands', function (): void {
    $category = catCreate('large', position: 2_147_483_648);
    expect($category->position)->toBe(2_147_483_648)
        ->and(Catalog::categories()->update(new UpdateCategory($category->id, position: PHP_INT_MAX))->position)->toBe(PHP_INT_MAX);
});

it('uses the catalog page limit for categories', function (): void {
    expect((new ListCategories(first: 200))->first)->toBe(200)
        ->and(fn () => new ListCategories(first: 201))->toThrow(InvalidCommand::class);
});
