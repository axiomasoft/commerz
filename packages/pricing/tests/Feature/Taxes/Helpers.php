<?php

declare(strict_types=1);

// Shared fixtures of the tax and calculator tests.

use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\TaxClassView;
use Commerz\Pricing\Taxes\Data\TaxRateView;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Data\TaxZoneView;

function txClass(string $code = 'standard'): TaxClassView
{
    return Pricing::taxes()->defineClass(new DefineTaxClass($code, ucfirst($code)));
}

/** @param  list<TaxZoneMember>  $members */
function txZone(string $code = 'ru', array $members = [new TaxZoneMember('RU')]): TaxZoneView
{
    return Pricing::taxes()->defineZone(new DefineTaxZone($code, strtoupper($code), $members));
}

function txRate(string $class = 'standard', string $zone = 'ru', int $bp = 2200, string $code = 'vat', ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): TaxRateView
{
    return Pricing::taxes()->setRate(new SetTaxRate($class, $zone, $bp, $code, $from, $until));
}

function txAt(string $moment): DateTimeImmutable
{
    return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
}
