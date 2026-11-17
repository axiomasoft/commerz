<?php

declare(strict_types=1);

namespace Commerz\Catalog\Errors;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-catalog` error codes.
 */
#[Api(since: '0.1')]
final readonly class CatalogProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(CatalogErrorCode::AttributeNotAllowed, 422, 'Attribute Not Allowed on Product Type', ErrorCategory::Validation, ['attribute', 'level']),
            $this->define(CatalogErrorCode::AttributeNotFilterable, 422, 'Attribute Not Filterable', ErrorCategory::Validation, ['attribute']),
            $this->define(CatalogErrorCode::AttributeNotFound, 404, 'Attribute Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CatalogErrorCode::AttributeNotWritable, 409, 'Attribute Not Writable', ErrorCategory::Conflict, ['attribute', 'status']),
            $this->define(CatalogErrorCode::CategoryArchived, 409, 'Category Archived', ErrorCategory::Conflict, ['category']),
            $this->define(CatalogErrorCode::CategoryCycle, 409, 'Category Hierarchy Cycle', ErrorCategory::Conflict, ['category', 'parent']),
            $this->define(CatalogErrorCode::CategoryHasChildren, 409, 'Category Has Children', ErrorCategory::Conflict, ['category']),
            $this->define(CatalogErrorCode::CategoryNotFound, 404, 'Category Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CatalogErrorCode::DuplicateCode, 409, 'Duplicate Catalog Code', ErrorCategory::Conflict, ['entity', 'code']),
            $this->define(CatalogErrorCode::DuplicateCombination, 409, 'Duplicate Variant Option Combination', ErrorCategory::Conflict, ['axes']),
            $this->define(CatalogErrorCode::InvalidAttributeConfig, 422, 'Invalid Attribute Config', ErrorCategory::Validation, ['attribute', 'reason']),
            $this->define(CatalogErrorCode::InvalidAttributeValue, 422, 'Invalid Attribute Value', ErrorCategory::Validation, ['attribute', 'reason']),
            $this->define(CatalogErrorCode::InvalidAttributes, 422, 'Invalid Attribute Set', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidAxes, 422, 'Invalid Variant Axes', ErrorCategory::Validation, ['reason']),
            $this->define(CatalogErrorCode::InvalidBarcode, 422, 'Invalid Barcode', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidChoices, 422, 'Invalid Attribute Choices', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidCode, 422, 'Invalid Catalog Code', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidDimensions, 422, 'Invalid Variant Dimensions', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidFilter, 422, 'Invalid Product Filter', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidHandle, 422, 'Invalid Product Handle', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidOptionValues, 422, 'Invalid Option Values', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidProductType, 422, 'Invalid Product Type', ErrorCategory::Validation, ['reason']),
            $this->define(CatalogErrorCode::InvalidSku, 422, 'Invalid SKU', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidSkuPattern, 422, 'Invalid SKU Pattern', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::InvalidStatusTransition, 409, 'Invalid Catalog Status Transition', ErrorCategory::Conflict, ['entity', 'from', 'to']),
            $this->define(CatalogErrorCode::InvalidWindow, 422, 'Invalid Listing Availability Window', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::ListingNotFound, 404, 'Listing Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CatalogErrorCode::MissingRequiredAttributes, 422, 'Missing Required Attributes', ErrorCategory::Validation, ['codes']),
            $this->define(CatalogErrorCode::OptionNotFound, 404, 'Option Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CatalogErrorCode::ProductArchived, 409, 'Product Archived', ErrorCategory::Conflict, ['product']),
            $this->define(CatalogErrorCode::ProductNotFound, 404, 'Product Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CatalogErrorCode::ProductTypeArchived, 409, 'Product Type Archived', ErrorCategory::Conflict, ['type']),
            $this->define(CatalogErrorCode::ProductTypeNotFound, 404, 'Product Type Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(CatalogErrorCode::TooManyAxes, 422, 'Too Many Variant Axes', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::TooManyVariants, 429, 'Too Many Variants Requested', ErrorCategory::RateLimit, ['requested', 'limit']),
            $this->define(CatalogErrorCode::UnknownOptionValue, 422, 'Unknown Option Value', ErrorCategory::Validation, ['option', 'value']),
            $this->define(CatalogErrorCode::UnsupportedFilter, 422, 'Unsupported Attribute Filter Operator', ErrorCategory::Validation, ['*']),
            $this->define(CatalogErrorCode::VariantNotFound, 404, 'Variant Not Found', ErrorCategory::NotFound, ['entity', 'id']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        CatalogErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: false,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}
