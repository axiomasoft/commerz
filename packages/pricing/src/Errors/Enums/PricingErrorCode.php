<?php

declare(strict_types=1);

namespace Commerz\Pricing\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-pricing`.
 */
#[Api(since: '0.1')]
enum PricingErrorCode: string implements ErrorCode
{
    case CurrencyMismatch = 'pricing.currency_mismatch';
    case DuplicateCode = 'pricing.duplicate_code';
    case DuplicateMember = 'pricing.duplicate_member';
    case DuplicatePrice = 'pricing.duplicate_price';
    case InvalidAmount = 'pricing.invalid_amount';
    case InvalidBatch = 'pricing.invalid_batch';
    case InvalidChannels = 'pricing.invalid_channels';
    case InvalidCode = 'pricing.invalid_code';
    case InvalidCompareAt = 'pricing.invalid_compare_at';
    case InvalidGroup = 'pricing.invalid_group';
    case InvalidMember = 'pricing.invalid_member';
    case InvalidName = 'pricing.invalid_name';
    case InvalidPriority = 'pricing.invalid_priority';
    case InvalidQuantity = 'pricing.invalid_quantity';
    case InvalidRate = 'pricing.invalid_rate';
    case InvalidTaxAmount = 'pricing.invalid_tax_amount';
    case InvalidTaxCode = 'pricing.invalid_tax_code';
    case InvalidTaxItem = 'pricing.invalid_tax_item';
    case InvalidTaxRequest = 'pricing.invalid_tax_request';
    case InvalidTaxResult = 'pricing.invalid_tax_result';
    case InvalidWindow = 'pricing.invalid_window';
    case PriceListArchived = 'pricing.price_list_archived';
    case PriceListNotFound = 'pricing.price_list_not_found';
    case PriceNotFound = 'pricing.price_not_found';
    case PriceWindowOverlap = 'pricing.price_window_overlap';
    case TaxClassNotFound = 'pricing.tax_class_not_found';
    case TaxRateNotFound = 'pricing.tax_rate_not_found';
    case TaxRateOverlap = 'pricing.tax_rate_overlap';
    case TaxZoneMemberTaken = 'pricing.tax_zone_member_taken';
    case TaxZoneNotFound = 'pricing.tax_zone_not_found';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
