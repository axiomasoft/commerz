<?php

declare(strict_types=1);

use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFilterable;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotWritable;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\InvalidStatusTransition;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Categories\Exceptions\CategoryArchived;
use Commerz\Catalog\Categories\Exceptions\CategoryCycle;
use Commerz\Catalog\Categories\Exceptions\CategoryHasChildren;
use Commerz\Catalog\Categories\Exceptions\CategoryNotFound;
use Commerz\Catalog\Collections\Exceptions\CollectionArchived;
use Commerz\Catalog\Collections\Exceptions\CollectionNotFound;
use Commerz\Catalog\Collections\Exceptions\InvalidCollectionOperation;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Errors\CatalogProblemDefinitions;
use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Catalog\Listings\Exceptions\ListingNotFound;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Catalog\Projection\Exceptions\StorefrontProductNotFound;
use Commerz\Catalog\Variants\Exceptions\DuplicateCombination;
use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Catalog\Variants\Exceptions\TooManyVariants;
use Commerz\Catalog\Variants\Exceptions\VariantNotFound;
use Commerz\Errors\CommerzException;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

it('covers every CatalogErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new CatalogProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(CatalogErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected);
});

it('exposes Packman error descriptors on catalog exceptions', function (CommerzException $e, CatalogErrorCode $code, ErrorCategory $category): void {
    $descriptor = $e->errorDescriptor();

    expect($e)->toBeInstanceOf(CodedException::class)
        ->and($descriptor->errorCode)->toBe($code)
        ->and($descriptor->category)->toBe($category);
})->with([
    'attribute not allowed' => [new AttributeNotAllowed('color', AttributeLevel::Product), CatalogErrorCode::AttributeNotAllowed, ErrorCategory::Validation],
    'attribute not filterable' => [new AttributeNotFilterable('notes'), CatalogErrorCode::AttributeNotFilterable, ErrorCategory::Validation],
    'attribute not found' => [new AttributeNotFound('color'), CatalogErrorCode::AttributeNotFound, ErrorCategory::NotFound],
    'attribute not writable' => [new AttributeNotWritable('color', 'retired'), CatalogErrorCode::AttributeNotWritable, ErrorCategory::Conflict],
    'invalid attribute config' => [new InvalidAttributeConfig('color', 'bad'), CatalogErrorCode::InvalidAttributeConfig, ErrorCategory::Validation],
    'invalid attribute value' => [new InvalidAttributeValue('color', 'bad'), CatalogErrorCode::InvalidAttributeValue, ErrorCategory::Validation],
    'invalid status transition' => [new InvalidStatusTransition('Attribute', 'retired', 'active'), CatalogErrorCode::InvalidStatusTransition, ErrorCategory::Conflict],
    'missing required attributes' => [new MissingRequiredAttributes(['color']), CatalogErrorCode::MissingRequiredAttributes, ErrorCategory::Validation],
    'category archived' => [new CategoryArchived('tops'), CatalogErrorCode::CategoryArchived, ErrorCategory::Conflict],
    'category cycle' => [new CategoryCycle('c1', 'c2'), CatalogErrorCode::CategoryCycle, ErrorCategory::Conflict],
    'category has children' => [new CategoryHasChildren('tops'), CatalogErrorCode::CategoryHasChildren, ErrorCategory::Conflict],
    'category not found' => [new CategoryNotFound('tops'), CatalogErrorCode::CategoryNotFound, ErrorCategory::NotFound],
    'collection archived' => [new CollectionArchived('summer'), CatalogErrorCode::CollectionArchived, ErrorCategory::Conflict],
    'collection not found' => [new CollectionNotFound('summer'), CatalogErrorCode::CollectionNotFound, ErrorCategory::NotFound],
    'invalid collection operation' => [new InvalidCollectionOperation('summer', 'bad'), CatalogErrorCode::InvalidCollectionOperation, ErrorCategory::Validation],
    'duplicate code' => [new DuplicateCode('Product', 'tee'), CatalogErrorCode::DuplicateCode, ErrorCategory::Conflict],
    'listing not found' => [new ListingNotFound('p1', 'c1'), CatalogErrorCode::ListingNotFound, ErrorCategory::NotFound],
    'option not found' => [new OptionNotFound('size'), CatalogErrorCode::OptionNotFound, ErrorCategory::NotFound],
    'unknown option value' => [new UnknownOptionValue('size', 'xxl'), CatalogErrorCode::UnknownOptionValue, ErrorCategory::Validation],
    'product archived' => [new ProductArchived('tee'), CatalogErrorCode::ProductArchived, ErrorCategory::Conflict],
    'product not found' => [new ProductNotFound('tee'), CatalogErrorCode::ProductNotFound, ErrorCategory::NotFound],
    'invalid product type' => [new InvalidProductType('bad'), CatalogErrorCode::InvalidProductType, ErrorCategory::Validation],
    'product type archived' => [new ProductTypeArchived('shirt'), CatalogErrorCode::ProductTypeArchived, ErrorCategory::Conflict],
    'product type not found' => [new ProductTypeNotFound('shirt'), CatalogErrorCode::ProductTypeNotFound, ErrorCategory::NotFound],
    'storefront product not found' => [new StorefrontProductNotFound('tee'), CatalogErrorCode::StorefrontProductNotFound, ErrorCategory::NotFound],
    'duplicate combination' => [new DuplicateCombination(['size' => 's']), CatalogErrorCode::DuplicateCombination, ErrorCategory::Conflict],
    'invalid axes' => [new InvalidAxes('missing'), CatalogErrorCode::InvalidAxes, ErrorCategory::Validation],
    'too many variants' => [new TooManyVariants(200, 100), CatalogErrorCode::TooManyVariants, ErrorCategory::RateLimit],
    'variant not found' => [new VariantNotFound('SKU-1'), CatalogErrorCode::VariantNotFound, ErrorCategory::NotFound],
]);
