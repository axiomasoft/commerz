<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Pricing\Calculation\Exceptions\InvalidTaxResult;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Errors\Enums\PricingErrorCode;
use Commerz\Pricing\Errors\PricingProblemDefinitions;
use Commerz\Pricing\Markets\Exceptions\MarketArchived;
use Commerz\Pricing\Markets\Exceptions\MarketCountryTaken;
use Commerz\Pricing\Markets\Exceptions\MarketNotFound;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListCycleDetected;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Pricing\Prices\Enums\StagedPriceStatus;
use Commerz\Pricing\Prices\Exceptions\PriceNotFoundById;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;
use Commerz\Pricing\Prices\Exceptions\StagedPriceNotFound;
use Commerz\Pricing\Prices\Exceptions\StagedPriceStateConflict;
use Commerz\Pricing\Taxes\Exceptions\TaxClassNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateOverlap;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneMemberTaken;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneNotFound;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

it('covers every PricingErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new PricingProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(PricingErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected);
});

it('exposes Packman error descriptors on pricing exceptions', function (CommerzException $e, PricingErrorCode $code, ErrorCategory $category): void {
    $descriptor = $e->errorDescriptor();

    expect($descriptor->errorCode)->toBe($code)
        ->and($descriptor->category)->toBe($category);
})->with([
    'invalid tax result' => [new InvalidTaxResult('rates', 'bad'), PricingErrorCode::InvalidTaxResult, ErrorCategory::Unavailable],
    'duplicate code' => [new DuplicateCode('Price list', 'retail'), PricingErrorCode::DuplicateCode, ErrorCategory::Conflict],
    'market archived' => [new MarketArchived('eu'), PricingErrorCode::MarketArchived, ErrorCategory::Conflict],
    'market country taken' => [new MarketCountryTaken('ch1', 'DE', 'eu'), PricingErrorCode::MarketCountryTaken, ErrorCategory::Conflict],
    'market not found' => [new MarketNotFound('m1'), PricingErrorCode::MarketNotFound, ErrorCategory::NotFound],
    'price list archived' => [new PriceListArchived('retail'), PricingErrorCode::PriceListArchived, ErrorCategory::Conflict],
    'price list cycle' => [new PriceListCycleDetected('vip', 'retail'), PricingErrorCode::PriceListCycle, ErrorCategory::Conflict],
    'price list not found' => [new PriceListNotFound('pl1'), PricingErrorCode::PriceListNotFound, ErrorCategory::NotFound],
    'price not found by id' => [new PriceNotFoundById('p1'), PricingErrorCode::PriceNotFound, ErrorCategory::NotFound],
    'price window overlap' => [new PriceWindowOverlap('tee', 'retail', 'p1'), PricingErrorCode::PriceWindowOverlap, ErrorCategory::Conflict],
    'staged price not found' => [new StagedPriceNotFound('sp1'), PricingErrorCode::StagedPriceNotFound, ErrorCategory::NotFound],
    'staged price state conflict' => [new StagedPriceStateConflict('sp1', StagedPriceStatus::Published), PricingErrorCode::StagedPriceStateConflict, ErrorCategory::Conflict],
    'tax class not found' => [new TaxClassNotFound('standard'), PricingErrorCode::TaxClassNotFound, ErrorCategory::NotFound],
    'tax rate not found' => [TaxRateNotFound::forId('r1'), PricingErrorCode::TaxRateNotFound, ErrorCategory::NotFound],
    'tax rate overlap' => [new TaxRateOverlap('standard', 'ru', 'vat', 'r1'), PricingErrorCode::TaxRateOverlap, ErrorCategory::Conflict],
    'tax zone member taken' => [new TaxZoneMemberTaken('RU', null, 'ru'), PricingErrorCode::TaxZoneMemberTaken, ErrorCategory::Conflict],
    'tax zone not found' => [new TaxZoneNotFound('ru'), PricingErrorCode::TaxZoneNotFound, ErrorCategory::NotFound],
]);
