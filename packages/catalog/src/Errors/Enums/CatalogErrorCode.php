<?php

declare(strict_types=1);

namespace Commerz\Catalog\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-catalog`.
 */
#[Api(since: '0.1')]
enum CatalogErrorCode: string implements ErrorCode
{
    case AssetAttachmentNotFound = 'catalog.asset_attachment_not_found';
    case AttributeNotAllowed = 'catalog.attribute_not_allowed';
    case AttributeNotFilterable = 'catalog.attribute_not_filterable';
    case AttributeNotFound = 'catalog.attribute_not_found';
    case AttributeNotWritable = 'catalog.attribute_not_writable';
    case BrandArchived = 'catalog.brand_archived';
    case BrandNotFound = 'catalog.brand_not_found';
    case CategoryArchived = 'catalog.category_archived';
    case CategoryCycle = 'catalog.category_cycle';
    case CategoryHasChildren = 'catalog.category_has_children';
    case CategoryNotFound = 'catalog.category_not_found';
    case CollectionArchived = 'catalog.collection_archived';
    case CollectionNotFound = 'catalog.collection_not_found';
    case DuplicateCode = 'catalog.duplicate_code';
    case DuplicateCombination = 'catalog.duplicate_combination';
    case DuplicateSlug = 'catalog.duplicate_slug';
    case InvalidAttributeConfig = 'catalog.invalid_attribute_config';
    case InvalidAttributeValue = 'catalog.invalid_attribute_value';
    case InvalidAttributes = 'catalog.invalid_attributes';
    case InvalidAxes = 'catalog.invalid_axes';
    case InvalidBarcode = 'catalog.invalid_barcode';
    case InvalidChoices = 'catalog.invalid_choices';
    case InvalidCode = 'catalog.invalid_code';
    case InvalidCollectionOperation = 'catalog.invalid_collection_operation';
    case InvalidDimensions = 'catalog.invalid_dimensions';
    case InvalidFilter = 'catalog.invalid_filter';
    case InvalidHandle = 'catalog.invalid_handle';
    case InvalidOptionValues = 'catalog.invalid_option_values';
    case InvalidProductType = 'catalog.invalid_product_type';
    case InvalidSku = 'catalog.invalid_sku';
    case InvalidSkuPattern = 'catalog.invalid_sku_pattern';
    case InvalidStatusTransition = 'catalog.invalid_status_transition';
    case InvalidWindow = 'catalog.invalid_window';
    case ListingNotFound = 'catalog.listing_not_found';
    case MissingRequiredAttributes = 'catalog.missing_required_attributes';
    case OptionNotFound = 'catalog.option_not_found';
    case ProductArchived = 'catalog.product_archived';
    case ProductNotFound = 'catalog.product_not_found';
    case ProductRelationNotFound = 'catalog.product_relation_not_found';
    case ProductTypeArchived = 'catalog.product_type_archived';
    case ProductTypeNotFound = 'catalog.product_type_not_found';
    case RouteNotFound = 'catalog.route_not_found';
    case StorefrontProductNotFound = 'catalog.storefront_product_not_found';
    case TooManyAxes = 'catalog.too_many_axes';
    case TooManyVariants = 'catalog.too_many_variants';
    case UnknownOptionValue = 'catalog.unknown_option_value';
    case UnsupportedFilter = 'catalog.unsupported_filter';
    case VariantNotFound = 'catalog.variant_not_found';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
