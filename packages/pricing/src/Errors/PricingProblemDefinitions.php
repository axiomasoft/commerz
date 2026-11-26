<?php

declare(strict_types=1);

namespace Commerz\Pricing\Errors;

use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-pricing` error codes.
 */
#[Api(since: '0.1')]
final readonly class PricingProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(PricingErrorCode::CurrencyMismatch, 422, 'Price Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::DuplicateCode, 409, 'Duplicate Pricing Code', ErrorCategory::Conflict, ['entity', 'code']),
            $this->define(PricingErrorCode::DuplicateMember, 422, 'Duplicate Tax Zone Member', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::DuplicatePrice, 422, 'Duplicate Price Entry', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidAmount, 422, 'Invalid Price Amount', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidBatch, 422, 'Invalid Price Batch', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidChannels, 422, 'Invalid Price List Channels', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidCode, 422, 'Invalid Pricing Code', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidCompareAt, 422, 'Invalid Compare-At Price', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidCountries, 422, 'Invalid Market Countries', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidCurrencies, 422, 'Invalid Market Currencies', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidFormula, 422, 'Invalid Price List Formula', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidGroup, 422, 'Invalid Customer Price Group', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidMember, 422, 'Invalid Tax Zone Member', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidName, 422, 'Invalid Pricing Name', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidPriority, 422, 'Invalid Price List Priority', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidQuantity, 422, 'Invalid Minimum Price Quantity', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidRate, 422, 'Invalid Tax Rate', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidRoundingRule, 422, 'Invalid Price Rounding Rule', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidTaxAmount, 422, 'Invalid Tax Amount', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidTaxCode, 422, 'Invalid Tax Code', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidTaxExemption, 422, 'Invalid Tax Exemption', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidTaxItem, 422, 'Invalid Taxable Item', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidTaxRequest, 422, 'Invalid Tax Request', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidTaxResult, 503, 'Invalid Tax Calculator Result', ErrorCategory::Unavailable, ['calculator', 'reason']),
            $this->define(PricingErrorCode::InvalidVatNumber, 422, 'Invalid B2B VAT Number', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::InvalidWindow, 422, 'Invalid Pricing Effective Window', ErrorCategory::Validation, ['*']),
            $this->define(PricingErrorCode::MarketArchived, 409, 'Market Archived', ErrorCategory::Conflict, ['market']),
            $this->define(PricingErrorCode::MarketCountryTaken, 409, 'Market Country Already Assigned', ErrorCategory::Conflict, ['channel', 'country', 'market']),
            $this->define(PricingErrorCode::MarketNotFound, 404, 'Market Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PricingErrorCode::PriceListArchived, 409, 'Price List Archived', ErrorCategory::Conflict, ['price_list']),
            $this->define(PricingErrorCode::PriceListCycle, 409, 'Price List Formula Cycle Detected', ErrorCategory::Conflict, ['price_list', 'parent_price_list']),
            $this->define(PricingErrorCode::PriceListNotFound, 404, 'Price List Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PricingErrorCode::PriceNotFound, 404, 'Price Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PricingErrorCode::PriceWindowOverlap, 409, 'Price Window Overlap', ErrorCategory::Conflict, ['sellable', 'price_list', 'conflicting_price']),
            $this->define(PricingErrorCode::StagedPriceNotFound, 404, 'Staged Price Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PricingErrorCode::StagedPriceStateConflict, 409, 'Staged Price State Conflict', ErrorCategory::Conflict, ['staged_price', 'status']),
            $this->define(PricingErrorCode::TaxClassNotFound, 404, 'Tax Class Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(PricingErrorCode::TaxRateNotFound, 404, 'Tax Rate Not Found', ErrorCategory::NotFound, ['*']),
            $this->define(PricingErrorCode::TaxRateOverlap, 409, 'Tax Rate Window Overlap', ErrorCategory::Conflict, ['tax_class', 'zone', 'tax_code', 'conflicting_rate']),
            $this->define(PricingErrorCode::TaxZoneMemberTaken, 409, 'Tax Zone Member Already Assigned', ErrorCategory::Conflict, ['country', 'region', 'zone']),
            $this->define(PricingErrorCode::TaxZoneNotFound, 404, 'Tax Zone Not Found', ErrorCategory::NotFound, ['entity', 'id']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        PricingErrorCode $code,
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
