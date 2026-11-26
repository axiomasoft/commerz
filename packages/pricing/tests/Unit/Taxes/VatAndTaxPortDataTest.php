<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Taxes\Data\CalculateTax;
use Commerz\Ports\Taxes\Data\TaxableLine;
use Commerz\Ports\Taxes\Data\TaxExemption;
use Commerz\Ports\Taxes\Internal\NullTaxEngine;
use Commerz\Pricing\Calculation\Data\TaxableItem;
use Commerz\Pricing\Calculation\Data\TaxRequest;
use Commerz\Pricing\Taxes\Data\VatRegistration;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Stores\Data\ChannelId;

it('normalizes and validates B2B VAT registrations and EU cross-border reverse charge eligibility', function (): void {
    $deVat = new VatRegistration(' de 123.456-789 ');
    $grVat = new VatRegistration('EL999999999');
    $chVat = new VatRegistration('CHE123456789');

    expect($deVat->vatNumber)->toBe('DE123456789')
        ->and($deVat->country)->toBe('DE')
        ->and($deVat->isEuMember())->toBeTrue()
        ->and($grVat->country)->toBe('GR')
        ->and($grVat->isEuMember())->toBeTrue()
        ->and($chVat->isEuMember())->toBeFalse();

    // Cross-border EU B2B (DE seller -> FR buyer with FR VAT ID): qualifies
    $frVat = new VatRegistration('FR12345678901');
    expect($frVat->qualifiesForReverseCharge('DE', 'FR'))->toBeTrue()
        // Domestic B2B (FR seller -> FR buyer): does NOT reverse charge
        ->and($frVat->qualifiesForReverseCharge('FR', 'FR'))->toBeFalse()
        // Destination mismatch (shipping to IT with FR VAT ID): does NOT reverse charge
        ->and($frVat->qualifiesForReverseCharge('DE', 'IT'))->toBeFalse();

    expect(fn () => new VatRegistration('12345'))->toThrow(InvalidCommand::class);
});

it('evaluates B2B reverse charge and tax exemptions on TaxRequest and core Taxes port DTOs', function (): void {
    $ctx = CommerceContext::for(ChannelId::generate(), Currency::of('EUR'));
    $item = new TaxableItem(AdjustmentTarget::Line, '1', null, 'standard', 1, Money::of('100.00', 'EUR'), false);

    $exemptReq = new TaxRequest($ctx, new Address('DE'), [$item], exemption: TaxExemption::exempt('diplomatic', 'CERT-1'));
    expect($exemptReq->isTaxExempt())->toBeTrue()
        ->and($exemptReq->isReverseCharge())->toBeFalse();

    $rcCrossBorder = new TaxRequest(
        $ctx,
        new Address('FR'),
        [$item],
        buyerVat: new VatRegistration('FR12345678901'),
        sellerCountry: 'DE',
    );
    expect($rcCrossBorder->isReverseCharge())->toBeTrue();

    $rcDomestic = new TaxRequest(
        $ctx,
        new Address('DE'),
        [$item],
        exemption: TaxExemption::reverseCharge('DE123456789', sellerCountry: 'DE'),
    );
    expect($rcDomestic->isReverseCharge())->toBeFalse();

    $nullEngine = new NullTaxEngine;
    $portRes = $nullEngine->calculate(new CalculateTax(
        context: $ctx,
        destination: new Address('FR'),
        lines: [TaxableLine::line('1', Money::of('100.00', 'EUR'))],
        exemption: TaxExemption::reverseCharge('FR12345678901', 'DE', 'intra_eu_b2b'),
    ));

    expect($portRes->reverseCharge)->toBeTrue()
        ->and($portRes->total->isZero())->toBeTrue()
        ->and($portRes->exemptionReason)->toBe('intra_eu_b2b');
});

it('validates lowestPriceLast30Days on ResolvedPrice', function (): void {
    $resolved = new ResolvedPrice(
        unit: Money::of('80.00', 'EUR'),
        compareAt: Money::of('100.00', 'EUR'),
        taxIncluded: true,
        source: 'price_list:retail',
        lowestPriceLast30Days: Money::of('75.00', 'EUR'),
    );

    expect($resolved->lowestPriceLast30Days)->toEqual(Money::of('75.00', 'EUR'))
        ->and(fn () => new ResolvedPrice(
            Money::of('80.00', 'EUR'),
            Money::of('100.00', 'EUR'),
            true,
            'price_list:retail',
            Money::of('75.00', 'USD'),
        ))->toThrow(InvalidCommand::class)
        ->and(fn () => new ResolvedPrice(
            Money::of('80.00', 'EUR'),
            Money::of('100.00', 'EUR'),
            true,
            'price_list:retail',
            Money::ofMinor(-1, 'EUR'),
        ))->toThrow(InvalidCommand::class);
});
