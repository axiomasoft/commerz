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
    case InvalidCountries = 'pricing.invalid_countries';
    case InvalidCurrencies = 'pricing.invalid_currencies';
    case InvalidFormula = 'pricing.invalid_formula';
    case InvalidGroup = 'pricing.invalid_group';
    case InvalidMember = 'pricing.invalid_member';
    case InvalidName = 'pricing.invalid_name';
    case InvalidPriority = 'pricing.invalid_priority';
    case InvalidQuantity = 'pricing.invalid_quantity';
    case InvalidRate = 'pricing.invalid_rate';
    case InvalidRoundingRule = 'pricing.invalid_rounding_rule';
    case InvalidTaxAmount = 'pricing.invalid_tax_amount';
    case InvalidTaxCode = 'pricing.invalid_tax_code';
    case InvalidTaxExemption = 'pricing.invalid_tax_exemption';
    case InvalidTaxItem = 'pricing.invalid_tax_item';
    case InvalidTaxRequest = 'pricing.invalid_tax_request';
    case InvalidTaxResult = 'pricing.invalid_tax_result';
    case InvalidVatNumber = 'pricing.invalid_vat_number';
    case InvalidWindow = 'pricing.invalid_window';
    case MarketArchived = 'pricing.market_archived';
    case MarketCountryTaken = 'pricing.market_country_taken';
    case MarketNotFound = 'pricing.market_not_found';
    case PriceListArchived = 'pricing.price_list_archived';
    case PriceListCycle = 'pricing.price_list_cycle';
    case PriceListNotFound = 'pricing.price_list_not_found';
    case PriceNotFound = 'pricing.price_not_found';
    case PriceWindowOverlap = 'pricing.price_window_overlap';
    case StagedPriceNotFound = 'pricing.staged_price_not_found';
    case StagedPriceStateConflict = 'pricing.staged_price_state_conflict';
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
